<?php

declare(strict_types=1);

/**
 * School Result Management Portal - data layer (Phase 2 and Phase 3).
 *
 * Every query used by the admin and teacher pages lives here, so that no SQL is
 * written inside a page file and every statement is a prepared statement.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/school-settings.php';

/* ------------------------------------------------------------------ */
/* Domain constants                                                    */
/* ------------------------------------------------------------------ */

const GENDERS = ['Male', 'Female'];
const SECTIONS = ['Nursery', 'Primary', 'Secondary'];
const STUDENT_STATUSES = ['Active', 'Graduated', 'Transferred', 'Suspended', 'Inactive'];
const RECORD_STATUSES = ['Active', 'Inactive'];
const PER_PAGE_OPTIONS = [20, 50, 100];
const DEFAULT_PER_PAGE = 20;

/* Phase 3: academic calendar and assignment vocabularies. */
const SESSION_STATUSES = ['Active', 'Closed', 'Inactive'];
const TERM_NAMES = ['First Term', 'Second Term', 'Third Term'];
const TERM_STATUSES = ['Active', 'Closed', 'Inactive'];
const ASSIGNMENT_STATUSES = ['Active', 'Inactive'];

const STUDENT_PHOTO_DIR = 'uploads/students';
const MAX_PHOTO_BYTES = 2097152; // 2 MB
const ALLOWED_PHOTO_TYPES = [
    'image/jpeg' => 'jpg',
    'image/pjpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/* ------------------------------------------------------------------ */
/* Database helpers                                                    */
/* ------------------------------------------------------------------ */

/** Run a prepared statement and return it. */
function dbRun(string $sql, array $params = []): PDOStatement
{
    $stmt = getDbConnection()->prepare($sql);

    foreach ($params as $key => $value) {
        $name = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
        $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $stmt->execute();

    return $stmt;
}

/** Fetch every row. */
function dbAll(string $sql, array $params = []): array
{
    return dbRun($sql, $params)->fetchAll();
}

/** Fetch a single row, or null. */
function dbOne(string $sql, array $params = []): ?array
{
    $row = dbRun($sql, $params)->fetch();

    return $row === false ? null : $row;
}

/** Fetch a single scalar value, or null. */
function dbValue(string $sql, array $params = [])
{
    $value = dbRun($sql, $params)->fetchColumn();

    return $value === false ? null : $value;
}

/**
 * Run a callable inside a transaction and roll back on any failure.
 *
 * @param callable(PDO):mixed $work
 * @return mixed
 */
function dbTransaction(callable $work)
{
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    try {
        $result = $work($pdo);
        $pdo->commit();

        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

/** Escape LIKE wildcards so user input cannot alter the pattern. */
function likeTerm(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
}

/** Read a positive integer from the query string, or return null. */
function queryInt(string $key): ?int
{
    $raw = $_GET[$key] ?? null;

    if (!is_string($raw) || $raw === '' || !ctype_digit($raw)) {
        return null;
    }

    $value = (int) $raw;

    return $value > 0 ? $value : null;
}

/** Read a positive integer from the POST body, or return null. */
function queryPostId(string $key): ?int
{
    $raw = $_POST[$key] ?? null;

    if (is_int($raw)) {
        return $raw > 0 ? $raw : null;
    }

    if (!is_string($raw) || $raw === '' || !ctype_digit($raw)) {
        return null;
    }

    $value = (int) $raw;

    return $value > 0 ? $value : null;
}

/** Read an array of positive integers from the POST body. */
function postIdList(string $key): array
{
    $raw = $_POST[$key] ?? null;

    if (!is_array($raw)) {
        return [];
    }

    $ids = [];

    foreach ($raw as $value) {
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            $ids[] = (int) $value;
        }
    }

    return array_values(array_unique($ids));
}

/* ------------------------------------------------------------------ */
/* Pagination                                                          */
/* ------------------------------------------------------------------ */

function resolvePerPage(): int
{
    $perPage = queryInt('per_page') ?? DEFAULT_PER_PAGE;

    return in_array($perPage, PER_PAGE_OPTIONS, true) ? $perPage : DEFAULT_PER_PAGE;
}

/** Build the paging metadata for a result set. */
function paginate(int $total, int $perPage, int $page): array
{
    $perPage = max(1, $perPage);
    $pages   = max(1, (int) ceil($total / $perPage));
    $page    = min(max(1, $page), $pages);
    $offset  = ($page - 1) * $perPage;

    return [
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $page,
        'pages'    => $pages,
        'offset'   => $offset,
        'from'     => $total === 0 ? 0 : $offset + 1,
        'to'       => min($offset + $perPage, $total),
    ];
}

/* ------------------------------------------------------------------ */
/* Generic validation                                                  */
/* ------------------------------------------------------------------ */

/** Validate a date string, optionally against today (no future dates). */
function isValidDate(string $value, bool $noFuture = false): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return false;
    }

    if ($noFuture && $date > new DateTime('today')) {
        return false;
    }

    return true;
}

/** Nigerian style phone numbers: digits, spaces and + - ( ) only. */
function isValidPhone(string $value): bool
{
    return (bool) preg_match('/^[0-9+\-\s()]{7,20}$/', $value);
}

/** Codes may contain letters, digits, slash, dash and underscore. */
function isValidCode(string $value): bool
{
    return (bool) preg_match('/^[A-Za-z0-9\/\-_]{2,30}$/', $value);
}

/** Names: letters, spaces, apostrophes, hyphens and periods. */
function isValidName(string $value): bool
{
    return (bool) preg_match("/^[A-Za-z][A-Za-z\s'.\-]{0,59}$/", $value);
}

function inList(string $value, array $list): bool
{
    return in_array($value, $list, true);
}

/** Read a value from $_POST and normalise it to a trimmed string. */
function field(string $key, int $max = 255): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? trim(postString($key, $max)) : '';
}

/** Read a nullable field: empty string becomes null. */
function nullableField(string $key, int $max = 255): ?string
{
    $value = field($key, $max);

    return $value === '' ? null : $value;
}

/**
 * Merge validated form data over the defaults used to re-render a form.
 *
 * `nullableField()` returns null for an empty input, while the rendering helpers
 * are strictly typed as string. Merging naively would replace a usable default
 * with null and raise a TypeError the moment a select is drawn again after a
 * failed validation, so nulls are normalised back to an empty string here.
 */
function mergeFormValues(array $defaults, array $data): array
{
    foreach ($data as $key => $value) {
        $defaults[$key] = $value === null ? '' : $value;
    }

    return $defaults;
}

/**
 * Join name parts into one clean string (no double spaces).
 *
 * The parts are not type-hinted as string on purpose: `middle_name` is a
 * nullable column, so a raw PDO row can hand us null here. With
 * `declare(strict_types=1)` active in every caller file, a `string` parameter
 * would turn a missing middle name into a fatal TypeError.
 */
function joinName(...$parts): string
{
    $clean = [];

    foreach ($parts as $part) {
        $part = trim((string) $part);

        if ($part !== '') {
            $clean[] = $part;
        }
    }

    return (string) preg_replace('/\s+/', ' ', implode(' ', $clean));
}

function studentFullName(array $student): string
{
    return joinName(
        (string) $student['first_name'],
        (string) ($student['middle_name'] ?? ''),
        (string) ($student['last_name'] ?? '')
    );
}

function teacherFullName(array $teacher): string
{
    return joinName(
        (string) $teacher['first_name'],
        (string) ($teacher['middle_name'] ?? ''),
        (string) ($teacher['last_name'] ?? '')
    );
}

/** Bootstrap badge class for a status value. */
function statusBadgeClass(string $status): string
{
    return [
        'Active'     => 'bg-success',
        'Inactive'   => 'bg-secondary',
        'Closed'     => 'bg-dark',
        'Graduated'  => 'bg-info text-dark',
        'Transferred' => 'bg-warning text-dark',
        'Suspended'  => 'bg-danger',
    ][$status] ?? 'bg-secondary';
}

/* ------------------------------------------------------------------ */
/* Students                                                            */
/* ------------------------------------------------------------------ */

function findStudent(int $id): ?array
{
    return dbOne('SELECT * FROM students WHERE id = :id LIMIT 1', [':id' => $id]);
}

/**
 * Paginated student list with server side search and filters.
 *
 * @return array{total: int, rows: array}
 */
function listStudents(array $filters): array
{
    $where  = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $term = '%' . likeTerm($search) . '%';
        /* Native prepared statements cannot reuse one named placeholder, so
           every column gets its own parameter. */
        $where[] = '(s.admission_number LIKE :search1
                     OR s.first_name LIKE :search2
                     OR s.middle_name LIKE :search3
                     OR s.last_name LIKE :search4
                     OR s.parent_name LIKE :search5
                     OR s.parent_phone LIKE :search6
                     OR CONCAT_WS(" ", s.first_name, s.middle_name, s.last_name) LIKE :search7)';
        $params['search1'] = $term;
        $params['search2'] = $term;
        $params['search3'] = $term;
        $params['search4'] = $term;
        $params['search5'] = $term;
        $params['search6'] = $term;
        $params['search7'] = $term;
    }

    $section = (string) ($filters['section'] ?? '');
    if (inList($section, SECTIONS)) {
        $where[] = 's.section = :section';
        $params['section'] = $section;
    }

    $classId = $filters['class_id'] ?? null;
    if (is_int($classId) && $classId > 0) {
        $where[] = 's.class_id = :class_id';
        $params['class_id'] = $classId;
    }

    $status = (string) ($filters['status'] ?? '');
    if (inList($status, STUDENT_STATUSES)) {
        $where[] = 's.status = :status';
        $params['status'] = $status;
    }

    $gender = (string) ($filters['gender'] ?? '');
    if (inList($gender, GENDERS)) {
        $where[] = 's.gender = :gender';
        $params['gender'] = $gender;
    }

    $whereSql = implode(' AND ', $where);

    $total = (int) dbValue("SELECT COUNT(*) FROM students s WHERE {$whereSql}", $params);

    $params['limit']  = (int) $filters['per_page'];
    $params['offset'] = (int) $filters['offset'];

    $rows = dbAll(
        "SELECT s.*, c.class_name
         FROM students s
         LEFT JOIN classes c ON c.id = s.class_id
         WHERE {$whereSql}
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT :limit OFFSET :offset",
        $params
    );

    return ['total' => $total, 'rows' => $rows];
}

/** All classes, optionally limited to one section. */
function classOptions(string $section = ''): array
{
    if (inList($section, SECTIONS)) {
        return dbAll(
            'SELECT id, class_name, section, capacity FROM classes
             WHERE section = :section AND status = "Active"
             ORDER BY class_name',
            [':section' => $section]
        );
    }

    return dbAll(
        'SELECT id, class_name, section, capacity FROM classes
         WHERE status = "Active"
         ORDER BY FIELD(section, "Nursery", "Primary", "Secondary"), class_name'
    );
}

/** Number of active students per class, keyed by class id. */
function activeStudentCountsByClass(): array
{
    $rows = dbAll(
        'SELECT class_id, COUNT(*) AS total
         FROM students
         WHERE status = "Active" AND class_id IS NOT NULL
         GROUP BY class_id'
    );

    $counts = [];
    foreach ($rows as $row) {
        $counts[(int) $row['class_id']] = (int) $row['total'];
    }

    return $counts;
}

/**
 * Validate the student form on the server.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function validateStudentInput(?int $ignoreId = null): array
{
    $data = [
        'admission_number' => field('admission_number', 30),
        'first_name'       => field('first_name', 50),
        'middle_name'      => nullableField('middle_name', 50),
        'last_name'        => nullableField('last_name', 50),
        'gender'           => field('gender', 10),
        'date_of_birth'    => field('date_of_birth', 10),
        'parent_name'      => nullableField('parent_name', 120),
        'parent_phone'     => nullableField('parent_phone', 30),
        'parent_email'     => nullableField('parent_email', 120),
        'address'          => nullableField('address', 255),
        'section'          => field('section', 20),
        'class_id'         => nullableField('class_id', 10),
        'admission_date'   => field('admission_date', 10),
        'status'           => field('status', 20),
    ];

    $errors = [];

    if ($data['admission_number'] === '') {
        $errors['admission_number'] = 'Admission number is required.';
    } elseif (dbValue('SELECT COUNT(*) FROM students WHERE admission_number = :v', [':v' => $data['admission_number']])) {
        $errors['admission_number'] = 'Admission number already exists.';
    }

    if ($data['first_name'] === '') {
        $errors['first_name'] = 'First name is required.';
    } elseif (!isValidName($data['first_name'])) {
        $errors['first_name'] = 'First name contains invalid characters.';
    }

    foreach (['middle_name' => 'Middle name', 'last_name' => 'Last name'] as $key => $label) {
        if ($data[$key] !== null && $data[$key] !== '' && !isValidName($data[$key])) {
            $errors[$key] = $label . ' contains invalid characters.';
        }
    }

    if (!inList($data['gender'], GENDERS)) {
        $errors['gender'] = 'Please select a gender.';
    }

    if ($data['date_of_birth'] === '') {
        $errors['date_of_birth'] = 'Date of birth is required.';
    } elseif (!isValidDate($data['date_of_birth'], true)) {
        $errors['date_of_birth'] = 'Enter a valid date of birth.';
    }

    if ($data['parent_phone'] !== null && $data['parent_phone'] !== '' && !isValidPhone($data['parent_phone'])) {
        $errors['parent_phone'] = 'Enter a valid phone number.';
    }

    if ($data['parent_email'] !== null && $data['parent_email'] !== '' && !isValidEmail($data['parent_email'])) {
        $errors['parent_email'] = 'Enter a valid e-mail address.';
    }

    if (!inList($data['section'], SECTIONS)) {
        $errors['section'] = 'Please select a section.';
    }

    if ($data['class_id'] === null || $data['class_id'] === '') {
        $errors['class_id'] = 'Please select a class.';
    } else {
        $class = dbOne('SELECT id, section, status FROM classes WHERE id = :id', [':id' => (int) $data['class_id']]);

        if (!$class) {
            $errors['class_id'] = 'The selected class does not exist.';
        } elseif ((string) $class['section'] !== $data['section']) {
            $errors['class_id'] = 'The selected class does not belong to the chosen section.';
        }
    }

    if ($data['admission_date'] === '') {
        $errors['admission_date'] = 'Admission date is required.';
    } elseif (!isValidDate($data['admission_date'])) {
        $errors['admission_date'] = 'Enter a valid admission date.';
    }

    if (!inList($data['status'], STUDENT_STATUSES)) {
        $errors['status'] = 'Please select a status.';
    }

    return [$data, $errors];
}

/** Insert a student and return the new id. */
function createStudent(array $data, ?string $photo): int
{
    dbRun(
        'INSERT INTO students
            (admission_number, first_name, middle_name, last_name, gender, date_of_birth,
             passport_photo, parent_name, parent_phone, parent_email, address,
             section, class_id, admission_date, status)
         VALUES
            (:admission_number, :first_name, :middle_name, :last_name, :gender, :date_of_birth,
             :passport_photo, :parent_name, :parent_phone, :parent_email, :address,
             :section, :class_id, :admission_date, :status)',
        [
            ':admission_number' => $data['admission_number'],
            ':first_name'       => $data['first_name'],
            ':middle_name'      => $data['middle_name'],
            ':last_name'        => $data['last_name'],
            ':gender'           => $data['gender'],
            ':date_of_birth'    => $data['date_of_birth'],
            ':passport_photo'   => $photo,
            ':parent_name'      => $data['parent_name'],
            ':parent_phone'     => $data['parent_phone'],
            ':parent_email'     => $data['parent_email'],
            ':address'          => $data['address'],
            ':section'          => $data['section'],
            ':class_id'         => (int) $data['class_id'],
            ':admission_date'   => $data['admission_date'],
            ':status'           => $data['status'],
        ]
    );

    return (int) getDbConnection()->lastInsertId();
}

/** Update a student. */
function updateStudent(int $id, array $data, ?string $photo, bool $clearPhoto): void
{
    if ($clearPhoto) {
        dbRun(
            'UPDATE students SET passport_photo = NULL WHERE id = :id',
            [':id' => $id]
        );
    }

    dbRun(
        'UPDATE students SET
            admission_number = :admission_number,
            first_name = :first_name,
            middle_name = :middle_name,
            last_name = :last_name,
            gender = :gender,
            date_of_birth = :date_of_birth,
            parent_name = :parent_name,
            parent_phone = :parent_phone,
            parent_email = :parent_email,
            address = :address,
            section = :section,
            class_id = :class_id,
            admission_date = :admission_date,
            status = :status
         WHERE id = :id',
        [
            ':id'               => $id,
            ':admission_number' => $data['admission_number'],
            ':first_name'       => $data['first_name'],
            ':middle_name'      => $data['middle_name'],
            ':last_name'        => $data['last_name'],
            ':gender'           => $data['gender'],
            ':date_of_birth'    => $data['date_of_birth'],
            ':parent_name'      => $data['parent_name'],
            ':parent_phone'     => $data['parent_phone'],
            ':parent_email'     => $data['parent_email'],
            ':address'          => $data['address'],
            ':section'          => $data['section'],
            ':class_id'         => (int) $data['class_id'],
            ':admission_date'   => $data['admission_date'],
            ':status'           => $data['status'],
        ]
    );

    if ($photo !== null) {
        dbRun(
            'UPDATE students SET passport_photo = :photo WHERE id = :id',
            [':photo' => $photo, ':id' => $id]
        );
    }
}

/** Ignore a record id when validating a unique field for an update. */
function isUniqueTaken(string $table, string $column, string $value, ?int $ignoreId, string $idColumn = 'id'): bool
{
    $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :value";

    if ($ignoreId !== null) {
        $sql .= " AND `{$idColumn}` <> :ignore";
    }

    $params = [':value' => $value];

    if ($ignoreId !== null) {
        $params[':ignore'] = $ignoreId;
    }

    return (int) dbValue($sql, $params) > 0;
}

function setStudentStatus(int $id, string $status): void
{
    dbRun(
        'UPDATE students SET status = :status WHERE id = :id',
        [':status' => $status, ':id' => $id]
    );
}

/* ------------------------------------------------------------------ */
/* Passport uploads                                                    */
/* ------------------------------------------------------------------ */

/**
 * Validate and store a passport photo.
 *
 * The client filename is never used: a random name is generated and only the
 * extension is taken from an allow-list keyed by the real MIME type, so a PHP
 * file renamed to .jpg is rejected rather than stored.
 *
 * @return array{file: ?string, error: ?string}
 */
function handlePassportUpload(?array $file): array
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['file' => null, 'error' => null];
    }

    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($code !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'The photo is larger than the server allows.',
            UPLOAD_ERR_FORM_SIZE  => 'The photo is larger than the form allows.',
            UPLOAD_ERR_PARTIAL    => 'The photo was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION  => 'A server extension blocked the upload.',
        ];

        return ['file' => null, 'error' => $messages[$code] ?? 'The photo could not be uploaded.'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['file' => null, 'error' => 'The uploaded photo could not be verified.'];
    }

    if ((int) ($file['size'] ?? 0) > MAX_PHOTO_BYTES) {
        return ['file' => null, 'error' => 'Passport photo must be smaller than 2 MB.'];
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        return ['file' => null, 'error' => 'Invalid image type. Please upload a JPG, PNG or WEBP photo.'];
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpPath);

    if (!isset(ALLOWED_PHOTO_TYPES[$mimeType])) {
        return ['file' => null, 'error' => 'Invalid image type. Please upload a JPG, PNG or WEBP photo.'];
    }

    $directory = dirname(__DIR__) . '/' . STUDENT_PHOTO_DIR;

    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['file' => null, 'error' => 'The photo folder is not writable on the server.'];
    }

    $safeName = date('Ymd') . '-' . bin2hex(random_bytes(10)) . '.' . ALLOWED_PHOTO_TYPES[$mimeType];
    $target   = $directory . '/' . $safeName;

    if (!@move_uploaded_file($tmpPath, $target)) {
        return ['file' => null, 'error' => 'The photo could not be saved on the server.'];
    }

    @chmod($target, 0644);

    return ['file' => $safeName, 'error' => null];
}

/** Remove a stored passport file. The school logo is never touched. */
function deletePassportPhoto(?string $fileName): void
{
    if ($fileName === null || $fileName === '' || $fileName === 'logo.jpeg') {
        return;
    }

    /* Only a generated name of the expected shape may ever be deleted. */
    if (preg_match('/^\d{8}-[a-f0-9]{20}\.(jpg|png|webp)$/', $fileName) !== 1) {
        return;
    }

    $path = dirname(__DIR__) . '/' . STUDENT_PHOTO_DIR . '/' . $fileName;

    if (is_file($path)) {
        @unlink($path);
    }
}

/** Public URL of a student photo, falling back to the school logo. */
function studentPhotoUrl(?string $fileName): string
{
    if ($fileName === null || $fileName === '') {
        return assetUrl('assets/images/logo.jpeg');
    }

    return assetUrl(STUDENT_PHOTO_DIR . '/' . $fileName);
}

/* ------------------------------------------------------------------ */
/* Teachers                                                            */
/* ------------------------------------------------------------------ */

function findTeacher(int $id): ?array
{
    return dbOne(
        'SELECT t.*, u.username, u.role, u.status AS account_status
         FROM teachers t
         LEFT JOIN users u ON u.id = t.user_id
         WHERE t.id = :id
         LIMIT 1',
        [':id' => $id]
    );
}

/** Active teachers for the dropdowns. */
function teacherOptions(): array
{
    return dbAll(
        'SELECT id, staff_number, first_name, middle_name, last_name
         FROM teachers
         WHERE status = "Active"
         ORDER BY first_name, last_name'
    );
}

function listTeachers(array $filters): array
{
    $where  = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $term = '%' . likeTerm($search) . '%';
        $where[] = '(t.staff_number LIKE :search1
                     OR t.first_name LIKE :search2
                     OR t.middle_name LIKE :search3
                     OR t.last_name LIKE :search4
                     OR t.phone LIKE :search5
                     OR t.email LIKE :search6
                     OR CONCAT_WS(" ", t.first_name, t.middle_name, t.last_name) LIKE :search7)';
        for ($i = 1; $i <= 7; $i++) {
            $params['search' . $i] = $term;
        }
    }

    $status = (string) ($filters['status'] ?? '');
    if (inList($status, RECORD_STATUSES)) {
        $where[] = 't.status = :status';
        $params['status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $total = (int) dbValue("SELECT COUNT(*) FROM teachers t WHERE {$whereSql}", $params);

    $params['limit']  = (int) $filters['per_page'];
    $params['offset'] = (int) $filters['offset'];

    $rows = dbAll(
        "SELECT t.*, u.username, u.status AS account_status
         FROM teachers t
         LEFT JOIN users u ON u.id = t.user_id
         WHERE {$whereSql}
         ORDER BY t.created_at DESC, t.id DESC
         LIMIT :limit OFFSET :offset",
        $params
    );

    return ['total' => $total, 'rows' => $rows];
}

/**
 * Validate the teacher form.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
 */
function validateTeacherInput(?int $ignoreId = null, bool $withAccount = false): array
{
    $data = [
        'staff_number'  => field('staff_number', 30),
        'first_name'    => field('first_name', 50),
        'middle_name'   => nullableField('middle_name', 50),
        'last_name'     => nullableField('last_name', 50),
        'gender'        => field('gender', 10),
        'phone'         => nullableField('phone', 30),
        'email'         => nullableField('email', 120),
        'address'       => nullableField('address', 255),
        'qualification' => nullableField('qualification', 120),
        'department'    => nullableField('department', 80),
        'status'        => field('status', 20),
    ];

    $errors = [];

    if ($data['staff_number'] === '') {
        $errors['staff_number'] = 'Staff number is required.';
    } elseif (isUniqueTaken('teachers', 'staff_number', $data['staff_number'], $ignoreId)) {
        $errors['staff_number'] = 'Staff number already exists.';
    }

    if ($data['first_name'] === '') {
        $errors['first_name'] = 'First name is required.';
    } elseif (!isValidName($data['first_name'])) {
        $errors['first_name'] = 'First name contains invalid characters.';
    }

    foreach (['middle_name' => 'Middle name', 'last_name' => 'Last name'] as $key => $label) {
        if ($data[$key] !== null && $data[$key] !== '' && !isValidName($data[$key])) {
            $errors[$key] = $label . ' contains invalid characters.';
        }
    }

    if (!inList($data['gender'], GENDERS)) {
        $errors['gender'] = 'Please select a gender.';
    }

    if ($data['phone'] !== null && $data['phone'] !== '' && !isValidPhone($data['phone'])) {
        $errors['phone'] = 'Enter a valid phone number.';
    }

    if ($data['email'] !== null && $data['email'] !== '') {
        if (!isValidEmail($data['email'])) {
            $errors['email'] = 'Enter a valid e-mail address.';
        } elseif (isUniqueTaken('teachers', 'email', $data['email'], $ignoreId)) {
            $errors['email'] = 'This e-mail address is already used by another teacher.';
        }
    }

    if (!inList($data['status'], RECORD_STATUSES)) {
        $errors['status'] = 'Please select a status.';
    }

    $account = ['username' => '', 'password' => ''];

    if ($withAccount) {
        $account['username'] = field('username', 50);
        $password            = field('password', 100);
        $confirm             = field('password_confirm', 100);

        if ($account['username'] === '') {
            $errors['username'] = 'Username is required.';
        } elseif (!isValidUsername($account['username'])) {
            $errors['username'] = 'Use 3 to 50 letters, numbers, dots, dashes or underscores.';
        } elseif (isUniqueTaken('users', 'username', $account['username'], null)) {
            $errors['username'] = 'Username already exists.';
        }

        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors['password'] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }

        $account['password'] = $password;
    }

    return [$data, $errors, $account];
}

/**
 * Create the portal account and the teacher profile in one transaction.
 *
 * @return int the new teacher id
 */
function createTeacherWithAccount(array $data, array $account): int
{
    return (int) dbTransaction(function (PDO $pdo) use ($data, $account) {
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password, full_name, email, role, status)
             VALUES (:username, :password, :full_name, :email, "teacher", 1)'
        );

        $fullName = joinName($data['first_name'], $data['middle_name'] ?? '', $data['last_name'] ?? '');

        $stmt->execute([
            ':username' => $account['username'],
            ':password' => password_hash($account['password'], PASSWORD_DEFAULT),
            ':full_name' => $fullName,
            ':email'    => $data['email'],
        ]);

        $userId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO teachers
                (staff_number, first_name, middle_name, last_name, gender, phone, email,
                 address, qualification, department, user_id, status)
             VALUES
                (:staff_number, :first_name, :middle_name, :last_name, :gender, :phone, :email,
                 :address, :qualification, :department, :user_id, :status)'
        );

        $stmt->execute([
            ':staff_number'  => $data['staff_number'],
            ':first_name'    => $data['first_name'],
            ':middle_name'   => $data['middle_name'],
            ':last_name'     => $data['last_name'],
            ':gender'        => $data['gender'],
            ':phone'         => $data['phone'],
            ':email'         => $data['email'],
            ':address'       => $data['address'],
            ':qualification' => $data['qualification'],
            ':department'    => $data['department'],
            ':user_id'       => $userId,
            ':status'        => $data['status'],
        ]);

        return (int) $pdo->lastInsertId();
    });
}

function updateTeacher(int $id, array $data): void
{
    dbRun(
        'UPDATE teachers SET
            staff_number = :staff_number,
            first_name = :first_name,
            middle_name = :middle_name,
            last_name = :last_name,
            gender = :gender,
            phone = :phone,
            email = :email,
            address = :address,
            qualification = :qualification,
            department = :department,
            status = :status
         WHERE id = :id',
        [
            ':id'            => $id,
            ':staff_number'  => $data['staff_number'],
            ':first_name'    => $data['first_name'],
            ':middle_name'   => $data['middle_name'],
            ':last_name'     => $data['last_name'],
            ':gender'        => $data['gender'],
            ':phone'         => $data['phone'],
            ':email'         => $data['email'],
            ':address'       => $data['address'],
            ':qualification' => $data['qualification'],
            ':department'    => $data['department'],
            ':status'        => $data['status'],
        ]
    );
}

/** Write a new bcrypt hash for a teacher account. The password is never shown. */
function resetTeacherPassword(int $teacherId, string $password, string $confirm): bool
{
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return false;
    }

    if ($password !== $confirm) {
        return false;
    }

    $teacher = findTeacher($teacherId);

    if (!$teacher || $teacher['user_id'] === null) {
        return false;
    }

    dbRun(
        'UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id',
        [
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':id'       => (int) $teacher['user_id'],
        ]
    );

    return true;
}

/** Keep the portal login usable when a teacher is deactivated or reactivated. */
function syncTeacherAccountStatus(int $teacherId, string $status): void
{
    $teacher = findTeacher($teacherId);

    if (!$teacher || $teacher['user_id'] === null) {
        return;
    }

    dbRun(
        'UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id',
        [
            ':status' => $status === 'Active' ? 1 : 0,
            ':id'     => (int) $teacher['user_id'],
        ]
    );
}

function setTeacherStatus(int $id, string $status): void
{
    dbRun(
        'UPDATE teachers SET status = :status WHERE id = :id',
        [':status' => $status, ':id' => $id]
    );

    syncTeacherAccountStatus($id, $status);
}

/* ------------------------------------------------------------------ */
/* Classes                                                             */
/* ------------------------------------------------------------------ */

function findClass(int $id): ?array
{
    return dbOne(
        'SELECT c.*, t.first_name AS teacher_first_name, t.middle_name AS teacher_middle_name,
                t.last_name AS teacher_last_name, t.staff_number AS teacher_staff_number
         FROM classes c
         LEFT JOIN teachers t ON t.id = c.class_teacher_id
         WHERE c.id = :id
         LIMIT 1',
        [':id' => $id]
    );
}

