<?php

declare(strict_types=1);

/**
 * Public result checker.
 *
 * The one page on the portal a parent can reach without an account. It asks for
 * four things and shows nothing until all four are right:
 *
 *   Admission Number  -  who the result belongs to
 *   Date of Birth     -  identity verification
 *   Academic Session  -  which year
 *   Term              -  which term
 *
 * None of them is enough on its own. An admission number is a name and a class;
 * it is not a secret, so it is never possible to look anybody up by typing it in.
 *
 * The two dropdowns only ever offer sessions and terms the school has actually
 * published something in, so the form cannot be used to discover which years this
 * school runs or which classes exist in them.
 *
 * On success the result is opened by a short lived server side session that closes
 * after 30 minutes. The result never appears in a URL, in the browser history,
 * or on a page a parent might bookmark or photograph.
 *
 * Every attempt is logged, per IP address, with a fixed reason code.
 */

require_once __DIR__ . '/config/functions.php';

sendPublicResultSecurityHeaders();

/* Load dynamic school settings */
$settings = getSchoolSettings();
$logoPath = $settings['logo_path'] ?? 'assets/images/logo.jpeg';

/* The two dropdowns describe what has been published, never what merely exists. */
$sessions = publishedResultSessionOptions();
$termMap  = publishedResultTermMap();

$form = [
    'admission_number' => '',
    'date_of_birth'    => '',
    'session_id'       => '',
    'term_id'          => '',
];

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'admission_number' => postString('admission_number', 40),
        'date_of_birth'    => postString('date_of_birth', 20),
        'session_id'       => (string) (int) postString('session_id', 10),
        'term_id'          => (string) (int) postString('term_id', 10),
    ];

    $sessionId = (int) $form['session_id'];
    $termId    = (int) $form['term_id'];

    /* The network throttle comes first. It is deliberately generous, because a
       school sits behind one NAT address and a morning of parents must not lock
       each other out. */
    if (publicResultIpThrottleExceeded()) {
        logResultAccess(null, null, $sessionId > 0 ? $sessionId : null, $termId > 0 ? $termId : null, false, 'rate_limited');

        $errorMessage = 'Too many result checks have been made from this network. '
            . 'Please wait a few minutes and try again, or contact the school office.';
    } elseif (!sessionTermHasPublishedResults($sessionId, $termId)) {
        /* A session and term the school has published nothing in. Refused without
           touching the student table, and without saying whether the student or
           the admission number exists. */
        logResultAccess(null, null, $sessionId > 0 ? $sessionId : null, $termId > 0 ? $termId : null, false, 'not_published');

        $errorMessage = 'Results for that academic session and term have not been published yet. '
            . 'Please check with the school office.';
    } else {
        $outcome = verifyPublicResultAccessByIdentity(
            $form['admission_number'],
            $form['date_of_birth'],
            $sessionId,
            $termId
        );

        if ($outcome['ok'] && $outcome['report_id'] !== null) {
            grantPublicResultAccess($outcome['report_id'], null);

            redirect('result-view.php');
        }

        $errorMessage = $outcome['message'];
    }
}

$formSessionId = (int) $form['session_id'];
$terms         = $termMap[$formSessionId] ?? [];

/* Open on something the visitor can actually use: the session and term they
   chose if those are still published, otherwise the first published pair. */
if ($sessions !== [] && ($formSessionId === 0 || !isset($termMap[$formSessionId]))) {
    foreach ($sessions as $candidate) {
        if (isset($termMap[(int) $candidate['id']])) {
            $formSessionId = (int) $candidate['id'];
            $terms         = $termMap[$formSessionId];
            break;
        }
    }
}

$defaultTermId = (int) $form['term_id'];

if ($defaultTermId === 0 && $terms !== []) {
    $defaultTermId = (int) $terms[0]['id'];
}

$flashes   = pullFlashes();
$pageTitle = 'Check Result';

/* One JSON payload for the session/term pair control below. The hex flags matter:
   a term name is school-entered text, and without them a name containing "</script>"
   would close the block and turn the rest of the page into markup. */
