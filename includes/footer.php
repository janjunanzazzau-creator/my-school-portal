<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/helpers.php';

/* Load dynamic school settings */
$settings = getSchoolSettings();
?>
        <footer class="app-footer">
            <div class="mb-1">
                <strong class="text-green"><?= e($settings['school_name'] ?? SCHOOL_NAME) ?></strong> &middot; <?= e($settings['school_campaign'] ?? SCHOOL_CAMPAIGN) ?>
            </div>
            <div class="mb-1"><?= e($settings['school_foundation'] ?? SCHOOL_FOUNDATION) ?></div>
            <div><?= e($settings['school_motto'] ?? SCHOOL_MOTTO) ?></div>
            <div class="mt-2">
                <?= e($settings['school_address'] ?? SCHOOL_ADDRESS) ?> &nbsp;|&nbsp;
                <a href="tel:<?= e(preg_replace('/\s+/', '', (string) ($settings['school_phone'] ?? SCHOOL_PHONE))) ?>" class="text-green"><?= e($settings['school_phone'] ?? SCHOOL_PHONE) ?></a>
                &nbsp;|&nbsp;
                <a href="mailto:<?= e($settings['school_email'] ?? SCHOOL_EMAIL) ?>" class="text-green"><?= e($settings['school_email'] ?? SCHOOL_EMAIL) ?></a>
            </div>
            <div class="mt-2">&copy; <?= e(date('Y')) ?> <?= e($settings['school_name'] ?? SCHOOL_NAME) ?>. All rights reserved.</div>
        </footer>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(assetUrl('assets/js/app.js')) ?>"></script>
</body>
</html>
