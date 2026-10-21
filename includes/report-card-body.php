<?php

declare(strict_types=1);

/**
 * The report card sheet.
 *
 * ONE renderer, shared by admin/print-report-card.php, public-result-print.php
 * and result-view.php. Those three pages differ only in how they are allowed to
 * reach a report card and in which fields the visitor is cleared to see; the
 * document itself must look identical in every one of them, so the markup lives
 * here rather than being copied three times.
 *
 * The file prints a document. It is included AFTER the including page has built
 * $rc and has already run its own authorisation gate. It reads $rc, it writes
 * nothing, and it never queries the database, so it cannot widen access to a
 * result the page was not already entitled to show.
 *
 * Required keys of $rc (all of them are produced by reportCardViewData()):
 *
 *   school, logo_url, stamp_url,
 *   student_name, admission_number, gender, age, guardian, guardian_phone, photo_url,
 *   class_name, section, term_name, session_name, term_start, term_end, next_term,
 *   attendance, subjects, structure, max_per_subject,
 *   grade_analysis, extremes, total_obtained, total_obtainable, average,
 *   average_percentage, average_grade, position, population, offered, passed, failed,
 *   psychomotor, effective, scale, teacher_comment, principal_comment, promotion,
 *   verification_code, report_number, status, previous, issued_on, show_guardian_contact
 *
 * Nothing on this page prints a PIN, a date of birth, a password, a session id
 * or any database key.
 */

if (!isset($rc) || !is_array($rc)) {
    throw new RuntimeException('The report card sheet was included without a prepared $rc array.');
}

require_once __DIR__ . '/../config/helpers.php';

/* ------------------------------------------------------------------ */
/* Formatting helpers, local to this document                          */
/* ------------------------------------------------------------------ */

