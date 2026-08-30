#!/bin/sh

set -eu

private_dir="/app/private/mathphp-units"
token="${MATHPHP_UNITS_REPO_TOKEN:-}"
ref="${MATHPHP_UNITS_REF:-main}"

if [ -n "$token" ] && [ ! -f "$private_dir/vendor/autoload.php" ]; then
    mkdir -p /app/private
    export GIT_CONFIG_COUNT=1
    export GIT_CONFIG_KEY_0=http.https://github.com/.extraheader
    export GIT_CONFIG_VALUE_0="Authorization: Bearer $token"
    git clone --depth 1 --branch "$ref" https://github.com/mathphp/mathphp-units.git "$private_dir"
    unset GIT_CONFIG_COUNT GIT_CONFIG_KEY_0 GIT_CONFIG_VALUE_0
    composer install --working-dir="$private_dir" --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
fi

exec php -S 0.0.0.0:8080 -t public
