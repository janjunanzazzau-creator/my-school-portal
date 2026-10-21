<#
    Runs the Phase 4 to 6 test suites in order on a clean slate.

    The suites share one demo database and one roster, and they build on each
    other: Phase 4 creates the results sheet, and the Phase 5 and 6 suites are
    written against the rows Phase 4 leaves. So the split is deliberate.

      results            cleared once, before the first suite. Phase 4 rebuilds it
                          and the later suites consume it.
      everything else    cleared before every suite. Report cards, PINs,
      generated          publications, access logs and attendance are each
                          suite's own business, and a leftover row from the last
                          run is a row this run did not create.

    The seeded roster is checked up front, because every suite assumes the six
    students in Primary 1.

    Usage:  powershell -ExecutionPolicy Bypass -File tests\run-all.ps1
#>

$ErrorActionPreference = 'Stop'

$Root   = Split-Path -Parent $MyInvocation.MyCommand.Path
$Php    = 'C:\xampp\php\php.exe'
$Mysql  = 'C:\xampp\mysql\bin\mysql.exe'
$Schema = 'school_result_portal'

# Child before parent, so no foreign key is left pointing at a deleted row.
$Shared = @(
    'results'
)

$Generated = @(
    'result_access_logs',
    'result_publication',
    'result_pins',
    'report_card_history',
    'report_cards',
    'result_history',
    'attendance_summary'
)

$Suites = @(
    'phase4-workflow-test.php',
    'phase5-engine-check.php',
    'phase5-workflow-test.php',
    'phase5-http-test.php',
    'phase6-engine-test.php',
    'phase6-workflow-test.php',
    'phase6-http-test.php'
)

function Reset-Tables([string[]] $Tables) {
    foreach ($table in $Tables) {
        "DELETE FROM $table;" | & $Mysql -uroot $Schema
        if ($LASTEXITCODE -ne 0) { throw "could not clear $table" }
    }
}

function Test-Roster {
    # Piped rather than passed with -e: PowerShell strips the quotes from an
    # argument bound for a native executable, which turns status = "Active"
    # into an unknown column.
    $Count = @'
SELECT COUNT(*)
  FROM student_class_enrollments e
  JOIN students s ON s.id = e.student_id
 WHERE e.class_id = 4 AND e.status = 'Active' AND s.status = 'Active';
'@ | & $Mysql -uroot -N -B $Schema

    $Count = ($Count | Select-Object -First 1).Trim()

    if ($Count -ne '6') {
        Write-Host ""
        Write-Host "The seeded roster is not intact: Primary 1 has $Count active students, not 6." -ForegroundColor Red
        Write-Host "The Phase 4, 5 and 6 suites are all written against that roster." -ForegroundColor Red
        Write-Host "Reload it with:  C:\xampp\mysql\bin\mysql.exe -uroot $Schema < database\sample_data_phase4.sql" -ForegroundColor Red
        exit 2
    }
}

if (-not (Test-Path -LiteralPath $Php))   { throw "php not found at $Php" }
if (-not (Test-Path -LiteralPath $Mysql)) { throw "mysql not found at $Mysql" }

Test-Roster

# Results first: the report card tables sit on top of it.
Reset-Tables ($Generated + $Shared)

$totalPassed = 0
$totalFailed = 0
$failedSuites = @()

foreach ($suite in $Suites) {
    Reset-Tables $Generated

    Write-Host ""
    Write-Host "===== $suite =====" -ForegroundColor Cyan

    $output = & $Php (Join-Path $Root $suite) 2>&1
    $output | Where-Object { $_ -match '^\s*(FAIL|SKIP)' } | ForEach-Object { Write-Host $_ }

    $summary = $output | Where-Object { $_ -match '(\d+) passed, (\d+) failed' } | Select-Object -Last 1
    if (-not $summary) { $summary = '0 passed, 1 failed' }

    if ($summary -match '(\d+) passed, (\d+) failed') {
        $passed = [int] $Matches[1]
        $failed = [int] $Matches[2]
    } else {
        $passed = 0
        $failed = 1
    }

    $totalPassed += $passed
    $totalFailed += $failed

    if ($failed -gt 0) {
        $failedSuites += $suite
        Write-Host "  $suite  ->  $passed passed, $failed failed" -ForegroundColor Red
    } else {
        Write-Host "  $suite  ->  $passed passed" -ForegroundColor DarkGray
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  total: $totalPassed passed, $totalFailed failed" -ForegroundColor $(if ($totalFailed -eq 0) { 'Green' } else { 'Red' })

if ($failedSuites.Count -gt 0) {
    Write-Host "  failing suites: $($failedSuites -join ', ')" -ForegroundColor Red
    exit 1
}

exit 0
