<?php

declare(strict_types=1);

/**
 * Generate one PIN, or a PIN for a whole class.
 *
 * This is the only page in the portal where a result PIN exists as readable
 * text, and it says so on the page that shows it. The PIN is hashed before it is
 * written, so nothing here can be recovered later - the honest answer to "I have
 * lost the PIN" is "issue a new one", and this screen does that in one step.
 *
 * Two modes:
 *
 *   one    - pick a student, a session, an expiry date and an attempt ceiling.
 *   class  - pick a class and a session, and every student who already has an
 *            approved or locked report gets a PIN. Students whose report is not
 *            ready are listed by name as skipped rather than quietly dropped,
 *            because a silent skip looks like a PIN was issued and then lost.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Inputs ----------------------------------------------------------------- */
$studentId     = queryInt('student_id');
$sessionId     = queryInt('session_id');
$classId       = queryInt('class_id');
$termId        = queryInt('term_id');
$issuedPins    = [];
$issuedSummary = null;
$errors        = [];
$form          = [
    'student_id'   => '',
    'session_id'   => '',
    'class_id'     => '',
    'term_id'      => '',
    'expires_at'   => date('Y-m-d', strtotime('+30 days')),
    'max_attempts' => (string) RESULT_PIN_DEFAULT_MAX_ATTEMPTS,
    'single_use'   => '0',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    $form = [
        'student_id'   => field('student_id', 10),
        'session_id'   => field('session_id', 10),
        'class_id'     => field('class_id', 10),
        'term_id'      => field('term_id', 10),
        'expires_at'   => field('expires_at', 10),
        'max_attempts' => field('max_attempts', 3),
        'single_use'   => !empty($_POST['single_use']) ? '1' : '0',
    ];

    $mode = field('mode', 10);

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/result-pin-generate.php');
    }

    $common = [
        'session_id'   => (int) $form['session_id'],
        'expires_at'   => $form['expires_at'],
        'max_attempts' => (int) $form['max_attempts'],
        'single_use'   => (int) $form['single_use'],
    ];

    if ($mode === 'class') {
        $class = (int) $form['class_id'];
        $term  = (int) $form['term_id'];

        $checked = validateResultPinInput($common, null, false);

        if ($class <= 0 || findClass($class) === null) {
            $errors['class_id'] = 'Please choose a class.';
        }

        $termRow = findTerm($term);

        if ($termRow === null || (int) $termRow['session_id'] !== (int) $form['session_id']) {
            $errors['term_id'] = 'Please choose a term that belongs to the selected session.';
        }

        if ($errors !== [] || !$checked['valid']) {
            $errors = $errors + $checked['errors'];
            setFlash('error', implode(' ', array_values($errors)));
            redirect('admin/result-pin-generate.php?session_id=' . (int) $form['session_id']
                . '&class_id=' . $class . '&term_id=' . $term);
        }

        $outcome = generateClassResultPins($class, (int) $form['session_id'], $term, $common);

        if ($outcome['issued'] === 0) {
            setFlash('error', 'No PIN was issued. Every student in that class either already has one or has no approved result yet.');
            redirect('admin/result-pin-generate.php?session_id=' . (int) $form['session_id']
                . '&class_id=' . $class . '&term_id=' . $term);
        }

        $issuedSummary = [
            'issued'  => $outcome['issued'],
            'skipped' => $outcome['skipped'],
        ];
        $issuedPins = $outcome['pins'];

        /* Deliberately NOT redirected: the secrets exist only in this response,
           so this page renders them once and refreshing loses them. */
    } else {
        $checked = validateResultPinInput(array_merge($common, [
            'student_id' => $form['student_id'],
        ]));

        if (!$checked['valid']) {
            setFlash('error', implode(' ', array_values($checked['errors'])));
            redirect('admin/result-pin-generate.php?student_id=' . (int) $form['student_id']
                . '&session_id=' . (int) $form['session_id']);
        }

        $outcome = generateResultPin($checked['value']);

        if (!$outcome['ok']) {
            setFlash('error', $outcome['message']);
            redirect('admin/result-pin-generate.php?student_id=' . (int) $form['student_id']
                . '&session_id=' . (int) $form['session_id']);
        }

        $issuedPins = [[
            'student'   => (string) ((array) findStudent($checked['value']['student_id']))['admission_number'],
            'name'      => studentFullName((array) findStudent($checked['value']['student_id'])),
            'pin'       => (string) $outcome['pin'],
            'reference' => (string) $outcome['reference'],
        ]];
        $issuedSummary = ['issued' => 1, 'skipped' => []];
    }
}

