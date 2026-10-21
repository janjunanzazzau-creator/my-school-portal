<?php

declare(strict_types=1);

/**
 * Save a teacher's result sheet as a draft.
 *
 * This is a POST endpoint only: it never renders a page, it saves and redirects.
 * Everything it is told is checked again here rather than trusted from
 * enter-result.php, because a request can be posted directly.
 */

require_once __DIR__ . '/../includes/teacher_bootstrap.php';

$profile = currentTeacherProfile();

if ($profile === null) {
    setFlash('error', 'No teacher record is linked to your account. Please contact the administrator.');
    redirect('login.php');
}

$teacherId = (int) $profile['id'];
$backQuery = 'class_id=' . (int) ($_POST['class_id'] ?? 0) . '&subject_id=' . (int) ($_POST['subject_id'] ?? 0)
    . '&session_id=' . (int) ($_POST['session_id'] ?? 0) . '&term_id=' . (int) ($_POST['term_id'] ?? 0);

$returnTo = 'teacher/enter-result.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Results can only be saved from the result sheet.');
    redirect($returnTo . '?' . $backQuery);
}

$token = $_POST[CSRF_FIELD_NAME] ?? null;

if (!verifyCsrfToken(is_string($token) ? $token : null)) {
    setFlash('error', 'Your session expired. Please try again.');
    redirect($returnTo . '?' . $backQuery);
}

$classId   = postIdList('class_id')[0] ?? null;
$subjectId = postIdList('subject_id')[0] ?? null;
$sessionId = postIdList('session_id')[0] ?? null;
$termId    = postIdList('term_id')[0] ?? null;

if ($classId === null || $subjectId === null || $sessionId === null || $termId === null) {
    setFlash('error', 'The result sheet was incomplete. Please choose the class, subject, session and term again.');
    redirect('teacher/enter-result.php');
}

/* The allocation is re-checked on the server. A teacher who edits the hidden
   fields in the form to reach a class they are not assigned to is refused here. */
if (!teacherCanManageResult($teacherId, $classId, $subjectId, $sessionId)) {
    setFlash('error', 'You are not actively assigned to that class and subject in the selected session, so nothing was saved.');
    redirect($returnTo . '?' . $backQuery);
}

$term = findTerm($termId);

if ($term === null || (int) $term['session_id'] !== $sessionId) {
    setFlash('error', 'The selected term does not belong to the selected academic session, so nothing was saved.');
    redirect($returnTo . '?' . $backQuery);
}

/* Only array shaped score input is accepted; anything else is ignored. */
$posted = $_POST['scores'] ?? [];

if (!is_array($posted)) {
    $posted = [];
}

/* Keep only the students who really sent a row, so an empty form submission
   cannot wipe a saved sheet. */
$rows = [];

foreach ($posted as $studentId => $fields) {
    $studentId = (int) $studentId;

    if ($studentId <= 0 || !is_array($fields)) {
        continue;
    }

    $hasValue = false;

    foreach (array_merge(resultAssessmentCodes(), ['teacher_remark']) as $key) {
        $value = $fields[$key] ?? '';

        if (is_string($value) && trim($value) !== '') {
            $hasValue = true;
            break;
        }
    }

    if ($hasValue) {
        $rows[$studentId] = $fields;
    }
}

if ($rows === []) {
    setFlash('error', 'No scores were entered, so there was nothing to save.');
    redirect($returnTo . '?' . $backQuery);
}

try {
    $outcome = saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, $rows);
} catch (Throwable $exception) {
    error_log('[PHASE4] Unable to save result sheet: ' . $exception->getMessage());
    setFlash('error', 'The scores could not be saved. Please try again.');
    redirect($returnTo . '?' . $backQuery);
}

$messages = [];

if ($outcome['created'] > 0) {
    $messages[] = $outcome['created'] . ' new result' . ($outcome['created'] === 1 ? '' : 's') . ' saved';
}

if ($outcome['updated'] > 0) {
    $messages[] = $outcome['updated'] . ' result' . ($outcome['updated'] === 1 ? '' : 's') . ' updated';
}

if ($messages === []) {
    $messages[] = 'No result was changed';
}

$message = implode(', ', $messages) . ' as a draft. Review the sheet, then submit it for approval.';

if ($outcome['errors'] !== []) {
    setFlash('error', $message . ' Some rows were not saved: ' . implode(' ', array_slice($outcome['errors'], 0, 3)));
} elseif ($outcome['skipped'] !== []) {
    setFlash(
        'warning',
        $message . ' '
        . count($outcome['skipped']) . ' row'
        . (count($outcome['skipped']) === 1 ? ' was' : 's were')
        . ' left alone because they are no longer a draft.'
    );
} else {
    setFlash('success', $message);
}

redirect($returnTo . '?' . $backQuery);