function listClasses(array $filters): array
{
    $where  = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $term = '%' . likeTerm($search) . '%';
        $where[] = '(c.class_name LIKE :search1
                     OR t.staff_number LIKE :search2
                     OR t.first_name LIKE :search3
                     OR t.last_name LIKE :search4
                     OR CONCAT_WS(" ", t.first_name, t.middle_name, t.last_name) LIKE :search5)';
        for ($i = 1; $i <= 5; $i++) {
            $params['search' . $i] = $term;
        }
    }

    $section = (string) ($filters['section'] ?? '');
    if (inList($section, SECTIONS)) {
        $where[] = 'c.section = :section';
        $params['section'] = $section;
    }

    $status = (string) ($filters['status'] ?? '');
    if (inList($status, RECORD_STATUSES)) {
        $where[] = 'c.status = :status';
        $params['status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $total = (int) dbValue(
        "SELECT COUNT(*) FROM classes c
         LEFT JOIN teachers t ON t.id = c.class_teacher_id
         WHERE {$whereSql}",
        $params
    );

    $params['limit']  = (int) $filters['per_page'];
    $params['offset'] = (int) $filters['offset'];

    $rows = dbAll(
        "SELECT c.*,
                t.first_name AS teacher_first_name, t.middle_name AS teacher_middle_name,
                t.last_name AS teacher_last_name, t.staff_number AS teacher_staff_number,
                (SELECT COUNT(*) FROM students s
                  WHERE s.class_id = c.id AND s.status = 'Active') AS student_count
         FROM classes c
         LEFT JOIN teachers t ON t.id = c.class_teacher_id
         WHERE {$whereSql}
         ORDER BY FIELD(c.section, 'Nursery', 'Primary', 'Secondary'), c.class_name
         LIMIT :limit OFFSET :offset",
        $params
    );

    return ['total' => $total, 'rows' => $rows];
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function validateClassInput(?int $ignoreId = null): array
{
    $data = [
        'class_name'       => field('class_name', 50),
        'section'          => field('section', 20),
        'class_teacher_id' => nullableField('class_teacher_id', 10),
        'capacity'         => field('capacity', 5),
        'status'           => field('status', 20),
    ];

    $errors = [];

    if ($data['class_name'] === '') {
        $errors['class_name'] = 'Class name is required.';
    } elseif (!inList($data['section'], SECTIONS)) {
        $errors['class_name'] = 'Please select a section.';
    } else {
        $existing = dbValue(
            'SELECT COUNT(*) FROM classes WHERE class_name = :name AND section = :section',
            [':name' => $data['class_name'], ':section' => $data['section']]
        );

        if ($existing && !dbValue(
            'SELECT COUNT(*) FROM classes WHERE class_name = :name AND section = :section AND id <> :id',
            [':name' => $data['class_name'], ':section' => $data['section'], ':id' => (int) $ignoreId]
        )) {
            $errors['class_name'] = 'This class already exists in the selected section.';
        }
    }

    $capacity = $data['capacity'];

    if ($capacity === '' || preg_match('/^\d{1,3}$/', $capacity) !== 1) {
        $errors['capacity'] = 'Enter a whole number between 1 and 500.';
    } else {
        $value = (int) $capacity;

        if ($value < 1 || $value > 500) {
            $errors['capacity'] = 'Capacity must be between 1 and 500.';
        } else {
            $data['capacity'] = $value;
        }
    }

    if ($data['class_teacher_id'] !== null && $data['class_teacher_id'] !== '') {
        if (!dbValue('SELECT COUNT(*) FROM teachers WHERE id = :id', [':id' => (int) $data['class_teacher_id']])) {
            $errors['class_teacher_id'] = 'The selected teacher does not exist.';
        }
    } else {
        $data['class_teacher_id'] = null;
    }

    if (!inList($data['status'], RECORD_STATUSES)) {
        $errors['status'] = 'Please select a status.';
    }

    return [$data, $errors];
}

function createClass(array $data): int
{
    dbRun(
        'INSERT INTO classes (class_name, section, class_teacher_id, capacity, status)
         VALUES (:class_name, :section, :class_teacher_id, :capacity, :status)',
        [
            ':class_name'       => $data['class_name'],
            ':section'          => $data['section'],
            ':class_teacher_id' => $data['class_teacher_id'] === null ? null : (int) $data['class_teacher_id'],
            ':capacity'         => (int) $data['capacity'],
            ':status'           => $data['status'],
        ]
    );

    return (int) getDbConnection()->lastInsertId();
}

function updateClass(int $id, array $data): void
{
    dbRun(
        'UPDATE classes SET
            class_name = :class_name,
            section = :section,
            class_teacher_id = :class_teacher_id,
            capacity = :capacity,
            status = :status
         WHERE id = :id',
        [
            ':id'               => $id,
            ':class_name'       => $data['class_name'],
            ':section'          => $data['section'],
            ':class_teacher_id' => $data['class_teacher_id'] === null ? null : (int) $data['class_teacher_id'],
            ':capacity'         => (int) $data['capacity'],
            ':status'           => $data['status'],
        ]
    );
}

function setClassStatus(int $id, string $status): void
{
    dbRun(
        'UPDATE classes SET status = :status WHERE id = :id',
        [':status' => $status, ':id' => $id]
    );
}

/* ------------------------------------------------------------------ */
/* Subjects                                                            */
/* ------------------------------------------------------------------ */

function findSubject(int $id): ?array
{
    return dbOne('SELECT * FROM subjects WHERE id = :id LIMIT 1', [':id' => $id]);
}

/** Active subjects for the dropdowns. */
function subjectOptions(?string $section = null): array
{
    if ($section !== null && inList($section, SECTIONS)) {
        return dbAll(
            'SELECT id, subject_code, subject_name, section
             FROM subjects
             WHERE status = "Active" AND section = :section
             ORDER BY subject_name',
            [':section' => $section]
        );
    }

    return dbAll(
        'SELECT id, subject_code, subject_name, section
         FROM subjects
         WHERE status = "Active"
         ORDER BY FIELD(section, "Nursery", "Primary", "Secondary"), subject_name'
    );
}

function listSubjects(array $filters): array
{
    $where  = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $term = '%' . likeTerm($search) . '%';
        $where[] = '(s.subject_code LIKE :search1 OR s.subject_name LIKE :search2)';
        $params['search1'] = $term;
        $params['search2'] = $term;
    }

    $section = (string) ($filters['section'] ?? '');
    if (inList($section, SECTIONS)) {
        $where[] = 's.section = :section';
        $params['section'] = $section;
    }

    $status = (string) ($filters['status'] ?? '');
    if (inList($status, RECORD_STATUSES)) {
        $where[] = 's.status = :status';
        $params['status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $total = (int) dbValue("SELECT COUNT(*) FROM subjects s WHERE {$whereSql}", $params);

    $params['limit']  = (int) $filters['per_page'];
    $params['offset'] = (int) $filters['offset'];

    $rows = dbAll(
        "SELECT s.*,
                (SELECT COUNT(*) FROM teacher_subject_assignments tsa
                  WHERE tsa.subject_id = s.id AND tsa.status = 'Active') AS class_count
         FROM subjects s
         WHERE {$whereSql}
         ORDER BY s.subject_name
         LIMIT :limit OFFSET :offset",
        $params
    );

    return ['total' => $total, 'rows' => $rows];
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function validateSubjectInput(?int $ignoreId = null): array
{
    $data = [
        'subject_code' => field('subject_code', 20),
        'subject_name' => field('subject_name', 120),
        'section'      => field('section', 20),
        'status'       => field('status', 20),
    ];

    $errors = [];

    if ($data['subject_code'] === '') {
        $errors['subject_code'] = 'Subject code is required.';
    } elseif (!isValidCode($data['subject_code'])) {
        $errors['subject_code'] = 'Use 2 to 30 letters, numbers, slash, dash or underscore.';
    } elseif (isUniqueTaken('subjects', 'subject_code', $data['subject_code'], $ignoreId)) {
        $errors['subject_code'] = 'Subject code already exists.';
    }

    if ($data['subject_name'] === '') {
        $errors['subject_name'] = 'Subject name is required.';
    }

    if (!inList($data['section'], SECTIONS)) {
        $errors['section'] = 'Please select a section.';
    }

    if (!inList($data['status'], RECORD_STATUSES)) {
        $errors['status'] = 'Please select a status.';
    }

    return [$data, $errors];
}

function createSubject(array $data): int
{
    dbRun(
        'INSERT INTO subjects (subject_code, subject_name, section, status)
         VALUES (:subject_code, :subject_name, :section, :status)',
        [
            ':subject_code' => $data['subject_code'],
            ':subject_name' => $data['subject_name'],
            ':section'      => $data['section'],
            ':status'       => $data['status'],
        ]
    );

    return (int) getDbConnection()->lastInsertId();
}

function updateSubject(int $id, array $data): void
{
    dbRun(
        'UPDATE subjects SET
            subject_code = :subject_code,
            subject_name = :subject_name,
            section = :section,
            status = :status
         WHERE id = :id',
        [
            ':id'           => $id,
            ':subject_code' => $data['subject_code'],
            ':subject_name' => $data['subject_name'],
            ':section'      => $data['section'],
            ':status'       => $data['status'],
        ]
    );
}

function setSubjectStatus(int $id, string $status): void
{
    dbRun(
        'UPDATE subjects SET status = :status WHERE id = :id',
        [':status' => $status, ':id' => $id]
    );
}

/* ------------------------------------------------------------------ */
/* Phase 3: academic sessions                                          */
/* ------------------------------------------------------------------ */

function findSession(int $id): ?array
{
    return dbOne('SELECT * FROM academic_sessions WHERE id = :id LIMIT 1', [':id' => $id]);
}

/** The one session flagged as current, or null. */
function currentSession(): ?array
{
    return dbOne('SELECT * FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
}

/** Sessions for the dropdowns, newest first. */
function sessionOptions(bool $activeOnly = false): array
{
    if ($activeOnly) {
        return dbAll(
            'SELECT id, session_name, start_date, end_date, status, is_current
             FROM academic_sessions
             WHERE status = "Active"
             ORDER BY start_date DESC'
        );
    }

    return dbAll(
        'SELECT id, session_name, start_date, end_date, status, is_current
         FROM academic_sessions
         ORDER BY start_date DESC'
    );
}

function listSessions(array $filters): array
{
    $where  = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $term = '%' . likeTerm($search) . '%';
        $where[] = '(s.session_name LIKE :search1 OR s.start_date LIKE :search2 OR s.end_date LIKE :search3)';
        $params['search1'] = $term;
        $params['search2'] = $term;
        $params['search3'] = $term;
    }

    $status = (string) ($filters['status'] ?? '');
    if (inList($status, SESSION_STATUSES)) {
        $where[] = 's.status = :status';
        $params['status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $total = (int) dbValue("SELECT COUNT(*) FROM academic_sessions s WHERE {$whereSql}", $params);

    $params['limit']  = (int) $filters['per_page'];
    $params['offset'] = (int) $filters['offset'];

    $rows = dbAll(
        "SELECT s.*,
                (SELECT COUNT(*) FROM terms t WHERE t.session_id = s.id) AS term_count,
                (SELECT COUNT(*) FROM student_class_enrollments e
                  WHERE e.session_id = s.id AND e.status = 'Active') AS enrollment_count
         FROM academic_sessions s
         WHERE {$whereSql}
         ORDER BY s.start_date DESC, s.id DESC
         LIMIT :limit OFFSET :offset",
        $params
    );

    return ['total' => $total, 'rows' => $rows];
}

/**
 * Validate the academic session form.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function validateSessionInput(?int $ignoreId = null): array
{
    $rawName = field('session_name', 20);
    $name    = trim(preg_replace('#\s*/\s*#', '/', $rawName) ?? '');

    $data = [
        'session_name' => $name,
        'start_date'   => field('start_date', 10),
        'end_date'     => field('end_date', 10),
        'status'       => field('status', 20),
    ];

    $errors = [];

    if ($name === '') {
        $errors['session_name'] = 'Session name is required.';
    } elseif (preg_match('/^\d{4}\/\d{4}$/', $name) !== 1) {
        $errors['session_name'] = 'Use the YYYY/YYYY format, for example 2026/2027.';
    } elseif (isUniqueTaken('academic_sessions', 'session_name', $name, $ignoreId)) {
        $errors['session_name'] = 'This academic session already exists.';
    }

    if ($data['start_date'] === '') {
        $errors['start_date'] = 'Start date is required.';
    } elseif (!isValidDate($data['start_date'])) {
        $errors['start_date'] = 'Enter a valid start date.';
    }

    if ($data['end_date'] === '') {
        $errors['end_date'] = 'End date is required.';
    } elseif (!isValidDate($data['end_date'])) {
        $errors['end_date'] = 'Enter a valid end date.';
    } elseif ($data['start_date'] !== '' && $data['end_date'] <= $data['start_date']) {
        $errors['end_date'] = 'The end date must be after the start date.';
    }

    if (!inList($data['status'], SESSION_STATUSES)) {
        $errors['status'] = 'Please select a status.';
    }

    return [$data, $errors];
}

function createSession(array $data): int
{
    dbRun(
        'INSERT INTO academic_sessions (session_name, start_date, end_date, status, is_current)
         VALUES (:session_name, :start_date, :end_date, :status, 0)',
        [
            ':session_name' => $data['session_name'],
            ':start_date'   => $data['start_date'],
            ':end_date'     => $data['end_date'],
            ':status'       => $data['status'],
        ]
    );

    return (int) getDbConnection()->lastInsertId();
}

function updateSession(int $id, array $data): void
{
    dbRun(
        'UPDATE academic_sessions SET
            session_name = :session_name,
            start_date = :start_date,
            end_date = :end_date,
            status = :status
         WHERE id = :id',
        [
            ':id'           => $id,
            ':session_name' => $data['session_name'],
            ':start_date'   => $data['start_date'],
            ':end_date'     => $data['end_date'],
            ':status'       => $data['status'],
        ]
    );
}

/**
 * Make one session the current academic session.
 *
 * Runs in a transaction: the current flag is cleared everywhere, the chosen
 * session is flagged and activated if needed, and the current-term flag is
 * dropped from every other session so the "current term belongs to the current
 * session" rule can never be broken.
 */
function setCurrentSession(int $sessionId): void
{
    dbTransaction(function (PDO $pdo) use ($sessionId) {
        $stmt = $pdo->prepare(
            'UPDATE academic_sessions SET is_current = 0, updated_at = NOW() WHERE is_current = 1'
        );
        $stmt->execute();

        $stmt = $pdo->prepare(
            'UPDATE academic_sessions
             SET is_current = 1, status = "Active", updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([':id' => $sessionId]);

        $stmt = $pdo->prepare(
            'UPDATE terms SET is_current = 0, updated_at = NOW() WHERE session_id <> :id'
        );
        $stmt->execute([':id' => $sessionId]);

        /* The current session decides which enrollment is authoritative, so the
           legacy `students.class_id` links have to follow the switch. */
        $students = $pdo->query('SELECT id FROM students ORDER BY id');
        foreach ($students->fetchAll() as $student) {
            syncStudentClassLink($pdo, (int) $student['id']);
        }
    });
}

/**
 * Change a session status.
 *
 * A session that is being closed or deactivated can no longer be the current
 * session, so the flag is cleared in the same statement.
 */
function setSessionStatus(int $sessionId, string $status): void
{
    if ($status === 'Active') {
        dbRun(
            'UPDATE academic_sessions SET status = :status, updated_at = NOW() WHERE id = :id',
            [':status' => $status, ':id' => $sessionId]
        );

        return;
    }

    dbRun(
        'UPDATE academic_sessions
         SET status = :status, is_current = 0, updated_at = NOW()
         WHERE id = :id',
        [':status' => $status, ':id' => $sessionId]
    );

    dbRun(
        'UPDATE terms SET is_current = 0, updated_at = NOW() WHERE session_id = :id',
        [':id' => $sessionId]
    );
}

/* ------------------------------------------------------------------ */
/* Phase 3: terms                                                      */
/* ------------------------------------------------------------------ */

function findTerm(int $id): ?array
{
    return dbOne(
        'SELECT t.*, a.session_name, a.is_current AS session_is_current, a.status AS session_status
         FROM terms t
         JOIN academic_sessions a ON a.id = t.session_id
         WHERE t.id = :id
         LIMIT 1',
        [':id' => $id]
    );
}

/** The current term of the current session, or null. */
function currentTerm(): ?array
{
    return dbOne(
        'SELECT t.*, a.session_name, a.is_current AS session_is_current
         FROM terms t
         JOIN academic_sessions a ON a.id = t.session_id
         WHERE t.is_current = 1 AND a.is_current = 1
         LIMIT 1'
    );
}

function listTerms(array $filters): array
{
    $where  = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $term = '%' . likeTerm($search) . '%';
        $where[] = '(t.term_name LIKE :search1 OR a.session_name LIKE :search2)';
        $params['search1'] = $term;
        $params['search2'] = $term;
    }

    $sessionId = $filters['session_id'] ?? null;
    if (is_int($sessionId) && $sessionId > 0) {
        $where[] = 't.session_id = :session_id';
        $params['session_id'] = $sessionId;
    }

    $termName = (string) ($filters['term_name'] ?? '');
    if (inList($termName, TERM_NAMES)) {
        $where[] = 't.term_name = :term_name';
        $params['term_name'] = $termName;
    }

    $status = (string) ($filters['status'] ?? '');
    if (inList($status, TERM_STATUSES)) {
        $where[] = 't.status = :status';
        $params['status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $total = (int) dbValue(
        "SELECT COUNT(*) FROM terms t
         JOIN academic_sessions a ON a.id = t.session_id
         WHERE {$whereSql}",
        $params
    );

    $params['limit']  = (int) $filters['per_page'];
    $params['offset'] = (int) $filters['offset'];

    $rows = dbAll(
        "SELECT t.*, a.session_name, a.is_current AS session_is_current, a.status AS session_status
         FROM terms t
         JOIN academic_sessions a ON a.id = t.session_id
         WHERE {$whereSql}
         ORDER BY a.start_date DESC,
                  FIELD(t.term_name, 'First Term', 'Second Term', 'Third Term')
         LIMIT :limit OFFSET :offset",
        $params
    );

    return ['total' => $total, 'rows' => $rows];
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function validateTermInput(?int $ignoreId = null): array
{
    $data = [
        'session_id' => nullableField('session_id', 10),
        'term_name'  => field('term_name', 20),
        'start_date' => field('start_date', 10),
        'end_date'   => field('end_date', 10),
        'status'     => field('status', 20),
    ];

    $errors = [];

    if ($data['session_id'] === null || $data['session_id'] === '') {
        $errors['session_id'] = 'Please select an academic session.';
    } elseif (!dbValue('SELECT COUNT(*) FROM academic_sessions WHERE id = :id', [':id' => (int) $data['session_id']])) {
        $errors['session_id'] = 'The selected academic session does not exist.';
    }

    if (!inList($data['term_name'], TERM_NAMES)) {
        $errors['term_name'] = 'Please select a term.';
    } elseif ($data['session_id'] !== null && $data['session_id'] !== '') {
        $sql = 'SELECT COUNT(*) FROM terms WHERE session_id = :session_id AND term_name = :term_name';
        $params = [':session_id' => (int) $data['session_id'], ':term_name' => $data['term_name']];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ignoreId;
        }

        if ((int) dbValue($sql, $params) > 0) {
            $errors['term_name'] = 'This term already exists in the selected academic session.';
        }
    }

    if ($data['start_date'] === '') {
        $errors['start_date'] = 'Start date is required.';
    } elseif (!isValidDate($data['start_date'])) {
        $errors['start_date'] = 'Enter a valid start date.';
    }

    if ($data['end_date'] === '') {
        $errors['end_date'] = 'End date is required.';
    } elseif (!isValidDate($data['end_date'])) {
        $errors['end_date'] = 'Enter a valid end date.';
    } elseif ($data['start_date'] !== '' && $data['end_date'] <= $data['start_date']) {
        $errors['end_date'] = 'The end date must be after the start date.';
    }

    if (!inList($data['status'], TERM_STATUSES)) {
        $errors['status'] = 'Please select a status.';
    }

    return [$data, $errors];
}

function createTerm(array $data): int
{
    dbRun(
        'INSERT INTO terms (session_id, term_name, start_date, end_date, status, is_current)
         VALUES (:session_id, :term_name, :start_date, :end_date, :status, 0)',
        [
            ':session_id' => (int) $data['session_id'],
            ':term_name'  => $data['term_name'],
            ':start_date' => $data['start_date'],
            ':end_date'   => $data['end_date'],
            ':status'     => $data['status'],
        ]
    );

    return (int) getDbConnection()->lastInsertId();
}

function updateTerm(int $id, array $data): void
{
    dbRun(
        'UPDATE terms SET
            session_id = :session_id,
            term_name = :term_name,
            start_date = :start_date,
            end_date = :end_date,
            status = :status
         WHERE id = :id',
        [
            ':id'         => $id,
            ':session_id' => (int) $data['session_id'],
            ':term_name'  => $data['term_name'],
            ':start_date' => $data['start_date'],
            ':end_date'   => $data['end_date'],
            ':status'     => $data['status'],
        ]
    );

    /* A term that no longer belongs to the current session can no longer be
       the current term. */
    dbRun(
        'UPDATE terms SET is_current = 0 WHERE id = :id AND session_id <> (SELECT id FROM academic_sessions WHERE is_current = 1)',
        [':id' => $id]
    );
}

/**
 * Make one term the current term.
 *
 * Only a term that belongs to the current academic session may become current;
 * a term from another session is refused with a clear warning.
 *
 * @return string an error message, or an empty string on success
 */
function setCurrentTerm(int $termId): string
{
    $term = findTerm($termId);

    if ($term === null) {
        return 'The selected term could not be found.';
    }

    $current = currentSession();

    if ($current === null) {
        return 'No current academic session has been set. Set one on the Academic Sessions page first.';
    }

    if ((int) $term['session_id'] !== (int) $current['id']) {
        return 'This term belongs to the academic session '
            . $term['session_name'] . ', but the current academic session is '
            . $current['session_name'] . '. Set the current session first.';
    }

    dbTransaction(function (PDO $pdo) use ($termId) {
        $stmt = $pdo->prepare(
            'UPDATE terms SET is_current = 0, updated_at = NOW()
             WHERE session_id = (SELECT id FROM academic_sessions WHERE is_current = 1)'
        );
        $stmt->execute();

        $stmt = $pdo->prepare(
            'UPDATE terms SET is_current = 1, status = "Active", updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $termId]);
    });

    return '';
}

function setTermStatus(int $termId, string $status): void
{
    if ($status === 'Active') {
        dbRun(
            'UPDATE terms SET status = :status, updated_at = NOW() WHERE id = :id',
            [':status' => $status, ':id' => $termId]
        );

        return;
    }

    dbRun(
        'UPDATE terms SET status = :status, is_current = 0, updated_at = NOW() WHERE id = :id',
        [':status' => $status, ':id' => $termId]
    );
}

/* ------------------------------------------------------------------ */
/* Phase 3: teacher class assignments                                  */
/* ------------------------------------------------------------------ */

function findTeacherClassAssignment(int $id): ?array
{
    return dbOne('SELECT * FROM teacher_class_assignments WHERE id = :id LIMIT 1', [':id' => $id]);
}

function listTeacherClassAssignments(array $filters): array
{
    $where  = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $term = '%' . likeTerm($search) . '%';
        $where[] = '(t.staff_number LIKE :search1
                     OR t.first_name LIKE :search2
                     OR t.last_name LIKE :search3
                     OR c.class_name LIKE :search4
                     OR a.session_name LIKE :search5
                     OR CONCAT_WS(" ", t.first_name, t.middle_name, t.last_name) LIKE :search6)';
        for ($i = 1; $i <= 6; $i++) {
            $params['search' . $i] = $term;
        }
    }

    foreach (['teacher_id' => 't.id', 'class_id' => 'tca.class_id', 'session_id' => 'tca.session_id'] as $key => $column) {
        $value = $filters[$key] ?? null;

        if (is_int($value) && $value > 0) {
            $where[] = $column . ' = :' . $key;
            $params[$key] = $value;
        }
    }

    $status = (string) ($filters['status'] ?? '');
    if (inList($status, ASSIGNMENT_STATUSES)) {
        $where[] = 'tca.status = :status';
        $params['status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $total = (int) dbValue(
        "SELECT COUNT(*) FROM teacher_class_assignments tca
         JOIN teachers t ON t.id = tca.teacher_id
         JOIN classes c ON c.id = tca.class_id
         JOIN academic_sessions a ON a.id = tca.session_id
         WHERE {$whereSql}",
        $params
    );

    $params['limit']  = (int) $filters['per_page'];
    $params['offset'] = (int) $filters['offset'];

    $rows = dbAll(
        "SELECT tca.*,
                CONCAT_WS(' ', t.first_name, t.middle_name, t.last_name) AS teacher_name,
                t.staff_number, c.class_name, c.section, a.session_name
         FROM teacher_class_assignments tca
         JOIN teachers t ON t.id = tca.teacher_id
         JOIN classes c ON c.id = tca.class_id
         JOIN academic_sessions a ON a.id = tca.session_id
         WHERE {$whereSql}
         ORDER BY tca.assigned_date DESC, tca.id DESC
         LIMIT :limit OFFSET :offset",
        $params
    );

    return ['total' => $total, 'rows' => $rows];
}

/**
 * Validate a teacher-to-class assignment.
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function validateTeacherClassAssignment(?int $ignoreId = null): array
{
    $data = [
        'teacher_id'    => nullableField('teacher_id', 10),
        'class_id'      => nullableField('class_id', 10),
        'session_id'    => nullableField('session_id', 10),
        'assigned_date' => field('assigned_date', 10),
        'status'        => field('status', 20),
    ];

    $errors = [];

    if ($data['teacher_id'] === null || $data['teacher_id'] === '') {
        $errors['teacher_id'] = 'Please select a teacher.';
    } else {
        $teacher = dbOne('SELECT id, status FROM teachers WHERE id = :id', [':id' => (int) $data['teacher_id']]);

        if (!$teacher) {
            $errors['teacher_id'] = 'The selected teacher does not exist.';
        } elseif ((string) $teacher['status'] !== 'Active') {
            $errors['teacher_id'] = 'Only an active teacher can be assigned.';
        }
    }

    if ($data['class_id'] === null || $data['class_id'] === '') {
        $errors['class_id'] = 'Please select a class.';
    } else {
        $class = dbOne('SELECT id, status FROM classes WHERE id = :id', [':id' => (int) $data['class_id']]);

        if (!$class) {
            $errors['class_id'] = 'The selected class does not exist.';
        } elseif ((string) $class['status'] !== 'Active') {
            $errors['class_id'] = 'Only an active class can be assigned.';
        }
    }

    if ($data['session_id'] === null || $data['session_id'] === '') {
        $errors['session_id'] = 'Please select an academic session.';
    } elseif (!dbValue('SELECT COUNT(*) FROM academic_sessions WHERE id = :id', [':id' => (int) $data['session_id']])) {
        $errors['session_id'] = 'The selected academic session does not exist.';
    }

    if ($data['assigned_date'] === '') {
        $data['assigned_date'] = date('Y-m-d');
    } elseif (!isValidDate($data['assigned_date'])) {
        $errors['assigned_date'] = 'Enter a valid assignment date.';
    }

    if (!inList($data['status'], ASSIGNMENT_STATUSES)) {
        $errors['status'] = 'Please select a status.';
    }

    /* Only active rows take part in the duplicate check, so a teacher can be
       re-assigned to the same class after a previous assignment was closed. */
    if ($errors === [] && $data['status'] === 'Active') {
        $sql = 'SELECT COUNT(*) FROM teacher_class_assignments
                WHERE teacher_id = :teacher_id AND class_id = :class_id
                  AND session_id = :session_id AND status = "Active"';
        $params = [
            ':teacher_id' => (int) $data['teacher_id'],
            ':class_id'   => (int) $data['class_id'],
            ':session_id' => (int) $data['session_id'],
        ];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ignoreId;
        }

        if ((int) dbValue($sql, $params) > 0) {
            $errors['class_id'] = 'This teacher is already assigned to this class in this academic session.';
        }
    }

    return [$data, $errors];
}

function createTeacherClassAssignment(array $data): int
{
    dbRun(
        'INSERT INTO teacher_class_assignments (teacher_id, class_id, session_id, assigned_date, status)
         VALUES (:teacher_id, :class_id, :session_id, :assigned_date, :status)',
        [
            ':teacher_id'    => (int) $data['teacher_id'],
            ':class_id'      => (int) $data['class_id'],
            ':session_id'    => (int) $data['session_id'],
            ':assigned_date' => $data['assigned_date'],
            ':status'        => $data['status'],
        ]
    );

    return (int) getDbConnection()->lastInsertId();
}

function setTeacherClassAssignmentStatus(int $id, string $status): void
{
    dbRun(
        'UPDATE teacher_class_assignments SET status = :status, updated_at = NOW() WHERE id = :id',
        [':status' => $status, ':id' => $id]
    );
}

/* ------------------------------------------------------------------ */
/* Phase 3: teacher subject assignments                                */
/* ------------------------------------------------------------------ */

function findTeacherSubjectAssignment(int $id): ?array
{
    return dbOne('SELECT * FROM teacher_subject_assignments WHERE id = :id LIMIT 1', [':id' => $id]);
}

function listTeacherSubjectAssignments(array $filters): array
{
    $where  = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $term = '%' . likeTerm($search) . '%';
        $where[] = '(t.staff_number LIKE :search1
                     OR t.first_name LIKE :search2
                     OR t.last_name LIKE :search3
                     OR sj.subject_code LIKE :search4
                     OR sj.subject_name LIKE :search5
                     OR c.class_name LIKE :search6
                     OR a.session_name LIKE :search7
                     OR CONCAT_WS(" ", t.first_name, t.middle_name, t.last_name) LIKE :search8)';
        for ($i = 1; $i <= 8; $i++) {
            $params['search' . $i] = $term;
        }
    }

    foreach ([
        'teacher_id' => 'tsa.teacher_id',
        'subject_id' => 'tsa.subject_id',
        'class_id'   => 'tsa.class_id',
        'session_id' => 'tsa.session_id',
    ] as $key => $column) {
        $value = $filters[$key] ?? null;

        if (is_int($value) && $value > 0) {
            $where[] = $column . ' = :' . $key;
            $params[$key] = $value;
        }
    }

    $status = (string) ($filters['status'] ?? '');
    if (inList($status, ASSIGNMENT_STATUSES)) {
        $where[] = 'tsa.status = :status';
        $params['status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $total = (int) dbValue(
        "SELECT COUNT(*) FROM teacher_subject_assignments tsa
         JOIN teachers t ON t.id = tsa.teacher_id
         JOIN subjects sj ON sj.id = tsa.subject_id
         JOIN classes c ON c.id = tsa.class_id
         JOIN academic_sessions a ON a.id = tsa.session_id
         WHERE {$whereSql}",
        $params
    );

    $params['limit']  = (int) $filters['per_page'];
    $params['offset'] = (int) $filters['offset'];

    $rows = dbAll(
        "SELECT tsa.*,
                CONCAT_WS(' ', t.first_name, t.middle_name, t.last_name) AS teacher_name,
                t.staff_number, sj.subject_code, sj.subject_name,
                c.class_name, c.section, a.session_name
         FROM teacher_subject_assignments tsa
         JOIN teachers t ON t.id = tsa.teacher_id
         JOIN subjects sj ON sj.id = tsa.subject_id
         JOIN classes c ON c.id = tsa.class_id
         JOIN academic_sessions a ON a.id = tsa.session_id
         WHERE {$whereSql}
         ORDER BY tsa.id DESC
         LIMIT :limit OFFSET :offset",
        $params
    );

    return ['total' => $total, 'rows' => $rows];
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function validateTeacherSubjectAssignment(?int $ignoreId = null): array
{
    $data = [
        'teacher_id' => nullableField('teacher_id', 10),
        'subject_id' => nullableField('subject_id', 10),
        'class_id'   => nullableField('class_id', 10),
        'session_id' => nullableField('session_id', 10),
        'status'     => field('status', 20),
    ];

    $errors = [];

    if ($data['teacher_id'] === null || $data['teacher_id'] === '') {
        $errors['teacher_id'] = 'Please select a teacher.';
    } else {
        $row = dbOne('SELECT id, status FROM teachers WHERE id = :id', [':id' => (int) $data['teacher_id']]);

        if (!$row) {
            $errors['teacher_id'] = 'The selected teacher does not exist.';
        } elseif ((string) $row['status'] !== 'Active') {
            $errors['teacher_id'] = 'Only an active teacher can be assigned.';
        }
    }

    if ($data['subject_id'] === null || $data['subject_id'] === '') {
        $errors['subject_id'] = 'Please select a subject.';
    } else {
        $row = dbOne('SELECT id, status FROM subjects WHERE id = :id', [':id' => (int) $data['subject_id']]);

        if (!$row) {
            $errors['subject_id'] = 'The selected subject does not exist.';
        } elseif ((string) $row['status'] !== 'Active') {
            $errors['subject_id'] = 'Only an active subject can be assigned.';
        }
    }

    if ($data['class_id'] === null || $data['class_id'] === '') {
        $errors['class_id'] = 'Please select a class.';
    } else {
        $row = dbOne('SELECT id, status FROM classes WHERE id = :id', [':id' => (int) $data['class_id']]);

        if (!$row) {
            $errors['class_id'] = 'The selected class does not exist.';
        } elseif ((string) $row['status'] !== 'Active') {
            $errors['class_id'] = 'Only an active class can be assigned.';
        }
    }

    if ($data['session_id'] === null || $data['session_id'] === '') {
        $errors['session_id'] = 'Please select an academic session.';
    } elseif (!dbValue('SELECT COUNT(*) FROM academic_sessions WHERE id = :id', [':id' => (int) $data['session_id']])) {
        $errors['session_id'] = 'The selected academic session does not exist.';
    }

    if (!inList($data['status'], ASSIGNMENT_STATUSES)) {
        $errors['status'] = 'Please select a status.';
    }

    if ($errors === [] && $data['status'] === 'Active') {
        $sql = 'SELECT COUNT(*) FROM teacher_subject_assignments
                WHERE teacher_id = :teacher_id AND subject_id = :subject_id
                  AND class_id = :class_id AND session_id = :session_id AND status = "Active"';
        $params = [
            ':teacher_id' => (int) $data['teacher_id'],
            ':subject_id' => (int) $data['subject_id'],
            ':class_id'   => (int) $data['class_id'],
            ':session_id' => (int) $data['session_id'],
        ];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ignoreId;
        }

        if ((int) dbValue($sql, $params) > 0) {
            $errors['subject_id'] = 'This teacher is already assigned this subject for this class in this academic session.';
        }
    }

    return [$data, $errors];
}

function createTeacherSubjectAssignment(array $data): int
{
    dbRun(
        'INSERT INTO teacher_subject_assignments (teacher_id, subject_id, class_id, session_id, status)
         VALUES (:teacher_id, :subject_id, :class_id, :session_id, :status)',
        [
            ':teacher_id' => (int) $data['teacher_id'],
            ':subject_id' => (int) $data['subject_id'],
            ':class_id'   => (int) $data['class_id'],
            ':session_id' => (int) $data['session_id'],
            ':status'     => $data['status'],
        ]
    );

    return (int) getDbConnection()->lastInsertId();
}

function setTeacherSubjectAssignmentStatus(int $id, string $status): void
{
    dbRun(
        'UPDATE teacher_subject_assignments SET status = :status, updated_at = NOW() WHERE id = :id',
        [':status' => $status, ':id' => $id]
    );
}

/* ------------------------------------------------------------------ */
/* Phase 3: student class enrollments                                  */
/* ------------------------------------------------------------------ */

function findEnrollment(int $id): ?array
{
    return dbOne(
        'SELECT e.*,
                CONCAT_WS(" ", s.first_name, s.middle_name, s.last_name) AS student_name,
                s.admission_number, c.class_name, c.section, a.session_name
         FROM student_class_enrollments e
         JOIN students s ON s.id = e.student_id
         JOIN classes c ON c.id = e.class_id
         JOIN academic_sessions a ON a.id = e.session_id
         WHERE e.id = :id
         LIMIT 1',
        [':id' => $id]
    );
}

function listEnrollments(array $filters): array
{
    $where  = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $term = '%' . likeTerm($search) . '%';
        $where[] = '(s.admission_number LIKE :search1
                     OR s.first_name LIKE :search2
                     OR s.middle_name LIKE :search3
                     OR s.last_name LIKE :search4
                     OR c.class_name LIKE :search5
                     OR a.session_name LIKE :search6
                     OR CONCAT_WS(" ", s.first_name, s.middle_name, s.last_name) LIKE :search7)';
        for ($i = 1; $i <= 7; $i++) {
            $params['search' . $i] = $term;
        }
    }

    foreach (['class_id' => 'e.class_id', 'session_id' => 'e.session_id'] as $key => $column) {
        $value = $filters[$key] ?? null;

        if (is_int($value) && $value > 0) {
            $where[] = $column . ' = :' . $key;
            $params[$key] = $value;
        }
    }

    $studentId = $filters['student_id'] ?? null;
    if (is_int($studentId) && $studentId > 0) {
        $where[] = 'e.student_id = :student_id';
        $params['student_id'] = $studentId;
    }

    $status = (string) ($filters['status'] ?? '');
    if (inList($status, ASSIGNMENT_STATUSES)) {
        $where[] = 'e.status = :status';
        $params['status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $total = (int) dbValue(
        "SELECT COUNT(*) FROM student_class_enrollments e
         JOIN students s ON s.id = e.student_id
         JOIN classes c ON c.id = e.class_id
         JOIN academic_sessions a ON a.id = e.session_id
         WHERE {$whereSql}",
        $params
    );

    $params['limit']  = (int) $filters['per_page'];
    $params['offset'] = (int) $filters['offset'];

    $rows = dbAll(
        "SELECT e.*,
                CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) AS student_name,
                s.admission_number, s.gender, c.class_name, c.section, a.session_name
         FROM student_class_enrollments e
         JOIN students s ON s.id = e.student_id
         JOIN classes c ON c.id = e.class_id
         JOIN academic_sessions a ON a.id = e.session_id
         WHERE {$whereSql}
         ORDER BY e.enrollment_date DESC, e.id DESC
         LIMIT :limit OFFSET :offset",
        $params
    );

    return ['total' => $total, 'rows' => $rows];
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function validateEnrollmentInput(?int $ignoreId = null): array
{
    $data = [
        'student_id'      => nullableField('student_id', 10),
        'session_id'      => nullableField('session_id', 10),
        'class_id'        => nullableField('class_id', 10),
        'enrollment_date' => field('enrollment_date', 10),
        'status'          => field('status', 20),
    ];

    $errors = [];

    if ($data['student_id'] === null || $data['student_id'] === '') {
        $errors['student_id'] = 'Please select a student.';
    } else {
        $row = dbOne('SELECT id, status FROM students WHERE id = :id', [':id' => (int) $data['student_id']]);

        if (!$row) {
            $errors['student_id'] = 'The selected student does not exist.';
        } elseif ((string) $row['status'] !== 'Active') {
            $errors['student_id'] = 'Only an active student can be enrolled.';
        }
    }

    if ($data['session_id'] === null || $data['session_id'] === '') {
        $errors['session_id'] = 'Please select an academic session.';
    } elseif (!dbValue('SELECT COUNT(*) FROM academic_sessions WHERE id = :id', [':id' => (int) $data['session_id']])) {
        $errors['session_id'] = 'The selected academic session does not exist.';
    }

    if ($data['class_id'] === null || $data['class_id'] === '') {
        $errors['class_id'] = 'Please select a class.';
    } elseif (!dbValue('SELECT COUNT(*) FROM classes WHERE id = :id', [':id' => (int) $data['class_id']])) {
        $errors['class_id'] = 'The selected class does not exist.';
    }

    if ($data['enrollment_date'] === '') {
        $data['enrollment_date'] = date('Y-m-d');
    } elseif (!isValidDate($data['enrollment_date'])) {
        $errors['enrollment_date'] = 'Enter a valid enrollment date.';
    }

    if (!inList($data['status'], ASSIGNMENT_STATUSES)) {
        $errors['status'] = 'Please select a status.';
    }

    if ($errors === [] && $data['status'] === 'Active') {
        $sql = 'SELECT COUNT(*) FROM student_class_enrollments
                WHERE student_id = :student_id AND session_id = :session_id AND status = "Active"';
        $params = [
            ':student_id' => (int) $data['student_id'],
            ':session_id' => (int) $data['session_id'],
        ];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $ignoreId;
        }

        if ((int) dbValue($sql, $params) > 0) {
            $errors['class_id'] = 'This student already has an active enrollment in the selected academic session.';
        }
    }

    return [$data, $errors];
}