/* --- Select options --------------------------------------------------------- */

$sessions   = sessionOptions();
$sessionId  = $sessionId ?? (int) ($form['session_id'] ?: 0);
$sessionId  = $sessionId > 0 ? $sessionId : (int) (($sessions[0]['id'] ?? 0));
$terms      = $sessionId > 0 ? sessionTermOptions($sessionId) : [];
$classes    = classOptions();
$termId     = $termId ?? (int) ($form['term_id'] ?: 0);

if ($studentId === null) {
    $studentId = (int) ($form['student_id'] ?: 0) ?: null;
}

if ($classId === null) {
    $classId = (int) ($form['class_id'] ?: 0) ?: null;
}

/* The students who can actually be given a PIN right now: those with an approved
   or locked report in the chosen session. Offering anybody else would only
   produce a PIN that cannot open anything. */
$pinEligible = [];

if ($sessionId > 0) {
    $pinEligible = dbAll(
        "SELECT s.id, s.admission_number, s.first_name, s.middle_name, s.last_name, c.class_name
           FROM report_cards rc
           JOIN students s ON s.id = rc.student_id
           LEFT JOIN classes c ON c.id = rc.class_id
          WHERE rc.session_id = :session_id
            AND rc.status IN ('approved', 'locked')
          GROUP BY s.id, s.admission_number, s.first_name, s.middle_name, s.last_name, c.class_name
          ORDER BY s.first_name, s.last_name",
        [':session_id' => $sessionId]
    );
}

$readyCheck = $classId !== null && $termId > 0
    ? validateResultPublication((int) $classId, $sessionId, $termId)
    : null;

/**
 * The four settings both forms share, rendered identically.
 *
 * Kept in one place so the single and the whole-class mode cannot end up with
 * different defaults: a PIN issued through the class button must expire and lock
 * exactly like one issued through the student button.
 */
