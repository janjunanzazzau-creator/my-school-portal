# Phase 4 HTTP smoke test
#
# Signs in over HTTP as the administrator and then as the teacher, opens every
# Phase 4 screen, and fails if any of them returns an error page, a PHP warning,
# a notice, or a page a signed out visitor should not be able to reach.
#
# The .NET WebRequest classes are used directly rather than Invoke-WebRequest,
# because a role rejection is a 302 and Invoke-WebRequest hides or throws on
# those. Following redirects on purpose is fine for reading a page, so both
# behaviours are available.
#
# Run from a command prompt:
#     powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\school-result-portal\tests\phase4-http-test.ps1

$ErrorActionPreference = 'Stop'
$base = 'http://localhost/school-result-portal'

$script:passed = 0
$script:failed = 0

function Pass($label) {
    $script:passed++
    Write-Output ("  PASS  " + $label)
}

function Fail($label, $problems) {
    $script:failed++
    Write-Output ("  FAIL  " + $label)
    foreach ($p in @($problems)) { Write-Output ("          - " + $p) }
}

# A tiny HTTP client that keeps one cookie per identity and can either follow or
# stop at a redirect.
function New-Client {
    param([string]$Label)
    return @{ Label = $Label; Cookie = '' }
}

function Invoke-Portal {
    param(
        $Client,
        [string]$Path,
        [string]$Method = 'GET',
        [hashtable]$Body = $null,
        [bool]$Follow = $true
    )

    $request = [System.Net.HttpWebRequest]::Create($base + $Path)
    $request.Method = $Method
    $request.AllowAutoRedirect = $Follow
    $request.Timeout = 25000
    $request.ReadWriteTimeout = 25000
    $request.UserAgent = 'Phase4SmokeTest/1.0'
    $request.Accept = 'text/html'

    if ($Client -ne $null -and $Client.Cookie -ne '') {
        $request.Headers.Add('Cookie', $Client.Cookie)
    }

    if ($Body -ne $null) {
        $pairs = @()

        foreach ($key in $Body.Keys) {
            $pairs += ([uri]::EscapeDataString($key) + '=' + [uri]::EscapeDataString([string]$Body[$key]))
        }

        $bytes = [System.Text.Encoding]::UTF8.GetBytes(($pairs -join '&'))
        $request.ContentType = 'application/x-www-form-urlencoded'
        $request.ContentLength = $bytes.Length
        $stream = $request.GetRequestStream()
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Close()
    }

    try {
        $response = $request.GetResponse()
    } catch [System.Net.WebException] {
        if ($_.Exception.Response -eq $null) { throw }
        $response = $_.Exception.Response
    }

    $status = [int] $response.StatusCode
    $location = ''

    if ($response.Headers['Location'] -ne $null) {
        $location = $response.Headers['Location']
    }

    # Carry the session cookie forward.
    $setCookie = $response.Headers['Set-Cookie']

    if ($setCookie -ne $null) {
        foreach ($line in @($setCookie -split "`n")) {
            $pair = ($line -split ';')[0]
            if ($pair -match '^\s*([^=]+)=([^;]*)') {
                $name = $matches[1].Trim()
                $value = $matches[2].Trim()
                $Client.Cookie = "$name=$value"
            }
        }
    }

    $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
    $content = $reader.ReadToEnd()
    $reader.Close()
    $response.Close()

    return @{ Status = $status; Body = $content; Location = $location }
}

# Read the CSRF token out of a rendered form.
function Get-CsrfToken {
    param([string]$Html)
    $m = [regex]::Match($Html, 'name="csrf_token"\s+value="([^"]+)"')
    if ($m.Success) { return $m.Groups[1].Value }
    return ''
}

function New-Login {
    param([string]$Username, [string]$Password, [string]$Label)

    $client = New-Client -Label $Label
    $page = Invoke-Portal -Client $client -Path '/login.php'
    $token = Get-CsrfToken -Html $page.Body

    if ($token -eq '') {
        throw "No CSRF token on login.php for $Username"
    }

    $result = Invoke-Portal -Client $client -Path '/login.php' -Method POST -Body @{
        username   = $Username
        password   = $Password
        csrf_token = $token
    } -Follow $false

    if ($result.Status -ne 302) {
        throw "Login for $Username did not redirect (got HTTP $($result.Status))."
    }

    return $client
}