/**
 * Point `students.class_id` at the class the student holds in the current
 * academic session.
 *
 * `student_class_enrollments` is the authoritative, session-scoped record. The
 * legacy `students.class_id` column is kept in step so the Phase 2 student list
 * and the Phase 3 session roster never disagree. When the student has no active
 * enrollment in the current session the link is cleared rather than left stale.
 */
function syncStudentClassLink(PDO $pdo, int $studentId): void
{
    $classId = $pdo->prepare(
        'SELECT e.class_id
         FROM student_class_enrollments e
         JOIN academic_sessions a ON a.id = e.session_id
         WHERE e.student_id = :student_id AND e.status = "Active" AND a.is_current = 1
         ORDER BY e.enrollment_date DESC, e.id DESC
         LIMIT 1'
    );
    $classId->execute([':student_id' => $studentId]);
    $value = $classId->fetchColumn();

    $update = $pdo->prepare(
        'UPDATE students SET class_id = :class_id, updated_at = NOW() WHERE id = :id'
    );
    $update->execute([':class_id' => $value === false ? null : (int) $value, ':id' => $studentId]);
}

function createEnrollment(array $data): int
{
    $enrollmentId = dbTransaction(function (PDO $pdo) use ($data) {
        $insert = $pdo->prepare(
            'INSERT INTO student_class_enrollments (student_id, class_id, session_id, enrollment_date, status)
             VALUES (:student_id, :class_id, :session_id, :enrollment_date, :status)'
        );
        $insert->execute([
            ':student_id'      => (int) $data['student_id'],
            ':class_id'        => (int) $data['class_id'],
            ':session_id'      => (int) $data['session_id'],
            ':enrollment_date' => $data['enrollment_date'],
            ':status'          => $data['status'],
        ]);

        syncStudentClassLink($pdo, (int) $data['student_id']);

        return (int) $pdo->lastInsertId();
    });

    return $enrollmentId;
}

function setEnrollmentStatus(int $id, string $status): void
{
    dbTransaction(function (PDO $pdo) use ($id, $status) {
        $update = $pdo->prepare(
            'UPDATE student_class_enrollments SET status = :status, updated_at = NOW() WHERE id = :id'
        );
        $update->execute([':status' => $status, ':id' => $id]);

        $find = $pdo->prepare('SELECT student_id FROM student_class_enrollments WHERE id = :id');
        $find->execute([':id' => $id]);
        $studentId = $find->fetchColumn();

        if ($studentId !== false) {
            syncStudentClassLink($pdo, (int) $studentId);
        }
    });
}

/** Active students ordered by name, for select inputs. */
function studentOptions(): array
{
    return dbAll(
        'SELECT id, admission_number, gender,
                CONCAT_WS(" ", first_name, middle_name, last_name) AS student_name
         FROM students
         WHERE status = "Active"
         ORDER BY last_name, first_name'
    );
}

/** Active students not yet enrolled in the given session. */
function studentsNotEnrolledInSession(int $sessionId): array
{
    return dbAll(
        'SELECT s.id, s.admission_number, s.gender,
                CONCAT_WS(" ", s.first_name, s.middle_name, s.last_name) AS student_name
         FROM students s
         WHERE s.status = "Active"
           AND NOT EXISTS (
                SELECT 1 FROM student_class_enrollments e
                WHERE e.student_id = s.id AND e.session_id = :session_id AND e.status = "Active"
           )
         ORDER BY s.last_name, s.first_name',
        [':session_id' => $sessionId]
    );
}

/**
 * Enroll many students into one class in a single transaction.
 *
 * Students who already hold an active enrollment in the session are skipped
 * rather than rejected, so a partially valid selection still makes progress.
 *
 * @return array{inserted: int, skipped: int}
 */
function bulkEnrollStudents(array $studentIds, int $classId, int $sessionId, string $date): array
{
    return dbTransaction(function (PDO $pdo) use ($studentIds, $classId, $sessionId, $date) {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM student_class_enrollments
             WHERE student_id = :student_id AND session_id = :session_id AND status = "Active"'
        );

        $insert = $pdo->prepare(
            'INSERT INTO student_class_enrollments (student_id, class_id, session_id, enrollment_date, status)
             VALUES (:student_id, :class_id, :session_id, :enrollment_date, "Active")'
        );

        $inserted = 0;
        $skipped  = 0;
        $touched  = [];

        foreach ($studentIds as $studentId) {
            $check->execute([':student_id' => $studentId, ':session_id' => $sessionId]);

            if ((int) $check->fetchColumn() > 0) {
                $skipped++;
                continue;
            }

            $insert->execute([
                ':student_id'      => $studentId,
                ':class_id'        => $classId,
                ':session_id'      => $sessionId,
                ':enrollment_date' => $date,
            ]);
            $inserted++;
            $touched[(int) $studentId] = true;
        }

        foreach (array_keys($touched) as $studentId) {
            syncStudentClassLink($pdo, $studentId);
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    });
}

/**
 * Promote students into a new class for a new academic session.
 *
 * A brand new enrollment row is created; the previous enrollment is kept so the
 * history is never overwritten.
 *
 * @return array{inserted: int, skipped: int}
 */
function promoteStudents(array $enrollmentIds, int $newClassId, int $newSessionId, string $date): array
{
    return dbTransaction(function (PDO $pdo) use ($enrollmentIds, $newClassId, $newSessionId, $date) {
        $selectOld = $pdo->prepare(
            'SELECT student_id FROM student_class_enrollments WHERE id = :id LIMIT 1'
        );

        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM student_class_enrollments
             WHERE student_id = :student_id AND session_id = :session_id AND status = "Active"'
        );

        $insert = $pdo->prepare(
            'INSERT INTO student_class_enrollments (student_id, class_id, session_id, enrollment_date, status)
             VALUES (:student_id, :class_id, :session_id, :enrollment_date, "Active")'
        );

        $inserted = 0;
        $skipped  = 0;
        $touched  = [];

        foreach ($enrollmentIds as $enrollmentId) {
            $selectOld->execute([':id' => $enrollmentId]);
            $studentId = $selectOld->fetchColumn();

            if ($studentId === false) {
                $skipped++;
                continue;
            }

            $check->execute([':student_id' => (int) $studentId, ':session_id' => $newSessionId]);

            if ((int) $check->fetchColumn() > 0) {
                $skipped++;
                continue;
            }

            $insert->execute([
                ':student_id'      => (int) $studentId,
                ':class_id'        => $newClassId,
                ':session_id'      => $newSessionId,
                ':enrollment_date' => $date,
            ]);
            $inserted++;
            $touched[(int) $studentId] = true;
        }

        foreach (array_keys($touched) as $studentId) {
            syncStudentClassLink($pdo, $studentId);
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    });
}

/** Students enrolled in one class for one academic session. */
function listClassEnrollments(int $classId, int $sessionId): array
{
    return dbAll(
        'SELECT e.id AS enrollment_id, e.enrollment_date, e.status,
                s.id AS student_id, s.admission_number, s.gender,
                CONCAT_WS(" ", s.first_name, s.middle_name, s.last_name) AS student_name
         FROM student_class_enrollments e
         JOIN students s ON s.id = e.student_id
         WHERE e.class_id = :class_id AND e.session_id = :session_id
         ORDER BY s.last_name, s.first_name',
        [':class_id' => $classId, ':session_id' => $sessionId]
    );
}

/** Active enrollment count per class for one session, keyed by class id. */
function enrollmentCountsByClass(int $sessionId): array
{
    $rows = dbAll(
        'SELECT class_id, COUNT(*) AS total
         FROM student_class_enrollments
         WHERE session_id = :session_id AND status = "Active"
         GROUP BY class_id',
        [':session_id' => $sessionId]
    );

    $counts = [];
    foreach ($rows as $row) {
        $counts[(int) $row['class_id']] = (int) $row['total'];
    }

    return $counts;
}

/* ------------------------------------------------------------------ */
/* Phase 3: teacher view of their own assignments                       */
/* ------------------------------------------------------------------ */

/** The teachers row linked to the signed-in user, or null. */
function currentTeacherProfile(): ?array
{
    $userId = currentUserId();

    if ($userId === null) {
        return null;
    }

    return dbOne('SELECT * FROM teachers WHERE user_id = :id LIMIT 1', [':id' => $userId]);
}

/** Classes the signed-in teacher is assigned to. */
function teacherClassAssignments(int $teacherId): array
{
    return dbAll(
        'SELECT tca.*, c.class_name, c.section, a.session_name, a.is_current AS session_is_current
         FROM teacher_class_assignments tca
         JOIN classes c ON c.id = tca.class_id
         JOIN academic_sessions a ON a.id = tca.session_id
         WHERE tca.teacher_id = :teacher_id
         ORDER BY tca.assigned_date DESC, tca.id DESC',
        [':teacher_id' => $teacherId]
    );
}

/** Subjects the signed-in teacher is assigned to. */
function teacherSubjectAssignments(int $teacherId): array
{
    return dbAll(
        'SELECT tsa.*, sj.subject_code, sj.subject_name,
                c.class_name, c.section, a.session_name, a.is_current AS session_is_current
         FROM teacher_subject_assignments tsa
         JOIN subjects sj ON sj.id = tsa.subject_id
         JOIN classes c ON c.id = tsa.class_id
         JOIN academic_sessions a ON a.id = tsa.session_id
         WHERE tsa.teacher_id = :teacher_id
         ORDER BY tsa.id DESC',
        [':teacher_id' => $teacherId]
    );
}

/* ------------------------------------------------------------------ */
/* Dashboard statistics                                                */
/* ------------------------------------------------------------------ */

function dashboardStatistics(): array
{
    $counts = [
        'students'  => 0,
        'teachers'  => 0,
        'classes'   => 0,
        'subjects'  => 0,
        'sessions'  => 0,
        'terms'     => 0,
    ];

    $rows = dbAll(
        "SELECT
            (SELECT COUNT(*) FROM students  WHERE status = 'Active')  AS students,
            (SELECT COUNT(*) FROM teachers  WHERE status = 'Active')  AS teachers,
            (SELECT COUNT(*) FROM classes   WHERE status = 'Active')  AS classes,
            (SELECT COUNT(*) FROM subjects  WHERE status = 'Active')  AS subjects,
            (SELECT COUNT(*) FROM academic_sessions WHERE status = 'Active') AS sessions,
            (SELECT COUNT(*) FROM terms      WHERE status = 'Active')  AS terms"
    );

    foreach ($rows as $row) {
        foreach ($counts as $key => $_) {
            $counts[$key] = (int) $row[$key];
        }
    }

    $bySection = ['Nursery' => 0, 'Primary' => 0, 'Secondary' => 0];

    $sectionRows = dbAll(
        "SELECT section, COUNT(*) AS total
         FROM students
         WHERE status = 'Active'
         GROUP BY section"
    );

    foreach ($sectionRows as $row) {
        $name = (string) $row['section'];

        if (isset($bySection[$name])) {
            $bySection[$name] = (int) $row['total'];
        }
    }

    $session  = currentSession();
    $enrolled = 0;

    if ($session !== null) {
        $enrolled = (int) dbValue(
            "SELECT COUNT(*) FROM student_class_enrollments
             WHERE session_id = :id AND status = 'Active'",
            [':id' => (int) $session['id']]
        );
    }

    $counts['by_section']     = $bySection;
    $counts['enrolled']       = $enrolled;
    $counts['current_session'] = $session;

    return $counts;
}

/* ====================================================================== */
/* PHASE 4: RESULT CALCULATION, GRADING, WORKFLOW AND REPORTING            */
/* ====================================================================== */

/**
 * The four assessment components, in the order they are shown on screen.
 *
 * This is the single list that drives the entry form, the database write, the
 * total and the grade. Adding a fifth component later means adding one value
 * here and one column to `results`, not editing five different files.
 */
function resultAssessmentCodes(): array
{
    return ['test_1', 'test_2', 'test_3', 'examination'];
}

/** Human labels used when a section has no assessment settings row. */
function resultAssessmentDefaultLabels(): array
{
    return [
        'Nursery'    => ['test_1' => 'Test 1', 'test_2' => 'Test 2', 'test_3' => 'Test 3', 'examination' => 'Examination'],
        'Primary'    => ['test_1' => 'Test 1', 'test_2' => 'Test 2', 'test_3' => 'Test 3', 'examination' => 'Examination'],
        'Secondary'  => ['test_1' => 'CA 1', 'test_2' => 'CA 2', 'test_3' => 'CA 3', 'examination' => 'Examination'],
    ];
}

/** The states a result can be in. */
function resultStatuses(): array
{
    return ['draft', 'submitted', 'approved', 'locked'];
}

/** The states a teacher is still allowed to change. */
function editableResultStatuses(): array
{
    return ['draft'];
}

/** The states that count towards a student's final result and position. */
function countedResultStatuses(): array
{
    return ['approved', 'locked'];
}

/**
 * The grade bands.
 *
 * The bands are stored as PERCENTAGES of the assessment total rather than as
 * raw points. With the default structure the total is 100, so 70 means exactly
 * a grade A and the required behaviour is reproduced. If an administrator later
 * raises the examination to 100 and the total to 130, a score of 91 (70% of
 * 130) is still an A, which is what a school would expect.
 */
function resultGradeBands(): array
{
    return [
        ['min' => 70.0, 'grade' => 'A', 'remark' => 'Excellent'],
        ['min' => 60.0, 'grade' => 'B', 'remark' => 'Very Good'],
        ['min' => 50.0, 'grade' => 'C', 'remark' => 'Good'],
        ['min' => 45.0, 'grade' => 'D', 'remark' => 'Fair'],
        ['min' => 40.0, 'grade' => 'E', 'remark' => 'Pass'],
        ['min' => 0.0,  'grade' => 'F', 'remark' => 'Fail'],
    ];
}

/** The lowest percentage that still counts as a pass. */
function resultPassPercentage(): float
{
    return 40.0;
}

/**
 * Turn a score into a grade.
 *
 * This is the server side twin of the JavaScript calculation in
 * teacher/enter-result.php. The browser copy exists only so the teacher sees the
 * total and grade update while typing; every number that is ever stored is
 * produced by this function.
 *
 * @return array{percentage: ?float, grade: ?string, remark: ?string, is_pass: ?bool}
 */
function calculateGrade(?float $score, float $maxTotal = 100.0): array
{
    $unknown = ['percentage' => null, 'grade' => null, 'remark' => null, 'is_pass' => null];

    if ($score === null) {
        return $unknown;
    }

    $maxTotal = $maxTotal > 0 ? $maxTotal : 100.0;
    $percent  = round(($score / $maxTotal) * 100, 2);

    foreach (resultGradeBands() as $band) {
        if ($percent >= $band['min']) {
            return [
                'percentage' => $percent,
                'grade'      => $band['grade'],
                'remark'     => $band['remark'],
                'is_pass'    => $percent >= resultPassPercentage(),
            ];
        }
    }

    return $unknown;
}

/**
 * The assessment structure for a school section.
 *
 * The maximum score of each component is read from `assessment_settings`, so an
 * administrator can change the structure without any code change. The small
 * hard-coded array is an emergency fallback used only if a section has no Active
 * settings row at all, which would otherwise make the total zero and break every
 * grade on the portal.
 *
 * @return array{components: array<string, array{label: string, max: float}>, max_total: float}
 */
function assessmentStructure(string $section): array
{
    $codes  = resultAssessmentCodes();
    $labels = resultAssessmentDefaultLabels();

    $rows = dbAll(
        "SELECT assessment_code, assessment_name, maximum_score
           FROM assessment_settings
          WHERE section = :section AND status = 'Active'
          ORDER BY display_order ASC, id ASC",
        [':section' => $section]
    );

    $components = [];

    foreach ($rows as $row) {
        $code = (string) $row['assessment_code'];

        if (!in_array($code, $codes, true)) {
            continue;
        }

        $components[$code] = [
            'label' => (string) $row['assessment_name'],
            'max'   => (float) $row['maximum_score'],
        ];
    }

    if (count($components) !== count($codes)) {
        foreach ($codes as $code) {
            if (isset($components[$code])) {
                continue;
            }

            $components[$code] = [
                'label' => $labels[$section][$code] ?? ucwords(str_replace('_', ' ', $code)),
                'max'   => 0.0,
            ];
        }
    }

    $maxTotal = 0.0;
    foreach ($components as $component) {
        $maxTotal += $component['max'];
    }

    // A total of zero would make every grade undefined, so fall back to 100.
    $maxTotal = $maxTotal > 0 ? $maxTotal : 100.0;

    return ['components' => $components, 'max_total' => round($maxTotal, 2)];
}

/** The section a class belongs to, used to pick the assessment structure. */
function resultClassSection(int $classId): ?string
{
    $section = dbValue('SELECT section FROM classes WHERE id = :id LIMIT 1', [':id' => $classId]);

    return $section === null ? null : (string) $section;
}

/**
 * Check the four component scores a teacher typed.
 *
 * An empty box means "not entered yet" and becomes NULL, which is different from
 * a zero: a student who did not sit a test has no score for it, and the
 * submission check depends on telling those two cases apart.
 *
 * @return array{values: array, errors: array<string, string>}
 */
function validateResultScores(array $posted, array $structure): array
{
    $values = [];
    $errors = [];

    foreach (resultAssessmentCodes() as $code) {
        $raw = $posted[$code] ?? '';

        if (is_array($raw)) {
            $raw = '';
        }

        $raw = trim((string) $raw);

        if ($raw === '') {
            $values[$code] = null;
            continue;
        }

        if (!is_numeric($raw)) {
            $errors[$code] = 'Enter a number, or leave the box empty.';
            continue;
        }

        $value = round((float) $raw, 2);

        if ($value < 0) {
            $errors[$code] = 'Score cannot be negative.';
            continue;
        }

        $max = (float) ($structure['components'][$code]['max'] ?? 0.0);

        if ($max > 0 && $value > $max) {
            $errors[$code] = 'The highest score allowed is ' . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . '.';
            continue;
        }

        $values[$code] = $value;
    }

    return ['values' => $values, 'errors' => $errors];
}

/**
 * Build the total, grade and remark for a set of component scores.
 *
 * The grade is only produced once all four components are present, because a
 * grade calculated from a part filled sheet would be misleading. The total is
 * still stored while a sheet is being filled in, so the teacher can see progress.
 */
function buildResultCalculation(array $values, float $maxTotal): array
{
    $codes    = resultAssessmentCodes();
    $total    = 0.0;
    $entered  = 0;
    $complete = true;

    foreach ($codes as $code) {
        $value = $values[$code] ?? null;

        if ($value === null) {
            $complete = false;
            continue;
        }

        $total += (float) $value;
        $entered++;
    }

    $grade = $complete ? calculateGrade($total, $maxTotal) : calculateGrade(null, $maxTotal);

    return [
        'total'        => round($total, 2),
        'grade'        => $grade['grade'],
        'grade_remark' => $grade['remark'],
        'percentage'   => $grade['percentage'],
        'is_pass'      => $grade['is_pass'],
        'complete'     => $complete,
        'entered'      => $entered,
        'required'     => count($codes),
    ];
}

/**
 * The terms that belong to one academic session, for a select box.
 *
 * A result is always "one subject in one term of one session", so the term list
 * has to follow the session. Reading the two independently is how a result ends
 * up attached to a term from the wrong year.
 */
function sessionTermOptions(int $sessionId): array
{
    if ($sessionId <= 0) {
        return [];
    }

    return dbAll(
        'SELECT id, term_name, status, is_current
           FROM terms
          WHERE session_id = :session_id
          ORDER BY term_name ASC, id ASC',
        [':session_id' => $sessionId]
    );
}

/** Bootstrap badge class for a result workflow state. */
function resultStatusBadge(string $status): string
{
    return [
        'draft'     => 'bg-secondary',
        'submitted' => 'bg-warning text-dark',
        'approved'  => 'bg-info text-dark',
        'locked'    => 'bg-success',
        'returned'  => 'bg-danger',
    ][$status] ?? 'bg-secondary';
}

/** A friendlier label for the lowercase workflow state used in the database. */
function resultStatusLabel(string $status): string
{
    return ucfirst($status);
}

/* ------------------------------------------------------------------ */
/* Authorisation: a teacher may only touch what they are assigned to    */
/* ------------------------------------------------------------------ */

/**
 * Every class and subject the teacher may enter results for.
 *
 * The list is always built from the database, so a teacher whose assignment has
 * been set to Inactive immediately loses access on the next page load.
 */
function teacherResultScope(int $teacherId, ?int $sessionId = null): array
{
    $sql = "SELECT tsa.id AS assignment_id, tsa.class_id, tsa.subject_id, tsa.session_id,
                   c.class_name, c.section, a.session_name, a.is_current AS session_is_current,
                   sj.subject_code, sj.subject_name
              FROM teacher_subject_assignments tsa
              JOIN classes c ON c.id = tsa.class_id
              JOIN subjects sj ON sj.id = tsa.subject_id
              JOIN academic_sessions a ON a.id = tsa.session_id
             WHERE tsa.teacher_id = :teacher_id
               AND tsa.status = 'Active'
               AND c.status = 'Active'
               AND sj.status = 'Active'";

    $params = [':teacher_id' => $teacherId];

    if ($sessionId !== null) {
        $sql .= ' AND tsa.session_id = :session_id';
        $params[':session_id'] = $sessionId;
    }

    $sql .= ' ORDER BY a.is_current DESC, a.session_name DESC, c.class_name ASC, sj.subject_name ASC';

    return dbAll($sql, $params);
}

/**
 * The gate every teacher result request passes through.
 *
 * The class, subject and session that arrive in the query string are user input,
 * so they are never trusted on their own. Only an Active teacher_subject_assignments
 * row lets the teacher through, and it must match all three values at once.
 */
function teacherCanManageResult(int $teacherId, int $classId, int $subjectId, int $sessionId): bool
{
    if ($teacherId <= 0 || $classId <= 0 || $subjectId <= 0 || $sessionId <= 0) {
        return false;
    }

    $found = dbValue(
        "SELECT COUNT(*)
           FROM teacher_subject_assignments tsa
           JOIN classes c ON c.id = tsa.class_id
           JOIN subjects sj ON sj.id = tsa.subject_id
          WHERE tsa.teacher_id = :teacher_id
            AND tsa.class_id = :class_id
            AND tsa.subject_id = :subject_id
            AND tsa.session_id = :session_id
            AND tsa.status = 'Active'
            AND c.status = 'Active'
            AND sj.status = 'Active'",
        [
            ':teacher_id' => $teacherId,
            ':class_id'   => $classId,
            ':subject_id' => $subjectId,
            ':session_id' => $sessionId,
        ]
    );

    return (int) $found > 0;
}

/**
 * The students a result sheet is built from.
 *
 * The roster comes from student_class_enrollments for the chosen session rather
 * than from students.class_id, because a student's class can differ between
 * sessions and the class_id column is only a convenience link.
 */
function classResultRoster(int $classId, int $sessionId): array
{
    return dbAll(
        "SELECT s.id AS student_id, s.admission_number, s.first_name, s.middle_name, s.last_name
           FROM student_class_enrollments e
           JOIN students s ON s.id = e.student_id
          WHERE e.class_id = :class_id
            AND e.session_id = :session_id
            AND e.status = 'Active'
            AND s.status = 'Active'
          ORDER BY s.admission_number ASC, s.last_name ASC, s.first_name ASC",
        [':class_id' => $classId, ':session_id' => $sessionId]
    );
}

/* ------------------------------------------------------------------ */
/* Reading results                                                      */
/* ------------------------------------------------------------------ */

/** One result with every label needed to display it. */
function findResult(int $id): ?array
{
    return dbOne(
        'SELECT r.*, s.admission_number, s.first_name, s.middle_name, s.last_name,
                c.class_name, c.section, sj.subject_code, sj.subject_name,
                a.session_name, t.term_name,
                te.first_name AS teacher_first_name, te.middle_name AS teacher_middle_name,
                te.last_name AS teacher_last_name
           FROM results r
           JOIN students s ON s.id = r.student_id
           JOIN classes c ON c.id = r.class_id
           JOIN subjects sj ON sj.id = r.subject_id
           JOIN academic_sessions a ON a.id = r.session_id
           JOIN terms t ON t.id = r.term_id
           JOIN teachers te ON te.id = r.teacher_id
          WHERE r.id = :id
          LIMIT 1',
        [':id' => $id]
    );
}

/** The single result row for a student, subject, session and term. */
function findResultFor(int $studentId, int $subjectId, int $sessionId, int $termId): ?array
{
    return dbOne(
        'SELECT * FROM results
          WHERE student_id = :student_id
            AND subject_id = :subject_id
            AND session_id = :session_id
            AND term_id = :term_id
          LIMIT 1',
        [
            ':student_id' => $studentId,
            ':subject_id' => $subjectId,
            ':session_id' => $sessionId,
            ':term_id'    => $termId,
        ]
    );
}

/**
 * The result sheet for one class, subject, session and term.
 *
 * The roster is returned on its own so a student who has no result row yet still
 * appears on the sheet with empty boxes, instead of silently missing.
 */
function resultSheet(int $classId, int $subjectId, int $sessionId, int $termId): array
{
    $roster = classResultRoster($classId, $sessionId);

    $existing = [];

    foreach (
        dbAll(
            'SELECT * FROM results
              WHERE class_id = :class_id
                AND subject_id = :subject_id
                AND session_id = :session_id
                AND term_id = :term_id',
            [
                ':class_id'   => $classId,
                ':subject_id' => $subjectId,
                ':session_id' => $sessionId,
                ':term_id'    => $termId,
            ]
        ) as $row
    ) {
        $existing[(int) $row['student_id']] = $row;
    }

    foreach ($roster as $index => $student) {
        $row = $existing[(int) $student['student_id']] ?? null;
        $calculation = buildResultCalculation(
            [
                'test_1'      => $row === null || $row['test_1'] === null ? null : (float) $row['test_1'],
                'test_2'      => $row === null || $row['test_2'] === null ? null : (float) $row['test_2'],
                'test_3'      => $row === null || $row['test_3'] === null ? null : (float) $row['test_3'],
                'examination' => $row === null || $row['examination'] === null ? null : (float) $row['examination'],
            ],
            100.0
        );

        $roster[$index]['result']       = $row;
        $roster[$index]['result_id']    = $row === null ? null : (int) $row['id'];
        $roster[$index]['status']       = $row === null ? 'draft' : (string) $row['status'];
        $roster[$index]['locked']       = $row !== null && !in_array((string) $row['status'], editableResultStatuses(), true);
        $roster[$index]['total']        = $calculation['total'];
        $roster[$index]['grade']        = $calculation['grade'];
        $roster[$index]['grade_remark'] = $calculation['grade_remark'];
        $roster[$index]['complete']     = $calculation['complete'];
    }

    return $roster;
}

/**
 * Search and filter the result list.
 *
 * @return array{rows: array, paging: array}
 */
function listResults(array $filters): array
{
    $where  = [];
    $params = [];

    if (!empty($filters['session_id'])) {
        $where[]           = 'r.session_id = :session_id';
        $params[':session_id'] = (int) $filters['session_id'];
    }

    if (!empty($filters['term_id'])) {
        $where[]              = 'r.term_id = :term_id';
        $params[':term_id']   = (int) $filters['term_id'];
    }

    if (!empty($filters['class_id'])) {
        $where[]             = 'r.class_id = :class_id';
        $params[':class_id']  = (int) $filters['class_id'];
    }

    if (!empty($filters['section']) && in_array($filters['section'], ['Nursery', 'Primary', 'Secondary'], true)) {
        $where[]             = 'c.section = :section';
        $params[':section']  = (string) $filters['section'];
    }

    if (!empty($filters['subject_id'])) {
        $where[]               = 'r.subject_id = :subject_id';
        $params[':subject_id'] = (int) $filters['subject_id'];
    }

    if (!empty($filters['teacher_id'])) {
        $where[]               = 'r.teacher_id = :teacher_id';
        $params[':teacher_id'] = (int) $filters['teacher_id'];
    }

    if (!empty($filters['status']) && in_array($filters['status'], resultStatuses(), true)) {
        $where[]            = 'r.status = :status';
        $params[':status']  = (string) $filters['status'];
    }

    if (!empty($filters['student_id'])) {
        $where[]               = 'r.student_id = :student_id';
        $params[':student_id'] = (int) $filters['student_id'];
    }

    // The free text box. The teacher column is deliberately left out of the
    // teacher screen's own search, which uses only admission number and name.
    $search = trim((string) ($filters['search'] ?? ''));

    if ($search !== '') {
        /* One placeholder per column rather than one shared name: PDO is not
           running in emulated mode, so a named placeholder may appear only once
           in a statement. */
        $columns = [
            's.admission_number', 's.first_name', 's.last_name',
            'sj.subject_name', 'sj.subject_code', 'c.class_name',
            'te.first_name', 'te.last_name',
        ];

        $parts = [];

        foreach ($columns as $index => $column) {
            $name                  = ':search_' . $index;
            $params[$name]         = likeTerm($search);
            $parts[]               = $column . ' LIKE ' . $name;
        }

        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $joins = ' FROM results r
               JOIN students s ON s.id = r.student_id
               JOIN classes c ON c.id = r.class_id
               JOIN subjects sj ON sj.id = r.subject_id
               JOIN academic_sessions a ON a.id = r.session_id
               JOIN terms t ON t.id = r.term_id
               JOIN teachers te ON te.id = r.teacher_id';

    $total = (int) dbValue('SELECT COUNT(*)' . $joins . $clause, $params);

    $perPage = (int) ($filters['per_page'] ?? DEFAULT_PER_PAGE);
    $page    = (int) ($filters['page'] ?? 1);
    $paging  = paginate($total, $perPage, $page);

    $rows = dbAll(
        'SELECT r.*, s.admission_number, s.first_name, s.middle_name, s.last_name,
                c.class_name, c.section, sj.subject_code, sj.subject_name,
                a.session_name, t.term_name,
                te.first_name AS teacher_first_name, te.middle_name AS teacher_middle_name,
                te.last_name AS teacher_last_name' . $joins . $clause
        . ' ORDER BY r.updated_at DESC, r.id DESC
          LIMIT ' . (int) $paging['per_page'] . ' OFFSET ' . (int) $paging['offset'],
        $params
    );

    return ['rows' => $rows, 'paging' => $paging];
}

/**
 * How many results sit in each workflow state.
 *
 * The optional filters let the dashboards show the counts for the current
 * session and term without counting results from other years.
 */
function resultStatusCounts(array $filters = []): array
{
    $counts = array_fill_keys(resultStatuses(), 0);

    $where  = [];
    $params = [];

    foreach (['session_id', 'term_id', 'class_id', 'subject_id', 'teacher_id', 'student_id'] as $key) {
        if (!empty($filters[$key])) {
            $where[]                = $key . ' = :' . $key;
            $params[':' . $key]     = (int) $filters[$key];
        }
    }

    if (!empty($filters['section']) && in_array($filters['section'], ['Nursery', 'Primary', 'Secondary'], true)) {
        $where[]           = 'section = :section';
        $params[':section'] = (string) $filters['section'];
    }

    $joins = ' FROM results r JOIN classes c ON c.id = r.class_id';
    $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    foreach (dbAll('SELECT r.status AS status, COUNT(*) AS total' . $joins . $clause . ' GROUP BY r.status', $params) as $row) {
        $status = (string) $row['status'];

        if (isset($counts[$status])) {
            $counts[$status] = (int) $row['total'];
        }
    }

    $counts['total'] = array_sum($counts);

    return $counts;
}

/* ------------------------------------------------------------------ */
/* History and audit                                                    */
/* ------------------------------------------------------------------ */

function clientIpAddress(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Write one workflow transition to `result_history`. */
function logResultHistory(
    PDO $pdo,
    int $resultId,
    string $action,
    ?string $oldStatus = null,
    ?string $newStatus = null,
    ?string $reason = null,
    ?int $userId = null
): void {
    $sql = 'INSERT INTO result_history
                (result_id, user_id, action, old_status, new_status, reason, ip_address)
            VALUES
                (:result_id, :user_id, :action, :old_status, :new_status, :reason, :ip_address)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':result_id'  => $resultId,
        ':user_id'    => $userId ?? currentUserId(),
        ':action'     => $action,
        ':old_status' => $oldStatus,
        ':new_status' => $newStatus,
        ':reason'     => $reason,
        ':ip_address' => clientIpAddress(),
    ]);
}

