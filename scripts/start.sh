#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# Development database: Unix socket only, authenticated as the workspace user.
data="$PWD/.local/mariadb"
runtime="$PWD/.local/run"
mkdir -p "$runtime"
chmod 700 "$runtime"
export DB_SOCKET="$runtime/mysql.sock"
export DB_USER="$(id -un)"
export DB_NAME=school_result_portal
export APP_BASE_URL=/

if [[ ! -d "$data/mysql" ]]; then
    mariadb-install-db --no-defaults --datadir="$data" \
        --auth-root-authentication-method=socket \
        --auth-root-socket-user="$DB_USER" >"$runtime/database-init.log" 2>&1
fi

db_pid=""
web_pid=""
cleanup() {
    [[ -z "$web_pid" ]] || kill "$web_pid" 2>/dev/null || true
    [[ -z "$db_pid" ]] || kill "$db_pid" 2>/dev/null || true
    wait || true
}
trap cleanup EXIT
trap 'exit 0' INT TERM

mariadbd --no-defaults --datadir="$data" --socket="$DB_SOCKET" \
    --pid-file="$runtime/mysql.pid" --skip-networking \
    --log-error="$runtime/database.log" &
db_pid=$!

ready=false
for ((i=0; i<60; i++)); do
    if mariadb-admin --no-defaults --socket="$DB_SOCKET" --user="$DB_USER" ping --silent >/dev/null 2>&1; then
        ready=true
        break
    fi
    if ! kill -0 "$db_pid" 2>/dev/null; then
        cat "$runtime/database.log"
        exit 1
    fi
    sleep 0.5
done
if [[ "$ready" != true ]]; then
    echo "MariaDB did not become ready. See .local/run/database.log." >&2
    exit 1
fi

# Run each supplied migration once; restarting must not reset school settings.
db=(mariadb --no-defaults --socket="$DB_SOCKET" --user="$DB_USER" --default-character-set=utf8mb4)
"${db[@]}" -e "CREATE DATABASE IF NOT EXISTS school_result_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE TABLE IF NOT EXISTS school_result_portal.replit_setup_migrations (filename VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);"
for file in school_result_portal.sql phase{2,3,4,5,6,7}.sql; do
    applied=$("${db[@]}" --batch --skip-column-names -e "SELECT COUNT(*) FROM school_result_portal.replit_setup_migrations WHERE filename='$file'")
    if [[ "$applied" == 0 ]]; then
        echo "Applying database/$file"
        "${db[@]}" <"database/$file"
        "${db[@]}" -e "INSERT INTO school_result_portal.replit_setup_migrations (filename) VALUES ('$file')"
    fi
done

echo "School portal ready on port 5000."
php -S 0.0.0.0:5000 scripts/router.php &
web_pid=$!
wait -n "$db_pid" "$web_pid"
