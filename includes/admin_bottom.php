<?php

declare(strict_types=1);

/**
 * Closes the frame opened by includes/admin_top.php.
 *
 * $flashes is pulled by admin_top.php and must be rendered here, otherwise every
 * success and error message set by a redirect is silently lost.
 */

if (!isset($flashes) || !is_array($flashes)) {
    $flashes = [];
}

?>
        <?= renderFlashes($flashes) ?>
    </div><!-- app-content -->
<?php

require_once __DIR__ . '/footer.php';