/** Write one line to `audit_logs`. */
function logAudit(string $action, ?int $entityId, string $entityType = 'result', ?int $userId = null): void
{
    try {
        $stmt = getDbConnection()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :ip_address)'
        );
        $stmt->execute([
            ':user_id'     => $userId ?? currentUserId(),
            ':action'      => $action,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':ip_address'  => clientIpAddress(),
        ]);
    } catch (Throwable $exception) {
        // The audit table must never be the reason a teacher cannot save a
        // score, so a failure here is swallowed rather than shown to the user.
    }
}

/** The full audit trail shown on the Result Review screen. */
function resultHistory(int $resultId): array
{
    return dbAll(
        'SELECT rh.*, u.full_name, u.username
           FROM result_history rh
           LEFT JOIN users u ON u.id = rh.user_id
          WHERE rh.result_id = :result_id
          ORDER BY rh.created_at ASC, rh.id ASC',
        [':result_id' => $resultId]
    );
}

/* ------------------------------------------------------------------ */
/* Writing results                                                      */
/* ------------------------------------------------------------------ */

/**
 * Save a result sheet.
 *
 * Everything happens inside one transaction, so a sheet is either fully saved or
 * not saved at all. The rules that matter most:
 *
 *  - a student who is not on the roster for this class and session is rejected,
 *    so a hand edited form cannot write a score onto another class;
 *  - a result that is submitted, approved or locked is never overwritten;
 *  - the total, grade and remark are recalculated by the server, so a hand
 *    edited total in the form has no effect.
 *
 * @return array{created: int, updated: int, skipped: array<int, string>, errors: array}
 */
function saveResultSheet(
    int $teacherId,
    int $classId,
    int $subjectId,
    int $sessionId,
    int $termId,
    array $postedRows
): array {
    $outcome = ['created' => 0, 'updated' => 0, 'skipped' => [], 'errors' => []];

    /* The caller's page checks the allocation too, but the rule belongs here as
       well: a function that can be reached another way must refuse on its own
       rather than trusting whoever called it. */
    if (!teacherCanManageResult($teacherId, $classId, $subjectId, $sessionId)) {
        $outcome['errors'][] = 'You are not actively assigned to that class and subject in the '
            . 'selected academic session, so no result was saved.';

        return $outcome;
    }

    $section   = resultClassSection($classId);
    $structure = $section === null
        ? ['components' => [], 'max_total' => 100.0]
        : assessmentStructure($section);

    $roster      = classResultRoster($classId, $sessionId);
    $rosterIndex = [];

    foreach ($roster as $student) {
        $rosterIndex[(int) $student['student_id']] = $student;
    }

    $term = findTerm($termId);

    if ($term === null || (int) $term['session_id'] !== $sessionId) {
        $outcome['errors'][] = 'The selected term does not belong to the selected academic session.';

        return $outcome;
    }

    dbTransaction(function (PDO $pdo) use (
        $teacherId,
        $classId,
        $subjectId,
        $sessionId,
        $termId,
        $postedRows,
        $rosterIndex,
        $structure,
        &$outcome
    ) {
        $insert = $pdo->prepare(
            'INSERT INTO results
                (student_id, class_id, subject_id, session_id, term_id, teacher_id,
                 test_1, test_2, test_3, examination,
                 total, grade, grade_remark, teacher_remark, status, created_at, updated_at)
             VALUES
                (:student_id, :class_id, :subject_id, :session_id, :term_id, :teacher_id,
                 :test_1, :test_2, :test_3, :examination,
                 :total, :grade, :grade_remark, :teacher_remark, \'draft\', NOW(), NOW())'
        );

        $update = $pdo->prepare(
            'UPDATE results
                SET teacher_id = :teacher_id,
                    test_1 = :test_1,
                    test_2 = :test_2,
                    test_3 = :test_3,
                    examination = :examination,
                    total = :total,
                    grade = :grade,
                    grade_remark = :grade_remark,
                    teacher_remark = :teacher_remark,
                    updated_at = NOW()
              WHERE id = :id
                AND status = \'draft\''
        );

        $find = $pdo->prepare(
            'SELECT * FROM results
              WHERE student_id = :student_id
                AND subject_id = :subject_id
                AND session_id = :session_id
                AND term_id = :term_id
              LIMIT 1'
        );

        foreach ($postedRows as $studentId => $fields) {
            $studentId = (int) $studentId;

            // A student who is not enrolled in this class for this session is not
            // a valid target, whatever the form claims.
            if (!isset($rosterIndex[$studentId])) {
                $outcome['errors'][] = 'A score was submitted for a student who is not enrolled in this class.';

                continue;
            }

            if (!is_array($fields)) {
                continue;
            }

            $checked = validateResultScores($fields, $structure);

            if ($checked['errors'] !== []) {
                $name    = studentFullName($rosterIndex[$studentId]);
                $outcome['errors'][] = $name . ': ' . implode(' ', $checked['errors']);

                continue;
            }

            $calculation = buildResultCalculation($checked['values'], (float) $structure['max_total']);

            $remark = trim((string) ($fields['teacher_remark'] ?? ''));
            $remark = $remark === '' ? null : substr($remark, 0, 255);

            $find->execute([
                ':student_id' => $studentId,
                ':subject_id' => $subjectId,
                ':session_id' => $sessionId,
                ':term_id'    => $termId,
            ]);

            $existing = $find->fetch();

            if ($existing === false) {
                try {
                    $insert->execute([
                        ':student_id'     => $studentId,
                        ':class_id'       => $classId,
                        ':subject_id'     => $subjectId,
                        ':session_id'     => $sessionId,
                        ':term_id'        => $termId,
                        ':teacher_id'     => $teacherId,
                        ':test_1'         => $checked['values']['test_1'],
                        ':test_2'         => $checked['values']['test_2'],
                        ':test_3'         => $checked['values']['test_3'],
                        ':examination'    => $checked['values']['examination'],
                        ':total'          => $calculation['total'],
                        ':grade'          => $calculation['grade'],
                        ':grade_remark'   => $calculation['grade_remark'],
                        ':teacher_remark' => $remark,
                    ]);
                } catch (PDOException $exception) {
                    // The UNIQUE index stopped a second row for the same student,
                    // subject, session and term. Say so instead of showing SQL.
                    $outcome['errors'][] = studentFullName($rosterIndex[$studentId])
                        . ': this result already exists and could not be saved again.';

                    continue;
                }

                $resultId = (int) $pdo->lastInsertId();
                logResultHistory($pdo, $resultId, 'created', null, 'draft');
                $outcome['created']++;

                continue;
            }

            $resultId  = (int) $existing['id'];
            $oldStatus = (string) $existing['status'];

            if (!in_array($oldStatus, editableResultStatuses(), true)) {
                $outcome['skipped'][] = studentFullName($rosterIndex[$studentId])
                    . ' is ' . strtolower($oldStatus) . ' and cannot be edited.';

                continue;
            }

            $update->execute([
                ':teacher_id'     => $teacherId,
                ':test_1'         => $checked['values']['test_1'],
                ':test_2'         => $checked['values']['test_2'],
                ':test_3'         => $checked['values']['test_3'],
                ':examination'    => $checked['values']['examination'],
                ':total'          => $calculation['total'],
                ':grade'          => $calculation['grade'],
                ':grade_remark'   => $calculation['grade_remark'],
                ':teacher_remark' => $remark,
                ':id'             => $resultId,
            ]);

            logResultHistory($pdo, $resultId, 'updated', $oldStatus, $oldStatus);
            $outcome['updated']++;
        }
    });

    if ($outcome['created'] > 0 || $outcome['updated'] > 0) {
        logAudit('result_sheet_saved', $classId, 'result_sheet');
    }

    return $outcome;
}

/**
 * Work out whether a result sheet is complete enough to submit.
 *
 * @return array{ready: bool, message: string, missing: array<int, string>, total: int}
 */
function resultSheetReadiness(int $classId, int $subjectId, int $sessionId, int $termId): array
{
    $section   = resultClassSection($classId);
    $structure = $section === null
        ? ['components' => [], 'max_total' => 100.0]
        : assessmentStructure($section);

    $roster  = classResultRoster($classId, $sessionId);
    $missing = [];

    foreach ($roster as $student) {
        $existing = findResultFor((int) $student['student_id'], $subjectId, $sessionId, $termId);

        if ($existing === null) {
            $missing[] = studentFullName($student) . ' (no scores entered)';

            continue;
        }

        $checked = validateResultScores($existing, $structure);

        if ($checked['errors'] !== []) {
            $missing[] = studentFullName($student) . ' (invalid score)';

            continue;
        }

        $calculation = buildResultCalculation($checked['values'], (float) $structure['max_total']);

        if (!$calculation['complete']) {
            $missing[] = studentFullName($student) . ' (missing a required score)';
        }
    }

    if ($missing === [] && $roster === []) {
        return [
            'ready'   => false,
            'message' => 'There are no students enrolled in this class yet, so there is nothing to submit.',
            'missing' => [],
            'total'   => 0,
        ];
    }

    return [
        'ready'   => $missing === [],
        'message' => $missing === []
            ? 'All required scores are present and the sheet is ready to submit.'
            : 'Result is incomplete. Please enter all required scores before submission.',
        'missing' => $missing,
        'total'   => count($roster),
    ];
}

/**
 * Move a whole result sheet from draft to submitted.
 *
 * Submission is refused unless every enrolled student has all four scores, so a
 * half filled sheet can never reach an administrator for approval.
 */
function submitResultSheet(
    int $teacherId,
    int $classId,
    int $subjectId,
    int $sessionId,
    int $termId
): array {
    $outcome = ['submitted' => 0, 'message' => '', 'missing' => []];

    /* Same rule as saving: the allocation is checked inside the function, not
       only by the page that happens to call it. */
    if (!teacherCanManageResult($teacherId, $classId, $subjectId, $sessionId)) {
        $outcome['message'] = 'You are not actively assigned to that class and subject in the '
            . 'selected academic session, so nothing was submitted.';

        return $outcome;
    }

    $readiness = resultSheetReadiness($classId, $subjectId, $sessionId, $termId);

    if (!$readiness['ready']) {
        return ['submitted' => 0, 'message' => $readiness['message'], 'missing' => $readiness['missing']];
    }

    $submitted = 0;

    dbTransaction(function (PDO $pdo) use ($classId, $subjectId, $sessionId, $termId, $teacherId, &$submitted) {
        $rows = dbAll(
            'SELECT r.id, r.status
               FROM results r
               JOIN student_class_enrollments e
                 ON e.student_id = r.student_id AND e.class_id = r.class_id AND e.session_id = r.session_id
              WHERE r.class_id = :class_id
                AND r.subject_id = :subject_id
                AND r.session_id = :session_id
                AND r.term_id = :term_id
                AND e.status = \'Active\'
                AND r.status = \'draft\'',
            [
                ':class_id'   => $classId,
                ':subject_id' => $subjectId,
                ':session_id' => $sessionId,
                ':term_id'    => $termId,
            ]
        );

        $statement = $pdo->prepare(
            "UPDATE results
                SET status = 'submitted',
                    submitted_at = NOW(),
                    teacher_id = :teacher_id,
                    updated_at = NOW()
              WHERE id = :id AND status = 'draft'"
        );

        foreach ($rows as $row) {
            $statement->execute([':teacher_id' => $teacherId, ':id' => (int) $row['id']]);

            if ($statement->rowCount() > 0) {
                logResultHistory($pdo, (int) $row['id'], 'submitted', 'draft', 'submitted');
                $submitted++;
            }
        }
    });

    if ($submitted > 0) {
        logAudit('result_sheet_submitted', $classId, 'result_sheet');
    }

    return [
        'submitted' => $submitted,
        'message'   => $submitted > 0
            ? $submitted . ' result' . ($submitted === 1 ? '' : 's') . ' submitted for review.'
            : 'These results have already been submitted.',
        'missing'   => [],
    ];
}

/* ------------------------------------------------------------------ */
/* Administrator workflow                                               */
/* ------------------------------------------------------------------ */

/**
 * Apply an administrator decision to one result.
 *
 * The state machine is fixed and lives in one place so no page can invent a
 * shortcut:
 *
 *     draft -> submitted -> approved -> locked
 *     submitted|approved -> draft   (return, a reason is required)
 *     locked -> approved             (unlock, a reason is required)
 *
 * @return array{ok: bool, message: string, status: ?string}
 */
function applyResultAction(int $resultId, string $action, string $reason = ''): array
{
    $allowed = ['approve' => 'approved', 'return' => 'draft', 'lock' => 'locked', 'unlock' => 'approved'];

    /* Only an administrator may move a result through the workflow. The calling
       page already requires an administrator, but the role is checked here as
       well so the rule cannot be bypassed by reaching this function another way. */
    if (!hasRole('admin')) {
        return [
            'ok'      => false,
            'message' => 'Only an administrator can review, approve, return or lock a result.',
            'status'  => null,
        ];
    }

    if (!isset($allowed[$action])) {
        return ['ok' => false, 'message' => 'That action is not available.', 'status' => null];
    }

    $result = findResult($resultId);

    if ($result === null) {
        return ['ok' => false, 'message' => 'That result no longer exists.', 'status' => null];
    }

    $from = (string) $result['status'];
    $to   = $allowed[$action];

    $permitted = [
        'approve' => ['submitted'],
        'return'  => ['submitted', 'approved'],
        'lock'    => ['approved'],
        'unlock'  => ['locked'],
    ][$action];

    if (!in_array($from, $permitted, true)) {
        return [
            'ok'      => false,
            'message' => 'A ' . $from . ' result cannot be ' . ($action === 'approve' ? 'approved' : $action . 'ed') . '.',
            'status'  => $from,
        ];
    }

    $reason = trim($reason);

    // A return or an unlock without a reason would leave the teacher with no way
    // of knowing what to fix, so the reason is mandatory in both cases.
    if (in_array($action, ['return', 'unlock'], true) && $reason === '') {
        return [
            'ok'      => false,
            'message' => 'A reason is required to ' . ($action === 'return' ? 'return' : 'unlock') . ' this result.',
            'status'  => $from,
        ];
    }

    $reason = $reason === '' ? null : substr($reason, 0, 255);
    $adminId = currentUserId();

    try {
        dbTransaction(function (PDO $pdo) use ($resultId, $action, $from, $to, $reason, $adminId) {
            if ($action === 'approve') {
                $stmt = $pdo->prepare(
                    "UPDATE results
                        SET status = 'approved',
                            approved_at = NOW(),
                            approved_by = :admin_id,
                            admin_feedback = NULL,
                            updated_at = NOW()
                      WHERE id = :id AND status = :from"
                );
                $stmt->execute([':admin_id' => $adminId, ':id' => $resultId, ':from' => $from]);
            } elseif ($action === 'return') {
                $stmt = $pdo->prepare(
                    "UPDATE results
                        SET status = 'draft',
                            approved_at = NULL,
                            approved_by = NULL,
                            admin_feedback = :reason,
                            updated_at = NOW()
                      WHERE id = :id AND status = :from"
                );
                $stmt->execute([':reason' => $reason, ':id' => $resultId, ':from' => $from]);
            } elseif ($action === 'lock') {
                $stmt = $pdo->prepare(
                    "UPDATE results
                        SET status = 'locked',
                            locked_at = NOW(),
                            locked_by = :admin_id,
                            updated_at = NOW()
                      WHERE id = :id AND status = :from"
                );
                $stmt->execute([':admin_id' => $adminId, ':id' => $resultId, ':from' => $from]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE results
                        SET status = 'approved',
                            locked_at = NULL,
                            locked_by = NULL,
                            admin_feedback = :reason,
                            updated_at = NOW()
                      WHERE id = :id AND status = :from"
                );
                $stmt->execute([':reason' => $reason, ':id' => $resultId, ':from' => $from]);
            }

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('The result was changed by someone else. Reload the page and try again.');
            }

            logResultHistory(
                $pdo,
                $resultId,
                $action === 'approve' ? 'approved' : ($action === 'lock' ? 'locked' : ($action === 'unlock' ? 'unlocked' : 'returned')),
                $from,
                $to,
                $reason,
                $adminId
            );
        });
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => $exception->getMessage(), 'status' => $from];
    }

    logAudit('result_' . $action . 'd', $resultId);

    $messages = [
        'approve' => 'Result approved.',
        'return'  => 'Result returned to the teacher with your reason.',
        'lock'    => 'Result locked. It can no longer be changed without an unlock.',
        'unlock'  => 'Result unlocked and moved back to approved.',
    ];

    return ['ok' => true, 'message' => $messages[$action], 'status' => $to];
}

/* ------------------------------------------------------------------ */
/* Derived totals: never stored, always calculated                       */
/* ------------------------------------------------------------------ */

/**
 * A student's total, average, pass count and position for one session and term.
 *
 * Only approved and locked results are counted, so a teacher's draft or a result
 * waiting for review can never change a position. The average divides by the
 * number of subjects actually graded, not by the number of subjects offered, so
 * a student who sat three subjects has an average of three results.
 */
function studentResultSummary(int $studentId, int $sessionId, int $termId): array
{
    $counted = countedResultStatuses();
    $placeholders = [];

    foreach ($counted as $index => $status) {
        $placeholders[] = ':status_' . $index;
    }

    $params = [
        ':student_id' => $studentId,
        ':session_id' => $sessionId,
        ':term_id'    => $termId,
    ];

    foreach ($counted as $index => $status) {
        $params[':status_' . $index] = $status;
    }

    $rows = dbAll(
        'SELECT r.id, r.class_id, r.total, r.grade, r.grade_remark, r.status,
                sj.subject_name, sj.subject_code
           FROM results r
           JOIN subjects sj ON sj.id = r.subject_id
          WHERE r.student_id = :student_id
            AND r.session_id = :session_id
            AND r.term_id = :term_id
            AND r.status IN (' . implode(', ', $placeholders) . ')
          ORDER BY sj.subject_name ASC',
        $params
    );

    $overallTotal = 0.0;
    $passed       = 0;
    $failed       = 0;
    $countedTotal = 0.0;

    foreach ($rows as $row) {
        if ($row['total'] === null) {
            continue;
        }

        $score  = (float) $row['total'];
        $overallTotal += $score;
        $countedTotal  += $score;

        if (calculateGrade($score)['is_pass'] === true) {
            $passed++;
        } else {
            $failed++;
        }
    }

    $subjects = count($rows);
    $average  = $subjects > 0 ? round($countedTotal / $subjects, 2) : 0.0;

    return [
        'student_id'    => $studentId,
        'session_id'    => $sessionId,
        'term_id'       => $termId,
        'rows'          => $rows,
        'subjects'      => $subjects,
        'overall_total' => round($overallTotal, 2),
        'average'       => $average,
        'passed'        => $passed,
        'failed'        => $failed,
        'class_id'      => $rows === [] ? null : (int) $rows[0]['class_id'],
    ];
}

/**
 * Positions for a class, term and session, using competition ranking.
 *
 * Competition ranking means equal totals share a position and the next position
 * is skipped: 85, 85, 80 gives 1st, 1st, 3rd. Only approved and locked results
 * take part, so a draft sheet cannot move anybody.
 */
function classResultPositions(int $classId, int $sessionId, int $termId): array
{
    $rows = dbAll(
        "SELECT r.student_id,
                SUM(r.total) AS overall_total,
                COUNT(*)     AS subjects
           FROM results r
          WHERE r.class_id = :class_id
            AND r.session_id = :session_id
            AND r.term_id = :term_id
            AND r.status IN ('approved', 'locked')
            AND r.total IS NOT NULL
          GROUP BY r.student_id
          ORDER BY overall_total DESC, r.student_id ASC",
        [':class_id' => $classId, ':session_id' => $sessionId, ':term_id' => $termId]
    );

    $positions = [];
    $lastTotal = null;
    $position  = 0;
    $index     = 0;

    foreach ($rows as $row) {
        $index++;
        $total = round((float) $row['overall_total'], 2);

        // A tie keeps the previous position; the next student is pushed down,
        // which is what produces 1st, 1st, 3rd.
        if ($lastTotal === null || $total !== $lastTotal) {
            $position = $index;
            $lastTotal = $total;
        }

        $positions[(int) $row['student_id']] = [
            'position'       => $position,
            'overall_total'  => $total,
            'subjects'       => (int) $row['subjects'],
        ];
    }

    return $positions;
}

/** Position for one student, or null when the student has no counted result. */
function studentPosition(int $studentId, int $classId, int $sessionId, int $termId): ?array
{
    $positions = classResultPositions($classId, $sessionId, $termId);

    return $positions[$studentId] ?? null;
}

/* ====================================================================== */
/* PHASE 5: REPORT CARDS, ATTENDANCE, PSYCHOMOTOR AND EFFECTIVE AREAS     */
/* ====================================================================== */

/** The report card workflow states. */
function reportCardStatuses(): array
{
    return ['draft', 'generated', 'approved', 'locked'];
}

/** The promotion outcomes an administrator may record. Never decided by the portal. */
function promotionStatuses(): array
{
    return ['Promoted', 'Promoted on Trial', 'Repeat', 'Graduated', 'Pending'];
}

/** The rating scale used by both the psychomotor traits and the effective areas. */
function ratingScale(): array
{
    return [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very Good', 5 => 'Excellent'];
}

/** The ten psychomotor or affective traits, in the order they are printed. */
function psychomotorTraits(): array
{
    return [
        'punctuality'    => 'Punctuality',
        'neatness'       => 'Neatness',
        'honesty'        => 'Honesty',
        'cooperation'    => 'Cooperation',
        'leadership'     => 'Leadership',
        'attentiveness'  => 'Attentiveness',
        'perseverance'   => 'Perseverance',
        'politeness'     => 'Politeness',
        'self_control'   => 'Self Control',
        'responsibility' => 'Responsibility',
    ];
}

/** The six effective areas, in the order they are printed. */
function effectiveAreaFields(): array
{
    return [
        'communication'     => 'Communication',
        'creativity'        => 'Creativity',
        'problem_solving'   => 'Problem Solving',
        'teamwork'          => 'Teamwork',
        'critical_thinking' => 'Critical Thinking',
        'practical_skills'  => 'Practical Skills',
    ];
}

/** Turn 4 into 4th, 11 into 11th, 22 into 22nd. Used everywhere a position prints. */
function ordinalSuffix(int $number): string
{
    $remainder100 = $number % 100;

    if ($remainder100 >= 11 && $remainder100 <= 13) {
        return $number . 'th';
    }

    switch ($number % 10) {
        case 1:  return $number . 'st';
        case 2:  return $number . 'nd';
        case 3:  return $number . 'rd';
        default: return $number . 'th';
    }
}

/** Bootstrap badge class for a report card workflow state. */
function reportCardBadgeClass(string $status): string
{
    return [
        'draft'     => 'bg-secondary',
        'generated' => 'bg-warning text-dark',
        'approved'  => 'bg-info text-dark',
        'locked'    => 'bg-success',
    ][$status] ?? 'bg-secondary';
}

/* ------------------------------------------------------------------ */
/* Report card numbering                                                */
/* ------------------------------------------------------------------ */

/**
 * The public report number prefix for a session.
 *
 * "2026/2027" becomes "2026-2027", so a report number reads
 * ORM-2026-2027-P5-0001. The date part comes from the session in the database,
 * never from the browser.
 */
function reportNumberPrefix(int $sessionId): string
{
    $name = (string) dbValue(
        'SELECT session_name FROM academic_sessions WHERE id = :id LIMIT 1',
        [':id' => $sessionId]
    );

    $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '';
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'SESSION';
    }

    return 'ORM-' . strtoupper($slug) . '-P5';
}

/**
 * The next free report number for a session.
 *
 * The UNIQUE index on report_number is the real guarantee. This function only
 * finds the highest number already issued for the prefix and adds one, and the
 * caller retries if two administrators generate at the same moment.
 */
function nextReportNumber(PDO $pdo, int $sessionId): string
{
    $prefix = reportNumberPrefix($sessionId);

    $highest = (int) $pdo->query(
        "SELECT COALESCE(MAX(CAST(RIGHT(report_number, 4) AS UNSIGNED)), 0)
           FROM report_cards
          WHERE report_number LIKE " . $pdo->quote($prefix . '-%')
    )->fetchColumn();

    return sprintf('%s-%04d', $prefix, $highest + 1);
}

/* ------------------------------------------------------------------ */
/* The subjects a class is expected to offer                             */
/* ------------------------------------------------------------------ */

/**
 * The subjects a class should have results in.
 *
 * The portal has no "class subjects" table, because Phases 2 to 4 deliberately
 * tied subjects to a section rather than to a class. So the required set is
 * derived: every Active subject offered for the class's section, plus any
 * subject that already has a result for a student in the class, which is how a
 * school that teaches an extra subject keeps it.
 */
function requiredSubjectsForClass(int $classId): array
{
    $section = dbValue('SELECT section FROM classes WHERE id = :id LIMIT 1', [':id' => $classId]);

    if ($section === null) {
        return [];
    }

    $rows = dbAll(
        "SELECT id, subject_code, subject_name
           FROM subjects
          WHERE status = 'Active'
            AND (section = :section
                 OR id IN (SELECT DISTINCT subject_id FROM results WHERE class_id = :class_id))
          ORDER BY FIELD(section, 'Nursery', 'Primary', 'Secondary'), subject_name",
        [':section' => (string) $section, ':class_id' => $classId]
    );

    return $rows;
}

/* ------------------------------------------------------------------ */
/* Result processing validation                                         */
/* ------------------------------------------------------------------ */

/**
 * Work out whether an official report can be produced for one student.
 *
 * An official report is only ever built from approved and locked results. This
 * function is the single gate that enforces it, and it is called both by the
 * individual generate action and by the bulk class generation.
 *
 * @return array{
 *     ready: bool,
 *     message: string,
 *     counted: int,
 *     offered: int,
 *     pending: array<int, array{subject: string, status: string}>,
 *     missing: array<int, string>,
 *     summary: array
 * }
 */
function validateReportGeneration(int $studentId, int $classId, int $sessionId, int $termId): array
{
    $required = requiredSubjectsForClass($classId);
    $offered  = count($required);

    $pending = [];
    $missing = [];
    $counted = 0;
    $total   = 0.0;
    $passed  = 0;
    $failed  = 0;

    /* One query for every subject result of this student, in the session and
       term. No per subject query is issued. */
    $rows = dbAll(
        "SELECT r.id, r.subject_id, r.status, r.total, r.grade, r.grade_remark
           FROM results r
          WHERE r.student_id = :student_id
            AND r.class_id = :class_id
            AND r.session_id = :session_id
            AND r.term_id = :term_id",
        [
            ':student_id' => $studentId,
            ':class_id'   => $classId,
            ':session_id' => $sessionId,
            ':term_id'    => $termId,
        ]
    );

    $bySubject = [];

    foreach ($rows as $row) {
        $bySubject[(int) $row['subject_id']] = $row;
    }

    foreach ($required as $subject) {
        $subjectId = (int) $subject['id'];
        $name      = (string) $subject['subject_name'];
        $result    = $bySubject[$subjectId] ?? null;

        if ($result === null) {
            $pending[] = ['subject' => $name, 'status' => 'No result recorded'];
            $missing[] = $name . ' - no result recorded';

            continue;
        }

        $status = (string) $result['status'];

        if (!in_array($status, countedResultStatuses(), true)) {
            $pending[] = ['subject' => $name, 'status' => $status];
            $missing[] = $name . ' - result is ' . $status;

            continue;
        }

        if ($result['total'] === null) {
            $pending[] = ['subject' => $name, 'status' => 'No total score'];
            $missing[] = $name . ' - result has no total score';

            continue;
        }

        $score     = (float) $result['total'];
        $total    += $score;
        $counted++;

        if (calculateGrade($score)['is_pass'] === true) {
            $passed++;
        } else {
            $failed++;
        }
    }

    $average = $counted > 0 ? round($total / $counted, 2) : 0.0;

    return [
        'ready'   => $offered > 0 && $pending === [],
        'message' => $offered > 0 && $pending === []
            ? 'All required subject results are approved or locked.'
            : 'Report cannot be generated because some subject results are incomplete.',
        'counted' => $counted,
        'offered' => $offered,
        'pending' => $pending,
        'missing' => $missing,
        'summary' => [
            'total'   => round($total, 2),
            'average' => $average,
            'passed'  => $passed,
            'failed'  => $failed,
        ],
    ];
}

/* ------------------------------------------------------------------ */
/* Reading report cards                                                 */
/* ------------------------------------------------------------------ */

/** One report card with every label needed to display it. */
function findReportCard(int $id): ?array
{
    return dbOne(
        'SELECT rc.*,
                s.admission_number, s.first_name, s.middle_name, s.last_name,
                s.gender, s.date_of_birth, s.passport_photo,
                c.class_name, c.section,
                a.session_name, t.term_name
           FROM report_cards rc
           JOIN students s ON s.id = rc.student_id
           JOIN classes c ON c.id = rc.class_id
           JOIN academic_sessions a ON a.id = rc.session_id
           JOIN terms t ON t.id = rc.term_id
          WHERE rc.id = :id
          LIMIT 1',
        [':id' => $id]
    );
}

/**
 * The report card a student already has for one class, session and term.
 *
 * There is a UNIQUE index on those four columns together, so there can only ever
 * be one. The generation screen uses it to show an existing card next to a
 * student rather than offering to create a duplicate.
 */
function findReportCardFor(int $studentId, int $classId, int $sessionId, int $termId): ?array
{
    return dbOne(
        'SELECT * FROM report_cards
          WHERE student_id = :student_id
            AND class_id = :class_id
            AND session_id = :session_id
            AND term_id = :term_id
          LIMIT 1',
        [
            ':student_id' => $studentId,
            ':class_id'   => $classId,
            ':session_id' => $sessionId,
            ':term_id'    => $termId,
        ]
    );
}

/** The subject rows that print on a report card, in subject order. */
function reportCardSubjectRows(int $reportId): array
{
    $report = dbOne(
        'SELECT student_id, class_id, session_id, term_id FROM report_cards WHERE id = :id LIMIT 1',
        [':id' => $reportId]
    );

    if ($report === null) {
        return [];
    }

    /* Every subject the class offers, so a missing subject prints as an empty
       row rather than quietly vanishing from the card. */
    $required = requiredSubjectsForClass((int) $report['class_id']);

    if ($required === []) {
        return [];
    }

    /* One query for all subjects of this student, not one per subject. */
    $columns = implode(', r.', resultAssessmentCodes());

    $stmt = getDbConnection()->prepare(
        'SELECT r.subject_id, r.status, r.' . $columns . ', r.total, r.grade, r.grade_remark, r.teacher_remark
           FROM results r
          WHERE r.student_id = :student_id
            AND r.class_id = :class_id
            AND r.session_id = :session_id
            AND r.term_id = :term_id'
    );

    $stmt->execute([
        ':student_id' => (int) $report['student_id'],
        ':class_id'   => (int) $report['class_id'],
        ':session_id' => (int) $report['session_id'],
        ':term_id'    => (int) $report['term_id'],
    ]);

    $bySubject = [];

    foreach ($stmt->fetchAll() as $row) {
        $bySubject[(int) $row['subject_id']] = $row;
    }

    $codes = resultAssessmentCodes();
    $rows  = [];

    foreach ($required as $subject) {
        $result = $bySubject[(int) $subject['id']] ?? null;

        $scores = array_fill_keys($codes, null);

        if ($result !== null) {
            foreach ($codes as $code) {
                $scores[$code] = $result[$code] === null ? null : (float) $result[$code];
            }
        }

        $rows[] = [
            'subject_id'     => (int) $subject['id'],
            'subject_code'   => (string) $subject['subject_code'],
            'subject_name'   => (string) $subject['subject_name'],
            'scores'         => $scores,
            'total'          => $result === null || $result['total'] === null ? null : (float) $result['total'],
            'grade'          => $result === null ? null : $result['grade'],
            'grade_remark'   => $result === null ? null : $result['grade_remark'],
            'teacher_remark' => $result === null ? null : $result['teacher_remark'],
            'status'         => $result === null ? null : (string) $result['status'],
            'counted'        => $result !== null
                && in_array((string) $result['status'], countedResultStatuses(), true),
        ];
    }

    return $rows;
}

