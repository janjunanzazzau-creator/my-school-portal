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

## Checks

PHP syntax: `find . -path './.local' -prune -o -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l`

The `tests/` directory contains the original workflow tests. Some create sample
records or assume XAMPP URLs; inspect them before running against real data.

## Before publishing or real school use

This workflow is for development preview, not a production deployment. Arrange
a durable MySQL/MariaDB service and upload storage, backups, production PHP
serving, HTTPS/session configuration, and non-default administrator credentials
before publishing. Do not assume the local development database will transfer
to or persist in a published deployment.
