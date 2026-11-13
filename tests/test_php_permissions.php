<?php
if (ini_get('zend.assertions') !== '1') throw new RuntimeException('Run with assertions enabled.');
define('ABSPATH', __DIR__ . '/');
function add_action(...$args) {}
function add_filter(...$args) {}
function current_user_can($capability) { return !in_array($capability, $GLOBALS['denied'] ?? [], true); }
function is_multisite() { return $GLOBALS['multisite'] ?? false; }
function is_super_admin() { return $GLOBALS['super_admin'] ?? false; }
require __DIR__ . '/../htmlpress.php';
assert(HTMLPress::can_manage() && HTMLPress::can_upload_php());
$GLOBALS['denied'] = ['edit_themes'];
assert(HTMLPress::can_manage() && !HTMLPress::can_upload_php());
$GLOBALS['denied'] = ['manage_options'];
assert(!HTMLPress::can_manage() && !HTMLPress::can_upload_php());
$GLOBALS['denied'] = [];
$GLOBALS['multisite'] = true;
assert(!HTMLPress::can_upload_php());
$GLOBALS['super_admin'] = true;
assert(HTMLPress::can_upload_php());
define(($argv[1] ?? '') === 'mods' ? 'DISALLOW_FILE_MODS' : 'DISALLOW_FILE_EDIT', true);
assert(HTMLPress::can_manage() && !HTMLPress::can_upload_php());
echo "PHP permission checks passed\n";