/** The attendance summary for a report card, or null when none has been entered. */
function reportCardAttendance(int $studentId, int $classId, int $sessionId, int $termId): ?array
{
    return dbOne(
        'SELECT * FROM attendance_summary
          WHERE student_id = :student_id
            AND class_id = :class_id
            AND session_id = :session_id
            AND term_id = :term_id
          LIMIT 1',
        [
            ':student_id' => $studentId,
            ':class_id'   => $classId,
            ':session_id' => $sessionId,
            ':term_id'    => $termId,
        ]
    );
}

/** The psychomotor ratings for a report card, or null when none has been entered. */
function reportCardPsychomotor(int $studentId, int $classId, int $sessionId, int $termId): ?array
{
    return dbOne(
        'SELECT * FROM psychomotor_assessments
          WHERE student_id = :student_id
            AND class_id = :class_id
            AND session_id = :session_id
            AND term_id = :term_id
          LIMIT 1',
        [
            ':student_id' => $studentId,
            ':class_id'   => $classId,
            ':session_id' => $sessionId,
            ':term_id'    => $termId,
        ]
    );
}

/** The effective area ratings for a report card, or null when none has been entered. */
function reportCardEffectiveAreas(int $studentId, int $classId, int $sessionId, int $termId): ?array
{
    return dbOne(
        'SELECT * FROM effective_areas
          WHERE student_id = :student_id
            AND class_id = :class_id
            AND session_id = :session_id
            AND term_id = :term_id
          LIMIT 1',
        [
            ':student_id' => $studentId,
            ':class_id'   => $classId,
            ':session_id' => $sessionId,
            ':term_id'    => $termId,
        ]
    );
}

/**
 * How many students are enrolled in a class for a session.
 *
 * The count comes from student_class_enrollments for that session rather than
 * from students.class_id, so a historical report card shows the class size the
 * student actually sat in, not the class they are in now.
 */
function classSizeForReport(int $classId, int $sessionId): int
{
    return (int) dbValue(
        "SELECT COUNT(*)
           FROM student_class_enrollments e
           JOIN students s ON s.id = e.student_id
          WHERE e.class_id = :class_id
            AND e.session_id = :session_id
            AND e.status = 'Active'",
        [':class_id' => $classId, ':session_id' => $sessionId]
    );
}

/**
 * How many students can be ranked in this class for this session and term.
 *
 * A student with no counted result cannot hold a position, so the denominator
 * of "5th of 35" is the number of ranked students rather than the roll, which
 * would otherwise claim a position that does not exist.
 */
function rankedStudentCount(int $classId, int $sessionId, int $termId): int
{
    return (int) dbValue(
        "SELECT COUNT(DISTINCT r.student_id)
           FROM results r
           JOIN student_class_enrollments e
             ON e.student_id = r.student_id AND e.class_id = r.class_id AND e.session_id = r.session_id
          WHERE r.class_id = :class_id
            AND r.session_id = :session_id
            AND r.term_id = :term_id
            AND r.status IN ('approved', 'locked')
            AND e.status = 'Active'",
        [':class_id' => $classId, ':session_id' => $sessionId, ':term_id' => $termId]
    );
}

/* ------------------------------------------------------------------ */
/* Report card print helpers (display only, never written back)        */
/* ------------------------------------------------------------------ */

/**
 * Assemble everything the report card sheet prints, for one report card.
 *
 * The three places a report card is shown - the admin print view, the parent's
 * public print view and the on-screen result - must show the same document, so
 * the whole view model is built here once instead of being assembled three
 * times. The result engine is not touched: this only calls the existing readers
 * (reportCardSubjectRows(), reportCardPsychomotor(), reportCardEffectiveAreas(),
 * previousPerformanceFor(), assessmentStructure()) and adds presentation figures
 * derived from what they already returned.
 *
 * Nothing here writes to the database, so calling it cannot change a result.
 *
 * @return array<string,mixed>
 */
function reportCardViewData(int $reportId, bool $includeGuardianPhone = false): array
{
    $report = findReportCard($reportId);

    if ($report === null) {
        return [];
    }

    $studentId = (int) $report['student_id'];
    $classId   = (int) $report['class_id'];
    $sessionId = (int) $report['session_id'];
    $termId    = (int) $report['term_id'];

    $structure    = assessmentStructure((string) $report['section']);
    $maxPerSubject = (float) $structure['max_total'];
    $subjects     = reportCardSubjectRows($reportId);
    $psychomotor  = reportCardPsychomotor($studentId, $classId, $sessionId, $termId);
    $effective    = reportCardEffectiveAreas($studentId, $classId, $sessionId, $termId);
    $guardian     = studentGuardianContact($studentId);

    /* The attendance frozen onto the card wins over the editable summary, so a
       locked card cannot change because a register was corrected later. */
    $attendance = $report['attendance_total'] === null
        ? reportCardAttendance($studentId, $classId, $sessionId, $termId)
        : [
            'days_present' => (int) $report['attendance_present'],
            'days_absent'  => (int) $report['attendance_absent'],
            'school_days'  => (int) $report['attendance_total'],
        ];

    $attendanceSummary = $attendance === null ? null : [
        'present' => (int) ($attendance['days_present'] ?? 0),
        'absent'  => (int) ($attendance['days_absent'] ?? 0),
        'total'   => (int) ($attendance['school_days'] ?? 0),
    ];

    $average     = $report['average_score'] === null ? null : (float) $report['average_score'];
    $gradeOfCard = $average === null ? null : calculateGrade($average, $maxPerSubject);

    $term = dbOne('SELECT * FROM terms WHERE id = :id LIMIT 1', [':id' => $termId]);
    $code = ensureReportCardVerificationCode($reportId);
    $school   = getSchoolSettings();
    $logoPath = (string) ($school['logo_path'] ?? 'assets/images/logo.jpeg');
    $stampPath = (string) ($school['stamp_path'] ?? '');

    return [
        'school'        => $school,
        'logo_url'      => assetUrl($logoPath),
        'stamp_url'     => $stampPath === '' ? null : assetUrl($stampPath),

        'student_name'  => studentFullName($report),
        'admission_number' => (string) $report['admission_number'],
        'gender'        => (string) $report['gender'],
        'age'           => studentAgeYears($report['date_of_birth'] ?? null),
        'guardian'      => $guardian['name'],
        'guardian_phone' => $includeGuardianPhone ? $guardian['phone'] : null,
        'show_guardian_contact' => $includeGuardianPhone,
        'photo_url'     => ($report['passport_photo'] ?? null) === null || $report['passport_photo'] === ''
            ? null
            : studentPhotoUrl((string) $report['passport_photo']),

        'class_name'    => (string) $report['class_name'],
        'section'       => (string) $report['section'],
        'session_name'  => (string) $report['session_name'],
        'term_name'     => (string) $report['term_name'],
        'term_start'    => $term === null ? null : (string) $term['start_date'],
        'term_end'      => $term === null ? null : (string) $term['end_date'],
        'next_term'     => nextTermInSession($sessionId, $termId),

        'attendance'    => $attendanceSummary,
        'subjects'      => $subjects,
        'structure'     => $structure,
        'max_per_subject' => $maxPerSubject,

        'grade_analysis' => reportCardGradeDistribution($subjects),
        'extremes'       => reportCardSubjectExtremes($subjects),

        'total_obtained'   => $report['total_score'] === null ? null : (float) $report['total_score'],
        'total_obtainable' => reportCardTotalObtainable($maxPerSubject, count($subjects)),
        'average'          => $average,
        'average_percentage' => $gradeOfCard['percentage'] ?? null,
        'average_grade'    => $gradeOfCard['grade'] ?? null,
        'position'         => $report['position'] === null ? null : (int) $report['position'],
        'population'       => (int) $report['total_students'] > 0
            ? (int) $report['total_students']
            : rankedStudentCount($classId, $sessionId, $termId),
        'offered'          => (int) $report['subjects_offered'],
        'passed'           => (int) $report['subjects_passed'],
        'failed'           => (int) $report['subjects_failed'],

        'psychomotor'    => $psychomotor,
        'effective'      => $effective,
        'scale'          => ratingScale(),

        'teacher_comment'   => (string) ($report['teacher_comment'] ?? ''),
        'principal_comment' => (string) ($report['principal_comment'] ?? ''),
        'promotion'         => trim((string) ($report['promotion_status'] ?? '')) === ''
            ? 'Pending'
            : (string) $report['promotion_status'],

        'verification_code' => $code,
        'report_number'     => (string) $report['report_number'],
        'status'            => (string) $report['status'],
        'previous'          => previousPerformanceFor($reportId),
        'issued_on'         => (string) ($report['generated_at'] ?? ''),
    ];
}

/**
 * Whole years between a date of birth and a reference date.
 *
 * Used only to print "Age" on a report card, so it never stores anything. The
 * comparison happens on the month and day as well as the year, so somebody who
 * has not had their birthday yet this session is not shown an age they have not
 * reached. Returns null when the date of birth is unknown.
 */
function studentAgeYears(?string $dateOfBirth, ?string $asOf = null): ?int
{
    if ($dateOfBirth === null || trim($dateOfBirth) === '') {
        return null;
    }

    try {
        $birth = new DateTimeImmutable($dateOfBirth);
    } catch (Throwable) {
        return null;
    }

    $reference = $asOf === null ? new DateTimeImmutable('today') : new DateTimeImmutable($asOf);
    $age = (int) $birth->diff($reference)->y;

    return $age >= 0 && $age < 200 ? $age : null;
}

/**
 * The parent or guardian recorded against a student.
 *
 * A report card prints a guardian line, but the guardian's phone number and
 * email address are deliberately not returned here: they exist for the office
 * to contact a family, not to be printed on a document that may be handed out.
 *
 * @return array{name: ?string, phone: ?string}
 */
function studentGuardianContact(int $studentId): array
{
    $row = dbOne(
        'SELECT parent_name, parent_phone FROM students WHERE id = :id LIMIT 1',
        [':id' => $studentId]
    );

    return [
        'name'  => $row === null ? null : ($row['parent_name'] !== null ? (string) $row['parent_name'] : null),
        'phone' => $row === null ? null : ($row['parent_phone'] !== null ? (string) $row['parent_phone'] : null),
    ];
}

/**
 * How many of a student's subjects fall into each grade letter.
 *
 * Derived from the rows reportCardSubjectRows() already produced, so it can
 * never disagree with the table printed above it. Grades follow
 * resultGradeBands(), so a band added there appears here without any change.
 * Subjects with no grade yet are counted separately and are not shown as a
 * band, because an empty row is not an F.
 *
 * @param array<int,array<string,mixed>> $subjectRows
 * @return array{distribution: array<string,int>, graded: int, ungraded: int}
 */
function reportCardGradeDistribution(array $subjectRows): array
{
    $distribution = [];

    foreach (resultGradeBands() as $band) {
        $distribution[(string) $band['grade']] = 0;
    }

    $graded   = 0;
    $ungraded = 0;

    foreach ($subjectRows as $row) {
        if (!($row['counted'] ?? false)) {
            continue;
        }

        $grade = $row['grade'] ?? null;

        if ($grade === null || trim((string) $grade) === '') {
            $ungraded++;

            continue;
        }

        $grade = (string) $grade;
        $distribution[$grade] = ($distribution[$grade] ?? 0) + 1;
        $graded++;
    }

    return ['distribution' => $distribution, 'graded' => $graded, 'ungraded' => $ungraded];
}

/**
 * The best and worst counted subject for one student, by subject total.
 *
 * Both come from the same rows the cognitive table prints. A student whose
 * results are not all counted yet still gets an answer from whichever subjects
 * do count, and null when nothing counts at all.
 *
 * @param  array<int,array<string,mixed>> $subjectRows
 * @return array{highest: ?array{subject_name: string, total: float}, lowest: ?array{subject_name: string, total: float}}
 */
function reportCardSubjectExtremes(array $subjectRows): array
{
    $highest = null;
    $lowest  = null;

    foreach ($subjectRows as $row) {
        if (!($row['counted'] ?? false) || ($row['total'] ?? null) === null) {
            continue;
        }

        $entry = [
            'subject_name' => (string) ($row['subject_name'] ?? ''),
            'total'        => (float) $row['total'],
        ];

        if ($highest === null || $entry['total'] > $highest['total']) {
            $highest = $entry;
        }

        if ($lowest === null || $entry['total'] < $lowest['total']) {
            $lowest = $entry;
        }
    }

    return ['highest' => $highest, 'lowest' => $lowest];
}

/**
 * The total a perfect result would have scored, for every subject offered.
 *
 * The assessment structure gives the per-subject ceiling, so this multiplies it
 * by the number of subjects the class actually offers rather than by a constant,
 * which keeps "Total Obtainable" correct if the school ever adds a subject or
 * changes the examination weighting.
 */
function reportCardTotalObtainable(float $maxPerSubject, int $subjectCount): float
{
    if ($maxPerSubject <= 0.0 || $subjectCount <= 0) {
        return 0.0;
    }

    return round($maxPerSubject * $subjectCount, 2);
}

/**
 * The term that follows the given one in the same session, if there is one.
 *
 * Powers the "Next term" panel. Terms are ordered by name, so the answer follows
 * the school's own First / Second / Third Term sequence rather than a date.
 */
function nextTermInSession(int $sessionId, int $termId): ?array
{
    $current = dbOne('SELECT * FROM terms WHERE id = :id AND session_id = :sid LIMIT 1', [
        ':id'  => $termId,
        ':sid' => $sessionId,
    ]);

    if ($current === null) {
        return null;
    }

    return dbOne(
        'SELECT * FROM terms
          WHERE session_id = :session_id
            AND term_name > :term_name
          ORDER BY term_name ASC
          LIMIT 1',
        [':session_id' => $sessionId, ':term_name' => (string) $current['term_name']]
    );
}

/* ------------------------------------------------------------------ */
/* Report card history                                                  */
/* ------------------------------------------------------------------ */

/** Write one workflow transition to `report_card_history`. */
function logReportCardHistory(
    PDO $pdo,
    int $reportId,
    string $action,
    ?string $oldStatus = null,
    ?string $newStatus = null,
    ?string $reason = null,
    ?int $userId = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO report_card_history
            (report_card_id, user_id, action, old_status, new_status, reason, ip_address)
         VALUES
            (:report_id, :user_id, :action, :old_status, :new_status, :reason, :ip_address)'
    );

    $stmt->execute([
        ':report_id'  => $reportId,
        ':user_id'    => $userId ?? currentUserId(),
        ':action'     => $action,
        ':old_status' => $oldStatus,
        ':new_status' => $newStatus,
        ':reason'     => $reason,
        ':ip_address' => clientIpAddress(),
    ]);
}

/** The audit trail shown on the report card screen. */
function reportCardHistory(int $reportId): array
{
    return dbAll(
        'SELECT rch.*, u.full_name, u.username
           FROM report_card_history rch
           LEFT JOIN users u ON u.id = rch.user_id
          WHERE rch.report_card_id = :report_id
          ORDER BY rch.created_at ASC, rch.id ASC',
        [':report_id' => $reportId]
    );
}

/* ------------------------------------------------------------------ */
/* Generation                                                           */
/* ------------------------------------------------------------------ */

/**
 * Generate one official report card.
 *
 * Nothing is calculated here from the browser. The function re-reads the student,
 * the class, the session, the term and the enrollment from the database, refuses
 * unless the student is really enrolled, refuses unless every required subject
 * result is approved or locked, and then writes the figures it calculated itself.
 *
 * Regenerating an unlocked report recomputes every column, so a corrected
 * subject result is picked up. Regenerating a locked report is refused outright,
 * which is what stops an official document changing after it has been released.
 *
 * @return array{ok: bool, id: ?int, message: string, missing: array}
 */
function generateReportCard(int $studentId, int $classId, int $sessionId, int $termId): array
{
    $failure = ['ok' => false, 'id' => null, 'message' => '', 'missing' => []];

    /* --- Every identifier is verified against the database, never trusted --- */
    $student = dbOne('SELECT * FROM students WHERE id = :id LIMIT 1', [':id' => $studentId]);
    $class   = dbOne('SELECT * FROM classes WHERE id = :id LIMIT 1', [':id' => $classId]);
    $session = dbOne('SELECT * FROM academic_sessions WHERE id = :id LIMIT 1', [':id' => $sessionId]);
    $term    = dbOne('SELECT * FROM terms WHERE id = :id LIMIT 1', [':id' => $termId]);

    if ($student === null) {
        $failure['message'] = 'That student does not exist.';

        return $failure;
    }

    if ($class === null) {
        $failure['message'] = 'That class does not exist.';

        return $failure;
    }

    if ($session === null) {
        $failure['message'] = 'That academic session does not exist.';

        return $failure;
    }

    if ($term === null || (int) $term['session_id'] !== $sessionId) {
        $failure['message'] = 'That term does not belong to the selected academic session.';

        return $failure;
    }

    /* The enrollment is the authority on whether this student sits in this class
       for this session. A student who has since moved class still has the old
       enrollment, so a historical report stays valid. */
    $enrolled = dbOne(
        "SELECT id FROM student_class_enrollments
          WHERE student_id = :student_id
            AND class_id = :class_id
            AND session_id = :session_id
            AND status = 'Active'
          LIMIT 1",
        [
            ':student_id' => $studentId,
            ':class_id'   => $classId,
            ':session_id' => $sessionId,
        ]
    );

    if ($enrolled === null) {
        $failure['message'] = studentFullName($student)
            . ' is not enrolled in ' . $class['class_name'] . ' for ' . $session['session_name'] . '.';

        return $failure;
    }

    /* --- The result processing gate --- */
    $check = validateReportGeneration($studentId, $classId, $sessionId, $termId);

    if (!$check['ready']) {
        $failure['message'] = $check['message'];
        $failure['missing'] = $check['missing'];

        return $failure;
    }

    /* --- The figures are taken from the Phase 4 ranking, never recomputed here,
           so a report card can never disagree with the results screen. --- */
    $positions   = classResultPositions($classId, $sessionId, $termId);
    $position    = $positions[$studentId]['position'] ?? null;
    $classSize   = classSizeForReport($classId, $sessionId);
    $rankedCount = rankedStudentCount($classId, $sessionId, $termId);

    $attendance = reportCardAttendance($studentId, $classId, $sessionId, $termId);

    try {
        $reportId = dbTransaction(function (PDO $pdo) use (
            $studentId,
            $classId,
            $sessionId,
            $termId,
            $check,
            $position,
            $classSize,
            $rankedCount,
            $attendance
        ): int {
            $existing = dbOne(
                'SELECT * FROM report_cards
                  WHERE student_id = :student_id
                    AND class_id = :class_id
                    AND session_id = :session_id
                    AND term_id = :term_id
                  LIMIT 1',
                [
                    ':student_id' => $studentId,
                    ':class_id'   => $classId,
                    ':session_id' => $sessionId,
                    ':term_id'    => $termId,
                ]
            );

            if ($existing !== null && (string) $existing['status'] === 'locked') {
                throw new RuntimeException(
                    'This report card is locked and cannot be regenerated. Unlock it first.'
                );
            }

            /* Comments, promotion and the approval stamps survive a regeneration,
               because they are the administrator's words about the figures, not
               part of the figures themselves.

               Only the figures live in $figures. The keys that identify the card
               differ between the INSERT and the UPDATE, and PDO is in native
               mode, which rejects any value that has no placeholder in the
               statement it is being sent to. */
            $figures = [
                ':total_score'      => $check['summary']['total'],
                ':average_score'    => $check['summary']['average'],
                ':position'         => $position,
                ':total_students'   => $rankedCount > 0 ? $rankedCount : $classSize,
                ':subjects_offered' => $check['offered'],
                ':subjects_passed'  => $check['summary']['passed'],
                ':subjects_failed'  => $check['summary']['failed'],
                ':present'          => $attendance === null ? null : (int) $attendance['days_present'],
                ':absent'           => $attendance === null ? null : (int) $attendance['days_absent'],
                ':total_days'       => $attendance === null ? null : (int) $attendance['school_days'],
            ];

            $keys = [
                ':student_id' => $studentId,
                ':class_id'   => $classId,
                ':session_id' => $sessionId,
                ':term_id'    => $termId,
            ];

            if ($existing === null) {
                $reportNumber = nextReportNumber($pdo, $sessionId);

                $insert = $pdo->prepare(
                    'INSERT INTO report_cards
                        (report_number, verification_code, student_id, class_id, session_id, term_id,
                         total_score, average_score, position, total_students,
                         subjects_offered, subjects_passed, subjects_failed,
                         attendance_present, attendance_absent, attendance_total,
                         status, generated_at, created_at, updated_at)
                     VALUES
                        (:report_number, :verification_code, :student_id, :class_id, :session_id, :term_id,
                         :total_score, :average_score, :position, :total_students,
                         :subjects_offered, :subjects_passed, :subjects_failed,
                         :present, :absent, :total_days,
                         \'generated\', NOW(), NOW(), NOW())'
                );

                /* The verification code is the public identity of a published
                   card, so it always uses the canonical ORM/RES/YYYY/NNNNNN form
                   that the checker, the QR code and the parent read out loud all
                   expect. The number is the next free one for the session; the
                   unique index on the column is the last line of defence, so a
                   collision takes the next number instead of failing the card. */
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $verificationCode = nextVerificationCode($pdo, $sessionId);

                    try {
                        $insert->execute($keys + $figures + [
                            ':report_number' => $reportNumber,
                            ':verification_code' => $verificationCode,
                        ]);

                        break;
                    } catch (PDOException $exception) {
                        if ($exception->getCode() !== '23000' || $attempt === 4) {
                            throw $exception;
                        }
                    }
                }

                $newId = (int) $pdo->lastInsertId();

                logReportCardHistory($pdo, $newId, 'generated', null, 'generated');

                return $newId;
            }

            $reportId  = (int) $existing['id'];
            $oldStatus = (string) $existing['status'];

            /* Regenerating an approved or generated card sends it back to
               generated, because the figures just changed and the earlier
               approval no longer describes them. */
            $update = $pdo->prepare(
                'UPDATE report_cards
                    SET total_score = :total_score,
                        average_score = :average_score,
                        position = :position,
                        total_students = :total_students,
                        subjects_offered = :subjects_offered,
                        subjects_passed = :subjects_passed,
                        subjects_failed = :subjects_failed,
                        attendance_present = :present,
                        attendance_absent = :absent,
                        attendance_total = :total_days,
                        status = \'generated\',
                        approved_at = NULL,
                        approved_by = NULL,
                        generated_at = NOW(),
                        updated_at = NOW()
                  WHERE id = :id'
            );

            $update->execute($figures + [':id' => $reportId]);

            logReportCardHistory($pdo, $reportId, 'generated', $oldStatus, 'generated');

            return $reportId;
        });
    } catch (RuntimeException $exception) {
        $failure['message'] = $exception->getMessage();

        return $failure;
    } catch (Throwable $exception) {
        error_log('[PHASE5] Unable to generate report card: ' . $exception->getMessage());
        $failure['message'] = 'The report card could not be generated. Please try again.';

        return $failure;
    }

    logAudit('report_card_generated', $reportId, 'report_card');

    return [
        'ok'      => true,
        'id'      => $reportId,
        'message' => 'Report card generated.',
        'missing' => [],
    ];
}

/* ------------------------------------------------------------------ */
/* Administrator workflow                                               */
/* ------------------------------------------------------------------ */

/**
 * Apply an administrator decision to one report card.
 *
 *   generated -> approved -> locked
 *   locked    -> approved            (unlock, a reason is required)
 *
 * A draft cannot be approved, because a draft has not been generated from
 * verified results yet.
 *
 * @return array{ok: bool, message: string, status: ?string}
 */
function applyReportCardAction(int $reportId, string $action, string $reason = ''): array
{
    $allowed = ['approve' => 'approved', 'lock' => 'locked', 'unlock' => 'approved'];

    /* Only an administrator may change the state of an official document. The
       calling page requires an administrator too, but the rule is enforced here
       as well so it cannot be bypassed. */
    if (!hasRole('admin')) {
        return [
            'ok'      => false,
            'message' => 'Only an administrator can approve, lock or unlock a report card.',
            'status'  => null,
        ];
    }

    if (!isset($allowed[$action])) {
        return ['ok' => false, 'message' => 'That action is not available.', 'status' => null];
    }

    $report = findReportCard($reportId);

    if ($report === null) {
        return ['ok' => false, 'message' => 'That report card no longer exists.', 'status' => null];
    }

    $from = (string) $report['status'];
    $to   = $allowed[$action];

    $permitted = [
        'approve' => ['generated'],
        'lock'    => ['approved'],
        'unlock'  => ['locked'],
    ][$action];

    if (!in_array($from, $permitted, true)) {
        return [
            'ok'      => false,
            'message' => 'A ' . $from . ' report card cannot be ' . $action . 'd.',
            'status'  => $from,
        ];
    }

    $reason = trim($reason);

    /* An unlock with no reason leaves the administrator no record of why a
       released document was changed, so it is refused. */
    if ($action === 'unlock' && $reason === '') {
        return [
            'ok'      => false,
            'message' => 'A reason is required to unlock this report card.',
            'status'  => $from,
        ];
    }

    $reason = $reason === '' ? null : substr($reason, 0, 255);
    $adminId = currentUserId();

    try {
        dbTransaction(function (PDO $pdo) use ($reportId, $action, $from, $to, $reason, $adminId) {
            if ($action === 'approve') {
                $stmt = $pdo->prepare(
                    "UPDATE report_cards
                        SET status = 'approved',
                            approved_at = NOW(),
                            approved_by = :admin_id,
                            updated_at = NOW()
                      WHERE id = :id AND status = :from"
                );
                $stmt->execute([':admin_id' => $adminId, ':id' => $reportId, ':from' => $from]);
            } elseif ($action === 'lock') {
                $stmt = $pdo->prepare(
                    "UPDATE report_cards
                        SET status = 'locked',
                            locked_at = NOW(),
                            locked_by = :admin_id,
                            updated_at = NOW()
                      WHERE id = :id AND status = :from"
                );
                $stmt->execute([':admin_id' => $adminId, ':id' => $reportId, ':from' => $from]);
            } else {
                /* Unlocking returns the card to approved, never to draft, so the
                   figures that were signed off stay signed off. */
                $stmt = $pdo->prepare(
                    "UPDATE report_cards
                        SET status = 'approved',
                            locked_at = NULL,
                            locked_by = NULL,
                            updated_at = NOW()
                      WHERE id = :id AND status = :from"
                );
                $stmt->execute([':id' => $reportId, ':from' => $from]);
            }

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('The report card was changed by someone else. Reload the page and try again.');
            }

            logReportCardHistory(
                $pdo,
                $reportId,
                $action === 'approve' ? 'approved' : ($action === 'lock' ? 'locked' : 'unlocked'),
                $from,
                $to,
                $reason,
                $adminId
            );
        });
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => $exception->getMessage(), 'status' => $from];
    }

    logAudit('report_card_' . $action . 'd', $reportId, 'report_card');

    $messages = [
        'approve' => 'Report card approved.',
        'lock'    => 'Report card locked. It is now read only.',
        'unlock'  => 'Report card unlocked and returned to approved.',
    ];

    return ['ok' => true, 'message' => $messages[$action], 'status' => $to];
}

/**
 * Save the administrator's comments and promotion decision.
 *
 * The calculated figures are not touched by this function at all, so there is
 * no code path anywhere in the portal that writes a total, an average or a
 * position by hand.
 *
 * @return array{ok: bool, message: string}
 */
function saveReportCardComments(int $reportId, array $input): array
{
    $report = findReportCard($reportId);

    if ($report === null) {
        return ['ok' => false, 'message' => 'That report card no longer exists.'];
    }

    if ((string) $report['status'] === 'locked') {
        return [
            'ok'      => false,
            'message' => 'This report card is locked, so its comments and promotion status cannot be changed. Unlock it first.',
        ];
    }

    $teacherComment = trim((string) ($input['teacher_comment'] ?? ''));
    $principalComment = trim((string) ($input['principal_comment'] ?? ''));
    $promotion = trim((string) ($input['promotion_status'] ?? ''));

    if ($promotion === '') {
        $promotion = 'Pending';
    }

    if (!in_array($promotion, promotionStatuses(), true)) {
        return ['ok' => false, 'message' => 'That promotion status is not one of the available options.'];
    }

    if ($teacherComment === '' && $principalComment === '') {
        return ['ok' => false, 'message' => 'Enter at least one comment before saving.'];
    }

    try {
        dbTransaction(function (PDO $pdo) use ($reportId, $report, $teacherComment, $principalComment, $promotion) {
            $oldStatus = (string) $report['status'];

            $stmt = $pdo->prepare(
                'UPDATE report_cards
                    SET teacher_comment = :teacher_comment,
                        principal_comment = :principal_comment,
                        promotion_status = :promotion_status,
                        updated_at = NOW()
                  WHERE id = :id AND status <> \'locked\''
            );

            $stmt->execute([
                ':teacher_comment'   => $teacherComment === '' ? null : substr($teacherComment, 0, 500),
                ':principal_comment' => $principalComment === '' ? null : substr($principalComment, 0, 500),
                ':promotion_status'  => $promotion,
                ':id'                => $reportId,
            ]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('This report card is locked, so nothing was saved.');
            }

            logReportCardHistory($pdo, $reportId, 'updated', $oldStatus, $oldStatus);
        });
    } catch (Throwable $exception) {
        error_log('[PHASE5] Unable to save report card comments: ' . $exception->getMessage());

        return ['ok' => false, 'message' => $exception->getMessage()];
    }

    logAudit('report_card_comments_saved', $reportId, 'report_card');

    return ['ok' => true, 'message' => 'Comments and promotion status saved.'];
}

/* ------------------------------------------------------------------ */
/* Listing and dashboard counts                                         */
/* ------------------------------------------------------------------ */

/**
 * Search and filter the report card list.
 *
 * @return array{rows: array, paging: array}
 */
