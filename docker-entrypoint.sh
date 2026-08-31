#!/bin/sh

set -eu

token="${MATHPHP_PRIVATE_REPO_TOKEN:-${MATHPHP_UNITS_REPO_TOKEN:-}}"
ref="${MATHPHP_PRIVATE_REPO_REF:-${MATHPHP_UNITS_REF:-main}}"

install_private_package() {
    package="$1"
    private_dir="$2"
    staging_dir="$(mktemp -d "/app/private/${package}.XXXXXX")"
    auth_header="$(printf 'x-access-token:%s' "$token" | base64 | tr -d '\n')"
    composer_auth="$(printf '{"github-oauth":{"github.com":"%s"}}' "$token")"

    if git -c "http.extraHeader=Authorization: Basic $auth_header" clone --depth 1 --branch "$ref" "https://github.com/mathphp/${package}.git" "$staging_dir" \
        && COMPOSER_AUTH="$composer_auth" composer install --working-dir="$staging_dir" --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader; then
        if [ -e "$private_dir" ]; then rm -rf "$private_dir"; fi
        mv "$staging_dir" "$private_dir"
        echo "MathPHP ${package} installed."
    else
        echo "MathPHP ${package} setup failed; continuing without that optional package." >&2
        rm -rf "$staging_dir"
    fi
    unset auth_header composer_auth
}

if [ -n "$token" ]; then
    mkdir -p /app/private
    install_private_package "mathphp-units" "/app/private/mathphp-units"
    install_private_package "mathphp-visuals" "/app/private/mathphp-visuals"
    install_private_package "mathphp-explaining" "/app/private/mathphp-explaining"
else
    echo 'MATHPHP_PRIVATE_REPO_TOKEN is not set; continuing without optional packages.' >&2
fi

exec php -S 0.0.0.0:8080 -t public
