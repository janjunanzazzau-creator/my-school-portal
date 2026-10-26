#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

for name in DB_HOST DB_NAME DB_USER DB_PASS; do
    if [[ -z "${!name:-}" ]]; then
        echo "Missing required production environment setting: $name" >&2
        exit 1
    fi
done

export DB_PORT="${DB_PORT:-3306}"
export APP_ENV=production
export APP_BASE_URL=/
unset DB_SOCKET || true

if ! php -r 'require "config/database.php"; getDbConnection();'; then
    echo "Could not connect to the configured production database." >&2
    exit 1
fi

nginx_bin="$(readlink -f "$(command -v nginx)")"
nginx_root="$(dirname "$(dirname "$nginx_bin")")"
fastcgi_params="$nginx_root/conf/fastcgi_params"
if [[ ! -f "$fastcgi_params" ]]; then
    echo "Nginx fastcgi_params file is missing." >&2
    exit 1
fi

nginx_runtime="$(mktemp -d /tmp/school-portal-nginx.XXXXXX)"
mkdir -p "$nginx_runtime/logs"
nginx_config="$nginx_runtime/nginx.conf"
sed -e "s|__PROJECT_ROOT__|$PWD|g" \
    -e "s|__FASTCGI_PARAMS__|$fastcgi_params|g" \
    config/nginx.conf >"$nginx_config"

fpm_pid=""
nginx_pid=""
cleanup() {
    [[ -z "$nginx_pid" ]] || kill "$nginx_pid" 2>/dev/null || true
    [[ -z "$fpm_pid" ]] || kill "$fpm_pid" 2>/dev/null || true
    wait || true
    rm -rf "$nginx_runtime"
}
trap cleanup EXIT
trap 'exit 0' INT TERM

php-fpm -F -y config/php-fpm.conf &
fpm_pid=$!

fpm_ready=false
for ((i = 0; i < 60; i++)); do
    if ! kill -0 "$fpm_pid" 2>/dev/null; then
        echo "PHP-FPM stopped before becoming ready." >&2
        exit 1
    fi
    if bash -c ': >/dev/tcp/127.0.0.1/9000' 2>/dev/null; then
        fpm_ready=true
        break
    fi
    sleep 0.1
done
if [[ "$fpm_ready" != true ]]; then
    echo "PHP-FPM did not become ready on port 9000." >&2
    exit 1
fi

nginx -e /dev/stderr -p "$nginx_runtime/" -c "$nginx_config" &
nginx_pid=$!

wait -n "$fpm_pid" "$nginx_pid"
