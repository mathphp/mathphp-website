#!/bin/sh

set -eu

private_dir="/app/private/mathphp-units"
token="${MATHPHP_UNITS_REPO_TOKEN:-}"
ref="${MATHPHP_UNITS_REF:-main}"

if [ -n "$token" ] && [ ! -f "$private_dir/vendor/autoload.php" ]; then
    mkdir -p /app/private
    git -c "http.extraHeader=Authorization: Bearer $token" clone --depth 1 --branch "$ref" https://github.com/mathphp/mathphp-units.git "$private_dir"
    composer install --working-dir="$private_dir" --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
fi

exec php -S 0.0.0.0:8080 -t public