# Open a page and complain about anything that looks like a broken render.
function Check-Page {
    param($Client, [string]$Path, [string]$Label, [string[]]$Expect = @())

    $problems = @()
    $response = $null

    try {
        $response = Invoke-Portal -Client $Client -Path $Path
    } catch {
        $problems += "request failed: $($_.Exception.Message)"
    }

    if ($response -ne $null) {
        $body = $response.Body

        if ($response.Status -ne 200) {
            $problems += "HTTP $($response.Status)"
        }

        foreach ($pattern in @(
            'Development error', 'Fatal error', 'Parse error', 'Warning:',
            'Notice:', 'Deprecated:', 'Uncaught', 'Undefined array key',
            'Undefined variable', 'SQLSTATE', 'Call to a member function',
            'Trying to access array offset'
        )) {
            if ($body.Contains($pattern)) {
                $problems += "page contains '$pattern'"
            }
        }

        foreach ($needle in $Expect) {
            if (-not $body.Contains($needle)) {
                $problems += "expected text missing: '$needle'"
            }
        }
    }

    if ($problems.Count -eq 0) {
        Pass ("{0,-11} {1}" -f $Client.Label, $Label)
        return
    }

    Fail ("{0,-11} {1}" -f $Client.Label, $Label) $problems
}

# A protected page must answer a signed out or wrong-role visitor with a
# redirect, never with content.
function Check-Blocked {
    param($Client, [string]$Path)

    $response = Invoke-Portal -Client $Client -Path $Path -Follow $false

    if ($response.Status -in @(301, 302, 303, 307, 403)) {
        Pass ("{0,-11} blocked from {1} (HTTP {2})" -f $Client.Label, $Path, $response.Status)
        return
    }

    Fail ("{0,-11} reached {1}" -f $Client.Label, $Path) @("expected a redirect, got HTTP $($response.Status)")
}

function Get-SqlScalar {
    param([string]$Query)

    $result = & 'C:\xampp\mysql\bin\mysql.exe' -u root -N -B -e $Query 2>$null
    $line = $result | Select-Object -First 1

    if ($null -eq $line) { return '' }
    return ([string] $line).Trim()
}

# ---------------------------------------------------------------------------
Write-Output ''
Write-Output '== Signing in =='

$admin = New-Login -Username 'admin' -Password 'Admin@12345' -Label 'admin'
Pass 'admin signed in'

$teacher = New-Login -Username 'p4teacher' -Password 'Teacher@12345' -Label 'p4teacher'
Pass 'p4teacher signed in'

# ---------------------------------------------------------------------------
Write-Output ''
Write-Output '== Administrator screens =='

Check-Page $admin '/dashboard.php'                       'dashboard'          @('Pending Result Reviews', 'Approved Results', 'Locked Results', 'Results At A Glance')
Check-Page $admin '/admin/results.php'                   'result list'        @('Result Administration', 'Pending Review', 'Admission number')
Check-Page $admin '/admin/results.php?status=submitted'  'filter: status'     @('Result Administration')
Check-Page $admin '/admin/results.php?status=approved'   'filter: approved'   @('Result Administration')
Check-Page $admin '/admin/results.php?status=locked'     'filter: locked'     @('Result Administration')
Check-Page $admin '/admin/results.php?search=Aisha'       'search: student'    @('Result Administration')
Check-Page $admin '/admin/results.php?search=ADM%2F2026'  'search: admission'  @('Result Administration')
Check-Page $admin '/admin/results.php?search=English'     'search: subject'    @('Result Administration')
Check-Page $admin '/admin/results.php?search=Primary'     'search: class'      @('Result Administration')
Check-Page $admin '/admin/results.php?search=Amina'       'search: teacher'    @('Result Administration')
Check-Page $admin '/admin/results.php?section=Primary'    'filter: section'    @('Result Administration')
Check-Page $admin '/admin/results.php?status=bogus'       'filter: bad value'  @('Result Administration')
Check-Page $admin '/admin/results.php?per_page=100'       'per page selector'  @('Result Administration')
Check-Page $admin '/admin/results.php?session_id=99999'   'filter: bad session' @('Result Administration')

$resultId = Get-SqlScalar "SELECT id FROM school_result_portal.results ORDER BY id LIMIT 1"

if ($resultId -ne '') {
    Check-Page $admin "/admin/result-review.php?id=$resultId" 'result review' @(
        'Score Breakdown', 'Grade Bands', 'Result History', 'Review Decision',
        'Student Standing', 'Excellent'
    )
} else {
    Write-Output '  SKIP  result review (no result rows yet)'
}

# A result id that does not exist must bounce back to the list, not error.
$missing = Invoke-Portal -Client $admin -Path '/admin/result-review.php?id=999999'
if ($missing.Status -eq 200 -and $missing.Body.Contains('Result Administration') -and -not $missing.Body.Contains('Development error')) {
    Pass 'admin      a missing result id returns to the list'
} else {
    Fail 'admin      a missing result id returns to the list' @("HTTP $($missing.Status)")
}