function listReportCards(array $filters): array
{
    $where  = [];
    $params = [];

    if (!empty($filters['session_id'])) {
        $where[]              = 'rc.session_id = :session_id';
        $params[':session_id'] = (int) $filters['session_id'];
    }

    if (!empty($filters['term_id'])) {
        $where[]           = 'rc.term_id = :term_id';
        $params[':term_id'] = (int) $filters['term_id'];
    }

    if (!empty($filters['class_id'])) {
        $where[]            = 'rc.class_id = :class_id';
        $params[':class_id'] = (int) $filters['class_id'];
    }

    if (!empty($filters['student_id'])) {
        $where[]               = 'rc.student_id = :student_id';
        $params[':student_id'] = (int) $filters['student_id'];
    }

    if (!empty($filters['status']) && in_array($filters['status'], reportCardStatuses(), true)) {
        $where[]           = 'rc.status = :status';
        $params[':status'] = (string) $filters['status'];
    }

    if (!empty($filters['section']) && in_array($filters['section'], ['Nursery', 'Primary', 'Secondary'], true)) {
        $where[]            = 'c.section = :section';
        $params[':section'] = (string) $filters['section'];
    }

    /* Restricting the list to a teacher is done by class, never by a teacher id
       on the report card itself, so a hand edited filter cannot widen access.

       The key is tested with array_key_exists rather than !empty on purpose: an
       EMPTY array means "no classes at all" and must return nothing. Treating it
       as "no filter" would hand a teacher who has just lost every allocation a
       listing of the whole portal. */
    if (array_key_exists('class_ids', $filters) && is_array($filters['class_ids'])) {
        $classIds = array_values(array_unique(array_map('intval', $filters['class_ids'])));
        $classIds = array_filter($classIds, static function (int $id): bool {
            return $id > 0;
        });

        if ($classIds === []) {
            /* An empty allow list means "no classes at all", which must return
               nothing rather than everything. */
            return ['rows' => [], 'paging' => paginate(0, (int) ($filters['per_page'] ?? DEFAULT_PER_PAGE), 1)];
        }

        $placeholders = [];

        foreach ($classIds as $index => $id) {
            $placeholders[]          = ':allowed_' . $index;
            $params[':allowed_' . $index] = $id;
        }

        $where[] = 'rc.class_id IN (' . implode(', ', $placeholders) . ')';
    }

    $search = trim((string) ($filters['search'] ?? ''));

    if ($search !== '') {
        /* One placeholder per column: PDO is not in emulated mode, so a named
           placeholder may appear only once in a statement. */
        $columns = ['s.admission_number', 's.first_name', 's.last_name', 'c.class_name', 'rc.report_number'];
        $parts   = [];

        foreach ($columns as $index => $column) {
            $name              = ':search_' . $index;
            $params[$name]     = likeTerm($search);
            $parts[]           = $column . ' LIKE ' . $name;
        }

        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $joins = ' FROM report_cards rc
               JOIN students s ON s.id = rc.student_id
               JOIN classes c ON c.id = rc.class_id
               JOIN academic_sessions a ON a.id = rc.session_id
               JOIN terms t ON t.id = rc.term_id';

    $total   = (int) dbValue('SELECT COUNT(*)' . $joins . $clause, $params);
    $perPage = (int) ($filters['per_page'] ?? DEFAULT_PER_PAGE);
    $paging  = paginate($total, $perPage, (int) ($filters['page'] ?? 1));

    $rows = dbAll(
        'SELECT rc.*, s.admission_number, s.first_name, s.middle_name, s.last_name,
                c.class_name, c.section, a.session_name, t.term_name' . $joins . $clause
        . ' ORDER BY rc.class_id ASC, s.admission_number ASC, rc.id ASC
          LIMIT ' . (int) $paging['per_page'] . ' OFFSET ' . (int) $paging['offset'],
        $params
    );

    return ['rows' => $rows, 'paging' => $paging];
}

/** How many report cards sit in each workflow state. */
function reportCardStatusCounts(array $filters = []): array
{
    $counts = array_fill_keys(reportCardStatuses(), 0);

    $where  = [];
    $params = [];

    foreach (['session_id', 'term_id', 'class_id', 'student_id'] as $key) {
        if (!empty($filters[$key])) {
            $where[]            = 'rc.' . $key . ' = :' . $key;
            $params[':' . $key] = (int) $filters[$key];
        }
    }

    if (!empty($filters['section']) && in_array($filters['section'], ['Nursery', 'Primary', 'Secondary'], true)) {
        $where[]            = 'c.section = :section';
        $params[':section'] = (string) $filters['section'];
    }

    /* Same rule as in listReportCards(): an empty class allow list means "no
       classes", not "no filter", so it must report zero rather than everything. */
    if (array_key_exists('class_ids', $filters) && is_array($filters['class_ids'])) {
        $classIds = array_values(array_unique(array_map('intval', $filters['class_ids'])));
        $classIds = array_filter($classIds, static function (int $id): bool {
            return $id > 0;
        });

        if ($classIds === []) {
            return $counts + ['total' => 0];
        }

        $placeholders = [];

        foreach ($classIds as $index => $id) {
            $placeholders[]              = ':allowed_' . $index;
            $params[':allowed_' . $index] = $id;
        }

        $where[] = 'rc.class_id IN (' . implode(', ', $placeholders) . ')';
    }

    $joins  = ' FROM report_cards rc JOIN classes c ON c.id = rc.class_id';
    $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    foreach (dbAll('SELECT rc.status AS status, COUNT(*) AS total' . $joins . $clause . ' GROUP BY rc.status', $params) as $row) {
        $status = (string) $row['status'];

        if (isset($counts[$status])) {
            $counts[$status] = (int) $row['total'];
        }
    }

    $counts['total'] = array_sum($counts);

    return $counts;
}

/* ------------------------------------------------------------------ */
/* Attendance                                                           */
/* ------------------------------------------------------------------ */

/**
 * Save the term attendance summary for one student.
 *
 * Present plus absent must equal the school days, so a term can never be shown
 * as a hundred and ten days out of a hundred. The arithmetic is checked here and
 * again by a CHECK constraint in the table.
 *
 * @return array{ok: bool, message: string}
 */
function saveAttendanceSummary(
    int $studentId,
    int $classId,
    int $sessionId,
    int $termId,
    int $schoolDays,
    int $daysPresent,
    int $daysAbsent
): array {
    $student = dbOne('SELECT * FROM students WHERE id = :id LIMIT 1', [':id' => $studentId]);
    $class   = dbOne('SELECT * FROM classes WHERE id = :id LIMIT 1', [':id' => $classId]);
    $term    = dbOne('SELECT * FROM terms WHERE id = :id LIMIT 1', [':id' => $termId]);

    if ($student === null || $class === null) {
        return ['ok' => false, 'message' => 'That student or class no longer exists.'];
    }

    if ($term === null || (int) $term['session_id'] !== $sessionId) {
        return ['ok' => false, 'message' => 'That term does not belong to the selected academic session.'];
    }

    $enrolled = dbOne(
        "SELECT id FROM student_class_enrollments
          WHERE student_id = :student_id
            AND class_id = :class_id
            AND session_id = :session_id
            AND status = 'Active'
          LIMIT 1",
        [
            ':student_id' => $studentId,
            ':class_id'   => $classId,
            ':session_id' => $sessionId,
        ]
    );

    if ($enrolled === null) {
        return [
            'ok'      => false,
            'message' => studentFullName($student) . ' is not enrolled in this class for the selected session.',
        ];
    }

    if ($schoolDays <= 0) {
        return ['ok' => false, 'message' => 'The number of school days must be at least 1.'];
    }

    if ($daysPresent < 0 || $daysAbsent < 0) {
        return ['ok' => false, 'message' => 'Days present and days absent cannot be negative.'];
    }

    if ($daysPresent > $schoolDays) {
        return ['ok' => false, 'message' => 'Days present cannot be more than the number of school days.'];
    }

    if ($daysAbsent > $schoolDays) {
        return ['ok' => false, 'message' => 'Days absent cannot be more than the number of school days.'];
    }

    if ($daysPresent + $daysAbsent !== $schoolDays) {
        return [
            'ok'      => false,
            'message' => 'Days present (' . $daysPresent . ') plus days absent (' . $daysAbsent
                . ') must equal the school days (' . $schoolDays . ').',
        ];
    }

    /* A locked report card carries printed attendance figures, so its attendance
       must not be changed underneath it. */
    $locked = dbOne(
        "SELECT id FROM report_cards
          WHERE student_id = :student_id
            AND class_id = :class_id
            AND session_id = :session_id
            AND term_id = :term_id
            AND status = 'locked'
          LIMIT 1",
        [
            ':student_id' => $studentId,
            ':class_id'   => $classId,
            ':session_id' => $sessionId,
            ':term_id'    => $termId,
        ]
    );

    if ($locked !== null) {
        return [
            'ok'      => false,
            'message' => 'This student\'s report card is locked, so the attendance on it cannot be changed.',
        ];
    }

    try {
        $stmt = getDbConnection()->prepare(
            'INSERT INTO attendance_summary
                (student_id, class_id, session_id, term_id, school_days, days_present, days_absent)
             VALUES
                (:student_id, :class_id, :session_id, :term_id, :school_days, :days_present, :days_absent)
             ON DUPLICATE KEY UPDATE
                school_days  = VALUES(school_days),
                days_present = VALUES(days_present),
                days_absent  = VALUES(days_absent),
                updated_at   = NOW()'
        );

        $stmt->execute([
            ':student_id'   => $studentId,
            ':class_id'     => $classId,
            ':session_id'   => $sessionId,
            ':term_id'      => $termId,
            ':school_days'  => $schoolDays,
            ':days_present' => $daysPresent,
            ':days_absent'  => $daysAbsent,
        ]);
    } catch (Throwable $exception) {
        error_log('[PHASE5] Unable to save attendance: ' . $exception->getMessage());

        return ['ok' => false, 'message' => 'The attendance could not be saved. Please try again.'];
    }

    return ['ok' => true, 'message' => 'Attendance saved.'];
}

/** The attendance rows for a whole class, in one query. */
function classAttendanceSummary(int $classId, int $sessionId, int $termId): array
{
    /* Enrollments are per session, not per term, so there is no term on the
       enrollment to join through: the term comes from the attendance row itself. */
    return dbAll(
        'SELECT ats.*, s.admission_number, s.first_name, s.middle_name, s.last_name
           FROM student_class_enrollments e
           JOIN students s ON s.id = e.student_id
           LEFT JOIN attendance_summary ats
                  ON ats.student_id = e.student_id
                 AND ats.class_id = e.class_id
                 AND ats.session_id = e.session_id
                 AND ats.term_id = :term_id
          WHERE e.class_id = :class_id
            AND e.session_id = :session_id
            AND e.status = \'Active\'
            AND s.status = \'Active\'
          ORDER BY s.admission_number',
        [':class_id' => $classId, ':session_id' => $sessionId, ':term_id' => $termId]
    );
}

/* ------------------------------------------------------------------ */
/* Psychomotor and effective areas                                      */
/* ------------------------------------------------------------------ */

/**
 * Save the psychomotor and effective area ratings for one student.
 *
 * Every rating is checked against the 1 to 5 scale before anything is written,
 * so a rating of 0 or 6 can never reach the database even if a form is edited by
 * hand. Both records are written in one transaction, so a report card never shows
 * psychomotor ratings without effective areas.
 *
 * @return array{ok: bool, message: string, errors: array<string, string>}
 */
function saveAssessments(
    int $studentId,
    int $classId,
    int $sessionId,
    int $termId,
    array $psychomotor,
    array $effective
): array {
    $student = dbOne('SELECT * FROM students WHERE id = :id LIMIT 1', [':id' => $studentId]);
    $class   = dbOne('SELECT * FROM classes WHERE id = :id LIMIT 1', [':id' => $classId]);
    $term    = dbOne('SELECT * FROM terms WHERE id = :id LIMIT 1', [':id' => $termId]);

    if ($student === null || $class === null) {
        return ['ok' => false, 'message' => 'That student or class no longer exists.', 'errors' => []];
    }

    if ($term === null || (int) $term['session_id'] !== $sessionId) {
        return [
            'ok'      => false,
            'message' => 'That term does not belong to the selected academic session.',
            'errors'  => [],
        ];
    }

    $enrolled = dbOne(
        "SELECT id FROM student_class_enrollments
          WHERE student_id = :student_id
            AND class_id = :class_id
            AND session_id = :session_id
            AND status = 'Active'
          LIMIT 1",
        [
            ':student_id' => $studentId,
            ':class_id'   => $classId,
            ':session_id' => $sessionId,
        ]
    );

    if ($enrolled === null) {
        return [
            'ok'      => false,
            'message' => studentFullName($student) . ' is not enrolled in this class for the selected session.',
            'errors'  => [],
        ];
    }

    $locked = dbOne(
        "SELECT id FROM report_cards
          WHERE student_id = :student_id
            AND class_id = :class_id
            AND session_id = :session_id
            AND term_id = :term_id
            AND status = 'locked'
          LIMIT 1",
        [
            ':student_id' => $studentId,
            ':class_id'   => $classId,
            ':session_id' => $sessionId,
            ':term_id'    => $termId,
        ]
    );

    if ($locked !== null) {
        return [
            'ok'      => false,
            'message' => 'This student\'s report card is locked, so the assessments on it cannot be changed.',
            'errors'  => [],
        ];
    }

    $errors = [];

    /* A missing box means "leave whatever is already stored", so a partially
       filled form updates the traits it touches without wiping the rest. */
    $cleanPsychomotor = [];
    $cleanEffective   = [];
    $anyValue         = false;

    foreach (psychomotorTraits() as $field => $label) {
        if (!array_key_exists($field, $psychomotor)) {
            continue;
        }

        $raw = $psychomotor[$field];

        if ($raw === '' || $raw === null) {
            continue;
        }

        if (!is_numeric($raw) || (float) $raw != (int) $raw || (int) $raw < 1 || (int) $raw > 5) {
            $errors[$field] = $label . ' must be a whole number from 1 to 5.';

            continue;
        }

        $cleanPsychomotor[$field] = (int) $raw;
        $anyValue                 = true;
    }

    foreach (effectiveAreaFields() as $field => $label) {
        if (!array_key_exists($field, $effective)) {
            continue;
        }

        $raw = $effective[$field];

        if ($raw === '' || $raw === null) {
            continue;
        }

        if (!is_numeric($raw) || (float) $raw != (int) $raw || (int) $raw < 1 || (int) $raw > 5) {
            $errors[$field] = $label . ' must be a whole number from 1 to 5.';

            continue;
        }

        $cleanEffective[$field] = (int) $raw;
        $anyValue              = true;
    }

    if ($errors !== []) {
        return [
            'ok'      => false,
            'message' => 'Some ratings were outside the 1 to 5 scale, so nothing was saved.',
            'errors'  => $errors,
        ];
    }

    if (!$anyValue) {
        return ['ok' => false, 'message' => 'No ratings were entered, so there was nothing to save.', 'errors' => []];
    }

    try {
        dbTransaction(function (PDO $pdo) use (
            $studentId,
            $classId,
            $sessionId,
            $termId,
            $cleanPsychomotor,
            $cleanEffective
        ) {
            foreach ([['psychomotor_assessments', $cleanPsychomotor], ['effective_areas', $cleanEffective]] as $pair) {
                [$table, $clean] = $pair;

                if ($clean === []) {
                    continue;
                }

                /* Positional placeholders, not named ones. A rating appears twice
                   in this statement - once in the INSERT and once in the ON
                   DUPLICATE KEY UPDATE - and PDO is in native mode, where a named
                   placeholder may only be bound once. Repeating a `?` and passing
                   the values twice is what MariaDB expects. */
                $columns      = array_keys($clean);
                $values       = array_values($clean);
                $insertMarks  = implode(', ', array_fill(0, count($columns), '?'));
                $assignments  = implode(', ', array_map(static function (string $column): string {
                    return $column . ' = ?';
                }, $columns));

                /* The table and column names come from the two fixed arrays above
                   and never from the request, so this cannot be an injection. */
                $pdo->prepare(
                    'INSERT INTO ' . $table
                    . ' (student_id, class_id, session_id, term_id, ' . implode(', ', $columns) . ')
                     VALUES (?, ?, ?, ?, ' . $insertMarks . ')
                     ON DUPLICATE KEY UPDATE ' . $assignments . ', updated_at = NOW()'
                )->execute(array_merge(
                    [$studentId, $classId, $sessionId, $termId],
                    $values,
                    $values
                ));
            }
        });
    } catch (Throwable $exception) {
        error_log('[PHASE5] Unable to save assessments: ' . $exception->getMessage());

        return [
            'ok'      => false,
            'message' => 'The assessments could not be saved. Please try again.',
            'errors'  => [],
        ];
    }

    return ['ok' => true, 'message' => 'Assessments saved.', 'errors' => []];
}

/* ------------------------------------------------------------------ */
/* Previous term and previous session performance                        */
/* ------------------------------------------------------------------ */

/**
 * The term immediately before the given one in the same academic session.
 *
 * `terms.term_name` is an enum of First, Second and Third Term, so ordering by
 * the column itself is the school order. Nothing is invented: a student in the
 * first term simply has no previous term, which is reported as null.
 */
function previousTermInSession(int $sessionId, int $termId): ?array
{
    $current = dbOne('SELECT * FROM terms WHERE id = :id AND session_id = :sid LIMIT 1', [
        ':id'  => $termId,
        ':sid' => $sessionId,
    ]);

    if ($current === null) {
        return null;
    }

    return dbOne(
        'SELECT * FROM terms
          WHERE session_id = :session_id
            AND term_name < :term_name
          ORDER BY term_name DESC
          LIMIT 1',
        [':session_id' => $sessionId, ':term_name' => (string) $current['term_name']]
    );
}

/**
 * The academic session immediately before the given one.
 *
 * Ordered by start_date, so the comparison follows the calendar rather than a
 * name that may have been typed differently.
 */
function previousAcademicSession(int $sessionId): ?array
{
    $current = dbOne('SELECT * FROM academic_sessions WHERE id = :id LIMIT 1', [':id' => $sessionId]);

    if ($current === null) {
        return null;
    }

    return dbOne(
        'SELECT * FROM academic_sessions
          WHERE start_date < :start_date
          ORDER BY start_date DESC
          LIMIT 1',
        [':start_date' => (string) $current['start_date']]
    );
}

/**
 * How one student performed in a given session and term, or null when there is
 * nothing to show.
 *
 * This is the same shape the current report card uses, so the historical panel
 * and the current panel are calculated by one set of rules. Only approved and
 * locked results count, exactly as they do now, so the historical figures can
 * never be based on marking that was never released.
 *
 * @return array{total: float, average: float, position: ?int, total_students: int, subjects: int}|null
 */
function studentPerformanceFor(int $studentId, int $sessionId, int $termId): ?array
{
    $summary = studentResultSummary($studentId, $sessionId, $termId);

    if ($summary['subjects'] === 0) {
        return null;
    }

    /* The class the student sat in for THAT session, taken from the historical
       enrollment rather than from the current students.class_id. */
    $classId = (int) dbValue(
        "SELECT class_id
           FROM student_class_enrollments
          WHERE student_id = :student_id
            AND session_id = :session_id
            AND status = 'Active'
          ORDER BY id DESC
          LIMIT 1",
        [':student_id' => $studentId, ':session_id' => $sessionId]
    );

    if ($classId === 0) {
        return null;
    }

    $positions = classResultPositions($classId, $sessionId, $termId);
    $entry     = $positions[$studentId] ?? null;

    return [
        'total'          => (float) $summary['overall_total'],
        'average'        => (float) $summary['average'],
        'position'       => $entry['position'] ?? null,
        'total_students' => rankedStudentCount($classId, $sessionId, $termId),
        'subjects'       => (int) $summary['subjects'],
    ];
}

/**
 * The previous term and previous session panels for a report card.
 *
 * Every field is null when the history does not exist. The report card prints
 * "N/A" for those, so a first term in the school's first year never shows a
 * fabricated zero or a figure lifted from the current term.
 */
function previousPerformanceFor(int $reportId): array
{
    $report = dbOne(
        'SELECT student_id, class_id, session_id, term_id FROM report_cards WHERE id = :id LIMIT 1',
        [':id' => $reportId]
    );

    if ($report === null) {
        return ['previous_term' => null, 'previous_session' => null];
    }

    $studentId = (int) $report['student_id'];
    $sessionId = (int) $report['session_id'];
    $termId    = (int) $report['term_id'];

    /* Previous term: the same session, the term before this one. */
    $previousTerm = previousTermInSession($sessionId, $termId);

    $previousTermData = null;

    if ($previousTerm !== null) {
        $previousTermData = studentPerformanceFor($studentId, $sessionId, (int) $previousTerm['id']);
    }

    /* Previous session: the session before this one, and the LAST term of it,
       because a session ends with its final term rather than its first. */
    $previousSession = previousAcademicSession($sessionId);
    $previousSessionData = null;

    if ($previousSession !== null) {
        $lastTerm = dbOne(
            'SELECT * FROM terms
              WHERE session_id = :session_id AND status <> \'Inactive\'
              ORDER BY term_name DESC
              LIMIT 1',
            [':session_id' => (int) $previousSession['id']]
        );

        if ($lastTerm !== null) {
            $previousSessionData = studentPerformanceFor(
                $studentId,
                (int) $previousSession['id'],
                (int) $lastTerm['id']
            );
        }
    }

    return [
        'previous_term'    => $previousTermData,
        'previous_session' => $previousSessionData,
    ];
}

/* ------------------------------------------------------------------ */
/* Teacher access                                                       */
/* ------------------------------------------------------------------ */

/**
 * The classes a teacher may read report cards for.
 *
 * A teacher sees the report cards of the students in the classes assigned to
 * them, never their own report card, and never a whole portal listing. The list
 * comes from teacher_class_assignments on every request, so removing an
 * assignment removes access immediately.
 */
function teacherReportCardClassIds(int $teacherId, ?int $sessionId = null): array
{
    if ($teacherId <= 0) {
        return [];
    }

    $sql = "SELECT DISTINCT tca.class_id
              FROM teacher_class_assignments tca
             WHERE tca.teacher_id = :teacher_id
               AND tca.status = 'Active'";

    $params = [':teacher_id' => $teacherId];

    if ($sessionId !== null) {
        $sql .= ' AND tca.session_id = :session_id';
        $params[':session_id'] = $sessionId;
    }

    return array_map('intval', array_column(dbAll($sql, $params), 'class_id'));
}

/**
 * The same scope as teacherReportCardClassIds(), with the labels the teacher's
 * filter dropdowns need.
 *
 * Only classes whose own status is still Active are listed, so a class that has
 * been archived does not linger in a filter that can only ever return nothing.
 */
function teacherReportCardScope(int $teacherId, ?int $sessionId = null): array
{
    if ($teacherId <= 0) {
        return [];
    }

    $sql = "SELECT DISTINCT tca.class_id, c.class_name, c.section,
                   tca.session_id, a.session_name, a.is_current AS session_is_current
              FROM teacher_class_assignments tca
              JOIN classes c ON c.id = tca.class_id
              JOIN academic_sessions a ON a.id = tca.session_id
             WHERE tca.teacher_id = :teacher_id
               AND tca.status = 'Active'
               AND c.status = 'Active'";

    $params = [':teacher_id' => $teacherId];

    if ($sessionId !== null) {
        $sql .= ' AND tca.session_id = :session_id';
        $params[':session_id'] = $sessionId;
    }

    $sql .= ' ORDER BY a.is_current DESC, a.session_name DESC, c.class_name ASC';

    return dbAll($sql, $params);
}

/** May this teacher read this report card? */
function teacherCanViewReportCard(int $teacherId, array $report): bool
{
    return in_array((int) $report['class_id'], teacherReportCardClassIds($teacherId), true);
}

/**
 * May this teacher add the class teacher's comment to this report card?
 *
 * The teacher must hold the class allocation for the session the card belongs to,
 * and the card must not be locked. Nothing else about a report card can be
 * changed by a teacher: not the total, the average, the position, the promotion
 * status, and not the approval state.
 */
function teacherCanCommentOnReportCard(int $teacherId, array $report): bool
{
    if ((string) $report['status'] === 'locked') {
        return false;
    }

    $found = dbValue(
        "SELECT COUNT(*)
           FROM teacher_class_assignments tca
          WHERE tca.teacher_id = :teacher_id
            AND tca.class_id = :class_id
            AND tca.session_id = :session_id
            AND tca.status = 'Active'",
        [
            ':teacher_id' => $teacherId,
            ':class_id'   => (int) $report['class_id'],
            ':session_id' => (int) $report['session_id'],
        ]
    );

    return (int) $found > 0;
}

/**
 * Save only the class teacher's comment on a report card.
 *
 * A teacher can reach exactly one column through this function. The promotion
 * status and the principal's comment are not parameters, so they cannot be
 * changed by a teacher even by posting extra fields.
 */
function saveTeacherReportComment(int $reportId, string $comment): array
{
    $profile = currentTeacherProfile();

    if ($profile === null) {
        return ['ok' => false, 'message' => 'No teacher record is linked to your account.'];
    }

    $report = findReportCard($reportId);

    if ($report === null) {
        return ['ok' => false, 'message' => 'That report card no longer exists.'];
    }

    if (!teacherCanCommentOnReportCard((int) $profile['id'], $report)) {
        return [
            'ok'      => false,
            'message' => 'You cannot add a comment to this report card. You are not the class teacher for it, '
                . 'or it is already locked.',
        ];
    }

    $comment = trim($comment);

    if ($comment === '') {
        return ['ok' => false, 'message' => 'Please type a comment before saving.'];
    }

    if (mb_strlen($comment) > 500) {
        return ['ok' => false, 'message' => 'The comment is too long. Please keep it under 500 characters.'];
    }

    try {
        $stmt = getDbConnection()->prepare(
            "UPDATE report_cards
                SET teacher_comment = :comment,
                    updated_at = NOW()
              WHERE id = :id AND status <> 'locked'"
        );

        $stmt->execute([':comment' => $comment, ':id' => $reportId]);
    } catch (Throwable $exception) {
        error_log('[PHASE5] Unable to save teacher comment: ' . $exception->getMessage());

        return ['ok' => false, 'message' => 'The comment could not be saved. Please try again.'];
    }

    logAudit('report_card_teacher_comment', $reportId, 'report_card');

    return ['ok' => true, 'message' => 'Your comment has been saved.'];
}

/* ================================================================== */
/* PHASE 6 - Public result checker, result PINs and result publication */
/* ================================================================== */

/**
 * Everything in this section serves one rule: a parent sees an official result,
 * and nothing else.
 *
 * The rules are not spread across the pages. They are all enforced here, so the
 * public form, the print sheet and the automated suites all go through exactly
 * the same gate and cannot drift apart:
 *
 *   1. A PIN is only ever stored as a password_hash(). The secret exists in the
 *      database as a hash that cannot be reversed, and on screen for exactly one
 *      page - the one immediately after it is generated.
 *   2. A PIN belongs to one student in one session. It can never open anybody
 *      else's result, and a wrong session is a failure like any other.
 *   3. Being approved is not enough. The school must publish the class and term
 *      deliberately, and may withdraw it again.
 *   4. Every attempt is counted, logged and rate limited, by PIN and by IP.
 *   5. Nothing on the public pages writes to a report card. There is no form on
 *      the result page that can reach the database at all.
 *
 * Failures never say which part was wrong. "Invalid result details." is the only
 * message a visitor gets, because "the admission number is right but the PIN is
 * not" is a free oracle for guessing the rest.
 */

/** Phase 6 vocabulary. */
const RESULT_PIN_STATUSES = ['active', 'used', 'expired', 'blocked', 'revoked'];
const RESULT_PUBLICATION_STATUSES = ['unpublished', 'published'];

/** Failed attempts the school may allow per PIN before it is blocked. */
const RESULT_PIN_ATTEMPT_CHOICES = [3, 5, 8, 10];

/** Default ceiling when the generation form does not say otherwise. */
const RESULT_PIN_DEFAULT_MAX_ATTEMPTS = 5;

/** How long a public result stays open after a successful check, in seconds. */
const PUBLIC_RESULT_SESSION_SECONDS = 1800;

/**
 * Network throttle. Deliberately generous: a school is usually behind one NAT,
 * so this must not lock a whole morning's parents out because one visitor fat
 * fingered a PIN. The per PIN counter is the sharp instrument; this is only the
 * safety net against a scripted sweep.
 */
const PUBLIC_RESULT_IP_FAILURE_LIMIT = 30;
const PUBLIC_RESULT_IP_FAILURE_WINDOW = 900;

/**
 * Fixed machine codes for the access log. Never visitor input.
 *
 * The wording is the exact text a visitor is shown, so the log, the screen and
 * the automated suites cannot drift apart. The first one is deliberately blunt:
 * "Invalid result details." says nothing about which of the four boxes was wrong,
 * because naming the field that was right is a free oracle for guessing the rest.
 */
const RESULT_ACCESS_FAILURE_REASONS = [
    'invalid_details' => 'Invalid result details.',
    'not_published'   => 'Results for this class and term have not been published yet.',
    'not_approved'    => 'This result has not been released by the school yet.',
    'no_report'       => 'This result is not available for the selected session and term.',
];

/* ------------------------------------------------------------------ */
/* Result PIN status vocabulary                                       */
/* ------------------------------------------------------------------ */

/** Every PIN status, in the order the admin list and the dashboard show them. */
function resultPinStatuses(): array
{
    return RESULT_PIN_STATUSES;
}

/** Every publication status. */
function resultPublicationStatuses(): array
{
    return RESULT_PUBLICATION_STATUSES;
}

/** The attempt ceilings offered on the generation form. */
function resultPinAttemptChoices(): array
{
    return RESULT_PIN_ATTEMPT_CHOICES;
}

function resultPinBadgeClass(string $status): string
{
    return [
        'active'  => 'bg-success',
        'used'    => 'bg-primary',
        'expired' => 'bg-secondary',
        'blocked' => 'bg-danger',
        'revoked' => 'bg-dark',
    ][$status] ?? 'bg-secondary';
}

/** Human label for a PIN status. */
function resultPinStatusLabel(string $status): string
{
    return [
        'active'  => 'Active',
        'used'    => 'Used',
        'expired' => 'Expired',
        'blocked' => 'Blocked',
        'revoked' => 'Revoked',
    ][$status] ?? ucfirst($status);
}

function resultPublicationBadgeClass(string $status): string
{
    return $status === 'published' ? 'bg-success' : 'bg-secondary';
}

/* ------------------------------------------------------------------ */
/* PIN values and references                                           */
/* ------------------------------------------------------------------ */

/**
 * A cryptographically secure PIN in the shape XXXX-XXXX.
 *
 * random_int() is used rather than rand()/mt_rand() because both of those are
 * Mersenne Twister generators: their internal state can be reconstructed from a
 * short run of their own output, which means a school that issued its PINs with
 * them could have every PIN predicted by anybody who saw a handful.
 *
 * The first digit is never 0 so the value always reads as a clean 8 digit number,
 * which still leaves 90,000,000 possible PINs against a 5 attempt ceiling.
 */
function generateResultPinValue(): string
{
    $value = random_int(10000000, 99999999);

    return substr((string) $value, 0, 4) . '-' . substr((string) $value, 4, 4);
}

/**
 * Normalise a PIN a visitor typed.
 *
 * People copy these off a slip of paper, so spaces, dots and a missing or extra
 * hyphen are all accepted and stripped before the hash is checked. The stored
 * hash is of the canonical 8 digit value with no separator, so the same PIN
 * always verifies however it was typed.
 */
function normaliseResultPin(string $pin): string
{
    return (string) preg_replace('/\D+/', '', $pin);
}

/** The year a PIN reference is issued in, from the server clock. */
function resultPinReferenceYear(): string
{
    return date('Y');
}

/**
 * The next free PIN reference for the current year.
 *
 * PIN-2026-000123. The reference carries no secret information whatsoever: it is
 * a year and a counter, so quoting it on the phone identifies a PIN without
 * revealing it. The UNIQUE index on pin_reference is the real guarantee, and
 * the caller retries if two administrators issue at the same moment.
 */
function nextResultPinReference(PDO $pdo): string
{
    $prefix = 'PIN-' . resultPinReferenceYear() . '-';

    $highest = (int) $pdo->query(
        'SELECT COALESCE(MAX(CAST(RIGHT(pin_reference, 6) AS UNSIGNED)), 0)
           FROM result_pins
          WHERE pin_reference LIKE ' . $pdo->quote($prefix . '%')
    )->fetchColumn();

    return sprintf('%s%06d', $prefix, $highest + 1);
}

/* ------------------------------------------------------------------ */
/* Result verification codes                                           */
/* ------------------------------------------------------------------ */

/**
 * The public verification prefix for a session.
 *
 * "2026/2027" becomes ORM/RES/2026, so a code reads ORM/RES/2026/000123. The
 * year comes from the session's own start_date in the database, never from the
 * browser.
 */
function verificationCodePrefix(int $sessionId): string
{
    $start = dbValue(
        'SELECT start_date FROM academic_sessions WHERE id = :id LIMIT 1',
        [':id' => $sessionId]
    );

    $year = is_string($start) && $start !== '' ? substr($start, 0, 4) : date('Y');

    if (!ctype_digit($year)) {
        $year = date('Y');
    }

    return 'ORM/RES/' . $year;
}

/** The next free verification code for a session. */
function nextVerificationCode(PDO $pdo, int $sessionId): string
{
    $prefix = verificationCodePrefix($sessionId);

    $highest = (int) $pdo->query(
        'SELECT COALESCE(MAX(CAST(RIGHT(verification_code, 6) AS UNSIGNED)), 0)
           FROM report_cards
          WHERE verification_code LIKE ' . $pdo->quote($prefix . '/%')
    )->fetchColumn();

    return sprintf('%s/%06d', $prefix, $highest + 1);
}

/**
 * Make sure a report card carries its verification code, and return it.
 *
 * The column is added to report_cards as nullable, so cards that existed before
 * Phase 6 have none. Rather than invent a default for them in the migration -
 * which would have had to guess a session per row - the code is assigned the
 * first time the card is shown publicly or on the admin screen, and is then
 * fixed for good. The UNIQUE index on the column is the real guarantee; this
 * retries around it so two administrators opening two cards at the same moment
 * cannot collide.
 */
function ensureReportCardVerificationCode(int $reportId): string
{
    $existing = dbValue(
        'SELECT verification_code FROM report_cards WHERE id = :id LIMIT 1',
        [':id' => $reportId]
    );

    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    $sessionId = (int) dbValue(
        'SELECT session_id FROM report_cards WHERE id = :id LIMIT 1',
        [':id' => $reportId]
    );

    if ($sessionId === 0) {
        return '';
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = nextVerificationCode(getDbConnection(), $sessionId);

        try {
            $stmt = getDbConnection()->prepare(
                'UPDATE report_cards
                    SET verification_code = :code
                  WHERE id = :id AND verification_code IS NULL'
            );
            $stmt->execute([':code' => $code, ':id' => $reportId]);
        } catch (Throwable $exception) {
            error_log('[PHASE6] Unable to set a verification code: ' . $exception->getMessage());

            return '';
        }

        /* Only claim success when this call is the one that wrote the column. */
        $stored = dbValue(
            'SELECT verification_code FROM report_cards WHERE id = :id LIMIT 1',
            [':id' => $reportId]
        );

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
    }

    return '';
}

/* ------------------------------------------------------------------ */
/* Issuing a PIN                                                       */
/* ------------------------------------------------------------------ */

/**
 * Check that a class and term actually have report cards ready to hand out.
 *
 * This is what keeps a PIN from being issued for a result that cannot be shown.
 * A PIN is only worth anything if there is an approved report behind it, so this
 * returns both the ready students and the ones that must be skipped, and the
 * bulk generator reports the skips rather than quietly dropping them.
 *
 * @return array{ready: array<int,int>, skipped: array<int,array{id:int,name:string,reason:string}>, total: int}
 */
function resultPinEligibleStudents(int $classId, int $sessionId, int $termId): array
{
    $rows = dbAll(
        "SELECT s.id, s.first_name, s.middle_name, s.last_name,
                rc.id AS report_id, rc.status AS report_status
           FROM report_cards rc
           JOIN students s ON s.id = rc.student_id
          WHERE rc.class_id = :class_id
            AND rc.session_id = :session_id
            AND rc.term_id = :term_id
            AND rc.status IN ('approved', 'locked')
          ORDER BY s.first_name, s.last_name",
        [':class_id' => $classId, ':session_id' => $sessionId, ':term_id' => $termId]
    );

    /* Every student enrolled in the class, so the screen can show who is being
       left out and why rather than only who is being included. */
    $enrolled = dbAll(
        "SELECT e.student_id, s.first_name, s.middle_name, s.last_name
           FROM student_class_enrollments e
           JOIN students s ON s.id = e.student_id
          WHERE e.class_id = :class_id
            AND e.session_id = :session_id
            AND e.status = 'Active'
            AND s.status = 'Active'
          ORDER BY s.first_name, s.last_name",
        [':class_id' => $classId, ':session_id' => $sessionId]
    );

    $ready    = [];
    $skipped  = [];
    $withCard = [];

    foreach ($rows as $row) {
        $ready[] = (int) $row['id'];
        $withCard[(int) $row['id']] = $row;
    }

    foreach ($enrolled as $row) {
        $studentId = (int) $row['student_id'];

        if (isset($withCard[$studentId])) {
            continue;
        }

        $skipped[] = [
            'id'     => $studentId,
            'name'   => studentFullName($row),
            'reason' => 'no approved report card for this term',
        ];
    }

    return ['ready' => $ready, 'skipped' => $skipped, 'total' => count($ready)];
}

/**
 * Validate the PIN generation form.
 *
 * Only an administrator may reach this, and that is checked here as well as in
 * the page, so the rule cannot be bypassed by calling the function directly.
 *
 * $requireStudent is false for the whole-class mode, where the students come from
 * the class rather than the form. Everything else - the session, the expiry date
 * and the attempt ceiling - is validated exactly the same way, so the two modes
 * can never disagree about what a valid PIN is.
 *
 * @return array{valid: bool, errors: array<string,string>, value: array<string,mixed>}
 */
function validateResultPinInput(array $input, ?int $ignoreId = null, bool $requireStudent = true): array
{
    $errors = [];

    $studentId = isset($input['student_id']) ? (int) $input['student_id'] : 0;
    $sessionId = isset($input['session_id']) ? (int) $input['session_id'] : 0;

    $expiresAt = trim((string) ($input['expires_at'] ?? ''));
    $maxAttempts = isset($input['max_attempts']) ? (int) $input['max_attempts'] : RESULT_PIN_DEFAULT_MAX_ATTEMPTS;
    $singleUse = !empty($input['single_use']) ? 1 : 0;

    /* What this function returns already carries the closing time on the date,
       and generateResultPin() validates whatever it is handed. A page that has
       run this check once and passes the result straight back in therefore hands
       over "2026-11-16 23:59:59" rather than "2026-11-16", which isValidDate()
       rejects. Both exact shapes are accepted here, and the result is always the
       full form, so checking an already checked value is a no-op instead of a
       failure. Anything else - trailing rubbish included - is still refused. */
    $expiresDate = '';
    $expiresStamp = '';

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt) === 1) {
        $expiresDate = $expiresAt;
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiresAt) === 1) {
        $expiresDate = substr($expiresAt, 0, 10);
        $expiresStamp = substr($expiresAt, 11);
    }

    if ($requireStudent) {
        if ($studentId <= 0) {
            $errors['student_id'] = 'Please choose a student.';
        } elseif (findStudent($studentId) === null) {
            $errors['student_id'] = 'That student no longer exists.';
        }
    }

    if ($sessionId <= 0) {
        $errors['session_id'] = 'Please choose an academic session.';
    } elseif (findSession($sessionId) === null) {
        $errors['session_id'] = 'That academic session no longer exists.';
    }

    if ($expiresAt === '') {
        $errors['expires_at'] = 'Please choose an expiry date.';
    } elseif ($expiresDate === '') {
        $errors['expires_at'] = 'Please enter a valid expiry date.';
    } elseif (!isValidDate($expiresDate, false)) {
        $errors['expires_at'] = 'Please enter a valid expiry date.';
    } elseif (strtotime($expiresDate . ' 23:59:59') < time()) {
        $errors['expires_at'] = 'The expiry date must be today or later.';
    }

    if (!in_array($maxAttempts, RESULT_PIN_ATTEMPT_CHOICES, true)) {
        $errors['max_attempts'] = 'Please choose how many failed attempts to allow.';
    }

    return [
        'valid'  => $errors === [],
        'errors' => $errors,
        'value'  => [
            'student_id'   => $studentId,
            'session_id'   => $sessionId,
            'expires_at'   => $expiresDate === '' ? null : $expiresDate . ($expiresStamp === '' ? ' 23:59:59' : ' ' . $expiresStamp),
            'max_attempts' => $maxAttempts,
            'single_use'   => $singleUse,
        ],
    ];
}

