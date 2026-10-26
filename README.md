# School Student Result Management Portal

A PHP/MySQL web application for managing school records and, in later phases,
student results for **Orphans Model Nursery and Primary School (Makarantar Marayu)**.

The application is built on the XAMPP stack and uses a responsive
Bootstrap 5 interface in the school colours (green, gold and white).

---

## 1. Requirements

| Component | Version used |
| --------- | ------------ |
| PHP       | 8.0.30 (ZTS) |
| MySQL / MariaDB | 10.4.32 |
| Apache    | 2.4.58 |
| Bootstrap | 5.3.3 (CDN) |
| Bootstrap Icons | 1.11.3 (CDN) |

All of the above ship with XAMPP, so a standard installation is enough.

## 2. Installation

1. Copy the project folder into the XAMPP web root:

   ```
   C:\xampp\htdocs\school-result-portal
   ```

2. Start **Apache** and **MySQL** from the XAMPP control panel.

3. Create an empty database and import the complete schema:

   ```
   database/setup.sql
   ```

   In phpMyAdmin, select the new database, open **Import**, and choose
   `database/setup.sql`. Or create and import it from a command prompt:

   ```
   C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE school_result_portal DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 school_result_portal ^
       < C:\xampp\htdocs\school-result-portal\database\setup.sql
   ```

   The combined setup applies phases 1–7 and is safe to re-import. It does not
   include the optional sample-data files. A fresh database receives the seeded
   administrator account; change its default password before production use.

4. Open the portal:

   ```
   http://localhost/school-result-portal/
   ```

5. Sign in with the seeded administrator account:

   | Username | Password    |
   | -------- | ----------- |
   | `admin`  | `Admin@12345` |

Change the administrator password before using the portal in a real school.

## 3. Project layout

```
school-result-portal/
├── index.php              Entry point; routes each role to its dashboard
├── login.php              Sign in with CSRF protection and safe redirects
├── logout.php             Ends the session and clears the cookie
├── dashboard.php          Administrator dashboard
├── assets/
│   ├── css/style.css      Green / gold / white theme and shared components
│   └── js/app.js          Sidebar, dependent selects, validation, confirms
├── config/
│   ├── config.php         Constants, error handling, secure session setup
│   ├── database.php       PDO connection
│   ├── auth.php           Sign in, roles, middleware, CSRF tokens
│   ├── helpers.php        Escaping, redirects, flash messages
│   └── functions.php      All data access and validation
├── database/              SQL schema and migrations
├── includes/              Shared layout: header, navbar, sidebar, footer
├── admin/                 Administrator pages (all require an admin)
└── teacher/               Teacher pages (require a teacher)
```

## 4. Roles and access control

Every page requires its authorisation middleware **before** it reads a record,
so an unauthenticated visitor cannot learn whether a record id exists.

| Area    | Middleware                          | Who can reach it |
| ------- | ----------------------------------- | ---------------- |
| `admin/` | `includes/admin_bootstrap.php` → `requireAdmin()` | Administrators only |
| `teacher/` | `includes/teacher_bootstrap.php` → `requireTeacher()` | Teachers only |

A teacher who opens an administrator page is refused and signed out. An
administrator who opens a teacher page is refused, because teacher pages show
personal allocation data.

## 5. Security measures

- **Passwords** are hashed with `password_hash()` and never displayed again.
- **CSRF tokens** are required on every state-changing form and validated with
  `hash_equals()`.
- **All SQL** uses prepared PDO statements; no value is concatenated into a query.
- **All output** is escaped through the `e()` helper.
- **Sessions** use `HttpOnly`, `SameSite=Lax` and a regenerated id on sign-in.
- **Current session and term** changes run inside a transaction, so the database
  can never end up with two current sessions or an orphaned current term.
- **File uploads** are restricted by MIME type and size, and the stored name is
  generated rather than taken from the browser.

## 6. Phase status

### Phase 1 — Foundation and authentication (complete)

- Database schema, PDO connection, secure session handling.
- Sign in and sign out with role-based redirects.
- Authorization middleware and CSRF protection.
- Shared layout, theme and client-side behaviour.

### Phase 2 — School records (complete)

- Students, with passport photo upload.
- Teachers, with optional portal accounts.
- Classes and subjects.
- Lists with search, filtering, sorting, pagination and per-page selection.
- Administrator dashboard.

### Phase 3 — Academic structure (complete)

- **Academic sessions** — name, start and end dates, status, current-session flag.
- **Terms** — First, Second and Third Term inside a session, with their own
  status and current-term flag.
- **Teacher-to-class assignments** — who is the class teacher of which class in
  which session.
- **Teacher-to-subject assignments** — who teaches which subject to which class
  in which session.
- **Student enrolments** — one active class per student per academic session.
- **Bulk enrolment** — place many students into one class in a single
  transaction; students who already have a class are skipped, not rejected.
- **Promotion** — move students into a new class for a new session. A new
  record is written and the previous one is kept, so the history is never
  overwritten.
- **Session-based class roster** — a class shows the students who were in it
  during a chosen session, not whatever the current class happens to be.
- **Current session and term selection** — transactional, and shown on both
  dashboards.
- **Teacher dashboard** — the signed-in teacher's own classes and subjects.
- **Setup checklist** on the administrator dashboard showing exactly what is
  still missing before results can be entered.

### Not built yet

Result entry, grade calculation, positions, report cards, the public result
checker, attendance and psychomotor/effective areas all belong to a later phase.
Their sidebar entries are present but clearly disabled — no placeholder pages
were created for them.

## 7. How the class roster stays consistent

`student_class_enrollments` is the authoritative record of which class a student
belongs to in a given academic session. The older `students.class_id` column is
kept in step by `syncStudentClassLink()`, which is called whenever an enrolment
is created, deactivated, bulk-created or promoted, and whenever the current
session changes. This is what stops the student list and the session roster from
disagreeing.

## 8. Database tables

| Table | Purpose |
| ----- | ------- |
| `users` | Portal accounts (administrators and teachers) |
| `students` | Student records |
| `teachers` | Teacher records, optionally linked to a `users` row |
| `classes` | Classes, grouped into Nursery / Primary / Secondary |
| `subjects` | Subjects, grouped into the same sections |
| `academic_sessions` | Academic years, one of which is current |
| `terms` | Terms within a session, one of which is current |
| `teacher_class_assignments` | Class teacher allocations |
| `teacher_subject_assignments` | Subject teaching allocations |
| `student_class_enrollments` | Session-scoped class membership |
| `schema_migrations` | Applied migration versions |

## 9. Troubleshooting

**Blank page or "Development error"** — the portal shows a detailed development
page for any uncaught error. Read the message on the page, and check the Apache
error log at `C:\xampp\apache\logs\error.log`. To hide the detail from visitors
on a live server, set `APP_ENV` to `'production'` in `config/config.php`; users
then see a friendly message and the details go to the log only.

**Blank lists on a fresh install** — the academic session must be set. Open
*Academic Sessions* and use *Set as current*.

**"Access denied"** — the browser session expired. Sign in again; each sign-in
rotates the session id.
