#!/bin/sh

set -eu

private_dir="/app/private/mathphp-units"
token="${MATHPHP_UNITS_REPO_TOKEN:-}"
ref="${MATHPHP_UNITS_REF:-main}"

if [ -n "$token" ] && [ ! -f "$private_dir/vendor/autoload.php" ]; then
    mkdir -p /app/private
    if [ -e "$private_dir" ]; then rm -rf "$private_dir"; fi
    auth_header="$(printf 'x-access-token:%s' "$token" | base64 | tr -d '\n')"
    if git -c "http.extraHeader=Authorization: Basic $auth_header" clone --depth 1 --branch "$ref" https://github.com/mathphp/mathphp-units.git "$private_dir"; then
        unset auth_header
        composer install --working-dir="$private_dir" --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
    else
        echo 'MathPHP Units setup failed; continuing without the optional package.' >&2
        unset auth_header
    fi
elif [ -z "$token" ]; then
    echo 'MATHPHP_UNITS_REPO_TOKEN is not set; continuing without the optional package.' >&2
fi

exec php -S 0.0.0.0:8080 -t public