# The Phase 1 to 3 screens must still work.
Check-Page $admin '/admin/students.php'            'students (phase 2)'       @('Students')
Check-Page $admin '/admin/teachers.php'            'teachers (phase 2)'       @('Teachers')
Check-Page $admin '/admin/classes.php'             'classes (phase 2)'        @('Classes')
Check-Page $admin '/admin/subjects.php'            'subjects (phase 2)'       @('Subjects')
Check-Page $admin '/admin/sessions.php'            'sessions (phase 3)'       @('Academic Sessions')
Check-Page $admin '/admin/terms.php'               'terms (phase 3)'          @('Terms')
Check-Page $admin '/admin/teacher-class-assignments.php'  'class assignments (p3)' @('Teacher')
Check-Page $admin '/admin/teacher-subject-assignments.php' 'subject assignments (p3)' @('Subject')
Check-Page $admin '/admin/student-enrollments.php' 'enrollments (phase 3)'     @('Enrollment Records')
Check-Page $admin '/admin/promote-students.php'    'promote (phase 3)'        @('Promote')

# ---------------------------------------------------------------------------
Write-Output ''
Write-Output '== Teacher screens =='

Check-Page $teacher '/teacher/dashboard.php'            'dashboard'          @('My Dashboard', 'My Draft Results', 'Awaiting Review', 'Enter Result', 'My Result Sheets')
Check-Page $teacher '/teacher/my-classes.php'           'my classes'         @('My Classes')
Check-Page $teacher '/teacher/my-subjects.php'          'my subjects'        @('My Subjects', 'English')
Check-Page $teacher '/teacher/my-results.php'           'my results'         @('My Results', 'Result Records')
Check-Page $teacher '/teacher/my-results.php?status=approved' 'my results: approved' @('My Results')
Check-Page $teacher '/teacher/my-results.php?search=Aisha'    'my results: search'   @('My Results')
Check-Page $teacher '/teacher/enter-result.php'         'enter result picker' @('Enter Results')

$scope = Get-SqlScalar @"
SELECT CONCAT_WS('|',
  (SELECT c.id FROM school_result_portal.classes c WHERE c.class_name='Primary 1' AND c.section='Primary' LIMIT 1),
  (SELECT s.id FROM school_result_portal.subjects s WHERE s.subject_code='ENG' LIMIT 1),
  (SELECT a.id FROM school_result_portal.academic_sessions a WHERE a.is_current=1 LIMIT 1),
  (SELECT t.id FROM school_result_portal.terms t WHERE t.is_current=1 LIMIT 1))
"@

if ($scope -match '^\d+\|\d+\|\d+\|\d+$') {
    $p = $scope -split '\|'
    $sheet = "/teacher/enter-result.php?class_id=$($p[0])&subject_id=$($p[1])&session_id=$($p[2])&term_id=$($p[3])"

    # The sheet must render whichever state it is in.
    Check-Page $teacher $sheet 'result sheet renders' @('Test 1', 'Examination', 'out of 70')

    $drafts = Get-SqlScalar @"
SELECT COUNT(*) FROM school_result_portal.results
 WHERE class_id=$($p[0]) AND subject_id=$($p[1]) AND session_id=$($p[2]) AND term_id=$($p[3]) AND status='draft'
"@

    if ([int] $drafts -eq 0) {
        # The read-only notice has to be present and the edit buttons absent.
        $readOnly = Invoke-Portal -Client $teacher -Path $sheet
        $problems = @()

        if (-not $readOnly.Body.Contains('already been submitted')) {
            $problems += "expected the read-only notice, found none"
        }

        if ($readOnly.Body.Contains('Submit for Review')) {
            $problems += "a submitted sheet still offers the Submit button"
        }

        if ($readOnly.Body.Contains('Save Draft')) {
            $problems += "a submitted sheet still offers the Save button"
        }

        if ($problems.Count -eq 0) {
            Pass 'p4teacher   submitted sheet is read only'
        } else {
            Fail 'p4teacher   submitted sheet is read only' $problems
        }
    } else {
        Check-Page $teacher $sheet 'draft sheet is editable' @('Save Draft', 'Submit for Review', 'Teacher''s remark')
    }

    # The positions panel only belongs to a class and term filter, and it must
    # appear once both are chosen.
    $filtered = Invoke-Portal -Client $admin -Path ("/admin/results.php?class_id=$($p[0])&session_id=$($p[2])&term_id=$($p[3])")

    if ($filtered.Body.Contains('Class Positions')) {
        Pass 'admin       class positions appear for a class and term filter'
    } else {
        Fail 'admin       class positions appear for a class and term filter' @('the positions panel is missing')
    }

    # A class the teacher is not assigned to must be refused, not rendered.
    Check-Page $teacher "/teacher/enter-result.php?class_id=13&subject_id=$($p[1])&session_id=$($p[2])&term_id=$($p[3])" `
        'unassigned class refused' @('not actively assigned')

    # A term that belongs to another session must be refused.
    Check-Page $teacher "/teacher/enter-result.php?class_id=$($p[0])&subject_id=$($p[1])&session_id=$($p[2])&term_id=99999" `
        'foreign term refused' @('does not belong')

    # No class chosen yet must simply show the picker.
    Check-Page $teacher '/teacher/enter-result.php' 'picker with nothing chosen' @('Enter Results')
} else {
    Write-Output '  SKIP  result sheet (sample assignment missing)'
}