/**
 * Issue one PIN and return the secret exactly once.
 *
 * The returned 'pin' is the only moment the secret exists outside the visitor's
 * hands. It is hashed before it is written and is never recoverable afterwards,
 * which is why the caller shows it on a page that says so and offers a way to
 * replace it if it is lost.
 *
 * Issuing a new PIN for a student who already has one in that session retires
 * the old PIN first, so there is never more than one PIN that can open that
 * student's result. That mirrors the admin screen's "Generate New PIN" action.
 *
 * @return array{ok: bool, message: string, pin: string|null, reference: string|null, pin_id: int|null}
 */
function generateResultPin(array $input, ?int $createdBy = null): array
{
    if (!hasRole('admin')) {
        return [
            'ok' => false, 'message' => 'Only an administrator can issue a result PIN.',
            'pin' => null, 'reference' => null, 'pin_id' => null,
        ];
    }

    $checked = validateResultPinInput($input);
    $data    = $checked['value'];

    if (!$checked['valid']) {
        return [
            'ok' => false,
            'message' => implode(' ', array_values($checked['errors'])),
            'pin' => null, 'reference' => null, 'pin_id' => null,
        ];
    }

    $plain = generateResultPinValue();
    $store = normaliseResultPin($plain);

    /* The hash is made before anything is written, so a database failure can
       never leave a row whose PIN nobody knows. */
    $hash = password_hash($store, PASSWORD_DEFAULT);

    if (!is_string($hash) || $hash === '') {
        return [
            'ok' => false, 'message' => 'The result PIN could not be prepared. Please try again.',
            'pin' => null, 'reference' => null, 'pin_id' => null,
        ];
    }

    try {
        $pinId = dbTransaction(function (PDO $pdo) use ($data, $hash, $createdBy) {
            /* Retire whatever this student could already use, so a lost PIN can
               be replaced without leaving the old one working. */
            $stmt = $pdo->prepare(
                "UPDATE result_pins
                    SET status = 'revoked', updated_at = NOW()
                  WHERE student_id = :student_id
                    AND session_id = :session_id
                    AND status IN ('active', 'used', 'expired', 'blocked')"
            );
            $stmt->execute([
                ':student_id' => $data['student_id'],
                ':session_id' => $data['session_id'],
            ]);

            $reference = nextResultPinReference($pdo);

            $insert = $pdo->prepare(
                'INSERT INTO result_pins
                     (student_id, session_id, pin_hash, pin_reference, status, single_use,
                      expires_at, max_attempts, failed_attempts, created_by)
                 VALUES
                     (:student_id, :session_id, :pin_hash, :pin_reference, :status, :single_use,
                      :expires_at, :max_attempts, 0, :created_by)'
            );
            $insert->execute([
                ':student_id'    => $data['student_id'],
                ':session_id'    => $data['session_id'],
                ':pin_hash'      => $hash,
                ':pin_reference' => $reference,
                ':status'        => 'active',
                ':single_use'    => $data['single_use'],
                ':expires_at'    => $data['expires_at'],
                ':max_attempts'  => $data['max_attempts'],
                ':created_by'    => $createdBy ?? currentUserId(),
            ]);

            return (int) $pdo->lastInsertId();
        });
    } catch (Throwable $exception) {
        error_log('[PHASE6] Unable to issue a result PIN: ' . $exception->getMessage());

        return [
            'ok' => false, 'message' => 'The result PIN could not be saved. Please try again.',
            'pin' => null, 'reference' => null, 'pin_id' => null,
        ];
    }

    $reference = (string) dbValue(
        'SELECT pin_reference FROM result_pins WHERE id = :id LIMIT 1',
        [':id' => $pinId]
    );

    logAudit('result_pin_issued', $pinId, 'result_pin');

    return [
        'ok'        => true,
        'message'   => 'Result PIN issued.',
        'pin'       => $plain,
        'reference' => $reference,
        'pin_id'    => $pinId,
    ];
}

/**
 * Issue a PIN for every student in a class who already has an approved result.
 *
 * A student whose report is not ready is reported as skipped and gets no PIN:
 * handing out a PIN for a result nobody can see would only produce a failed
 * check at the front desk.
 *
 * @return array{issued: int, skipped: array<int,array{name:string,reason:string}>, pins: array<int,array{student:string,pin:string,reference:string}>}
 */
function generateClassResultPins(int $classId, int $sessionId, int $termId, array $input, ?int $createdBy = null): array
{
    if (!hasRole('admin')) {
        return ['issued' => 0, 'skipped' => [], 'pins' => []];
    }

    $eligible = resultPinEligibleStudents($classId, $sessionId, $termId);

    $issued = 0;
    $pins   = [];
    $failed = [];

    foreach ($eligible['ready'] as $studentId) {
        $result = generateResultPin(
            array_merge($input, ['student_id' => $studentId, 'session_id' => $sessionId]),
            $createdBy
        );

        if ($result['ok'] && $result['pin'] !== null) {
            $issued++;
            $pins[] = [
                'student'   => (string) findStudent($studentId)['admission_number'],
                'name'      => studentFullName((array) findStudent($studentId)),
                'pin'       => $result['pin'],
                'reference' => (string) $result['reference'],
            ];
            continue;
        }

        $failed[] = [
            'name'   => studentFullName((array) findStudent($studentId)),
            'reason' => $result['message'],
        ];
    }

    return [
        'issued'  => $issued,
        'skipped' => array_merge($eligible['skipped'], $failed),
        'pins'    => $pins,
    ];
}

/* ------------------------------------------------------------------ */
/* Reading issued PINs                                                 */
/* ------------------------------------------------------------------ */

/**
 * One PIN with the labels the admin list needs.
 *
 * `pin_hash` is deliberately not in the SELECT list. Nothing on any page ever
 * needs it, and a column that is never selected cannot be printed by accident -
 * the strongest guarantee available that the hash cannot reach a response.
 */
function findResultPin(int $id): ?array
{
    return dbOne(
        'SELECT rp.id, rp.student_id, rp.session_id, rp.pin_reference, rp.status,
                rp.single_use, rp.expires_at, rp.max_attempts, rp.failed_attempts,
                rp.last_used_at, rp.created_by, rp.created_at, rp.updated_at,
                s.admission_number, s.first_name, s.middle_name, s.last_name,
                a.session_name
           FROM result_pins rp
           JOIN students s ON s.id = rp.student_id
           JOIN academic_sessions a ON a.id = rp.session_id
          WHERE rp.id = :id
          LIMIT 1',
        [':id' => $id]
    );
}

/** The same row without the joins, for internal checks. */
function findResultPinRow(int $id): ?array
{
    return dbOne(
        'SELECT id, student_id, session_id, pin_hash, pin_reference, status, single_use,
                expires_at, max_attempts, failed_attempts, last_used_at, created_by,
                created_at, updated_at
           FROM result_pins
          WHERE id = :id
          LIMIT 1',
        [':id' => $id]
    );
}

/** Has this PIN passed its expiry date? */
function resultPinIsExpired(array $pin): bool
{
    $expiresAt = (string) ($pin['expires_at'] ?? '');

    if ($expiresAt === '') {
        return true;
    }

    return strtotime($expiresAt) < time();
}

/**
 * The status a PIN is in right now, which is not always the status stored.
 *
 * An active PIN whose expiry date has passed is expired, whatever the row says.
 * The date is the authority; the column is only a cache of it, so a portal that
 * was switched off over the holidays still refuses the PIN the moment it is
 * switched on again.
 */
function resultPinEffectiveStatus(array $pin): string
{
    $status = (string) ($pin['status'] ?? '');

    /* A PIN that was retired or blocked on purpose keeps that status, even after
       its expiry date: the administrator's decision outranks the clock. */
    if (in_array($status, ['revoked', 'blocked', 'used'], true)) {
        return $status;
    }

    if (resultPinIsExpired($pin)) {
        return 'expired';
    }

    return 'active';
}

/**
 * Persist the expiry of every PIN whose date has passed.
 *
 * Called from the admin screens so the list and the dashboard tell the truth
 * without a cron job, and lazily by verifyPublicResultAccess() so a PIN that is
 * never looked at cannot keep working.
 */
function sweepExpiredResultPins(): int
{
    try {
        $stmt = getDbConnection()->prepare(
            "UPDATE result_pins
                SET status = 'expired', updated_at = NOW()
              WHERE status = 'active'
                AND expires_at IS NOT NULL
                AND expires_at < NOW()"
        );
        $stmt->execute();

        return $stmt->rowCount();
    } catch (Throwable $exception) {
        error_log('[PHASE6] Unable to sweep expired PINs: ' . $exception->getMessage());

        return 0;
    }
}

/**
 * The admin PIN list, filtered and paginated.
 *
 * `pin_hash` is never selected, so no amount of filtering can put it on screen.
 */
function listResultPins(array $filters): array
{
    $search   = trim((string) ($filters['search'] ?? ''));
    $sessionId = isset($filters['session_id']) && $filters['session_id'] !== null
        ? (int) $filters['session_id']
        : null;
    $status   = (string) ($filters['status'] ?? '');
    $classId  = isset($filters['class_id']) && $filters['class_id'] !== null
        ? (int) $filters['class_id']
        : null;

    $perPage = resolvePerPage();
    $page    = (int) ($filters['page'] ?? 1);
    $page    = $page > 0 ? $page : 1;

    $where  = [];
    $params = [];

    if ($search !== '') {
        /* Admission number, PIN reference or student name. Native prepares reject a
           named placeholder used twice, so each column gets its own. */
        $term = '%' . likeTerm($search) . '%';

        $where[] = '(s.admission_number LIKE :search_admission
                     OR rp.pin_reference LIKE :search_reference
                     OR CONCAT(s.first_name, " ", s.last_name) LIKE :search_name)';
        $params[':search_admission'] = $term;
        $params[':search_reference'] = $term;
        $params[':search_name']      = $term;
    }

    if ($sessionId !== null) {
        $where[] = 'rp.session_id = :session_id';
        $params[':session_id'] = $sessionId;
    }

    if (in_array($status, RESULT_PIN_STATUSES, true)) {
        /* "expired" is partly a question about the clock, not just the column, so
           it has to be spelled out rather than compared. */
        if ($status === 'expired') {
            $where[] = "(rp.status = 'expired'
                         OR (rp.status = 'active' AND rp.expires_at < NOW()))";
        } else {
            $where[] = 'rp.status = :status';
            $params[':status'] = $status;
        }
    }

    if ($classId !== null) {
        $where[] = 'EXISTS (SELECT 1
                             FROM student_class_enrollments e
                            WHERE e.student_id = rp.student_id
                              AND e.session_id = rp.session_id
                              AND e.class_id = :class_id
                              AND e.status = \'Active\')';
        $params[':class_id'] = $classId;
    }

    $sql = 'FROM result_pins rp
              JOIN students s ON s.id = rp.student_id
              JOIN academic_sessions a ON a.id = rp.session_id';

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $total = (int) dbValue('SELECT COUNT(*) ' . $sql, $params);

    $paging = paginate($total, $perPage, $page);

    /* Ordering puts the PINs that need attention first, then the most recently
       issued. The status column is sorted against a computed expression so an
       expired PIN is grouped with the expired ones without being rewritten. */
    /* `student_id` and `session_id` are selected because admin/result-pins.php
       links each row to the student and groups by session. Leaving them out
       made every search that actually matched a row fail on an undefined key. */
    $listSql = 'SELECT rp.id, rp.student_id, rp.session_id, rp.pin_reference, rp.status,
                       rp.single_use, rp.expires_at,
                       rp.max_attempts, rp.failed_attempts, rp.last_used_at, rp.created_at,
                       s.admission_number, s.first_name, s.middle_name, s.last_name,
                       a.session_name
                  ' . $sql . "
                ORDER BY FIELD(
                            CASE
                                WHEN rp.status = 'revoked' THEN 1
                                WHEN rp.status = 'blocked' THEN 2
                                WHEN rp.status = 'expired'
                                  OR (rp.status = 'active' AND rp.expires_at < NOW()) THEN 3
                                WHEN rp.status = 'used' THEN 4
                                ELSE 5
                            END,
                            1, 2, 3, 4, 5
                         ),
                         rp.created_at DESC, rp.id DESC
                LIMIT :limit OFFSET :offset";

    $params[':limit']  = (int) $paging['per_page'];
    $params[':offset'] = (int) $paging['offset'];

    $rows = dbAll($listSql, $params);

    /* The stored status is normalised to the effective one for display, so a PIN
       whose date passed overnight is shown as Expired rather than Active. */
    foreach ($rows as $index => $row) {
        $rows[$index]['status'] = resultPinEffectiveStatus($row);
    }

    return ['rows' => $rows, 'paging' => $paging];
}

/** How many PINs sit in each status. */
function resultPinStatusCounts(array $filters = []): array
{
    $sessionId = isset($filters['session_id']) && $filters['session_id'] !== null
        ? (int) $filters['session_id']
        : null;

    $params  = [];
    $session = '';

    if ($sessionId !== null) {
        $session = ' WHERE session_id = :session_id';
        $params[':session_id'] = $sessionId;
    }

    $rows = dbAll(
        "SELECT status,
                SUM(CASE WHEN status = 'active' AND expires_at < NOW() THEN 1 ELSE 0 END) AS overdue,
                COUNT(*) AS total
           FROM result_pins" . $session . '
          GROUP BY status',
        $params
    );

    $counts = array_fill_keys(RESULT_PIN_STATUSES, 0);

    foreach ($rows as $row) {
        $status = (string) $row['status'];

        if (isset($counts[$status])) {
            $counts[$status] = (int) $row['total'];
        }
    }

    /* An active PIN that has passed its date belongs in the Expired column, not
       in Active, so the dashboard's number is what the school can act on. */
    foreach ($rows as $row) {
        if ((string) $row['status'] === 'active') {
            $counts['expired'] += (int) $row['overdue'];
            $counts['active'] -= (int) $row['overdue'];
        }
    }

    $counts['total'] = array_sum($counts);

    return $counts;
}

/* ------------------------------------------------------------------ */
/* Acting on a PIN                                                     */
/* ------------------------------------------------------------------ */

/**
 * Revoke, block, unblock or extend one PIN.
 *
 * Every action is administrator only, checked here as well as in the page, and
 * every action is written to the audit log with the reason the administrator
 * gave. Revoking is terminal by design: a revoked PIN is never revived by an
 * extension or an unblock, because "I revoked that number" has to mean the
 * number is dead.
 *
 * @return array{ok: bool, message: string, status: string|null}
 */
function applyResultPinAction(int $pinId, string $action, array $input = [], ?int $actorId = null): array
{
    if (!hasRole('admin')) {
        return ['ok' => false, 'message' => 'Only an administrator can manage result PINs.', 'status' => null];
    }

    $allowed = ['revoke', 'block', 'unblock', 'extend'];

    if (!in_array($action, $allowed, true)) {
        return ['ok' => false, 'message' => 'That action is not available.', 'status' => null];
    }

    $pin = findResultPinRow($pinId);

    if ($pin === null) {
        return ['ok' => false, 'message' => 'That result PIN no longer exists.', 'status' => null];
    }

    $reason = trim((string) ($input['reason'] ?? ''));
    $reason = $reason === '' ? null : substr($reason, 0, 255);

    try {
        switch ($action) {
            case 'revoke':
                dbRun(
                    "UPDATE result_pins
                        SET status = 'revoked', updated_at = NOW()
                      WHERE id = :id",
                    [':id' => $pinId]
                );
                $newStatus = 'revoked';
                $message   = 'The result PIN has been revoked. It can no longer open any result.';
                break;

            case 'block':
                dbRun(
                    "UPDATE result_pins
                        SET status = 'blocked', updated_at = NOW()
                      WHERE id = :id",
                    [':id' => $pinId]
                );
                $newStatus = 'blocked';
                $message   = 'The result PIN has been blocked. It can no longer open any result.';
                break;

            case 'unblock':
                /* A blocked PIN can only come back if it has not expired and was
                   not revoked, so unblocking cannot be used to smuggle a dead PIN
                   back into service. */
                if ((string) $pin['status'] === 'revoked') {
                    return [
                        'ok' => false,
                        'message' => 'A revoked PIN cannot be unblocked. Issue a new PIN instead.',
                        'status' => 'revoked',
                    ];
                }

                if (resultPinIsExpired($pin)) {
                    dbRun(
                        "UPDATE result_pins
                            SET status = 'expired', failed_attempts = 0, updated_at = NOW()
                          WHERE id = :id",
                        [':id' => $pinId]
                    );

                    return [
                        'ok' => false,
                        'message' => 'That PIN has already expired, so it cannot be unblocked. '
                            . 'Extend its expiry date first, or issue a new PIN.',
                        'status' => 'expired',
                    ];
                }

                dbRun(
                    "UPDATE result_pins
                        SET status = 'active', failed_attempts = 0, updated_at = NOW()
                      WHERE id = :id",
                    [':id' => $pinId]
                );
                $newStatus = 'active';
                $message   = 'The result PIN is active again and its failed attempts have been cleared.';
                break;

            case 'extend':
                $newExpiry = trim((string) ($input['expires_at'] ?? ''));

                if ($newExpiry === '' || !isValidDate($newExpiry, false)) {
                    return [
                        'ok' => false,
                        'message' => 'Please choose a valid expiry date.',
                        'status' => (string) $pin['status'],
                    ];
                }

                /* A dead PIN is never brought back to life by changing its date.
                   That applies to an expired one, which has to be unblocked on
                   purpose, and to a revoked one, which has to be replaced. If
                   either could be revived by extending it, "revoked" would stop
                   meaning anything. */
                if ((string) $pin['status'] === 'expired' || resultPinIsExpired($pin)) {
                    return [
                        'ok' => false,
                        'message' => 'This PIN has expired and will not be brought back to life by changing its date. '
                            . 'Issue a new PIN instead.',
                        'status' => 'expired',
                    ];
                }

                if ((string) $pin['status'] === 'revoked') {
                    return [
                        'ok' => false,
                        'message' => 'A revoked PIN cannot be extended. Issue a new PIN instead.',
                        'status' => 'revoked',
                    ];
                }

                dbRun(
                    'UPDATE result_pins
                        SET expires_at = :expires_at, updated_at = NOW()
                      WHERE id = :id',
                    [':expires_at' => $newExpiry . ' 23:59:59', ':id' => $pinId]
                );
                $newStatus = resultPinEffectiveStatus(array_merge($pin, [
                    'expires_at' => $newExpiry . ' 23:59:59',
                ]));
                $message   = 'The result PIN expiry date has been moved to ' . $newExpiry . '.';
                break;

            default:
                return ['ok' => false, 'message' => 'That action is not available.', 'status' => null];
        }
    } catch (Throwable $exception) {
        error_log('[PHASE6] Unable to act on result PIN ' . $pinId . ': ' . $exception->getMessage());

        return [
            'ok' => false,
            'message' => 'The change could not be saved. Please try again.',
            'status' => (string) $pin['status'],
        ];
    }

    logAudit('result_pin_' . $action . ($reason === null ? '' : '_with_reason'), $pinId, 'result_pin', $actorId);

    return ['ok' => true, 'message' => $message, 'status' => $newStatus];
}

/**
 * How many PINs a student currently has working in a session.
 *
 * Used by the admin screens to warn before issuing a second one.
 */
function activeResultPinFor(int $studentId, int $sessionId): ?array
{
    return dbOne(
        "SELECT id, pin_reference, status, expires_at
           FROM result_pins
          WHERE student_id = :student_id
            AND session_id = :session_id
            AND status = 'active'
            AND expires_at >= NOW()
          ORDER BY id DESC
          LIMIT 1",
        [':student_id' => $studentId, ':session_id' => $sessionId]
    );
}

/* ------------------------------------------------------------------ */
/* Result publication                                                  */
/* ------------------------------------------------------------------ */

/**
 * Whether a class and term has been published to the public.
 *
 * This is a single indexed lookup that every public page makes once per request.
 * A missing row and a row that says 'unpublished' are the same answer, which is
 * the safe default: a class nobody has published is not public.
 */
function isResultPublished(int $sessionId, int $termId, int $classId): bool
{
    $status = dbValue(
        'SELECT status FROM result_publication
          WHERE session_id = :session_id
            AND term_id = :term_id
            AND class_id = :class_id
          LIMIT 1',
        [':session_id' => $sessionId, ':term_id' => $termId, ':class_id' => $classId]
    );

    return (string) $status === 'published';
}

/**
 * The published status of a class and term, as 'published' or 'unpublished'.
 *
 * Used by the admin screen, which needs to show and change the state rather than
 * only ask about it.
 */
function resultPublicationStatus(int $sessionId, int $termId, int $classId): string
{
    $status = dbValue(
        'SELECT status FROM result_publication
          WHERE session_id = :session_id
            AND term_id = :term_id
            AND class_id = :class_id
          LIMIT 1',
        [':session_id' => $sessionId, ':term_id' => $termId, ':class_id' => $classId]
    );

    return in_array((string) $status, RESULT_PUBLICATION_STATUSES, true)
        ? (string) $status
        : 'unpublished';
}

/**
 * Work out whether a class and term is ready to be published.
 *
 * Publishing makes a result visible to the public, so this refuses anything
 * unfinished: every enrolled student's card has to exist and be approved or
 * locked first. A half finished class is exactly the thing that must never
 * reach a parent.
 *
 * @return array{ready: bool, message: string, total: int, approved: int, locked: int, pending: array<int,string>}
 */
function validateResultPublication(int $classId, int $sessionId, int $termId): array
{
    $totals = dbOne(
        "SELECT COUNT(*) AS enrolled,
                SUM(CASE WHEN rc.status = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN rc.status = 'locked'   THEN 1 ELSE 0 END) AS locked
           FROM student_class_enrollments e
           JOIN students s ON s.id = e.student_id
           LEFT JOIN report_cards rc
                  ON rc.student_id = e.student_id
                 AND rc.session_id = e.session_id
                 AND rc.term_id = :term_id
                 AND rc.class_id = e.class_id
          WHERE e.class_id = :class_id
            AND e.session_id = :session_id
            AND e.status = 'Active'
            AND s.status = 'Active'",
        [':class_id' => $classId, ':session_id' => $sessionId, ':term_id' => $termId]
    );

    $enrolled = (int) ($totals['enrolled'] ?? 0);
    $approved = (int) ($totals['approved'] ?? 0);
    $locked   = (int) ($totals['locked'] ?? 0);
    $settled  = $approved + $locked;

    $pending = [];

    if ($enrolled === 0) {
        return [
            'ready' => false,
            'message' => 'No student is enrolled in this class for the selected session.',
            'total' => 0, 'approved' => 0, 'locked' => 0, 'pending' => $pending,
        ];
    }

    if ($settled < $enrolled) {
        /* Name the students that are holding the class back, so the screen is
           actionable rather than just refusing. */
        $pendingRows = dbAll(
            "SELECT s.first_name, s.middle_name, s.last_name, s.admission_number,
                    rc.status AS report_status
               FROM student_class_enrollments e
               JOIN students s ON s.id = e.student_id
               LEFT JOIN report_cards rc
                      ON rc.student_id = e.student_id
                     AND rc.session_id = e.session_id
                      AND rc.term_id = :term_id
                      AND rc.class_id = e.class_id
              WHERE e.class_id = :class_id
                AND e.session_id = :session_id
                AND e.status = 'Active'
                AND s.status = 'Active'
                AND (rc.id IS NULL OR rc.status NOT IN ('approved', 'locked'))
              ORDER BY s.first_name, s.last_name
              LIMIT 50",
            [':class_id' => $classId, ':session_id' => $sessionId, ':term_id' => $termId]
        );

        foreach ($pendingRows as $row) {
            $pending[] = studentFullName($row)
                . ($row['report_status'] === null
                    ? ' (no report card)'
                    : ' (' . (string) $row['report_status'] . ')');
        }

        return [
            'ready' => false,
            'message' => sprintf(
                '%d of %d student(s) in this class still have a result that is not approved. '
                . 'Approve or lock every report card before publishing.',
                $enrolled - $settled,
                $enrolled
            ),
            'total' => $enrolled, 'approved' => $approved, 'locked' => $locked, 'pending' => $pending,
        ];
    }

    return [
        'ready'   => true,
        'message' => sprintf('All %d report card(s) in this class are approved or locked.', $enrolled),
        'total'   => $enrolled,
        'approved' => $approved,
        'locked'  => $locked,
        'pending' => $pending,
    ];
}

/**
 * Publish a class and term, or withdraw it again.
 *
 * Publishing re-runs validateResultPublication() at the moment of the change
 * rather than trusting what the screen showed, so a card that was taken back to
 * 'generated' in another browser cannot slip through. Withdrawing has no such
 * check: the school must always be able to pull a result back, even mid-term.
 *
 * @return array{ok: bool, message: string, status: string|null, check: array|null}
 */
function applyResultPublication(
    int $classId,
    int $sessionId,
    int $termId,
    string $action,
    ?int $actorId = null
): array {
    if (!hasRole('admin')) {
        return ['ok' => false, 'message' => 'Only an administrator can publish results.', 'status' => null, 'check' => null];
    }

    if (!in_array($action, ['publish', 'unpublish'], true)) {
        return ['ok' => false, 'message' => 'That action is not available.', 'status' => null, 'check' => null];
    }

    if ($classId <= 0 || findClass($classId) === null) {
        return ['ok' => false, 'message' => 'That class no longer exists.', 'status' => null, 'check' => null];
    }

    if ($sessionId <= 0 || findSession($sessionId) === null) {
        return ['ok' => false, 'message' => 'That academic session no longer exists.', 'status' => null, 'check' => null];
    }

    $term = findTerm($termId);

    if ($term === null || (int) $term['session_id'] !== $sessionId) {
        return [
            'ok' => false,
            'message' => 'That term does not belong to the selected academic session.',
            'status' => null,
            'check' => null,
        ];
    }

    $check = validateResultPublication($classId, $sessionId, $termId);

    if ($action === 'publish' && !$check['ready']) {
        return [
            'ok' => false,
            'message' => $check['message'],
            'status' => resultPublicationStatus($sessionId, $termId, $classId),
            'check' => $check,
        ];
    }

    $now   = $action === 'publish';
    $after = $now ? 'published' : 'unpublished';
    $actor = $actorId ?? currentUserId();

    try {
        /* One row per class, term and session, enforced by a UNIQUE index. The
           duplicate-key path is the normal case for an unpublish, because the row
           already exists from when it was published. */
        dbRun(
            'INSERT INTO result_publication
                 (session_id, term_id, class_id, status, published_at, published_by,
                  unpublished_at, unpublished_by)
             VALUES
                 (:session_id, :term_id, :class_id, :status, :now, :actor, :now2, :actor2)
             ON DUPLICATE KEY UPDATE
                 status = VALUES(status),
                 published_at = VALUES(published_at),
                 published_by = VALUES(published_by),
                 unpublished_at = VALUES(unpublished_at),
                 unpublished_by = VALUES(unpublished_by),
                 updated_at = NOW()',
            [
                ':session_id'     => $sessionId,
                ':term_id'        => $termId,
                ':class_id'       => $classId,
                ':status'         => $after,
                ':now'            => $now ? date('Y-m-d H:i:s') : null,
                ':actor'          => $now ? $actor : null,
                ':now2'           => $now ? null : date('Y-m-d H:i:s'),
                ':actor2'         => $now ? null : $actor,
            ]
        );
    } catch (Throwable $exception) {
        error_log('[PHASE6] Unable to change publication: ' . $exception->getMessage());

        return [
            'ok' => false,
            'message' => 'The publication change could not be saved. Please try again.',
            'status' => resultPublicationStatus($sessionId, $termId, $classId),
            'check' => $check,
        ];
    }

    logAudit('result_' . ($now ? 'published' : 'unpublished'), $classId, 'result_publication', $actor);

    return [
        'ok' => true,
        'message' => $now
            ? 'Results for this class and term are now published. Parents can check them with a result PIN.'
            : 'Results for this class and term are no longer published. The public result checker will not show them.',
        'status' => $after,
        'check' => $check,
    ];
}

