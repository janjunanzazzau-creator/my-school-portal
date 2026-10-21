<?php

declare(strict_types=1);

/**
 * Edit a student, replace the passport photo or remove it.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$studentId = queryInt('id');
$student   = $studentId === null ? null : findStudent($studentId);

if ($student === null) {
    setFlash('error', 'The selected student could not be found.');
    redirect('admin/students.php');
}

$errors = [];
$values = [
    'admission_number' => (string) $student['admission_number'],
    'first_name'       => (string) $student['first_name'],
    'middle_name'      => (string) ($student['middle_name'] ?? ''),
    'last_name'        => (string) ($student['last_name'] ?? ''),
    'gender'           => (string) $student['gender'],
    'date_of_birth'    => (string) ($student['date_of_birth'] ?? ''),
    'parent_name'      => (string) ($student['parent_name'] ?? ''),
    'parent_phone'     => (string) ($student['parent_phone'] ?? ''),
    'parent_email'     => (string) ($student['parent_email'] ?? ''),
    'address'          => (string) ($student['address'] ?? ''),
    'section'          => (string) $student['section'],
    'class_id'         => (string) ($student['class_id'] ?? ''),
    'admission_date'   => (string) ($student['admission_date'] ?? date('Y-m-d')),
    'status'           => (string) $student['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token      = $_POST[CSRF_FIELD_NAME] ?? null;
    $removePhoto = isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1';

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/student-edit.php?id=' . (int) $studentId);
    }

    [$data, $errors] = validateStudentInput($studentId);
    $values = mergeFormValues($values, $data);

    $upload     = handlePassportUpload($_FILES['passport_photo'] ?? null);
    $oldPhoto   = $student['passport_photo'];
    $newPhoto   = $upload['file'];
    $clearPhoto = $removePhoto;

    if ($upload['error'] !== null) {
        $errors['passport_photo'] = $upload['error'];
    }

    if ($errors === []) {
        try {
            updateStudent($studentId, $data, $newPhoto, $clearPhoto);

            if ($newPhoto !== null) {
                deletePassportPhoto($oldPhoto);
            } elseif ($clearPhoto) {
                deletePassportPhoto($oldPhoto);
            }

            setFlash('success', 'Student record updated successfully.');
            redirect('admin/student-view.php?id=' . (int) $studentId);
        } catch (Throwable $exception) {
            error_log('[PHASE2] Unable to update student: ' . $exception->getMessage());

            if ($newPhoto !== null) {
                deletePassportPhoto($newPhoto);
            }

            $errors['form'] = 'The student could not be saved. Please try again.';
        }
    }
}

$classes = classOptions();

$pageTitle    = 'Edit Student';
$pageHeading  = 'Edit Student';
$pageSubtitle = studentFullName($student) . ' (' . $student['admission_number'] . ')';
$activeMenu   = 'students';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/student-view.php?id=' . (int) $studentId)) ?>">
            <i class="bi bi-eye"></i> View
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/students.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" data-validate novalidate>
    <?= csrfField() ?>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-image text-green"></i> Passport Photograph</div>
        <div class="form-card__body">
            <div class="row g-3 align-items-center">
                <div class="col-auto">
                    <img class="photo-frame" src="<?= e(studentPhotoUrl($student['passport_photo'])) ?>"
                         alt="Current passport photograph" data-photo-preview>
                </div>
                <div class="col">
                    <label class="form-label" for="passport_photo">Replace photograph</label>
                    <input class="form-control<?= isset($errors['passport_photo']) ? ' is-invalid' : '' ?>"
                           type="file" id="passport_photo" name="passport_photo"
                           accept="image/jpeg,image/png,image/webp" data-photo-input>
                    <?= inlineError($errors, 'passport_photo') ?>
                    <div class="form-text">
                        Uploading a new photograph removes the previous file. JPG, PNG or WEBP, maximum 2 MB.
                    </div>

                    <?php if ($student['passport_photo'] !== null && $student['passport_photo'] !== ''): ?>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="remove_photo"
                                   name="remove_photo" value="1">
                            <label class="form-check-label" for="remove_photo">
                                Remove the current photograph
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-person-vcard text-green"></i> Student Details</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required" for="admission_number">Admission Number</label>
                    <input class="<?= inputClass($errors, 'admission_number') ?>" type="text"
                           id="admission_number" name="admission_number" maxlength="30" required
                           value="<?= e($values['admission_number']) ?>">
                    <?= inlineError($errors, 'admission_number') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="admission_date">Admission Date</label>
                    <input class="<?= inputClass($errors, 'admission_date') ?>" type="date"
                           id="admission_date" name="admission_date" required
                           value="<?= e($values['admission_date']) ?>">
                    <?= inlineError($errors, 'admission_date') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="first_name">First Name</label>
                    <input class="<?= inputClass($errors, 'first_name') ?>" type="text"
                           id="first_name" name="first_name" maxlength="50" required
                           value="<?= e($values['first_name']) ?>">
                    <?= inlineError($errors, 'first_name') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="middle_name">Middle Name</label>
                    <input class="<?= inputClass($errors, 'middle_name') ?>" type="text"
                           id="middle_name" name="middle_name" maxlength="50"
                           value="<?= e($values['middle_name']) ?>">
                    <?= inlineError($errors, 'middle_name') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="last_name">Last Name</label>
                    <input class="<?= inputClass($errors, 'last_name') ?>" type="text"
                           id="last_name" name="last_name" maxlength="50"
                           value="<?= e($values['last_name']) ?>">
                    <?= inlineError($errors, 'last_name') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="gender">Gender</label>
                    <select class="<?= inputClass($errors, 'gender') ?>" id="gender" name="gender" required>
                        <option value="">Select gender</option>
                        <?php foreach (GENDERS as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['gender'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'gender') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="date_of_birth">Date of Birth</label>
                    <input class="<?= inputClass($errors, 'date_of_birth') ?>" type="date"
                           id="date_of_birth" name="date_of_birth" required
                           value="<?= e($values['date_of_birth']) ?>">
                    <?= inlineError($errors, 'date_of_birth') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="status">Status</label>
                    <select class="<?= inputClass($errors, 'status') ?>" id="status" name="status" required>
                        <?php foreach (STUDENT_STATUSES as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['status'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'status') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-person-badge text-green"></i> Parent / Guardian</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="parent_name">Parent / Guardian Name</label>
                    <input class="<?= inputClass($errors, 'parent_name') ?>" type="text"
                           id="parent_name" name="parent_name" maxlength="120"
                           value="<?= e($values['parent_name']) ?>">
                    <?= inlineError($errors, 'parent_name') ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="parent_phone">Phone</label>
                    <input class="<?= inputClass($errors, 'parent_phone') ?>" type="text"
                           id="parent_phone" name="parent_phone" maxlength="30"
                           value="<?= e($values['parent_phone']) ?>">
                    <?= inlineError($errors, 'parent_phone') ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="parent_email">E-mail</label>
                    <input class="<?= inputClass($errors, 'parent_email') ?>" type="email"
                           id="parent_email" name="parent_email" maxlength="120"
                           value="<?= e($values['parent_email']) ?>">
                    <?= inlineError($errors, 'parent_email') ?>
                </div>

                <div class="col-12">
                    <label class="form-label" for="address">Home Address</label>
                    <input class="<?= inputClass($errors, 'address') ?>" type="text"
                           id="address" name="address" maxlength="255"
                           value="<?= e($values['address']) ?>">
                    <?= inlineError($errors, 'address') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-door-open text-green"></i> Class Placement</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required" for="section">Section</label>
                    <select class="<?= inputClass($errors, 'section') ?>" id="section" name="section"
                            required data-class-section>
                        <option value="">Select section</option>
                        <?php foreach (SECTIONS as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['section'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'section') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="class_id">Class</label>
                    <select class="<?= inputClass($errors, 'class_id') ?>" id="class_id" name="class_id"
                            required data-class-target data-selected="<?= e($values['class_id']) ?>">
                        <option value="">Select class</option>
                        <?php foreach ($classes as $class): ?>
                            <?php if ($values['section'] === '' || $class['section'] === $values['section']): ?>
                                <option value="<?= (int) $class['id'] ?>"<?= isSelected((string) $class['id'], (string) $values['class_id']) ?>>
                                    <?= e($class['class_name']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'class_id') ?>
                    <div class="form-text">
                        This is the current class. To keep a history per academic session, use Student Enrollments.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Changes</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/student-view.php?id=' . (int) $studentId)) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
