# HTMLPress

A WordPress plugin for publishing multilingual pages from complete HTML documents. Requires WordPress 6.5+, PHP 8.1+, and the `finfo` extension. See [`readme.txt`](readme.txt) for installation, usage, and security notes.

## Tests

Standalone checks: `php -d zend.assertions=1 -d assert.exception=1 tests/test_core.php` and the other `tests/test_*.php` scripts. WordPress integration: `php tests/integration.php /path/to/wp-load.php`.