/** The publication list, filtered by session, term, class and state. */
function listResultPublications(array $filters): array
{
    $sessionId = isset($filters['session_id']) && $filters['session_id'] !== null
        ? (int) $filters['session_id']
        : null;
    $termId = isset($filters['term_id']) && $filters['term_id'] !== null
        ? (int) $filters['term_id']
        : null;
    $classId = isset($filters['class_id']) && $filters['class_id'] !== null
        ? (int) $filters['class_id']
        : null;
    $status = (string) ($filters['status'] ?? '');

    $where  = [];
    $params = [];

    if ($sessionId !== null) {
        $where[] = 'rp.session_id = :session_id';
        $params[':session_id'] = $sessionId;
    }

    if ($termId !== null) {
        $where[] = 'rp.term_id = :term_id';
        $params[':term_id'] = $termId;
    }

    if ($classId !== null) {
        $where[] = 'rp.class_id = :class_id';
        $params[':class_id'] = $classId;
    }

    if (in_array($status, RESULT_PUBLICATION_STATUSES, true)) {
        $where[] = 'rp.status = :status';
        $params[':status'] = $status;
    }

    $sql = ' FROM result_publication rp
              JOIN academic_sessions a ON a.id = rp.session_id
              JOIN terms t ON t.id = rp.term_id
              JOIN classes c ON c.id = rp.class_id';

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $total = (int) dbValue('SELECT COUNT(*)' . $sql, $params);
    $perPage = resolvePerPage();
    $page = (int) ($filters['page'] ?? 1);
    $paging = paginate($total, $perPage, $page > 0 ? $page : 1);

    $params[':limit']  = (int) $paging['per_page'];
    $params[':offset'] = (int) $paging['offset'];

    $rows = dbAll(
        'SELECT rp.id, rp.session_id, rp.term_id, rp.class_id, rp.status,
                rp.published_at, rp.unpublished_at, rp.updated_at,
                a.session_name, t.term_name, c.class_name, c.section' . $sql . '
          ORDER BY rp.updated_at DESC, rp.id DESC
          LIMIT :limit OFFSET :offset',
        $params
    );

    /* How many settled report cards each publication actually covers, in one
       grouped query rather than one per row. */
    $counts = [];

    if ($rows !== []) {
        $seen = [];

        foreach ($rows as $row) {
            $seen[(int) $row['class_id'] . ':' . (int) $row['session_id'] . ':' . (int) $row['term_id']] = [
                (int) $row['class_id'], (int) $row['session_id'], (int) $row['term_id'],
            ];
        }

        /* One prepared statement with a fixed number of placeholders, built from
           the triples actually on this page - never an id from the query string. */
        $clauses = array_fill(0, count($seen), '(class_id = ? AND session_id = ? AND term_id = ?)');

        $stmt = getDbConnection()->prepare(
            "SELECT class_id, session_id, term_id, COUNT(*) AS total
               FROM report_cards
              WHERE status IN ('approved', 'locked')
                AND (" . implode(' OR ', $clauses) . ")
              GROUP BY class_id, session_id, term_id"
        );
        $stmt->execute(array_merge(...array_values($seen)));

        foreach ($stmt->fetchAll() as $group) {
            $counts[(int) $group['class_id'] . ':' . (int) $group['session_id'] . ':' . (int) $group['term_id']]
                = (int) $group['total'];
        }
    }

    foreach ($rows as $index => $row) {
        $key = (int) $row['class_id'] . ':' . (int) $row['session_id'] . ':' . (int) $row['term_id'];
        $rows[$index]['card_count'] = $counts[$key] ?? 0;
    }

    return ['rows' => $rows, 'paging' => $paging];
}

/** How many class and term combinations are published. */
function resultPublicationCounts(array $filters = []): array
{
    $params  = [];
    $session = '';

    $sessionId = isset($filters['session_id']) && $filters['session_id'] !== null
        ? (int) $filters['session_id']
        : null;

    if ($sessionId !== null) {
        $session = ' WHERE session_id = :session_id';
        $params[':session_id'] = $sessionId;
    }

    $rows = dbAll(
        'SELECT status, COUNT(*) AS total FROM result_publication' . $session . ' GROUP BY status',
        $params
    );

    $counts = ['published' => 0, 'unpublished' => 0];

    foreach ($rows as $row) {
        $status = (string) $row['status'];

        if (isset($counts[$status])) {
            $counts[$status] = (int) $row['total'];
        }
    }

    $counts['total'] = $counts['published'] + $counts['unpublished'];

    return $counts;
}

/**
 * The academic sessions the public result checker may offer.
 *
 * Only sessions with at least one published class and term appear. A session the
 * school has not published anything in has nothing a parent could usefully check,
 * and listing it would only tell a stranger which years this school runs.
 */
function publishedResultSessionOptions(): array
{
    return dbAll(
        "SELECT DISTINCT a.id, a.session_name, a.is_current
           FROM result_publication rp
           JOIN academic_sessions a ON a.id = rp.session_id
          WHERE rp.status = 'published'
          ORDER BY a.start_date DESC, a.id DESC"
    );
}

/**
 * The terms of one session that have something published.
 *
 * Only terms belonging to the given session, so the second dropdown can never
 * offer a term from a different year.
 */
function publishedResultTermOptions(int $sessionId): array
{
    return dbAll(
        "SELECT DISTINCT t.id, t.term_name, t.is_current
           FROM result_publication rp
           JOIN terms t ON t.id = rp.term_id
          WHERE rp.status = 'published'
            AND rp.session_id = :session_id
          ORDER BY t.term_name",
        [':session_id' => $sessionId]
    );
}

/** True when this session and term have any published class at all. */
function sessionTermHasPublishedResults(int $sessionId, int $termId): bool
{
    return (int) dbValue(
        "SELECT COUNT(*)
           FROM result_publication
          WHERE status = 'published'
            AND session_id = :session_id
            AND term_id = :term_id",
        [':session_id' => $sessionId, ':term_id' => $termId]
    ) > 0;
}

/**
 * Every published term, grouped by the session it belongs to.
 *
 * The public checker uses this to keep its two dropdowns in step without an
 * asynchronous round trip. One query fetches the whole map, the page embeds it,
 * and the browser filters it locally.
 *
 * Doing it this way rather than with AJAX is deliberate. A request per session
 * change would be one more public, unauthenticated endpoint whose only job is to
 * return a list, and every such endpoint is another thing to harden. The map
 * contains nothing but term ids and names that the visitor could already read off
 * the page they are already on.
 *
 * @return array<int, array<int, array{id:int, term_name:string}>>
 */
function publishedResultTermMap(): array
{
    $rows = dbAll(
        "SELECT DISTINCT rp.session_id, t.id, t.term_name
           FROM result_publication rp
           JOIN terms t ON t.id = rp.term_id
          WHERE rp.status = 'published'
          ORDER BY rp.session_id, t.term_name"
    );

    $map = [];

    foreach ($rows as $row) {
        $map[(int) $row['session_id']][] = [
            'id'        => (int) $row['id'],
            'term_name' => (string) $row['term_name'],
        ];
    }

    return $map;
}

/* ------------------------------------------------------------------ */
/* The public access log                                               */
/* ------------------------------------------------------------------ */

/**
 * Record one attempt at the public result checker.
 *
 * The PIN itself is never a parameter here, deliberately. Only its id, so a log
 * row can be traced back to a PIN without the log ever holding a secret, and
 * failure_reason comes from the fixed RESULT_ACCESS_FAILURE_REASONS set rather
 * than from visitor input, so nothing a visitor types can be stored.
 *
 * A logging failure is swallowed: losing an audit line must never turn a working
 * result check into an error page for a parent.
 */
function logResultAccess(
    ?int $pinId,
    ?int $studentId,
    ?int $sessionId,
    ?int $termId,
    bool $success,
    ?string $reason = null
): void {
    try {
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        /* Every one of these columns is a foreign key. A refused attempt often
           quotes a session or a term that does not exist, and a row that cannot be
           written is a row that cannot be counted - so an id that no longer
           resolves is dropped to NULL rather than allowed to abort the insert.
           The attempt is still recorded, which is the part that matters. */
        dbRun(
            'INSERT INTO result_access_logs
                 (pin_id, student_id, session_id, term_id, ip_address, user_agent,
                  success, failure_reason)
             VALUES
                 (:pin_id, :student_id, :session_id, :term_id, :ip_address, :user_agent,
                  :success, :failure_reason)',
            [
                ':pin_id'         => $pinId !== null && existsById('result_pins', $pinId) ? $pinId : null,
                ':student_id'     => $studentId !== null && existsById('students', $studentId) ? $studentId : null,
                ':session_id'     => $sessionId !== null && existsById('academic_sessions', $sessionId) ? $sessionId : null,
                ':term_id'        => $termId !== null && existsById('terms', $termId) ? $termId : null,
                ':ip_address'     => clientIpAddress() === '' ? '0.0.0.0' : clientIpAddress(),
                ':user_agent'     => substr($agent, 0, 255),
                ':success'        => $success ? 1 : 0,
                ':failure_reason' => $reason === null ? null : substr($reason, 0, 40),
            ]
        );
    } catch (Throwable $exception) {
        error_log('[PHASE6] Unable to write a result access log line: ' . $exception->getMessage());
    }
}

/**
 * Does this row still exist?
 *
 * The table name comes from the call sites in this file only, never from input,
 * so it is not an injection point; it is interpolated because PDO cannot bind an
 * identifier.
 */
function existsById(string $table, int $id): bool
{
    static $allowed = [
        'result_pins'        => true,
        'students'           => true,
        'academic_sessions'  => true,
        'terms'              => true,
    ];

    if ($id <= 0 || !isset($allowed[$table])) {
        return false;
    }

    return (int) dbValue('SELECT COUNT(*) FROM ' . $table . ' WHERE id = :id', [':id' => $id]) > 0;
}

/** Access log counts for the dashboard: attempts, successes and failures. */
function resultAccessLogCounts(?int $sinceId = null): array
{
    $where  = $sinceId !== null ? ' WHERE id > :since' : '';
    $params = $sinceId !== null ? [':since' => $sinceId] : [];

    $row = dbOne('SELECT COUNT(*) AS total,
                         SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS ok,
                         SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed,
                         MAX(id) AS latest_id
                    FROM result_access_logs' . $where, $params);

    $counts = [
        'total'  => (int) ($row['total'] ?? 0),
        'success' => (int) ($row['ok'] ?? 0),
        'failed' => (int) ($row['failed'] ?? 0),
        'latest_id' => (int) ($row['latest_id'] ?? 0),
    ];

    /* The failure breakdown the administrator needs in order to tell a forgotten
       PIN from an attack: one grouped query over the same log. */
    $counts['reasons'] = dbAll(
        "SELECT failure_reason, COUNT(*) AS total
           FROM result_access_logs
          WHERE success = 0 AND failure_reason IS NOT NULL
            AND accessed_at >= (NOW() - INTERVAL 30 DAY)
          GROUP BY failure_reason
          ORDER BY total DESC"
    );

    return $counts;
}

/** The most recent public access attempts, for the dashboard table. */
function recentResultAccessLogs(int $limit = 10): array
{
    $limit = max(1, min($limit, 50));

    return dbAll(
        'SELECT l.*, s.admission_number, s.first_name, s.middle_name, s.last_name,
                rp.pin_reference, a.session_name, t.term_name
           FROM result_access_logs l
           LEFT JOIN result_pins rp ON rp.id = l.pin_id
           LEFT JOIN students s ON s.id = l.student_id
           LEFT JOIN academic_sessions a ON a.id = l.session_id
           LEFT JOIN terms t ON t.id = l.term_id
          ORDER BY l.id DESC
          LIMIT ' . $limit
    );
}

/**
 * The network throttle.
 *
 * Counts the failed attempts from this IP inside a short window. The ceiling is
 * deliberately high and the window short, because a school is normally behind a
 * single NAT address: this must stop a scripted sweep without stopping a morning
 * of parents all checking results from the same office.
 */
function publicResultIpThrottleExceeded(?string $ip = null): bool
{
    $ip = $ip === null ? clientIpAddress() : $ip;

    if ($ip === '') {
        return false;
    }

    $failures = (int) dbValue(
        'SELECT COUNT(*)
           FROM result_access_logs
          WHERE ip_address = :ip
            AND success = 0
            AND accessed_at >= (NOW() - INTERVAL ' . (int) PUBLIC_RESULT_IP_FAILURE_WINDOW . ' SECOND)',
        [':ip' => $ip]
    );

    return $failures >= PUBLIC_RESULT_IP_FAILURE_LIMIT;
}

/* ------------------------------------------------------------------ */
/* The verification gate                                               */
/* ------------------------------------------------------------------ */

/**
 * Check the four things a visitor typed and decide whether to open a result.
 *
 * This is the only function in the portal that can let an unauthenticated visitor
 * see a student record, so it is written to be uninformative on failure and
 * complete on success.
 *
 * The order matters and is deliberate:
 *
 *   1. The admission number is looked up on its UNIQUE index. A student who does
 *      not exist is a failure recorded against no student at all.
 *   2. The PIN is looked up for THAT student in THAT session. A PIN that belongs
 *      to another student is simply not found, which is why "do not allow PIN
 *      reuse across students" needs no special case: the query already scopes it.
 *   3. The status, the expiry and the attempt counter are checked. Failing them
 *      produces the specific message the school needs ("expired", "too many
 *      attempts"), because that tells the visitor what to do next without telling
 *      an attacker anything they did not already know.
 *   4. Only then is the term checked for existence and belonging to the session,
 *      and only then is publication and approval consulted.
 *   5. password_verify() runs on a hash even when the PIN was not found, against
 *      a dummy, so the response time does not reveal whether the PIN existed.
 *
 * @return array{
 *     ok: bool,
 *     message: string,
 *     reason: string|null,
 *     pin_id: int|null,
 *     student_id: int|null,
 *     report_id: int|null
 * }
 */
function verifyPublicResultAccess(
    string $admissionNumber,
    string $pin,
    int $sessionId,
    int $termId
): array {
    $denied = static function (string $message, ?string $reason, ?int $pinId = null, ?int $studentId = null): array {
        return [
            'ok' => false, 'message' => $message, 'reason' => $reason,
            'pin_id' => $pinId, 'student_id' => $studentId, 'report_id' => null,
        ];
    };

    $invalid = RESULT_ACCESS_FAILURE_REASONS['invalid_details'];

    if ($sessionId <= 0 || $termId <= 0 || trim($admissionNumber) === '' || trim($pin) === '') {
        logResultAccess(null, null, $sessionId > 0 ? $sessionId : null, $termId > 0 ? $termId : null, false, $invalid);

        return $denied(RESULT_ACCESS_FAILURE_REASONS['invalid_details'], $invalid);
    }

    /* The term has to belong to the session the visitor chose. Checked before
       anything is looked up so a mismatched pair never reaches the PIN table. */
    $term = dbOne(
        'SELECT id, session_id FROM terms WHERE id = :id LIMIT 1',
        [':id' => $termId]
    );

    if ($term === null || (int) $term['session_id'] !== $sessionId) {
        logResultAccess(null, null, $sessionId, $termId, false, $invalid);

        return $denied(RESULT_ACCESS_FAILURE_REASONS['invalid_details'], $invalid);
    }

    /* 1. The student, by admission number. Unique index, so exactly one row. */
    $student = dbOne(
        "SELECT id, admission_number, first_name, middle_name, last_name, status
           FROM students
          WHERE admission_number = :admission_number
          LIMIT 1",
        [':admission_number' => trim($admissionNumber)]
    );

    $studentId = $student === null ? null : (int) $student['id'];

    if ($studentId === null) {
        /* Still run a verification so a wrong admission number and a wrong PIN
           take the same time. */
        password_verify(normaliseResultPin($pin), dummyPasswordHash());

        logResultAccess(null, null, $sessionId, $termId, false, $invalid);

        return $denied(RESULT_ACCESS_FAILURE_REASONS['invalid_details'], $invalid);
    }

    /* 2. The PIN, scoped to this student AND this session. A PIN issued for a
          different student, or for a different session, is not in this result. */
    $candidate = dbOne(
        'SELECT * FROM result_pins
          WHERE student_id = :student_id
            AND session_id = :session_id
          ORDER BY (status = \'active\') DESC, id DESC
          LIMIT 1',
        [':student_id' => $studentId, ':session_id' => $sessionId]
    );

    if ($candidate === null) {
        password_verify(normaliseResultPin($pin), dummyPasswordHash());

        logResultAccess(null, $studentId, $sessionId, $termId, false, $invalid);

        return $denied(RESULT_ACCESS_FAILURE_REASONS['invalid_details'], $invalid, null, $studentId);
    }

    $pinId    = (int) $candidate['id'];
    $supplied = normaliseResultPin($pin);
    $matches  = password_verify($supplied, (string) $candidate['pin_hash']);

    if (!$matches) {
        /* A PIN that already failed its ceiling is not even checked: it is dead,
           and making it live again by being guessed would be absurd. */
        if ((int) $candidate['failed_attempts'] >= (int) $candidate['max_attempts']) {
            logResultAccess($pinId, $studentId, $sessionId, $termId, false, 'too_many_attempts');

            return $denied(
                'Too many failed attempts. Please contact the school.',
                'too_many_attempts',
                $pinId,
                $studentId
            );
        }

        recordResultPinFailure($pinId);

        logResultAccess($pinId, $studentId, $sessionId, $termId, false, $invalid);

        return $denied(RESULT_ACCESS_FAILURE_REASONS['invalid_details'], $invalid, $pinId, $studentId);
    }

    /* The secret was right. From here the visitor is entitled to a specific
       answer, so the PIN's own state can be reported honestly. */
    $status = resultPinEffectiveStatus($candidate);

    if ($status === 'expired') {
        markResultPinExpired($pinId);

        logResultAccess($pinId, $studentId, $sessionId, $termId, false, 'expired');

        return $denied('This result PIN has expired.', 'expired', $pinId, $studentId);
    }

    if ($status === 'revoked') {
        logResultAccess($pinId, $studentId, $sessionId, $termId, false, 'revoked');

        return $denied(
            'This result PIN is no longer valid. Please contact the school.',
            'revoked',
            $pinId,
            $studentId
        );
    }

    if ($status === 'blocked') {
        logResultAccess($pinId, $studentId, $sessionId, $termId, false, 'blocked');

        return $denied(
            'Too many failed attempts. Please contact the school.',
            'blocked',
            $pinId,
            $studentId
        );
    }

    if ($status === 'used') {
        logResultAccess($pinId, $studentId, $sessionId, $termId, false, 'used');

        return $denied(
            'This result PIN has already been used. Please request a new one from the school.',
            'used',
            $pinId,
            $studentId
        );
    }

    if ((int) $candidate['failed_attempts'] >= (int) $candidate['max_attempts']) {
        blockResultPin($pinId);

        logResultAccess($pinId, $studentId, $sessionId, $termId, false, 'too_many_attempts');

        return $denied(
            'Too many failed attempts. Please contact the school.',
            'too_many_attempts',
            $pinId,
            $studentId
        );
    }

    /* 3. The report card itself. Which class is the student's result in for this
          session and term is worked out from the report card, never from a class
          the visitor supplied - they cannot supply one. */
    $report = dbOne(
        "SELECT id, class_id, status
           FROM report_cards
          WHERE student_id = :student_id
            AND session_id = :session_id
            AND term_id = :term_id
          LIMIT 1",
        [':student_id' => $studentId, ':session_id' => $sessionId, ':term_id' => $termId]
    );

    if ($report === null) {
        /* The PIN was right but there is nothing official behind it. Do not leak
           that: from outside, this is the same answer as a wrong PIN. */
        logResultAccess($pinId, $studentId, $sessionId, $termId, false, RESULT_ACCESS_FAILURE_REASONS['no_report']);

        return $denied(
            'This result is not available for the selected session and term.',
            RESULT_ACCESS_FAILURE_REASONS['no_report'],
            $pinId,
            $studentId
        );
    }

    $reportId = (int) $report['id'];
    $classId  = (int) $report['class_id'];

    if (!in_array((string) $report['status'], ['approved', 'locked'], true)) {
        logResultAccess($pinId, $studentId, $sessionId, $termId, false, RESULT_ACCESS_FAILURE_REASONS['not_approved']);

        return $denied(
            'This result has not been released by the school yet.',
            RESULT_ACCESS_FAILURE_REASONS['not_approved'],
            $pinId,
            $studentId
        );
    }

    /* 4. Publication is the last gate, and it is checked on the class the card
          belongs to. */
    if (!isResultPublished($sessionId, $termId, $classId)) {
        logResultAccess($pinId, $studentId, $sessionId, $termId, false, RESULT_ACCESS_FAILURE_REASONS['not_published']);

        return $denied(
            'Results for this class and term have not been published yet.',
            RESULT_ACCESS_FAILURE_REASONS['not_published'],
            $pinId,
            $studentId
        );
    }

    recordResultPinUse($pinId, (int) $candidate['single_use']);

    logResultAccess($pinId, $studentId, $sessionId, $termId, true, null);

    return [
        'ok' => true,
        'message' => 'Result found.',
        'reason' => null,
        'pin_id' => $pinId,
        'student_id' => $studentId,
        'report_id' => $reportId,
    ];
}

/**
 * Check public result access using Admission Number + Date of Birth + Session + Term.
 *
 * This replaces the PIN-based verification with a 4-field identity check:
 * 1. Admission Number exists
 * 2. Date of Birth matches that student
 * 3. Academic Session exists
 * 4. Term belongs to the selected session
 * 5. Student has a report card for that session/term
 * 6. Report card is approved or locked
 * 7. Results are published for that class/term/session
 *
 * On failure, returns the same generic message regardless of which check failed,
 * to prevent information leakage about other students.
 *
 * @return array{
 *     ok: bool,
 *     message: string,
 *     reason: string|null,
 *     student_id: int|null,
 *     report_id: int|null
 * }
 */
function verifyPublicResultAccessByIdentity(
    string $admissionNumber,
    string $dateOfBirth,
    int $sessionId,
    int $termId
): array {
    $denied = static function (string $message, ?string $reason, ?int $studentId = null): array {
        return [
            'ok' => false, 'message' => $message, 'reason' => $reason,
            'student_id' => $studentId, 'report_id' => null,
        ];
    };

    $invalid = 'invalid_details';

    if ($sessionId <= 0 || $termId <= 0 || trim($admissionNumber) === '' || trim($dateOfBirth) === '') {
        logResultAccess(null, null, $sessionId > 0 ? $sessionId : null, $termId > 0 ? $termId : null, false, $invalid);

        return $denied('Invalid student details or result not available.', $invalid);
    }

    /* The term has to belong to the session the visitor chose. */
    $term = dbOne(
        'SELECT id, session_id FROM terms WHERE id = :id LIMIT 1',
        [':id' => $termId]
    );

    if ($term === null || (int) $term['session_id'] !== $sessionId) {
        logResultAccess(null, null, $sessionId, $termId, false, $invalid);

        return $denied('Invalid student details or result not available.', $invalid);
    }

    /* 1. The student, by admission number. */
    $student = dbOne(
        "SELECT id, admission_number, first_name, middle_name, last_name, date_of_birth, status
           FROM students
          WHERE admission_number = :admission_number
          LIMIT 1",
        [':admission_number' => trim($admissionNumber)]
    );

    $studentId = $student === null ? null : (int) $student['id'];

    if ($studentId === null) {
        logResultAccess(null, null, $sessionId, $termId, false, $invalid);

        return $denied('Invalid student details or result not available.', $invalid);
    }

    /* 2. Date of Birth must match. */
    if ((string) $student['date_of_birth'] !== trim($dateOfBirth)) {
        logResultAccess(null, $studentId, $sessionId, $termId, false, $invalid);

        return $denied('Invalid student details or result not available.', $invalid, $studentId);
    }

    /* 3. The report card for this session/term. */
    $report = dbOne(
        "SELECT id, class_id, status
           FROM report_cards
          WHERE student_id = :student_id
            AND session_id = :session_id
            AND term_id = :term_id
          LIMIT 1",
        [':student_id' => $studentId, ':session_id' => $sessionId, ':term_id' => $termId]
    );

    if ($report === null) {
        logResultAccess(null, $studentId, $sessionId, $termId, false, 'no_report');

        return $denied('Invalid student details or result not available.', 'no_report', $studentId);
    }

    $reportId = (int) $report['id'];
    $classId  = (int) $report['class_id'];

    if (!in_array((string) $report['status'], ['approved', 'locked'], true)) {
        logResultAccess(null, $studentId, $sessionId, $termId, false, 'not_approved');

        return $denied('Invalid student details or result not available.', 'not_approved', $studentId);
    }

    /* 4. Publication check. */
    if (!isResultPublished($sessionId, $termId, $classId)) {
        logResultAccess(null, $studentId, $sessionId, $termId, false, 'not_published');

        return $denied('Invalid student details or result not available.', 'not_published', $studentId);
    }

    logResultAccess(null, $studentId, $sessionId, $termId, true, null);

    return [
        'ok' => true,
        'message' => 'Result found.',
        'reason' => null,
        'student_id' => $studentId,
        'report_id' => $reportId,
    ];
}

/**
 * Count one failed attempt against a PIN, and block it once it runs out.
 *
 * The increment and the block happen in one transaction, and the WHERE clause
 * stops the counter at the ceiling, so a burst of parallel guesses cannot push
 * it past max_attempts and break the CHECK constraint.
 */
function recordResultPinFailure(int $pinId): void
{
    try {
        dbTransaction(function (PDO $pdo) use ($pinId) {
            $stmt = $pdo->prepare(
                'UPDATE result_pins
                    SET failed_attempts = LEAST(failed_attempts + 1, max_attempts),
                        updated_at = NOW()
                  WHERE id = :id AND status = \'active\''
            );
            $stmt->execute([':id' => $pinId]);

            $block = $pdo->prepare(
                "UPDATE result_pins
                    SET status = 'blocked', updated_at = NOW()
                  WHERE id = :id
                    AND status = 'active'
                    AND failed_attempts >= max_attempts"
            );
            $block->execute([':id' => $pinId]);
        });
    } catch (Throwable $exception) {
        error_log('[PHASE6] Unable to count a failed PIN attempt: ' . $exception->getMessage());
    }
}

/** Block a PIN outright, e.g. once its counter has run out. */
function blockResultPin(int $pinId): void
{
    try {
        dbRun(
            "UPDATE result_pins
                SET status = 'blocked', updated_at = NOW()
              WHERE id = :id AND status = 'active'",
            [':id' => $pinId]
        );
    } catch (Throwable $exception) {
        error_log('[PHASE6] Unable to block a result PIN: ' . $exception->getMessage());
    }
}

/** Persist that a PIN has passed its expiry date. */
function markResultPinExpired(int $pinId): void
{
    try {
        dbRun(
            "UPDATE result_pins
                SET status = 'expired', updated_at = NOW()
              WHERE id = :id AND status = 'active'",
            [':id' => $pinId]
        );
    } catch (Throwable $exception) {
        error_log('[PHASE6] Unable to expire a result PIN: ' . $exception->getMessage());
    }
}

/**
 * Note that a PIN was used.
 *
 * `last_used_at` always moves. The status only becomes 'used' when the school
 * chose single use at issue time; the default is reusable until expiry, so a
 * parent can come back to the same result all term without asking for a new PIN.
 */
function recordResultPinUse(int $pinId, int $singleUse): void
{
    try {
        if ($singleUse === 1) {
            dbRun(
                "UPDATE result_pins
                    SET status = 'used', last_used_at = NOW(), updated_at = NOW()
                  WHERE id = :id",
                [':id' => $pinId]
            );
        } else {
            dbRun(
                'UPDATE result_pins
                    SET last_used_at = NOW(), updated_at = NOW()
                  WHERE id = :id',
                [':id' => $pinId]
            );
        }
    } catch (Throwable $exception) {
        error_log('[PHASE6] Unable to record a result PIN use: ' . $exception->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* The short lived public result session                               */
/* ------------------------------------------------------------------ */

/**
 * Open a public result session for one report card.
 *
 * The PIN is verified once, here, and never again: the result page reads the
 * session, not the PIN, and the PIN does not appear in any URL, in the page, or
 * in the browser history. The session id is rotated first, so a session id fixed
 * by a visitor before the check cannot be reused after it.
 *
 * The stored grant names a report card rather than a student, so the access
 * window cannot be turned into a general "browse any student's report" pass by
 * editing the session. It also names the PIN that opened it, so revoking or
 * blocking that PIN closes the window at once rather than leaving a leaked PIN
 * with somebody's result still on screen for the rest of its 30 minutes.
 *
 * @param int|null $pinId the PIN that was verified, when the caller knows it
 *
 * @return string the random grant token, which is never shown to the visitor
 */
function grantPublicResultAccess(int $reportId, ?int $pinId = null): string
{
    startSecureSession();

    /* Rotate on privilege change, exactly as the login does. */
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $token = bin2hex(random_bytes(32));

    $_SESSION['public_result'] = [
        'report_id'  => $reportId,
        'pin_id'     => $pinId,
        'token'      => $token,
        'issued_at'  => time(),
        'expires_at' => time() + PUBLIC_RESULT_SESSION_SECONDS,
    ];

    return $token;
}

/**
 * The report card the current public session is allowed to see.
 *
 * Returns null when there is no session, when it has expired, or when the card it
 * named has since been withdrawn. The expiry is enforced here on every read
 * rather than trusted from the cookie, so a visitor cannot keep a result open by
 * carrying the session file forward.
 */
function publicResultReport(): ?array
{
    startSecureSession();

    $grant = $_SESSION['public_result'] ?? null;

    if (!is_array($grant) || !isset($grant['report_id'], $grant['expires_at'])) {
        return null;
    }

    if ((int) $grant['expires_at'] < time()) {
        unset($_SESSION['public_result']);

        return null;
    }

    $report = findReportCard((int) $grant['report_id']);

    /* The card may have been deleted, or taken back out of the released state,
       while this session was open. Either way the window closes. */
    if ($report === null || !in_array((string) $report['status'], ['approved', 'locked'], true)) {
        unset($_SESSION['public_result']);

        return null;
    }

    if (!isResultPublished((int) $report['session_id'], (int) $report['term_id'], (int) $report['class_id'])) {
        unset($_SESSION['public_result']);

        return null;
    }

    /* The PIN that opened this window has to still be usable. A single use PIN
       reads as "used" here, which is exactly right: it opened the result it was
       issued for, and the window it opened is the use it was good for. Anything
       else - revoked, blocked, or expired since - closes the window, so pulling a
       leaked PIN stops working straight away instead of in half an hour. */
    $pinId = isset($grant['pin_id']) ? (int) $grant['pin_id'] : 0;

    if ($pinId > 0) {
        $pin = findResultPinRow($pinId);

        if ($pin === null || !in_array(resultPinEffectiveStatus($pin), ['active', 'used'], true)) {
            unset($_SESSION['public_result']);

            return null;
        }
    }

    return $report;
}

/** Is there a live public result session right now? */
function hasPublicResultAccess(): bool
{
    return publicResultReport() !== null;
}

/** Close the public result session without touching an admin or teacher login. */
function revokePublicResultAccess(): void
{
    startSecureSession();

    unset($_SESSION['public_result']);
}

/** Seconds left on the public result session, for the countdown on the page. */
function publicResultSecondsRemaining(): int
{
    startSecureSession();

    $grant = $_SESSION['public_result'] ?? null;

    if (!is_array($grant) || !isset($grant['expires_at'])) {
        return 0;
    }

    return max(0, (int) $grant['expires_at'] - time());
}

/**
 * Send the security headers that belong on a page showing a child's result.
 *
 * no-store keeps a result out of the shared disk cache on a machine at the front
 * desk and out of the back/forward cache; nosniff stops a browser guessing that
 * a response is something other than what it says; the referrer policy stops the
 * result URL leaking to a third party if the page ever links out.
 *
 * The header name is sent as literal lowercase here because some PHP
 * installations normalise nothing at all, and a duplicate header is worse than
 * an unusual casing.
 */
function sendPublicResultSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
}

/* ------------------------------------------------------------------ */
/* The bundle the public result page prints                            */
/* ------------------------------------------------------------------ */

/**
 * Everything the public result page needs, in the fewest queries.
 *
 * The public pages are the ones a school opens on a phone over a slow connection,
 * so the display is assembled here rather than in the page file: the card, its
 * subjects, attendance, both assessment sets and the previous standing all come
 * from here, and the page does no SQL of its own.
 *
 * Only what a parent needs for academic verification is returned. There is no
 * parent phone number, no home address, no teacher id, no user id and no
 * internal key in this array, so there is nothing to accidentally print.
 *
 * @return array<string,mixed>|null
 */
function publicResultBundle(int $reportId): ?array
{
    $report = findReportCard($reportId);

    if ($report === null) {
        return null;
    }

    if (!in_array((string) $report['status'], ['approved', 'locked'], true)) {
        return null;
    }

    if (!isResultPublished((int) $report['session_id'], (int) $report['term_id'], (int) $report['class_id'])) {
        return null;
    }

    $studentId = (int) $report['student_id'];
    $classId   = (int) $report['class_id'];
    $sessionId = (int) $report['session_id'];
    $termId    = (int) $report['term_id'];

    /* The attendance the card froze at generation time is preferred over the
       editable summary, because a locked card must not change when a teacher
       corrects next term's register. */
    $attendance = $report['attendance_total'] === null
        ? reportCardAttendance($studentId, $classId, $sessionId, $termId)
        : [
            'days_present' => (int) $report['attendance_present'],
            'days_absent'  => (int) $report['attendance_absent'],
            'school_days'  => (int) $report['attendance_total'],
        ];

    return [
        'verification_code' => ensureReportCardVerificationCode($reportId),
        'report_number'     => (string) $report['report_number'],
        'status'            => (string) $report['status'],

        'student_name'      => studentFullName($report),
        'admission_number' => (string) $report['admission_number'],
        'gender'            => (string) $report['gender'],

        'class_name'        => (string) $report['class_name'],
        'section'           => (string) $report['section'],
        'session_name'      => (string) $report['session_name'],
        'term_name'         => (string) $report['term_name'],

        'total_score'       => (float) $report['total_score'],
        'average_score'     => (float) $report['average_score'],
        'position'          => $report['position'] === null ? null : (int) $report['position'],
        'total_students'    => (int) $report['total_students'],
        'subjects_offered'  => (int) $report['subjects_offered'],
        'subjects_passed'   => (int) $report['subjects_passed'],
        'subjects_failed'   => (int) $report['subjects_failed'],

        'teacher_comment'   => (string) ($report['teacher_comment'] ?? ''),
        'principal_comment' => (string) ($report['principal_comment'] ?? ''),
        'promotion_status'  => (string) ($report['promotion_status'] ?? 'Pending'),

        'subjects'          => reportCardSubjectRows($reportId),
        'attendance'        => $attendance,
        'psychomotor'       => reportCardPsychomotor($studentId, $classId, $sessionId, $termId),
        'effective'         => reportCardEffectiveAreas($studentId, $classId, $sessionId, $termId),
        'previous'          => previousPerformanceFor($reportId),
        'structure'         => assessmentStructure((string) $report['section']),
    ];
}