$pinSharedFields = static function (array $sessions, int $sessionId, array $form, array $errors): string {
    $html = '<div class="mb-3">'
        . '<label class="form-label required" for="shared_session">Academic Session</label>'
        . '<select class="' . inputClass($errors, 'session_id') . '" id="shared_session" name="session_id" required>';

    foreach ($sessions as $option) {
        $html .= '<option value="' . (int) $option['id'] . '"'
            . isSelected((string) $sessionId, (string) $option['id']) . '>'
            . e($option['session_name'])
            . ((int) $option['is_current'] === 1 ? ' (current)' : '')
            . '</option>';
    }

    $html .= '</select>'
        . inlineError($errors, 'session_id')
        . '<div class="form-text">The PIN is scoped to this session and cannot open a result from another year.</div>'
        . '</div>';

    $html .= '<div class="row g-3">'
        . '<div class="col-sm-6 mb-3">'
        . '<label class="form-label required" for="shared_expires">Expiry Date</label>'
        . '<input class="' . inputClass($errors, 'expires_at') . '" type="date" id="shared_expires"'
        . ' name="expires_at" required value="' . e((string) $form['expires_at']) . '">'
        . inlineError($errors, 'expires_at')
        . '</div>'
        . '<div class="col-sm-6 mb-3">'
        . '<label class="form-label required" for="shared_attempts">Maximum Attempts</label>'
        . '<select class="' . inputClass($errors, 'max_attempts') . '" id="shared_attempts" name="max_attempts" required>';

    foreach (resultPinAttemptChoices() as $choice) {
        $html .= '<option value="' . (int) $choice . '"'
            . isSelected((string) $form['max_attempts'], (string) $choice) . '>'
            . $choice . ' failed attempt' . ($choice === 1 ? '' : 's') . '</option>';
    }

    $html .= '</select>'
        . inlineError($errors, 'max_attempts')
        . '<div class="form-text">After this many wrong guesses the PIN blocks itself.</div>'
        . '</div>'
        . '</div>';

    $html .= '<div class="form-check mb-3">'
        . '<input class="form-check-input" type="checkbox" id="single_use" name="single_use" value="1"'
        . ((string) $form['single_use'] === '1' ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="single_use">Single use</label>'
        . '<div class="form-text">'
        . 'Left off, the PIN keeps working until it expires, so a parent can check the '
        . 'result again without asking for another one. Turn it on to retire the PIN '
        . 'after its first successful check.'
        . '</div>'
        . '</div>';

    return $html;
};

$pageTitle    = 'Generate Result PIN';
$pageHeading  = 'Generate Result PIN';
$pageSubtitle = 'Issue the PIN a parent uses on the public result checker.';
$activeMenu   = 'resultpins';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/result-pins.php')) ?>">
            <i class="bi bi-arrow-left"></i> All Result PINs
        </a>
    </div>
</div>

<?php if ($issuedPins !== []): ?>
    <div class="card table-card mb-3" style="border:2px solid var(--gold)">
        <div class="table-card__head" style="background:var(--gold)">
            <h2 class="table-card__title" style="color:#fff">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Write these down now
                <small style="color:#fff">shown once</small>
            </h2>
        </div>

        <div class="table-card__body">
            <div class="alert alert-warning mb-3">
                <i class="bi bi-key-fill"></i>
                Only the <strong>hash</strong> of each PIN was stored, so this is the only
                time it will ever be shown. If it is lost, issue a new PIN from
                <a href="<?= e(assetUrl('admin/result-pins.php')) ?>">the PIN list</a> -
                the old one is retired automatically.
            </div>

            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Student</th>
                        <th>Admission No.</th>
                        <th>Result PIN</th>
                        <th>PIN Reference</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($issuedPins as $issued): ?>
                        <tr>
                            <td class="cell-strong"><?= e($issued['name']) ?></td>
                            <td class="mono"><?= e($issued['student']) ?></td>
                            <td>
                                <span class="mono fs-5 fw-bold text-green"><?= e($issued['pin']) ?></span>
                            </td>
                            <td class="mono"><?= e($issued['reference']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <button class="btn btn-sm btn-green" type="button" onclick="window.print()">
                    <i class="bi bi-printer-fill"></i> Print This Slip
                </button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(assetUrl('admin/result-pin-generate.php')) ?>">
                    <i class="bi bi-plus-circle"></i> Issue More
                </a>
            </div>

            <?php if ($issuedSummary !== null && $issuedSummary['skipped'] !== []): ?>
                <div class="alert alert-info mt-3 mb-0">
                    <strong><?= count($issuedSummary['skipped']) ?></strong> student(s)
                    in that class did not receive a PIN:
                    <ul class="mb-0 mt-1">
                        <?php foreach (array_slice($issuedSummary['skipped'], 0, 30) as $skip): ?>
                            <li>
                                <?= e($skip['name']) ?>
                                <span class="text-muted">- <?= e($skip['reason']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card form-card">
            <div class="form-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-person-fill text-green"></i> Issue One PIN
                </h2>
            </div>

            <div class="form-card__body">
                <form method="post" data-validate novalidate
                      action="<?= e(assetUrl('admin/result-pin-generate.php')) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="mode" value="one">

                    <div class="mb-3">
                        <label class="form-label required" for="student_id">Student</label>
                        <select class="<?= inputClass($errors, 'student_id') ?>" id="student_id"
                                name="student_id" required>
                            <option value="">Choose a student</option>
                            <?php foreach ($pinEligible as $candidate): ?>
                                <option value="<?= (int) $candidate['id'] ?>"
                                    <?= isSelected((string) $studentId, (string) $candidate['id']) ?>>
                                    <?= e(studentFullName($candidate)) ?>
                                    (<?= e($candidate['admission_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= inlineError($errors, 'student_id') ?>
                        <?php if ($pinEligible === []): ?>
                            <div class="form-text">
                                No student has an approved or locked report in this session yet.
                                Generate and approve a report card first.
                            </div>
                        <?php else: ?>
                            <div class="form-text">
                                Only students with an approved or locked report card are listed.
                            </div>
                        <?php endif; ?>
                    </div>

                    <?= $pinSharedFields($sessions, $sessionId, $form, $errors) ?>

                    <button class="btn btn-green w-100" type="submit">
                        <i class="bi bi-key-fill"></i> Generate PIN
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card form-card">
            <div class="form-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-people-fill text-green"></i> Issue A PIN For A Whole Class
                    <small>every student with an approved result</small>
                </h2>
            </div>

            <div class="form-card__body">
                <form method="post" data-validate novalidate
                      action="<?= e(assetUrl('admin/result-pin-generate.php')) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="mode" value="class">

                    <div class="mb-3">
                        <label class="form-label required" for="class_id">Class</label>
                        <select class="<?= inputClass($errors, 'class_id') ?>" id="class_id"
                                name="class_id" required>
                            <option value="">Choose a class</option>
                            <?php foreach ($classes as $option): ?>
                                <option value="<?= (int) $option['id'] ?>"
                                    <?= isSelected((string) $classId, (string) $option['id']) ?>>
                                    <?= e($option['class_name']) ?> (<?= e($option['section']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= inlineError($errors, 'class_id') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label required" for="term_id">Term</label>
                        <select class="<?= inputClass($errors, 'term_id') ?>" id="term_id"
                                name="term_id" required>
                            <option value="">Choose a term</option>
                            <?php foreach ($terms as $option): ?>
                                <option value="<?= (int) $option['id'] ?>"
                                    <?= isSelected((string) $termId, (string) $option['id']) ?>>
                                    <?= e($option['term_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= inlineError($errors, 'term_id') ?>
                        <div class="form-text">
                            The term decides which result card the PIN opens.
                        </div>
                    </div>

                    <?= $pinSharedFields($sessions, $sessionId, $form, $errors) ?>

                    <?php if ($readyCheck !== null): ?>
                        <div class="alert alert-<?= $readyCheck['ready'] ? 'success' : 'warning' ?>">
                            <?php if ($readyCheck['ready']): ?>
                                <i class="bi bi-check-circle-fill"></i>
                                <?= e($readyCheck['message']) ?>
                            <?php else: ?>
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <?= e($readyCheck['message']) ?>
                                <?php if ($readyCheck['pending'] !== []): ?>
                                    <details class="mt-1">
                                        <summary>Show the students holding it back</summary>
                                        <ul class="mb-0 mt-1 small">
                                            <?php foreach ($readyCheck['pending'] as $name): ?>
                                                <li><?= e($name) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <button class="btn btn-gold w-100" type="submit">
                        <i class="bi bi-people-fill"></i> Generate PINs For The Class
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card info-card mt-3 mb-4">
    <div class="info-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-shield-lock text-green"></i> What The PIN Is And Is Not
        </h2>
    </div>
    <div class="info-card__body">
        <div class="row g-3">
            <div class="col-md-6">
                <h3 class="h6">It is</h3>
                <ul class="mb-0 small">
                    <li>Eight random digits, made with <span class="mono">random_int()</span>.</li>
                    <li>Stored only as a <span class="mono">password_hash()</span> of its digits.</li>
                    <li>Scoped to one student in one academic session.</li>
                    <li>Shown on this page once, and never again.</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h3 class="h6">It is not</h3>
                <ul class="mb-0 small">
                    <li>Not recoverable. There is nothing to look it up with.</li>
                    <li>Not the PIN reference. The reference is public and safe to quote.</li>
                    <li>Not a login. It opens exactly one result, and only once published.</li>
                    <li>Not needed again after the first check, unless the school made it single use.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
