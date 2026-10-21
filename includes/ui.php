<?php

declare(strict_types=1);

/**
 * Reusable rendering helpers for the admin pages.
 * Keeps pagination, badges and form-state markup out of the page files.
 */

require_once __DIR__ . '/../config/functions.php';

/**
 * Render the queued flash messages as dismissable Bootstrap alerts.
 * Every value is escaped; only 'error' maps to the danger style.
 */
function renderFlashes(array $flashes): string
{
    $html = '';

    foreach ($flashes as $flash) {
        if (!is_array($flash) || !isset($flash['message'])) {
            continue;
        }

        $isError = ($flash['type'] ?? '') === 'error';

        $html .= '<div class="alert alert-' . ($isError ? 'danger' : 'success')
            . ' d-flex align-items-center gap-2" data-flash role="alert">'
            . '<i class="bi bi-' . ($isError ? 'exclamation-triangle-fill' : 'check-circle-fill') . '"></i>'
            . '<span>' . e((string) $flash['message']) . '</span>'
            . '<button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>'
            . '</div>';
    }

    return $html;
}

/** Build the current query string with optional overrides. */
function currentQueryString(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return http_build_query($query);
}

/** URL of the current page with overrides applied. */
function currentUrl(array $overrides = []): string
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));

    return assetUrl('admin/' . $script) . '?' . currentQueryString($overrides);
}

/** Status pill. */
function statusBadge(string $status): string
{
    return '<span class="badge ' . e(statusBadgeClass($status)) . '">' . e($status) . '</span>';
}

/** Section pill. */
function sectionBadge(string $section): string
{
    $colours = [
        'Nursery'   => 'bg-info text-dark',
        'Primary'   => 'bg-primary',
        'Secondary' => 'bg-dark',
    ];

    return '<span class="badge ' . e($colours[$section] ?? 'bg-secondary') . '">' . e($section) . '</span>';
}

/** Field error message markup. */
function inlineError(array $errors, string $key): string
{
    if (!isset($errors[$key])) {
        return '';
    }

    return '<div class="invalid-feedback d-block">' . e($errors[$key]) . '</div>';
}

/** CSS classes for an input that failed validation. */
function inputClass(array $errors, string $key, string $extra = ''): string
{
    $classes = ['form-control'];

    if (isset($errors[$key])) {
        $classes[] = 'is-invalid';
    }

    if ($extra !== '') {
        $classes[] = $extra;
    }

    return implode(' ', $classes);
}

/** "selected" attribute helper. */
function isSelected(string $current, string $expected): string
{
    return $current === $expected ? ' selected' : '';
}

/** Summary table row used on the view pages. */
function detailRow(string $label, string $value): string
{
    return '<div class="meta-row"><span class="meta-row__label">' . e($label)
        . '</span><span class="meta-row__value">' . $value . '</span></div>';
}

/** Placeholder row for an empty table. */
function emptyRow(int $columns, string $message): string
{
    return '<tr><td colspan="' . $columns . '" class="text-center text-muted py-4">'
        . '<i class="bi bi-inbox me-1"></i>' . e($message) . '</td></tr>';
}

/**
 * Pagination control. Keeps every current filter in the links.
 */
function renderPagination(array $paging, string $label = 'records'): string
{
    if ($paging['pages'] <= 1) {
        if ($paging['total'] === 0) {
            return '';
        }

        return '<div class="list-foot">Showing <strong>1</strong> to <strong>' . (int) $paging['to']
            . '</strong> of <strong>' . (int) $paging['total'] . '</strong> ' . e($label) . '</div>';
    }

    $page    = (int) $paging['page'];
    $pages   = (int) $paging['pages'];
    $numbers = [];

    $window = 2;
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= $window) {
            $numbers[] = $i;
        } elseif (end($numbers) !== '...') {
            $numbers[] = '...';
        }
    }

    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';

    $html .= $page > 1
        ? '<li class="page-item"><a class="page-link" href="' . e(currentUrl(['page' => $page - 1])) . '">&laquo;</a></li>'
        : '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';

    foreach ($numbers as $number) {
        if ($number === '...') {
            $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            continue;
        }

        $html .= (int) $number === $page
            ? '<li class="page-item active"><span class="page-link">' . $number . '</span></li>'
            : '<li class="page-item"><a class="page-link" href="' . e(currentUrl(['page' => $number])) . '">' . $number . '</a></li>';
    }

    $html .= $page < $pages
        ? '<li class="page-item"><a class="page-link" href="' . e(currentUrl(['page' => $page + 1])) . '">&raquo;</a></li>'
        : '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';

    $html .= '</ul></nav>';

    return '<div class="list-foot"><span>Showing <strong>' . (int) $paging['from'] . '</strong> to <strong>'
        . (int) $paging['to'] . '</strong> of <strong>' . (int) $paging['total'] . '</strong> ' . e($label) . '</span>'
        . $html . '</div>';
}

/** Records-per-page selector. */
function renderPerPageSelector(int $perPage): string
{
    $html = '<label class="form-label mb-0 small text-muted" for="per_page">Show</label>'
        . '<select class="form-select form-select-sm w-auto" name="per_page" id="per_page" onchange="this.form.submit()">';

    foreach (PER_PAGE_OPTIONS as $option) {
        $html .= '<option value="' . $option . '"' . isSelected((string) $perPage, (string) $option) . '>' . $option . '</option>';
    }

    return $html . '</select>';
}
