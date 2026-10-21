<?php

declare(strict_types=1);

/**
 * Reusable Print Footer
 *
 * Usage:
 *   require __DIR__ . '/print-footer.php';
 *
 * Adds page numbers and school branding at the bottom of each printed page.
 */

require_once __DIR__ . '/../config/functions.php';
$settings = getSchoolSettings();
$schoolName = $settings['school_name'] ?? 'School Name';
$schoolShortName = $settings['school_short_name'] ?? 'SCHOOL';
?>
<div class="print-footer" style="margin-top: 20px; border-top: 1.5pt solid #0f5132; padding-top: 8px; text-align: center; font-size: 7pt; line-height: 1.5; color: #5b6b60; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
    <div style="font-size: 8pt; font-weight: 700; color: #0b3d2c; letter-spacing: 0.3px; margin-bottom: 2px;"><?= e($schoolName) ?></div>
    <div><?= e($schoolShortName) ?> | Printed on <?= date('d F Y \a\t H:i') ?></div>
    <div style="margin-top: 4px;">
        <span>Page <span class="page-number"></span> of <span class="page-total"></span></span>
    </div>
</div>

<script>
/* Simple page number injection for browsers that support it */
(function() {
    if (typeof window.matchMedia === 'function') {
        var style = document.createElement('style');
        style.textContent = '@page { @bottom-center { content: "Page " counter(page) " of " counter(pages); font-size: 7pt; color: #5b6b60; } }';
        document.head.appendChild(style);
    }
})();
</script>

</body>
</html>