/** Trim trailing zeros: 94.00 becomes 94, 68.50 becomes 68.5. */
$rcNum = static function ($value): string {
    if ($value === null || $value === '') {
        return '&ndash;';
    }

    return e(rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'));
};

/** A value that may be absent, printed as a muted dash rather than blank. */
$rcVal = static function ($value, string $blank = '&ndash;'): string {
    if ($value === null || trim((string) $value) === '') {
        return $blank;
    }

    return e((string) $value);
};

$rcDash  = static function (): string {
    return '&ndash;';
};

$rcScale = $rc['scale'] ?? ratingScale();
$rcBands = resultGradeBands();

/** "94 / 100" style pair used under every assessment column heading. */
$rcComponentMax = static function (string $code) use ($rc): string {
    $component = $rc['structure']['components'][$code] ?? null;

    if ($component === null || (float) $component['max'] <= 0.0) {
        return '';
    }

    return '<span class="rc-table__max">/ ' . e(rtrim(rtrim(number_format((float) $component['max'], 2, '.', ''), '0'), '.')) . '</span>';
};

/**
 * One rating row: the trait, the scale word and five pips.
 * An unrated trait is shown as a dash, never as a zero, because a zero would
 * read as the worst possible rating rather than as "not recorded".
 */
$rcRatingRow = static function (string $label, $value) use ($rcScale, $rcDash): string {
    $rating = $value === null ? null : (int) $value;

    ob_start();
    ?>
    <tr>
        <td><?= e($label) ?></td>
        <td class="rc-pips">
            <?php if ($rating === null): ?>
                <span class="rc-pips__off">&#9675;&#9675;&#9675;&#9675;&#9675;</span>
            <?php else: ?>
                <?php for ($pip = 1; $pip <= 5; $pip++): ?>
                    <?= $pip <= $rating ? '&#9679;' : '<span class="rc-pips__off">&#9675;</span>' ?>
                <?php endfor; ?>
            <?php endif; ?>
        </td>
        <td class="rc-rate<?= $rating === null ? ' rc-rate--none' : '' ?>">
            <?= $rating === null ? '&ndash;' : e((string) ($rcScale[$rating] ?? $rating)) ?>
        </td>
    </tr>
    <?php
    return (string) ob_get_clean();
};

/** Short date for the panels: 12 Mar 2026. */
$rcDate = static function (?string $date): string {
    if ($date === null || trim($date) === '') {
        return '&ndash;';
    }

    try {
        return e((new DateTimeImmutable($date))->format('j M Y'));
    } catch (Throwable) {
        return e($date);
    }
};
?>
<article class="rc-sheet">

    <!-- ============================================================
         1. MASTHEAD
         ============================================================ -->
    <header class="rc-masthead">
        <div class="rc-masthead__brand">
            <img class="rc-masthead__logo" src="<?= e($rc['logo_url']) ?>" alt="<?= e($rc['school']['school_short_name'] ?? '') ?> logo">
        </div>
        <div class="rc-masthead__id">
            <p class="rc-masthead__name"><?= e($rc['school']['school_name'] ?? '') ?></p>
            <?php if (($rc['school']['school_campaign'] ?? '') !== ''): ?>
                <p class="rc-masthead__campaign"><?= e($rc['school']['school_campaign']) ?></p>
            <?php endif; ?>
            <?php if (($rc['school']['school_motto'] ?? '') !== ''): ?>
                <p class="rc-masthead__motto">&ldquo;<?= e($rc['school']['school_motto']) ?>&rdquo;</p>
            <?php endif; ?>
            <p class="rc-masthead__contact">
                <?php if (($rc['school']['school_address'] ?? '') !== ''): ?>
                    <b>Address:</b> <?= e($rc['school']['school_address']) ?><br>
                <?php endif; ?>
                <?php if (($rc['school']['school_phone'] ?? '') !== '' || ($rc['school']['school_email'] ?? '') !== ''): ?>
                    <b>Contact:</b>
                    <?= e(trim(($rc['school']['school_phone'] ?? '') . '  ' . ($rc['school']['school_email'] ?? ''), ' ')) ?>
                <?php endif; ?>
            </p>
        </div>
    </header>

    <!-- ============================================================
         2. REPORT TITLE
         ============================================================ -->
    <div class="rc-titlebar">
        <p class="rc-titlebar__main">Terminal Academic Progress Report</p>
        <p class="rc-titlebar__sub">
            <strong><?= e($rc['term_name']) ?></strong> Term &middot;
            <strong><?= e($rc['session_name']) ?></strong> Academic Session
            <?php if (($rc['status'] ?? '') !== ''): ?>
                &middot; Status: <?= e(ucfirst((string) $rc['status'])) ?>
            <?php endif; ?>
        </p>
    </div>

    <!-- ============================================================
         3. STUDENT INFORMATION PANEL
         ============================================================ -->
    <section class="rc-identity">
        <div class="rc-identity__photo-cell">
            <?php if (($rc['photo_url'] ?? null) !== null): ?>
                <img class="rc-photo" src="<?= e($rc['photo_url']) ?>" alt="Student photograph">
            <?php else: ?>
                <span class="rc-photo rc-photo--empty">Passport<br>Photo</span>
            <?php endif; ?>
        </div>
        <div class="rc-identity__body">
            <h2 class="rc-identity__name"><?= e($rc['student_name']) ?></h2>
            <div class="rc-identity__grid">
                <div class="rc-identity__row">
                    <div class="rc-field">
                        <span class="rc-field__label">Admission No.</span>
                        <span class="rc-field__value mono"><?= e($rc['admission_number']) ?></span>
                    </div>
                    <div class="rc-field">
                        <span class="rc-field__label">Gender</span>
                        <span class="rc-field__value"><?= $rcVal($rc['gender']) ?></span>
                    </div>
                    <div class="rc-field">
                        <span class="rc-field__label">Age</span>
                        <span class="rc-field__value<?= $rc['age'] === null ? ' rc-field__value--muted' : '' ?>">
                            <?= $rc['age'] === null ? 'Not recorded' : e((string) $rc['age']) . ' yrs' ?>
                        </span>
                    </div>
                    <div class="rc-field">
                        <span class="rc-field__label">Class</span>
                        <span class="rc-field__value"><?= e($rc['class_name']) ?></span>
                    </div>
                </div>
                <div class="rc-identity__row">
                    <div class="rc-field">
                        <span class="rc-field__label">Session</span>
                        <span class="rc-field__value"><?= e($rc['session_name']) ?></span>
                    </div>
                    <div class="rc-field">
                        <span class="rc-field__label">Term</span>
                        <span class="rc-field__value"><?= e($rc['term_name']) ?></span>
                    </div>
                    <div class="rc-field">
                        <span class="rc-field__label">Attendance</span>
                        <span class="rc-field__value">
                            <?php $rcAttendance = $rc['attendance']; ?>
                            <?php if ($rcAttendance === null || ($rcAttendance['total'] ?? null) === null): ?>
                                <span class="rc-field__value--muted">Not recorded</span>
                            <?php else: ?>
                                <?= (int) $rcAttendance['present'] ?>/<?= (int) $rcAttendance['total'] ?> days
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="rc-field">
                        <span class="rc-field__label">Parent / Guardian</span>
                        <span class="rc-field__value">
                            <?= $rc['guardian'] === null ? '<span class="rc-field__value--muted">Not recorded</span>' : e($rc['guardian']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         4. BODY - academic work (left) + character panels (right)
         ============================================================ -->
    <div class="rc-body">
        <div class="rc-main">

            <h2 class="rc-h2 rc-h2--tight">Cognitive Assessment Summary</h2>

            <div class="rc-table-wrap">
            <table class="rc-table">
                <colgroup>
                    <col style="width:24%">
                    <col style="width:9%">
                    <col style="width:9%">
                    <col style="width:9%">
                    <col style="width:12%">
                    <col style="width:11%">
                    <col style="width:8%">
                    <col style="width:18%">
                </colgroup>
                <thead>
                <tr>
                    <th class="rc-col-subject" rowspan="2">Subject</th>
                    <th colspan="3">Continuous Assessment</th>
                    <th rowspan="2">End of Term<br>Examination<?= $rcComponentMax('examination') ?></th>
                    <th rowspan="2">Total Score<?= $rcComponentMax('__total__') ?></th>
                    <th rowspan="2">Grade</th>
                    <th rowspan="2">Remark</th>
                </tr>
                <tr class="rc-subhead">
                    <th><?= e($rc['structure']['components']['test_1']['label'] ?? 'CA 1') ?><?= $rcComponentMax('test_1') ?></th>
                    <th><?= e($rc['structure']['components']['test_2']['label'] ?? 'CA 2') ?><?= $rcComponentMax('test_2') ?></th>
                    <th><?= e($rc['structure']['components']['test_3']['label'] ?? 'CA 3') ?><?= $rcComponentMax('test_3') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (($rc['subjects'] ?? []) === []): ?>
                    <tr>
                        <td colspan="8" class="rc-table__empty">No subjects are set up for this class.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rc['subjects'] as $rcRow): ?>
                        <?php
                        $rcCa = null;

                        foreach (['test_1', 'test_2', 'test_3'] as $rcCode) {
                            $rcValue = $rcRow['scores'][$rcCode] ?? null;

                            if ($rcValue !== null) {
                                $rcCa = ($rcCa ?? 0.0) + (float) $rcValue;
                            }
                        }

                        $rcRemark   = $rcRow['teacher_remark'] ?? $rcRow['grade_remark'] ?? '';
                        ?>
                        <tr>
                            <td class="rc-subject"><?= e($rcRow['subject_name']) ?></td>
                            <td class="rc-score"><?= $rcNum($rcRow['scores']['test_1'] ?? null) ?></td>
                            <td class="rc-score"><?= $rcNum($rcRow['scores']['test_2'] ?? null) ?></td>
                            <td class="rc-score"><?= $rcNum($rcRow['scores']['test_3'] ?? null) ?></td>
                            <td class="rc-score"><?= $rcNum($rcRow['scores']['examination'] ?? null) ?></td>
                            <td class="rc-total"><?= $rcNum($rcRow['total'] ?? null) ?></td>
                            <td class="rc-grade"><?= $rcRow['grade'] === null ? $rcDash() : e((string) $rcRow['grade']) ?></td>
                            <td class="rc-remark"><?= trim((string) $rcRemark) === '' ? '<span class="rc-muted">&ndash;</span>' : e((string) $rcRemark) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr>
                    <th colspan="5">Aggregate</th>
                    <th><?= $rcNum($rc['total_obtained'] ?? null) ?></th>
                    <th><?= $rc['average_grade'] === null ? $rcDash() : e($rc['average_grade']) ?></th>
                    <th class="rc-remark" style="text-align:left;">
                        <?= $rc['average_percentage'] === null ? $rcDash() : e(rtrim(rtrim(number_format((float) $rc['average_percentage'], 1, '.', ''), '0'), '.')) . '% average' ?>
                    </th>
                </tr>
                </tfoot>
            </table>
            </div>

            <!-- Grade analysis + performance summary -->
            <div class="rc-analysis">
                <div class="rc-analysis__cell">
                    <table class="rc-mini">
                        <thead>
                        <tr>
                            <th style="width:14mm;">Grade</th>
                            <th style="width:20mm;">Subjects</th>
                            <th>Share</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                        $rcAnalysis  = $rc['grade_analysis'];
                        $rcGraded    = max(1, (int) $rcAnalysis['graded']);
                        ?>
                        <?php foreach ($rcAnalysis['distribution'] as $rcGradeLetter => $rcGradeCount): ?>
                            <tr>
                                <td style="text-align:center;font-weight:700;"><?= e((string) $rcGradeLetter) ?></td>
                                <td style="text-align:center;"><?= (int) $rcGradeCount ?></td>
                                <td>
                                    <span class="rc-bars">
                                        <span class="rc-bars__fill" style="width:<?= e((string) round(($rcGradeCount / $rcGraded) * 100, 1)) ?>%;"></span>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($rcAnalysis['graded'] === 0): ?>
                            <tr>
                                <td colspan="3" class="rc-label" style="text-align:center;">No graded subject yet.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                        <tfoot>
                        <tr>
                            <td>Passed</td>
                            <td style="text-align:right;"><?= (int) $rc['passed'] ?> / <?= (int) $rc['offered'] ?></td>
                            <td style="text-align:right;">Failed: <?= (int) $rc['failed'] ?></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="rc-analysis__cell">
                    <table class="rc-mini">
                        <thead>
                        <tr>
                            <th>Performance Summary</th>
                            <th style="width:24mm;">Value</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td class="rc-label">Total obtained</td>
                            <td class="rc-value"><?= $rcNum($rc['total_obtained'] ?? null) ?></td>
                        </tr>
                        <tr>
                            <td class="rc-label">Total obtainable</td>
                            <td class="rc-value"><?= $rcNum($rc['total_obtainable'] ?? null) ?></td>
                        </tr>
                        <tr>
                            <td class="rc-label">Average</td>
                            <td class="rc-value"><?= $rcNum($rc['average'] ?? null) ?></td>
                        </tr>
                        <tr>
                            <td class="rc-label">Average percentage</td>
                            <td class="rc-value">
                                <?= $rc['average_percentage'] === null ? $rcDash() : e(rtrim(rtrim(number_format((float) $rc['average_percentage'], 1, '.', ''), '0'), '.')) . '%' ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="rc-label">Average grade</td>
                            <td class="rc-value"><?= $rc['average_grade'] === null ? $rcDash() : e($rc['average_grade']) ?></td>
                        </tr>
                        <tr>
                            <td class="rc-label">Class population</td>
                            <td class="rc-value"><?= (int) $rc['population'] ?></td>
                        </tr>
                        <tr>
                            <td class="rc-label">Position in class</td>
                            <td class="rc-value">
                                <?= $rc['position'] === null ? $rcDash() : e(ordinalSuffix((int) $rc['position'])) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="rc-label">Highest score</td>
                            <td class="rc-value">
                                <?php $rcHigh = $rc['extremes']['highest'] ?? null; ?>
                                <?= $rcHigh === null ? $rcDash() : e(rtrim(rtrim(number_format((float) $rcHigh['total'], 2, '.', ''), '0'), '.')) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="rc-label">Lowest score</td>
                            <td class="rc-value">
                                <?php $rcLow = $rc['extremes']['lowest'] ?? null; ?>
                                <?= $rcLow === null ? $rcDash() : e(rtrim(rtrim(number_format((float) $rcLow['total'], 2, '.', ''), '0'), '.')) ?>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($rcHigh !== null && $rcLow !== null): ?>
                <p style="font-size:6.4pt;color:var(--rc-muted);margin:1.4mm 0 0;">
                    Best subject: <strong><?= e($rcHigh['subject_name']) ?></strong> &middot;
                    Weakest subject: <strong><?= e($rcLow['subject_name']) ?></strong>
                </p>
            <?php endif; ?>

            <?php $rcPrevious = $rc['previous']; ?>
            <?php if (!empty($rcPrevious)): ?>
                <table class="rc-mini" style="margin-top:2.5mm;">
                    <thead>
                    <tr>
                        <th>Previous Standing</th>
                        <th style="width:22mm;">Total</th>
                        <th style="width:22mm;">Average</th>
                        <th style="width:24mm;">Position</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td class="rc-label">Previous term</td>
                        <td class="rc-value"><?= $rcPrevious['previous_term'] === null ? $rcDash() : $rcNum($rcPrevious['previous_term']['total']) ?></td>
                        <td class="rc-value"><?= $rcPrevious['previous_term'] === null ? $rcDash() : $rcNum($rcPrevious['previous_term']['average']) ?></td>
                        <td class="rc-value">
                            <?= $rcPrevious['previous_term'] === null || $rcPrevious['previous_term']['position'] === null
                                ? $rcDash()
                                : e(ordinalSuffix((int) $rcPrevious['previous_term']['position'])) ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="rc-label">Previous session</td>
                        <td class="rc-value"><?= $rcPrevious['previous_session'] === null ? $rcDash() : $rcNum($rcPrevious['previous_session']['total']) ?></td>
                        <td class="rc-value"><?= $rcPrevious['previous_session'] === null ? $rcDash() : $rcNum($rcPrevious['previous_session']['average']) ?></td>
                        <td class="rc-value">
                            <?= $rcPrevious['previous_session'] === null || $rcPrevious['previous_session']['position'] === null
                                ? $rcDash()
                                : e(ordinalSuffix((int) $rcPrevious['previous_session']['position'])) ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- ============================ SIDE PANELS ======================== -->
        <div class="rc-side">

            <section class="rc-panel">
                <div class="rc-panel__head rc-panel__head--green">Affective Domain</div>
                <div class="rc-panel__body">
                    <table class="rc-ratings">
                        <thead>
                        <tr>
                            <th>Area</th>
                            <th style="width:17mm;">Rating</th>
                            <th style="width:20mm;">Scale</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (effectiveAreaFields() as $rcKey => $rcLabel): ?>
                            <?= $rcRatingRow($rcLabel, $rc['effective'][$rcKey] ?? null) ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rc-panel">
                <div class="rc-panel__head">Psychomotor Skills</div>
                <div class="rc-panel__body">
                    <table class="rc-ratings">
                        <thead>
                        <tr>
                            <th>Skill</th>
                            <th style="width:17mm;">Rating</th>
                            <th style="width:20mm;">Scale</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (psychomotorTraits() as $rcKey => $rcLabel): ?>
                            <?= $rcRatingRow($rcLabel, $rc['psychomotor'][$rcKey] ?? null) ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rc-panel">
                <div class="rc-panel__head rc-panel__head--green">Grading Legend</div>
                <div class="rc-panel__body">
                    <table class="rc-legend">
                        <tbody>
                        <?php foreach ($rcBands as $rcBand): ?>
                            <tr>
                                <th><?= e((string) $rcBand['grade']) ?></th>
                                <td><?= e((string) $rcBand['remark']) ?></td>
                                <td class="rc-legend__pct"><?= (int) $rcBand['min'] ?>%+</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <table class="rc-legend" style="margin-top:1.4mm;">
                        <tbody>
                        <tr>
                            <th style="width:auto;text-align:left;font-size:6pt;">1&ndash;5</th>
                            <td><?= e(implode(' &middot; ', array_map(
                                static fn ($k, $v) => $k . ' = ' . $v,
                                array_keys($rcScale),
                                $rcScale
                            ))) ?></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rc-panel">
                <div class="rc-panel__head">Next Term Information</div>
                <div class="rc-panel__body">
                    <table class="rc-kv">
                        <tbody>
                        <tr>
                            <td>Promotion</td>
                            <td><?= $rcVal($rc['promotion'], 'Pending') ?></td>
                        </tr>
                        <tr>
                            <td>Term ends</td>
                            <td><?= $rcDate($rc['term_end'] ?? null) ?></td>
                        </tr>
                        <tr>
                            <td>Next term</td>
                            <td>
                                <?php if (($rc['next_term'] ?? null) === null): ?>
                                    <span style="color:var(--rc-muted);font-weight:500;">Last term of session</span>
                                <?php else: ?>
                                    <?= e($rc['next_term']['term_name']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Resumption</td>
                            <td>
                                <?= ($rc['next_term'] ?? null) === null ? '&ndash;' : $rcDate($rc['next_term']['start_date'] ?? null) ?>
                            </td>
                        </tr>
                        <?php if (!empty($rc['show_guardian_contact']) && $rc['guardian_phone'] !== null): ?>
                            <tr>
                                <td>Guardian</td>
                                <td><?= e($rc['guardian_phone']) ?></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rc-panel">
                <div class="rc-panel__head rc-panel__head--green">Scan to Verify</div>
                <div class="rc-panel__body rc-verify">
                    <?php if (($rc['verification_code'] ?? '') !== ''): ?>
                        <img class="rc-verify__qr"
                             src="<?= e(generateVerificationQRCodeDataUri((string) $rc['verification_code'], 180)) ?>"
                             alt="Scan to verify this result">
                        <p class="rc-verify__label">Result Verification Code</p>
                        <p class="rc-verify__code"><?= e((string) $rc['verification_code']) ?></p>
                        <p class="rc-verify__note">
                            Scan to Verify &mdash; confirms this document was released by the
                            school. It reveals no personal record.
                        </p>
                    <?php else: ?>
                        <p class="rc-verify__note" style="margin-top:0;">
                            No Result Verification Code has been issued for this result yet.
                        </p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>

    <!-- ============================================================
         5. COMMENTS
         ============================================================ -->
    <section class="rc-comments">
        <h2 class="rc-h2">Remarks</h2>
        <div class="rc-comments__grid">
            <div class="rc-comments__cell">
                <div class="rc-comment">
                    <div class="rc-comment__role">Class Teacher's Comment</div>
                    <div class="rc-comment__text<?= trim((string) ($rc['teacher_comment'] ?? '')) === '' ? ' rc-comment__text--blank' : '' ?>">
                        <?= trim((string) ($rc['teacher_comment'] ?? '')) === '' ? '&nbsp;' : e((string) $rc['teacher_comment']) ?>
                    </div>
                    <div class="rc-comment__sign"><span>&nbsp;</span>Class Teacher</div>
                </div>
            </div>
            <div class="rc-comments__cell">
                <div class="rc-comment rc-comment--blue">
                    <div class="rc-comment__role">Head Teacher's Comment</div>
                    <div class="rc-comment__text<?= trim((string) ($rc['principal_comment'] ?? '')) === '' ? ' rc-comment__text--blank' : '' ?>">
                        <?= trim((string) ($rc['principal_comment'] ?? '')) === '' ? '&nbsp;' : e((string) $rc['principal_comment']) ?>
                    </div>
                    <div class="rc-comment__sign"><span>&nbsp;</span>Head Teacher</div>
                </div>
            </div>
            <div class="rc-comments__cell">
                <div class="rc-comment rc-comment--blue">
                    <div class="rc-comment__role">Parent / Guardian</div>
                    <div class="rc-comment__text rc-comment__text--blank">&nbsp;</div>
                    <div class="rc-comment__sign"><span>&nbsp;</span>Parent / Guardian</div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         6. SIGNATURES + STAMP
         ============================================================ -->
    <div class="rc-signatures">
        <div class="rc-signatures__cell">
            <div class="rc-signatures__line"></div>
            <div class="rc-signatures__role">Class Teacher</div>
            <div class="rc-signatures__meta">Signature &amp; date</div>
        </div>
        <div class="rc-signatures__cell">
            <div class="rc-signatures__line"></div>
            <div class="rc-signatures__role">Head Teacher</div>
            <div class="rc-signatures__meta">Signature &amp; date</div>
        </div>
        <div class="rc-signatures__cell">
            <?php if (($rc['stamp_url'] ?? null) !== null): ?>
                <div class="rc-stamp rc-stamp--filled">
                    <img src="<?= e($rc['stamp_url']) ?>" alt="School stamp">
                </div>
            <?php else: ?>
                <div class="rc-stamp">School Stamp</div>
            <?php endif; ?>
            <div class="rc-signatures__role">Official Stamp</div>
        </div>
    </div>

    <!-- ============================================================
         7. FOOTER
         ============================================================ -->
    <footer class="rc-footer">
        <p class="rc-footer__school"><?= e($rc['school']['school_name'] ?? '') ?></p>
        <p class="rc-footer__contact">
            <?= e(trim(($rc['school']['school_address'] ?? '') . '  |  ' . ($rc['school']['school_phone'] ?? ''), ' |')) ?>
            <?php if (($rc['school']['school_email'] ?? '') !== ''): ?>
                &nbsp;|&nbsp; <?= e($rc['school']['school_email']) ?>
            <?php endif; ?>
        </p>
        <p class="rc-footer__meta">
            <span>Report no. <?= e($rc['report_number'] ?? '') ?></span>
            <span>Issued <?= $rcDate($rc['issued_on'] ?? null) ?></span>
            <span>Verification <?= e(($rc['verification_code'] ?? '') !== '' ? (string) $rc['verification_code'] : 'pending') ?></span>
            <span><?= e($rc['school']['school_motto'] ?? '') ?></span>
        </p>
    </footer>
</article>