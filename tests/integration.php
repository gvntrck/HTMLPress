<?php
/** Real-WordPress integration checks for HTMLPress. Run: php tests/integration.php /path/to/wp-load.php */

$wp_load = $argv[1] ?? getenv('HTMLPRESS_WP_LOAD');
if (!$wp_load || !is_file($wp_load)) {
    fwrite(STDERR, "SKIP: provide wp-load.php as argv[1] or HTMLPRESS_WP_LOAD.\n");
    exit(0);
}
require_once $wp_load;
$plugin = dirname(__DIR__) . '/htmlpress/htmlpress.php';
if (!class_exists('HTMLPress')) {
    require_once $plugin;
}

$failures = 0;
$check = static function ($condition, string $message) use (&$failures): void {
    if (!$condition) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};
$admin = get_users(['role' => 'administrator', 'number' => 1]);
if (!$admin) {
    fwrite(STDERR, "FAIL: no administrator user exists.\n");
    exit(1);
}
wp_set_current_user($admin[0]->ID);
if (!HTMLPress::can_manage()) {
    fwrite(STDERR, "FAIL: administrator lacks required HTMLPress capabilities.\n");
    exit(1);
}

$suffix = 'qa-' . strtolower(wp_generate_password(8, false, false));
$slug = $suffix;
$html = static fn(string $lang): string => '<!doctype html><html><head><style>body{color:red}</style></head><body>' . $lang . '<script>window.qa="\\\\\\\"; // </body> decoy\n</script></body></html>';
$ids = [];
$normal = $child = 0;
$parent = $unrelated = 0;
$root_group = [];
$old_front = [get_option('show_on_front'), get_option('page_on_front'), get_option('page_for_posts')];
try {
    $created = HTMLPress::save_group(0, 'QA ' . $suffix, $slug, ['pt' => $html('pt'), 'en' => $html('en'), 'es' => $html('es')]);
    $check(is_array($created), 'create group returns three IDs');
    if (!is_array($created)) { $check(false, 'group creation failed: ' . $created->get_error_message()); }
    if (!is_array($created)) { throw new RuntimeException('Cannot run dependent checks without the managed fixture.'); }
    $ids = $created;
    $check(count(array_unique($ids)) === 3, 'group has three distinct page IDs');
    $group = HTMLPress::group($ids['pt']);
    $check(count($group) === 3, 'group resolves all languages');
    $check(HTMLPress::url($ids['pt']) === home_url('/' . $slug . '/') && HTMLPress::url($ids['en']) === home_url('/en/' . $slug . '/') && HTMLPress::url($ids['es']) === home_url('/es/' . $slug . '/'), 'language URLs use expected public routes');
    $check(get_post_meta($ids['en'], HTMLPress::HTML, true) === $html('en'), 'raw HTML including JS backslashes is preserved');

    $before_urls = array_map([HTMLPress::class, 'url'], $ids);
    $updated = HTMLPress::save_group($ids['en'], 'QA Updated ' . $suffix, $slug, ['pt' => $html('pt-new')]);
    $check(is_array($updated) && $updated === $ids, 'partial edit preserves all IDs');
    $check(array_map([HTMLPress::class, 'url'], $ids) === $before_urls, 'partial edit preserves URLs');
    $check(get_post_meta($ids['pt'], HTMLPress::HTML, true) === $html('pt-new') && get_post_meta($ids['en'], HTMLPress::HTML, true) === $html('en'), 'edit updates only submitted document');
    $title_only = HTMLPress::save_group($ids['pt'], 'QA title only', $slug, []);
    $check(!is_wp_error($title_only), 'edit without document changes accepts title');
    $check(is_array($title_only) && count(array_unique(array_map(static fn($id) => get_post_field('post_title', $id), $ids))) === 1, 'title-only edit updates all three shared titles');

    $collision = HTMLPress::save_group(0, 'duplicate', $slug, ['pt' => $html('pt'), 'en' => $html('en'), 'es' => $html('es')]);
    $check(is_wp_error($collision), 'duplicate slug is rejected');
    $check(count(get_posts(['post_type' => 'page', 'post_status' => 'any', 'meta_key' => HTMLPress::GROUP, 'meta_value' => get_post_meta($ids['pt'], HTMLPress::GROUP, true)])) === 3, 'duplicate attempt creates no extra pages');
    $invalid = HTMLPress::save_group($ids['pt'], 'invalid', $slug, ['pt' => $html('must-not-save'), 'en' => $html('en'), 'es' => 'invalid']);
    $check(is_wp_error($invalid) && get_post_meta($ids['pt'], HTMLPress::HTML, true) === $html('pt-new'), 'invalid final document fails atomically');

    $normal = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'normal ' . $suffix, 'post_name' => $slug . '-normal']);
    $editor_id = wp_insert_user(['user_login' => $suffix, 'user_pass' => wp_generate_password(), 'role' => 'editor']);
    if (!is_wp_error($editor_id)) {
        wp_set_current_user($editor_id);
        $check(is_wp_error(HTMLPress::save_group(0, 'forbidden', $suffix . '-blocked', ['pt' => $html('pt'), 'en' => $html('en'), 'es' => $html('es')])), 'editor without manage_options/unfiltered_html cannot create managed group');
        $check(!HTMLPress_Admin::use_editor(true, get_post($ids['pt'])) && HTMLPress_Admin::use_editor(true, get_post($normal)), 'managed editor disabled while ordinary page editor remains available');
        $check(!user_can($editor_id, 'edit_post', $ids['pt']), 'managed page edit capability denied to editor');
        wp_set_current_user($admin[0]->ID);
        $check(HTMLPress_Admin::use_editor(true, get_post($ids['pt'])) === false, 'managed page remains outside the standard editor for managers');
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($editor_id);
    }

    wp_update_post(['ID' => $normal, 'post_title' => 'renamed ' . $suffix]);
    $check(get_post_field('post_name', $normal) === $slug . '-normal', 'renaming ordinary page does not alter its slug');
    $parent = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'English parent ' . $suffix, 'post_name' => 'en']);
    $child = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'managed collision ' . $suffix, 'post_name' => $slug, 'post_parent' => $parent]);
    $check(get_post_field('post_name', $child) !== $slug, 'normal child cannot collide with managed /en/slug path');
    $unrelated = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'unrelated ' . $suffix, 'post_name' => $suffix . '-other', 'post_parent' => $parent]);
    $check(get_post_field('post_name', $unrelated) === $suffix . '-other', 'unrelated normal child slug remains unchanged');

    foreach (['pt' => $slug, 'en' => 'en/' . $slug, 'es' => 'es/' . $slug] as $lang => $path) {
        $wp = new WP(); $wp->request = $path; $wp->query_vars = ['name' => 'wrong', 'page_id' => '999'];
        HTMLPress::route_request($wp);
        $check((int) ($wp->query_vars['page_id'] ?? 0) === $ids[$lang], "route {$lang} maps exact public path");
    }
    $wp = new WP(); $wp->request = 'en/' . $suffix . '-other'; $wp->query_vars = ['name' => $suffix . '-other'];
    HTMLPress::route_request($wp);
    $check(($wp->query_vars['name'] ?? '') === $suffix . '-other' && !isset($wp->query_vars['page_id']), 'unrelated /en/ route keeps original query');

    update_option('show_on_front', 'page'); update_option('page_on_front', $ids['pt']);
    $check(HTMLPress::url($ids['pt']) === home_url('/') && HTMLPress::url($ids['en']) === home_url('/en/') && HTMLPress::url($ids['es']) === home_url('/es/'), 'front group URLs map to language roots');
    foreach (['en' => 'en', 'es' => 'es'] as $lang => $path) {
        $wp = new WP(); $wp->request = $path; $wp->query_vars = [];
        HTMLPress::route_request($wp);
        $check((int) ($wp->query_vars['page_id'] ?? 0) === $ids[$lang], "front root {$lang} routes to language page");
        $wp = new WP(); $wp->request = $path . '/' . $slug; $wp->query_vars = [];
        HTMLPress::route_request($wp);
        $check((int) ($wp->query_vars['page_id'] ?? 0) === $ids[$lang], "old {$lang} path remains an alias");
    }
    $rejected_front = HTMLPress::protect_front_page($ids['en'], 0);
    $check((int) $rejected_front === 0, 'selecting English managed page as front is rejected');

    update_option('show_on_front', 'posts'); update_option('page_on_front', 0);
    update_option('show_on_front', 'posts'); update_option('page_on_front', 0);
    $wp = new WP(); $wp->request = 'es'; $wp->query_vars = [];
    HTMLPress::route_request($wp);
    $check(!isset($wp->query_vars['page_id']), '/es root without front page does not capture normal route');
    $root_normal = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'root en ' . $suffix, 'post_name' => 'en']);
    $root_group = HTMLPress::save_group(0, 'Root ' . $suffix, $suffix . '-root', ['pt' => $html('pt'), 'en' => $html('en'), 'es' => $html('es')]);
    $check(is_array($root_group), 'separate root-route fixture created');
    if (is_array($root_group)) {
        $check((int) HTMLPress::protect_front_page($root_group['pt'], 0) === 0, 'front page rejected when normal root /en conflicts');
        foreach ($root_group as $id) { delete_post_meta($id, HTMLPress::GROUP); wp_delete_post($id, true); }
        $root_group = [];
    }
    wp_delete_post($root_normal, true);
} finally {
    if ($normal) wp_delete_post($normal, true);
    if ($child) wp_delete_post($child, true);
    if (isset($unrelated) && $unrelated) wp_delete_post($unrelated, true);
    if (isset($parent) && $parent) wp_delete_post($parent, true);
    foreach (array_merge($ids, $root_group) as $id) {
        delete_post_meta($id, HTMLPress::GROUP);
        wp_delete_post($id, true);
    }
    update_option('show_on_front', $old_front[0]);
    update_option('page_on_front', $old_front[1]);
    update_option('page_for_posts', $old_front[2]);
}
if ($failures) { fwrite(STDERR, "{$failures} integration assertion(s) failed.\n"); exit(1); }
fwrite(STDOUT, "PASS: HTMLPress integration assertions completed ({$suffix}).\n");
