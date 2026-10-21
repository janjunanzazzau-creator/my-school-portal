<?php

declare(strict_types=1);

/**
 * Submit a result sheet to the administrator.
 *
 * Submission is refused unless every enrolled student has all four component
 * scores, and the teacher is told exactly which students are blocking it. Once
 * submitted the sheet leaves the teacher's hands: the score boxes are read only
 * until an administrator returns it with a reason.
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
    setFlash('error', 'Results can only be submitted from the result sheet.');
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

if (!teacherCanManageResult($teacherId, $classId, $subjectId, $sessionId)) {
    setFlash('error', 'You are not actively assigned to that class and subject in the selected session, so nothing was submitted.');
    redirect($returnTo . '?' . $backQuery);
}

$term = findTerm($termId);

if ($term === null || (int) $term['session_id'] !== $sessionId) {
    setFlash('error', 'The selected term does not belong to the selected academic session, so nothing was submitted.');
    redirect($returnTo . '?' . $backQuery);
}

/* Save anything still sitting in the form, so a teacher who types the last
   missing score and presses Submit does not have to press Save first. */
$posted = $_POST['scores'] ?? [];

if (is_array($posted)) {
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

    if ($rows !== []) {
        try {
            $saved = saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, $rows);

            if ($saved['errors'] !== []) {
                setFlash(
                    'error',
                    'Some scores could not be read, so the sheet was not submitted. '
                    . implode(' ', array_slice($saved['errors'], 0, 3))
                );
                redirect($returnTo . '?' . $backQuery);
            }
        } catch (Throwable $exception) {
            error_log('[PHASE4] Unable to save result sheet before submission: ' . $exception->getMessage());
            setFlash('error', 'The scores could not be saved, so the sheet was not submitted. Please try again.');
            redirect($returnTo . '?' . $backQuery);
        }
    }
}

try {
    $outcome = submitResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId);
} catch (Throwable $exception) {
    error_log('[PHASE4] Unable to submit result sheet: ' . $exception->getMessage());
    setFlash('error', 'The results could not be submitted. Please try again.');
    redirect($returnTo . '?' . $backQuery);
}

if ($outcome['submitted'] === 0 && $outcome['missing'] === [] && strpos($outcome['message'], 'already') !== false) {
    setFlash('warning', $outcome['message']);
} elseif ($outcome['submitted'] === 0) {
    setFlash('error', $outcome['message']);
} else {
    setFlash('success', $outcome['message'] . ' The administrator will review it before the results are released.');
}

redirect($returnTo . '?' . $backQuery);
