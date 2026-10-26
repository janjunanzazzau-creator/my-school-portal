# Running on Replit

This is the imported PHP/MySQL school portal. Keep its PHP pages, PDO data
access, Bootstrap interface, and existing directory layout.

- Click **Run** to start the **School portal** workflow (`bash scripts/start.sh`).
- PHP 8.2 serves the site on `0.0.0.0:5000`. MariaDB is installed through Nix.
- The startup script initializes a local development database and applies the
  supplied base schema and phases 2–7 once each. Optional sample data is not loaded.
- Sign in with the initial administrator account documented in `README.md`.
  Change that default password before entering real school records.
- Database files are in `.local/mariadb/`; logs and the database socket are in
  `.local/run/`. Both are excluded from Git and publishing. Do not delete the
  database directory unless intentionally resetting all local portal data.
- MariaDB accepts only local Unix socket connections, authenticated as the
  workspace OS user. No external database credentials are required for preview.
- `scripts/start.sh` sets `APP_BASE_URL=/`, `DB_SOCKET`, `DB_USER`, and `DB_NAME`
  for the local workflow. Other environments can use the existing `DB_HOST`,
  `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS` settings instead.
- `scripts/router.php` prevents the development server from exposing internal
  configuration, SQL dumps, tests, and hidden files.
- Production is configured as a Replit VM deployment using Nginx and PHP-FPM
  (`scripts/start-production.sh`), separate from the local MariaDB preview.
  The production launcher fails closed if DB_HOST, DB_NAME, DB_USER, or DB_PASS
  is missing; DB_PORT defaults to 3306. Set these in the production environment,
  with DB_PASS stored as a secret.
- Production startup does not run schema migrations or sample data against the
  external database. Make sure it already has the portal schema and intended
  school data before publishing.

## Checks

PHP syntax: `find . -path './.local' -prune -o -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l`

The `tests/` directory contains the original workflow tests. Some create sample
records or assume XAMPP URLs; inspect them before running against real data.

## Before using for real school records

The production server configuration is in place, but publishing still requires
production database settings and a user-initiated Publish action. The local
preview database is not copied into production. Configure backups and durable
storage for student photos, logos, and stamps before relying on uploaded files;
uploads currently live under the app directory. Replace the seeded administrator
password and verify the published database and restore process before real
school use.
