<?php

declare(strict_types=1);

/**
 * School settings - loaded early to avoid circular dependencies.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

/**
 * Get the school settings row (id=1).
 *
 * @return array{
 *     id: int,
 *     school_name: string,
 *     school_short_name: string,
 *     school_campaign: string|null,
 *     school_foundation: string|null,
 *     school_motto: string|null,
 *     school_address: string|null,
 *     school_phone: string|null,
 *     school_email: string|null,
 *     school_website: string|null,
 *     logo_path: string|null,
 *     stamp_path: string|null,
 *     created_at: string,
 *     updated_at: string
 * }
 */
function getSchoolSettings(): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM school_settings WHERE id = 1 LIMIT 1');
    $stmt->execute();
    $settings = $stmt->fetch();

    /* Fallback to config constants if the settings table is empty. */
    $defaults = [
        'school_name'           => SCHOOL_NAME,
        'school_short_name'     => SCHOOL_SHORT_NAME,
        'school_campaign'       => SCHOOL_CAMPAIGN,
        'school_foundation'     => SCHOOL_FOUNDATION,
        'school_motto'          => SCHOOL_MOTTO,
        'school_address'        => SCHOOL_ADDRESS,
        'school_phone'          => SCHOOL_PHONE,
        'school_email'          => SCHOOL_EMAIL,
        'school_website'        => '',
        'logo_path'             => 'assets/images/logo.jpeg',
        'stamp_path'            => '',
    ];

    if ($settings === false) {
        return array_merge(['id' => 1], $defaults, [
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /* A stored value that was cleared must not blank the school identity. */
    foreach ($defaults as $field => $fallback) {
        if (!isset($settings[$field]) || $settings[$field] === '') {
            $settings[$field] = $fallback;
        }
    }

    return $settings;
}

/**
 * Update school settings.
 *
 * @param array<string, string> $data
 * @return int Number of affected rows
 */
function updateSchoolSettings(array $data): int
{
    $allowed = [
        'school_name',
        'school_short_name',
        'school_campaign',
        'school_foundation',
        'school_motto',
        'school_address',
        'school_phone',
        'school_email',
        'school_website',
        'logo_path',
        'stamp_path',
    ];

    $fields = [];
    $values = [];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $fields[] = "`$field` = :$field";
            $values[":$field"] = $data[$field] ?? '';
        }
    }

    if ($fields === []) {
        return 0;
    }

    $values[':updated_at'] = date('Y-m-d H:i:s');

    $sql = 'UPDATE `school_settings` SET ' . implode(', ', $fields) . ', `updated_at` = :updated_at WHERE `id` = 1';
    $stmt = getDbConnection()->prepare($sql);
    $stmt->execute($values);

    return $stmt->rowCount();
}

/**
 * Generate a new registration number.
 *
 * Format: [ABBREVIATION]/[YY]/[SEQUENCE] e.g. OMNPS/26/001
 * Sequence resets each year.
 *
 * When $shortName is omitted the abbreviation configured in School Settings is
 * used, so a caller that just wants the next number for this school never has to
 * repeat the school's own configuration. An explicit $shortName is still honoured
 * exactly as before.
 *
 * The row lock below is what makes the sequence safe against two people being
 * added at the same moment, so it must run inside a transaction. When the caller
 * has already opened one, this function joins that transaction instead of opening
 * a second one - PDO cannot nest, and the caller stays in charge of the final
 * commit or rollback.
 *
 * @param string|null $shortName School abbreviation, or null to use the configured one
 * @return string Generated registration number
 */