# ---------------------------------------------------------------------------
Write-Output ''
Write-Output '== Role separation =='

foreach ($path in @('/admin/results.php', '/admin/result-review.php?id=1', '/admin/result-action.php', '/dashboard.php')) {
    Check-Blocked $teacher $path
}

foreach ($path in @('/teacher/enter-result.php', '/teacher/save-result.php', '/teacher/submit-result.php', '/teacher/my-results.php')) {
    Check-Blocked $admin $path
}

$anon = New-Client -Label 'anonymous'
foreach ($path in @('/dashboard.php', '/admin/results.php', '/teacher/enter-result.php', '/admin/result-review.php?id=1', '/teacher/my-results.php')) {
    Check-Blocked $anon $path
}

# ---------------------------------------------------------------------------
Write-Output ''
Write-Output '== Action endpoints reject bad requests =='

# A POST with no CSRF token must be refused.
$code = (Invoke-Portal -Client $teacher -Path '/teacher/save-result.php' -Method POST -Follow $false -Body @{
    class_id   = $p[0]; subject_id = $p[1]; session_id = $p[2]; term_id = $p[3]
}).Status

if ($code -eq 302) { Pass 'save-result.php refuses a POST with no CSRF token' }
else { Fail 'save-result.php refuses a POST with no CSRF token' @("expected a redirect, got HTTP $code") }

$code = (Invoke-Portal -Client $admin -Path '/admin/result-action.php' -Method POST -Follow $false -Body @{
    result_id = $resultId; action = 'approve'
}).Status

if ($code -eq 302) { Pass 'result-action.php refuses a POST with no CSRF token' }
else { Fail 'result-action.php refuses a POST with no CSRF token' @("expected a redirect, got HTTP $code") }

# A GET must never perform an action.
$code = (Invoke-Portal -Client $admin -Path '/admin/result-action.php' -Follow $false).Status
if ($code -eq 302) { Pass 'result-action.php refuses a GET' }
else { Fail 'result-action.php refuses a GET' @("expected a redirect, got HTTP $code") }

# A valid CSRF token but an invented action must change nothing.
$review = Invoke-Portal -Client $admin -Path "/admin/result-review.php?id=$resultId"
$token = Get-CsrfToken -Html $review.Body
$before = Get-SqlScalar "SELECT status FROM school_result_portal.results WHERE id=$resultId"

Invoke-Portal -Client $admin -Path '/admin/result-action.php' -Method POST -Follow $false -Body @{
    result_id  = $resultId
    action     = 'obliterate'
    csrf_token = $token
} | Out-Null

$after = Get-SqlScalar "SELECT status FROM school_result_portal.results WHERE id=$resultId"
if ($before -eq $after) { Pass "an invented action leaves the status as '$after'" }
else { Fail 'an invented action leaves the status alone' @("was '$before', now '$after'") }

# Returning or unlocking without a reason must be refused.
if ($before -eq 'locked') {
    Invoke-Portal -Client $admin -Path '/admin/result-action.php' -Method POST -Follow $false -Body @{
        result_id = $resultId; action = 'unlock'; reason = '   '; csrf_token = $token
    } | Out-Null
    $after = Get-SqlScalar "SELECT status FROM school_result_portal.results WHERE id=$resultId"
    if ($after -eq 'locked') { Pass 'unlocking without a reason is refused' }
    else { Fail 'unlocking without a reason is refused' @("status is now '$after'") }
}

# A teacher must not be able to act on a result even with a valid token.
$teacherPage = Invoke-Portal -Client $teacher -Path '/teacher/my-results.php'
$teacherToken = Get-CsrfToken -Html $teacherPage.Body
Invoke-Portal -Client $teacher -Path '/admin/result-action.php' -Method POST -Follow $false -Body @{
    result_id = $resultId; action = 'approve'; csrf_token = $teacherToken
} | Out-Null
$after = Get-SqlScalar "SELECT status FROM school_result_portal.results WHERE id=$resultId"
if ($before -eq $after) { Pass 'a teacher cannot post to the action endpoint' }
else { Fail 'a teacher cannot post to the action endpoint' @("status is now '$after'") }

# ---------------------------------------------------------------------------
Write-Output ''
Write-Output ("  " + $script:passed + " passed, " + $script:failed + " failed")
Write-Output ''

exit $script:failed
