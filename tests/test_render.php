<?php
/** Request-level rendering regression checks with a simulated WordPress environment. */
if (ini_get('zend.assertions') !== '1') throw new RuntimeException('Run with assertions enabled.');
if (!isset($argv[1])) {
	foreach (['html', 'php', 'php-assets', 'error', 'password'] as $mode) {
		$command = [PHP_BINARY, '-d', 'zend.assertions=1', '-d', 'assert.exception=1', __FILE__, $mode];
		$process = proc_open($command, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
		assert(is_resource($process));
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]);
		assert(proc_close($process) === 0, $errors);
		assert(!str_contains($output, '<?php') && !str_contains($output, 'SECRET'));
		if ($mode === 'error') {
			assert($output === 'ERROR:500');
		} elseif ($mode === 'password') {
			assert($output === 'PASSWORD');
		} else {
			assert(str_contains($output, 'rel="canonical"'));
			assert(str_contains($output, 'lang="pt-BR"'));
			assert(str_contains($output, $mode === 'html' ? 'Static page' : 'Dynamic product'));
			assert(str_contains($output, '<base href=') === ($mode === 'php-assets'));
		}
	}
	echo "request rendering checks passed\n";
	exit;
}
ob_start();
require __DIR__ . '/test_core.php';
ob_end_clean();
$mode = $argv[1];
$root = sys_get_temp_dir() . '/htmlpress-render-' . bin2hex(random_bytes(8));
$GLOBALS['render_root'] = $root;
function get_queried_object() { return $GLOBALS['render_page']; }
function is_preview() { return false; }
function post_password_required($page) { return $GLOBALS['argv'][1] === 'password'; }
function get_the_password_form($page) { return 'PASSWORD'; }
function nocache_headers() { $GLOBALS['nocache_called'] = true; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function untrailingslashit($value) { return rtrim($value, '/'); }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function wp_upload_dir() { return ['basedir'=>$GLOBALS['render_root']]; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES); }
function esc_html__($text, ...$args) { return htmlspecialchars($text, ENT_QUOTES); }
function status_header($status) { $GLOBALS['status'] = $status; }
function wp_die($message, $title = '', $args = []) { echo 'ERROR:' . $args['response']; exit; }
function load_template($_template_file, $once = true, $args = []) { global $post; require $_template_file; }
function wc_get_products($args = []) { return [(object)['name'=>'Dynamic product']]; }
$php = <<<'PHP'
<?php
?><!doctype html><html><head></head><body><?php
foreach (wc_get_products() as $product) echo $product->name;
?></body></html>
PHP;
if ($mode === 'error') $php = '<?php echo "SECRET"; throw new RuntimeException("SECRET");';
$source = $mode === 'html' ? '<html><head></head><body>Static page</body></html>' : $php;
$ids = HTMLPress::save_group(0, 'Render test', 'render-test', ['pt'=>$source], null, ['pt'=>$mode === 'html' ? 'html' : 'php']);
assert(is_array($ids));
$post = get_post($ids['pt']);
$GLOBALS['render_page'] = $post;
$group = '12345678-1234-1234-1234-123456789abc';
update_post_meta($post->ID, HTMLPress::GROUP, $group);
$_SERVER['REQUEST_URI'] = '/render-test/';
$_SERVER['REQUEST_METHOD'] = 'GET';
if ($mode === 'php-assets') {
	assert(HTMLPress_Bundle::store($root . '/htmlpress', $group, 'pt', ['css/site.css'=>['bytes'=>'body{}']]));
	register_shutdown_function(static function () use ($root): void {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		rmdir($root);
	});
}
if ($mode !== 'html') {
	ob_start();
	HTMLPress::render();
	assert(ob_get_clean() === ''); // PHP must wait for template_include.
	HTMLPress::render_php('/theme/page.php');
} else {
	HTMLPress::render();
}
throw new RuntimeException('Managed render must finish the request.');