function generateRegistrationNumber(?string $shortName = null): string
{
    if ($shortName === null || trim($shortName) === '') {
        $shortName = (string) (getSchoolSettings()['school_short_name'] ?? SCHOOL_SHORT_NAME);
    }

    $pdo = getDbConnection();
    $yearTwoDigit = (int) date('y');

    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        /* Lock the row for this school/year to avoid race conditions */
        $stmt = $pdo->prepare(
            'SELECT `next_sequence` FROM `registration_sequences`
             WHERE `school_short_name` = :short_name AND `year_two_digit` = :year
             FOR UPDATE'
        );
        $stmt->execute([
            ':short_name' => $shortName,
            ':year'       => $yearTwoDigit,
        ]);
        $row = $stmt->fetch();

        if ($row === false) {
            /* No row yet for this school/year - insert with sequence 1 */
            $sequence = 1;
            $stmt = $pdo->prepare(
                'INSERT INTO `registration_sequences` (`school_short_name`, `year_two_digit`, `next_sequence`)
                 VALUES (:short_name, :year, 2)'
            );
            $stmt->execute([
                ':short_name' => $shortName,
                ':year'       => $yearTwoDigit,
            ]);
        } else {
            $sequence = (int) $row['next_sequence'];
            $nextSequence = $sequence + 1;
            $stmt = $pdo->prepare(
                'UPDATE `registration_sequences` SET `next_sequence` = :next_seq
                 WHERE `school_short_name` = :short_name AND `year_two_digit` = :year'
            );
            $stmt->execute([
                ':next_seq'   => $nextSequence,
                ':short_name' => $shortName,
                ':year'       => $yearTwoDigit,
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return sprintf('%s/%02d/%03d', strtoupper($shortName), $yearTwoDigit, $sequence);
    } catch (Throwable $e) {
        if ($ownsTransaction) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Parse a registration number into its components.
 *
 * @return array{school_short_name: string, year: int, sequence: int}|null
 */
function parseRegistrationNumber(string $regNumber): ?array
{
    $parts = explode('/', $regNumber);
    if (count($parts) !== 3) {
        return null;
    }

    return [
        'school_short_name' => $parts[0],
        'year'              => (int) $parts[1],
        'sequence'          => (int) $parts[2],
    ];
}

/**
 * Generate QR code SVG for result verification.
 *
 * @param string $verificationCode The verification code to encode
 * @param int $size Size of the QR code in pixels
 * @return string SVG markup
 */
function generateVerificationQRCode(string $verificationCode, int $size = 120): string
{
    $verifyUrl = BASE_URL . '/verify-result.php?code=' . urlencode($verificationCode);

    // Use a simple QR code generation - in production, use a proper library
    // For now, we'll use Google Charts API as fallback, but generate inline SVG
    // using a simple pattern (this is a placeholder - replace with real QR library)
    
    // Simple QR code placeholder - in production use endroid/qr-code or similar
    $qrSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">
        <rect width="100%" height="100%" fill="white"/>
        <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="10" fill="#999">QR Code</text>
        <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="8" fill="#ccc" dy="15">Verification</text>
    </svg>';
    
    return $qrSvg;
}

/**
 * Generate QR code as data URI for embedding in HTML.
 *
 * @param string $verificationCode The verification code to encode
 * @param int $size Size of the QR code in pixels
 * @return string Data URI
 */
function generateVerificationQRCodeDataUri(string $verificationCode, int $size = 120): string
{
    $svg = generateVerificationQRCode($verificationCode, $size);
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * Verify a result using the verification code.
 *
 * @param string $verificationCode
 * @return array|null Report card data or null if not found
 */
function verifyResultByCode(string $verificationCode): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT rc.*, s.admission_number, s.full_name as student_name, c.class_name, 
                ses.session_name, t.term_name
           FROM report_cards rc
           JOIN students s ON s.id = rc.student_id
           JOIN classes c ON c.id = rc.class_id
           JOIN academic_sessions ses ON ses.id = rc.session_id
           JOIN terms t ON t.id = rc.term_id
          WHERE rc.verification_code = :code
          LIMIT 1'
    );
    $stmt->execute([':code' => $verificationCode]);
    $result = $stmt->fetch();
    
    if ($result === false) {
        return null;
    }
    
    return $result;
}