$termMapJson = json_encode(
    $termMap,
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Check a published <?= e($settings['school_short_name'] ?? SCHOOL_SHORT_NAME) ?> result using your admission number and date of birth.">
    <title><?= e($pageTitle) ?> | <?= e($settings['school_short_name'] ?? SCHOOL_SHORT_NAME) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(assetUrl('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="auth-body">

<div class="auth-card" style="max-width:560px">

    <div class="auth-card__head">
        <img src="<?= e(assetUrl($logoPath)) ?>" alt="<?= e($settings['school_short_name'] ?? SCHOOL_SHORT_NAME) ?> logo">
        <h1><?= e($settings['school_name'] ?? SCHOOL_NAME) ?></h1>
        <p><?= e($settings['school_campaign'] ?? SCHOOL_CAMPAIGN) ?></p>
    </div>

    <div class="auth-card__body">

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : 'success') ?>" role="alert">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                <span><?= e($errorMessage) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($sessions === []): ?>
            <h2 class="h5 text-center mb-3" style="color:var(--green)">Check Result</h2>

            <div class="alert alert-info d-flex align-items-start gap-2 mb-0" role="alert">
                <i class="bi bi-info-circle-fill mt-1"></i>
                <span>
                    No results have been published yet. Please contact the school office
                    for your result.
                </span>
            </div>
        <?php else: ?>

            <h2 class="h5 text-center mb-1" style="color:var(--green)">Check Result</h2>
            <p class="text-center text-muted small mb-3">
                Enter all four details exactly as they appear on your school records.
            </p>

            <form method="post" action="<?= e(assetUrl('result-checker.php')) ?>" novalidate>
                <div class="mb-3">
                    <label class="form-label required" for="admission_number">Admission Number</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                        <input class="form-control" type="text" id="admission_number"
                               name="admission_number" maxlength="40" required
                               autocomplete="off" autocapitalize="characters"
                               placeholder="ORM/2026/001"
                               value="<?= e($form['admission_number']) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label required" for="date_of_birth">Date of Birth</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-calendar-date"></i></span>
                        <input class="form-control" type="date" id="date_of_birth"
                               name="date_of_birth" required
                               autocomplete="off"
                               value="<?= e($form['date_of_birth']) ?>">
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6 mb-3">
                        <label class="form-label required" for="session_id">Academic Session</label>
                        <select class="form-select" id="session_id" name="session_id" required>
                            <?php foreach ($sessions as $session): ?>
                                <option value="<?= (int) $session['id'] ?>"
                                    <?= (string) $formSessionId === (string) $session['id'] ? ' selected' : '' ?>>
                                    <?= e($session['session_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-sm-6 mb-3">
                        <label class="form-label required" for="term_id">Term</label>
                        <select class="form-select" id="term_id" name="term_id" required>
                            <?php if ($terms === []): ?>
                                <option value="">No published term</option>
                            <?php endif; ?>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= (int) $term['id'] ?>"
                                    <?= (string) $defaultTermId === (string) $term['id'] ? ' selected' : '' ?>>
                                    <?= e($term['term_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button class="btn btn-green w-100" type="submit">
                    <i class="bi bi-search"></i> Check Result
                </button>
            </form>

            <hr class="my-4">

            <div class="small text-muted">
                <p class="mb-2">
                    <i class="bi bi-shield-lock me-1"></i>
                    Your result opens on the next page and closes by itself after
                    <?= (int) (PUBLIC_RESULT_SESSION_SECONDS / 60) ?> minutes.
                </p>
                <p class="mb-0">
                    <i class="bi bi-question-circle me-1"></i>
                    If you cannot access your result, please contact the school office.
                </p>
            </div>
        <?php endif; ?>

        <p class="text-center mt-3 mb-0" style="font-size:.74rem;color:var(--muted)">
            <?= e(SCHOOL_MOTTO) ?>
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(assetUrl('assets/js/app.js')) ?>"></script>
<script>
/* The term dropdown only ever offers terms of the chosen session. The server has
   already put that restriction on what it accepts, so this is purely to keep the
   two lists in step as the visitor changes session: one query on page load
   carries the whole map, and no further request is made. */
(function () {
    var termMap = <?= $termMapJson ?>;
    var sessionSelect = document.getElementById('session_id');
    var termSelect = document.getElementById('term_id');

    if (!sessionSelect || !termSelect) {
        return;
    }

    sessionSelect.addEventListener('change', function () {
        var terms = termMap[sessionSelect.value] || [];

        termSelect.innerHTML = '';

        if (terms.length === 0) {
            termSelect.appendChild(new Option('No published term', ''));
            termSelect.disabled = true;
            return;
        }

        termSelect.disabled = false;
        terms.forEach(function (term) {
            termSelect.appendChild(new Option(term.term_name, term.id));
        });
    });
}());
</script>
</body>
</html>