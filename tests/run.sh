#!/usr/bin/env bash
set -eu
cd "$(dirname "$0")/.."
for file in ./*.php tests/*.php; do
    php -l "$file"
done
for file in tests/test_*.php; do
    php -d zend.assertions=1 -d assert.exception=1 "$file"
done
php tests/test_editor_render.php new
php tests/test_editor_render.php new no-php
php -d zend.assertions=1 -d assert.exception=1 tests/test_php_permissions.php mods
