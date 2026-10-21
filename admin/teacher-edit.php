<?php

declare(strict_types=1);

/**
 * Edit a teacher profile and reset the portal password.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$teacherId = queryInt('id');
$teacher   = $teacherId === null ? null : findTeacher($teacherId);

if ($teacher === null) {
    setFlash('error', 'The selected teacher could not be found.');
    redirect('admin/teachers.php');
}

$errors    = [];
$passwords = [];
$values    = [
    'staff_number'  => (string) $teacher['staff_number'],
    'first_name'    => (string) $teacher['first_name'],
    'middle_name'   => (string) ($teacher['middle_name'] ?? ''),
    'last_name'     => (string) ($teacher['last_name'] ?? ''),
    'gender'        => (string) $teacher['gender'],
    'phone'         => (string) ($teacher['phone'] ?? ''),
    'email'         => (string) ($teacher['email'] ?? ''),
    'address'       => (string) ($teacher['address'] ?? ''),
    'qualification' => (string) ($teacher['qualification'] ?? ''),
    'department'    => (string) ($teacher['department'] ?? ''),
    'status'        => (string) $teacher['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST[CSRF_FIELD_NAME] ?? null;
    $action = field('action', 30);

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/teacher-edit.php?id=' . (int) $teacherId);
    }

    if ($action === 'reset_password') {
        $password = field('password', 100);
        $confirm  = field('password_confirm', 100);

        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            $passwords['password'] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        } elseif ($password !== $confirm) {
            $passwords['password_confirm'] = 'The two passwords do not match.';
        } elseif (resetTeacherPassword($teacherId, $password, $confirm)) {
            setFlash('success', 'The portal password was reset. The new password is never displayed.');
            redirect('admin/teacher-view.php?id=' . (int) $teacherId);
        } else {
            $passwords['password'] = 'This teacher has no portal account to update.';
        }
    } else {
        [$data, $errors] = validateTeacherInput($teacherId);
        $values = mergeFormValues($values, $data);

        if ($errors === []) {
            try {
                updateTeacher($teacherId, $data);
                syncTeacherAccountStatus($teacherId, $data['status']);
                setFlash('success', 'Teacher record updated successfully.');
                redirect('admin/teacher-view.php?id=' . (int) $teacherId);
            } catch (Throwable $exception) {
                error_log('[PHASE2] Unable to update teacher: ' . $exception->getMessage());
                $errors['form'] = 'The teacher could not be saved. Please try again.';
            }
        }
    }
}

$pageTitle    = 'Edit Teacher';
$pageHeading  = 'Edit Teacher';
$pageSubtitle = teacherFullName($teacher) . ' (' . $teacher['staff_number'] . ')';
$activeMenu   = 'teachers';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/teacher-view.php?id=' . (int) $teacherId)) ?>">
            <i class="bi bi-eye"></i> View
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/teachers.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update">

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-person-vcard text-green"></i> Staff Details</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required" for="staff_number">Staff Number</label>
                    <input class="<?= inputClass($errors, 'staff_number') ?>" type="text"
                           id="staff_number" name="staff_number" maxlength="30" required
                           value="<?= e($values['staff_number']) ?>">
                    <?= inlineError($errors, 'staff_number') ?>
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
                    <label class="form-label required" for="status">Status</label>
                    <select class="<?= inputClass($errors, 'status') ?>" id="status" name="status" required>
                        <?php foreach (RECORD_STATUSES as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['status'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'status') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="phone">Phone</label>
                    <input class="<?= inputClass($errors, 'phone') ?>" type="text"
                           id="phone" name="phone" maxlength="30"
                           value="<?= e($values['phone']) ?>">
                    <?= inlineError($errors, 'phone') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="email">E-mail</label>
                    <input class="<?= inputClass($errors, 'email') ?>" type="email"
                           id="email" name="email" maxlength="120"
                           value="<?= e($values['email']) ?>">
                    <?= inlineError($errors, 'email') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="department">Department</label>
                    <input class="<?= inputClass($errors, 'department') ?>" type="text"
                           id="department" name="department" maxlength="80"
                           value="<?= e($values['department']) ?>">
                    <?= inlineError($errors, 'department') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="qualification">Qualification</label>
                    <input class="<?= inputClass($errors, 'qualification') ?>" type="text"
                           id="qualification" name="qualification" maxlength="120"
                           value="<?= e($values['qualification']) ?>">
                    <?= inlineError($errors, 'qualification') ?>
                </div>

                <div class="col-12">
                    <label class="form-label" for="address">Address</label>
                    <input class="<?= inputClass($errors, 'address') ?>" type="text"
                           id="address" name="address" maxlength="255"
                           value="<?= e($values['address']) ?>">
                    <?= inlineError($errors, 'address') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Changes</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/teacher-view.php?id=' . (int) $teacherId)) ?>">Cancel</a>
    </div>
</form>

<div class="form-card">
    <div class="form-card__head"><i class="bi bi-key text-green"></i> Reset Portal Password</div>
    <div class="form-card__body">
        <?php if ($teacher['user_id'] === null): ?>
            <div class="alert-soft-danger mb-0">This teacher has no portal account.</div>
        <?php else: ?>
            <form method="post" data-validate novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reset_password">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label required" for="password">New Password</label>
                        <div style="position: relative">
                            <input class="<?= inputClass($passwords, 'password') ?>" type="password"
                                   id="password" name="password" required
                                   minlength="<?= (int) PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                            <button class="password-toggle" type="button" data-password-toggle
                                    aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                        <?= inlineError($passwords, 'password') ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label required" for="password_confirm">Confirm New Password</label>
                        <input class="<?= inputClass($passwords, 'password_confirm') ?>" type="password"
                               id="password_confirm" name="password_confirm" required
                               minlength="<?= (int) PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                        <?= inlineError($passwords, 'password_confirm') ?>
                    </div>
                </div>

                <button class="btn btn-gold mt-3" type="submit">
                    <i class="bi bi-shield-lock"></i> Reset Password
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
