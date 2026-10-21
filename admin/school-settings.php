<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* ------------------------------------------------------------------ */
/* School Settings Page                                              */
/* ------------------------------------------------------------------ */

$pageTitle   = 'School Settings';
$pageHeading = 'School Identity';
$pageSubtitle = 'Configure the school name, logo, and details shown on report cards.';
$activeMenu  = 'school-settings';

/* Handle form submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    requireAdmin();

    $fields = [
        'school_name',
        'school_short_name',
        'school_campaign',
        'school_foundation',
        'school_motto',
        'school_address',
        'school_phone',
        'school_email',
        'school_website',
    ];

    $data = [];
    foreach ($fields as $field) {
        $data[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    /* Handle logo upload */
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../assets/images/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $filename = 'logo.' . strtolower($ext);
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['logo']['tmp_name'], $targetPath)) {
            $data['logo_path'] = 'assets/images/' . $filename;
        }
    }

    /* Handle stamp upload */
    if (isset($_FILES['stamp']) && $_FILES['stamp']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../assets/images/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $ext = pathinfo($_FILES['stamp']['name'], PATHINFO_EXTENSION);
        $filename = 'stamp.' . strtolower($ext);
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['stamp']['tmp_name'], $targetPath)) {
            $data['stamp_path'] = 'assets/images/' . $filename;
        }
    }

    try {
        updateSchoolSettings($data);
        setFlash('success', 'School settings updated successfully.');
    } catch (Throwable $e) {
        setFlash('error', 'Failed to update settings: ' . $e->getMessage());
    }

    header('Location: ' . assetUrl('admin/school-settings.php'));
    exit;
}

/* Load current settings */
try {
    $settings = getSchoolSettings();
} catch (Throwable $e) {
    $settings = [];
}

/* Fallback values */
$defaults = [
    'school_name'         => 'ORPHANS MODEL NURSERY AND PRIMARY SCHOOL',
    'school_short_name'   => 'OMNPS',
    'school_campaign'     => '(MAKARANTAR MARAYU)',
    'school_foundation'   => 'ZAZZAU ORPHANS INITIATIVE FOUNDATION',
    'school_motto'        => 'KNOWLEDGE IS SHIELD TO THE ORPHANS',
    'school_address'      => 'Zazzau, Kaduna State, Nigeria',
    'school_phone'        => '+234 803 000 0000',
    'school_email'        => 'info@omnpzazzau.edu.ng',
    'school_website'      => '',
    'logo_path'           => 'assets/images/logo.jpeg',
    'stamp_path'          => '',
];

foreach ($defaults as $key => $val) {
    if (!isset($settings[$key]) || $settings[$key] === '') {
        $settings[$key] = $val;
    }
}

$logoUrl = assetUrl($settings['logo_path']);
$stampUrl = $settings['stamp_path'] ? assetUrl($settings['stamp_path']) : null;

require_once __DIR__ . '/../includes/admin_top.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><?= e($pageHeading) ?></h1>
</div>

<p class="text-muted mb-4"><?= e($pageSubtitle) ?></p>

<?php if ($flashes = pullFlashes()): ?>
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">School Information</h5>
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-3">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                    <div class="col-12">
                        <label class="form-label">School Name <span class="text-danger">*</span></label>
                        <input type="text" name="school_name" class="form-control" required
                            value="<?= e($settings['school_name']) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Short Name / Abbreviation <span class="text-danger">*</span></label>
                        <input type="text" name="school_short_name" class="form-control" required maxlength="10"
                            value="<?= e($settings['school_short_name']) ?>">
                        <div class="form-text">Used in registration numbers (e.g., OMNPS/26/001)</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Campaign / Tagline</label>
                        <input type="text" name="school_campaign" class="form-control"
                            value="<?= e($settings['school_campaign']) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Foundation / Parent Organization</label>
                        <input type="text" name="school_foundation" class="form-control"
                            value="<?= e($settings['school_foundation']) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Motto</label>
                        <textarea name="school_motto" class="form-control" rows="2"><?= e($settings['school_motto']) ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea name="school_address" class="form-control" rows="2"><?= e($settings['school_address']) ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="school_phone" class="form-control"
                            value="<?= e($settings['school_phone']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="school_email" class="form-control"
                            value="<?= e($settings['school_email']) ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Website</label>
                        <input type="url" name="school_website" class="form-control"
                            value="<?= e($settings['school_website']) ?>">
                    </div>

                    <div class="col-12">
                        <hr>
                        <h6>Registration Number Preview</h6>
                        <div class="alert alert-info">
                            Format: <strong><?= e($settings['school_short_name']) ?>/26/001</strong>
                            <br><small class="text-muted">[ABBREVIATION]/[YY]/[SEQUENCE] — sequence resets each year</small>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Save School Information</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Logo & Stamp</h5>
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-3">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                    <div class="col-12">
                        <label class="form-label">School Logo</label>
                        <div class="mb-2">
                            <img src="<?= e($logoUrl) ?>" alt="Current logo" class="img-thumbnail" style="max-height: 120px;">
                        </div>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <div class="form-text">Recommended: 150x150px, PNG or JPG, transparent background</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Official Stamp / Seal</label>
                        <?php if ($stampUrl): ?>
                            <div class="mb-2">
                                <img src="<?= e($stampUrl) ?>" alt="Current stamp" class="img-thumbnail" style="max-height: 120px;">
                            </div>
                        <?php else: ?>
                            <div class="form-text text-muted">No stamp uploaded yet</div>
                        <?php endif; ?>
                        <input type="file" name="stamp" class="form-control" accept="image/*">
                        <div class="form-text">Recommended: 200x200px, PNG with transparent background</div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary">Upload Images</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <h5 class="mb-0">Preview</h5>
            </div>
            <div class="card-body">
                <div class="text-center p-3 border rounded bg-light" style="font-family: 'Times New Roman', serif;">
                    <img src="<?= e($logoUrl) ?>" alt="Logo" style="max-height: 80px;" class="mb-2">
                    <div class="fw-bold" style="font-size: 1.1rem;"><?= e($settings['school_name']) ?></div>
                    <div class="text-muted small"><?= e($settings['school_campaign']) ?></div>
                    <div class="text-muted small"><?= e($settings['school_foundation']) ?></div>
                    <hr class="my-2">
                    <div class="small fw-bold">MOTTO: <?= e($settings['school_motto']) ?></div>
                    <div class="small text-muted mt-2">
                        <?= e($settings['school_address']) ?><br>
                        Tel: <?= e($settings['school_phone']) ?> | Email: <?= e($settings['school_email']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin_bottom.php'; ?>