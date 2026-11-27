#!/usr/bin/env bash
# setup-php reports a failed WP-CLI download as a note and carries on, so the
# job only dies at its first `wp` call. Fetch WP-CLI here when it is missing.
set -euo pipefail

if command -v wp >/dev/null 2>&1; then
    wp --version
    exit 0
fi

url='https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar'
tmp="$(mktemp -d)"
fetch() { curl -fsSL --retry 5 --retry-all-errors --retry-delay 5 -o "$2" "$1"; }

fetch "$url" "$tmp/wp-cli.phar"
fetch "$url.sha512" "$tmp/wp-cli.phar.sha512"
echo "$(cat "$tmp/wp-cli.phar.sha512")  $tmp/wp-cli.phar" | sha512sum -c -

sudo install -m 0755 "$tmp/wp-cli.phar" /usr/local/bin/wp
wp --version
