<?php
/** Isolated trusted-template execution and cleanup checks (not real WordPress). */
if (ini_get('zend.assertions') !== '1') throw new RuntimeException('Run with -d zend.assertions=1 -d assert.exception=1');
class WP_Error { public function __construct(public string $code = '', public string $message = '') {} }
require __DIR__ . '/../class-htmlpress-php.php';
function home_url($path = '') { return 'https://example.test' . $path; }
function wc_get_products($args = []) { return [(object)['name'=>'Produto de teste']]; }
function load_template($_template_file, $load_once = true, $args = []) {
	global $post, $wpdb;
	$GLOBALS['template_path'] = $_template_file;
	assert(is_file($_template_file));
	require $_template_file;
}
$post = (object)['ID'=>42];
$wpdb = (object)['prefix'=>'wp_'];
$source = <<<'PHP'
<?php
echo home_url('/'), $post->ID, $wpdb->prefix;
foreach (wc_get_products() as $product) echo $product->name;
?><html><head></head><body><img src="<?= $args['assets_url'] ?>logo.png"></body></html>
PHP;
assert(HTMLPress_PHP::validate($source) === true);
assert(HTMLPress_PHP::validate('<?= 1 + 2 ?>') === true);
assert(HTMLPress_PHP::validate('<?php error syntax') instanceof WP_Error);
assert(HTMLPress_PHP::validate('<html>no PHP</html>') instanceof WP_Error);
assert(HTMLPress_PHP::validate("<?php \0") instanceof WP_Error);
assert(HTMLPress_PHP::validate('<?php //' . str_repeat('x', 2097152)) instanceof WP_Error);
assert(!isset($GLOBALS['validation_executed']));
assert(HTMLPress_PHP::validate('<?php $GLOBALS["validation_executed"] = true;') === true);
assert(!isset($GLOBALS['validation_executed']));
$level = ob_get_level();
$output = HTMLPress_PHP::execute($source, ['assets_url'=>'https://example.test/assets/'], [__DIR__]);
assert(str_contains($output, 'https://example.test/42wp_Produto de teste'));
assert(str_contains($output, 'https://example.test/assets/logo.png'));
clearstatcache(true, $GLOBALS['template_path']);
assert(ob_get_level() === $level && !is_file($GLOBALS['template_path']));
try {
	HTMLPress_PHP::execute('<?php echo "partial secret"; throw new RuntimeException("secret");', []);
	throw new LogicException('Should have thrown.');
} catch (RuntimeException $error) {
	assert($error->getMessage() === 'secret');
	clearstatcache(true, $GLOBALS['template_path']);
	assert(ob_get_level() === $level && !is_file($GLOBALS['template_path']));
}
try {
	HTMLPress_PHP::execute('<?php echo 1;', [], [sys_get_temp_dir()]);
	throw new LogicException('Public temp directory must be refused.');
} catch (RuntimeException $error) {
	assert(str_contains($error->getMessage(), 'outside public'));
}
echo "PHP template checks passed\n";
