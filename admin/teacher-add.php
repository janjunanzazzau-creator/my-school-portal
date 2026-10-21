<?php

declare(strict_types=1);

/**
 * Create a staff record and its portal login account in one transaction.
 * The password is hashed with password_hash() and is never displayed again.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$errors = [];
$values = [
    'staff_number'  => '',
    'first_name'    => '',
    'middle_name'   => '',
    'last_name'     => '',
    'gender'        => '',
    'phone'         => '',
    'email'         => '',
    'address'       => '',
    'qualification' => '',
    'department'    => '',
    'status'        => 'Active',
    'username'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/teacher-add.php');
    }

    [$data, $errors, $account] = validateTeacherInput(null, true);
    $values = mergeFormValues($values, $data);
    $values['username'] = $account['username'];

    if ($errors === []) {
        try {
            $teacherId = createTeacherWithAccount($data, $account);
            setFlash('success', 'Teacher ' . $data['staff_number'] . ' was added with a portal account.');
            redirect('admin/teacher-view.php?id=' . $teacherId);
        } catch (Throwable $exception) {
            error_log('[PHASE2] Unable to save teacher: ' . $exception->getMessage());
            $errors['form'] = 'The teacher could not be saved. Please try again.';
        }
    }
}

$pageTitle    = 'Add Teacher';
$pageHeading  = 'Add Teacher';
$pageSubtitle = 'Create a staff record and a portal login account.';
$activeMenu   = 'teachers';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/teachers.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back to Teachers
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>

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

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-key text-green"></i> Portal Login Account</div>
        <div class="form-card__body">
            <div class="alert-soft-info">
                The password is stored with <code>password_hash()</code> and is never displayed again.
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required" for="username">Username</label>
                    <input class="<?= inputClass($errors, 'username') ?>" type="text"
                           id="username" name="username" maxlength="50" required
                           autocomplete="off" value="<?= e($values['username']) ?>">
                    <?= inlineError($errors, 'username') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="password">Password</label>
                    <div style="position: relative">
                        <input class="<?= inputClass($errors, 'password') ?>" type="password"
                               id="password" name="password" required minlength="<?= (int) PASSWORD_MIN_LENGTH ?>"
                               autocomplete="new-password">
                        <button class="password-toggle" type="button" data-password-toggle
                                aria-label="Show password"><i class="bi bi-eye"></i></button>
                    </div>
                    <?= inlineError($errors, 'password') ?>
                    <div class="form-text">Minimum <?= (int) PASSWORD_MIN_LENGTH ?> characters.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="password_confirm">Confirm Password</label>
                    <input class="<?= inputClass($errors, 'password_confirm') ?>" type="password"
                           id="password_confirm" name="password_confirm" required
                           minlength="<?= (int) PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                    <?= inlineError($errors, 'password_confirm') ?>
                </div>
            </div>

            <div class="form-text mt-2">
                The account is created with the role <strong>teacher</strong>.
                Result entry becomes available in Phase 4.
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Teacher</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/teachers.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
