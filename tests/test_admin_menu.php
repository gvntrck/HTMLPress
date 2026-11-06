<?php
/** Standalone regression check: navigation must not depend on admin notices. */
define('ABSPATH', __DIR__ . '/');
$actions = [];
$menus = [];
class HTMLPress {
    public static bool $allowed = true;
    public static function can_manage(): bool { return self::$allowed; }
}
function add_action($hook, $callback, ...$args) { $GLOBALS['actions'][$hook][] = $callback; }
function add_filter(...$args) {}
function __($text, ...$args) { return $text; }
function add_submenu_page(...$args) { $GLOBALS['menus'][] = $args; }
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
require __DIR__ . '/../class-htmlpress-admin.php';
HTMLPress_Admin::init();
check(!isset($actions['admin_notices']), 'Creation shortcut must not use admin notices.');
check(isset($actions['admin_menu']), 'Menu hook must be registered.');
call_user_func($actions['admin_menu'][0]);
check(count($menus) === 1, 'Authorized administrators must get one menu link.');
check($menus[0][0] === 'edit.php?post_type=page', 'Shortcut must belong to Pages.');
check($menus[0][4] === 'post-new.php?post_type=page&htmlpress=1', 'Shortcut must open the HTML editor directly.');
HTMLPress::$allowed = false;
HTMLPress_Admin::menu();
check(count($menus) === 1, 'Unauthorized users must not get the shortcut.');
echo "admin menu checks passed\n";
