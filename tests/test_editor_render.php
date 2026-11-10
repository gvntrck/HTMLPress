<?php
/** Regression: reentrant editor probes must not duplicate the admin layout. */
define('ABSPATH', __DIR__ . '/fixtures/');
$GLOBALS['test_new'] = ($argv[1] ?? '') === 'new';
$pagenow = $GLOBALS['test_new'] ? 'post-new.php' : 'post.php';
if ($GLOBALS['test_new']) $_GET = ['post_type' => 'page', 'htmlpress' => '1'];
class HTMLPress {
    public static function is_managed($id) { return !$GLOBALS['test_new']; }
    public static function can_manage() { return true; }
    public static function group($id) { return ['pt'=>$GLOBALS['test_post']]; }
    public static function public_slug($id) { return 'example'; }
    public static function url($id) { return 'https://example.test/example/'; }
}
function __($text, ...$args) { return $text; }
function esc_html__($text, ...$args) { return htmlspecialchars($text); }
function esc_html($text) { return htmlspecialchars($text); }
function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_url($text) { return htmlspecialchars($text, ENT_QUOTES); }
function admin_url($path) { return '/wp-admin/'.$path; }
function wp_nonce_field(...$args) {}
$GLOBALS['test_post'] = (object)['ID'=>7,'post_type'=>'page','post_title'=>'Example'];
$GLOBALS['header_count'] = 0;
require __DIR__.'/../class-htmlpress-admin.php';
ob_start();
$first = HTMLPress_Admin::replace_editor(false, $GLOBALS['test_post']);
$second = HTMLPress_Admin::replace_editor(false, $GLOBALS['test_post']);
$output = ob_get_clean();
if (!$first || !$second || $GLOBALS['header_count'] !== 1 || substr_count($output, '<form ') !== 1) {
    throw new RuntimeException('Editor layout was rendered more than once.');
}
if (substr_count($output, 'type="file"') !== 3 || substr_count($output, 'accept=".html,.htm,.zip"') !== 3 || strpos($output, 'bundle_') !== false) {
    throw new RuntimeException('Expected one HTML/ZIP upload per language, not separate asset inputs.');
}
if (!preg_match('/<input[^>]*id="htmlpress-slug"[^>]*>/', $output, $slugInput)
    || strpos($slugInput[0], 'name="slug"') === false
    || strpos($slugInput[0], 'value="' . ($GLOBALS['test_new'] ? '' : 'example') . '"') === false
    || preg_match('/\b(readonly|disabled)\b/', $slugInput[0])) {
    throw new RuntimeException('Expected an editable public slug, prefilled when editing.');
}
preg_match_all('/<input type="file"[^>]*>/', $output, $uploads);
foreach ($uploads[0] as $index => $input) {
    if ((strpos($input, ' required') !== false) !== ($GLOBALS['test_new'] && $index === 0)) {
        throw new RuntimeException('Only Portuguese is required on a new page; all replacements are optional.');
    }
    $help = 'aria-describedby="htmlpress-upload-help' . ($GLOBALS['test_new'] ? '' : ' htmlpress-replace-help') . '"';
    if (strpos($input, $help) === false) {
        throw new RuntimeException('Upload guidance must be associated with each file input.');
    }
}
if (substr_count($output, '<table class="form-table" role="presentation">') !== 2
    || strpos($output, '<h2>Conteúdo por idioma</h2>') === false
    || (strpos($output, '<h2>Páginas por idioma</h2>') !== false) === $GLOBALS['test_new']
    || strpos($output, '<button type="submit" class="button button-primary">') === false) {
    throw new RuntimeException('Expected native WordPress form groups and an explicit save action.');
}
echo "editor render checks passed\n";
