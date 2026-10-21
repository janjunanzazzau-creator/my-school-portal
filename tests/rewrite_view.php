<?php
$path = 'C:/xampp/htdocs/school-result-portal/result-view.php';
$src  = file_get_contents($path);

$articlePos = strpos($src, '<article class="report-sheet">');
$footPos    = strpos($src, '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>');

if ($articlePos === false || $footPos === false || $footPos < $articlePos) {
    fwrite(STDERR, "Could not locate the split points.\n");
    exit(1);
}

$head   = substr($src, 0, $articlePos);
$footer = substr($src, $footPos);

/* The on-screen wrapper is widened so the A4 sheet is not letterboxed. */
$head = str_replace(
    'style="max-width:900px;margin:0 auto;padding:1rem .5rem 0"',
    'style="max-width:800px;margin:0 auto;padding:1rem .5rem 0"',
    $head
);

$middle = <<<'PHPCODE'
<?php /* The report card itself is the shared sheet, identical to the office
        print and the parent print. This page only adds the countdown and the
        print/close controls above it; it draws no part of the card itself. */ ?>
<?php require __DIR__ . '/includes/report-card-body.php'; ?>

PHPCODE;

file_put_contents($path, $head . $middle . $footer);

echo "rewritten: " . strlen($head . $middle . $footer) . " bytes\n";