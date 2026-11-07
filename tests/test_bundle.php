<?php
if (ini_get('zend.assertions') !== '1') { throw new RuntimeException('Run with -d zend.assertions=1 -d assert.exception=1'); }
class WP_Error {}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
require __DIR__ . '/../htmlpress/class-htmlpress-document.php';
require __DIR__ . '/../htmlpress/class-htmlpress-bundle.php';
if (!function_exists('wp_mkdir_p')) { function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); } }
assert(HTMLPress_Bundle::path('css/site.css'));
assert(!HTMLPress_Bundle::path('../secret.css'));
assert(!HTMLPress_Bundle::path('a\\b.css'));
assert(!HTMLPress_Bundle::path('x.php'));
assert(!HTMLPress_Bundle::path('.htaccess'));
assert(HTMLPress_Bundle::mime('img/logo.PNG') === 'image/png');
assert(HTMLPress_Bundle::mime('img/logo.svg') === null);
assert(HTMLPress_Bundle::mime('run.php') === null);
assert(HTMLPress_Bundle::validAsset('main.js', 'console.log(1)'));
assert(!HTMLPress_Bundle::validAsset('main.js', ''));
$root = sys_get_temp_dir() . '/htmlpress-' . bin2hex(random_bytes(5));
assert(HTMLPress_Bundle::store($root, '12345678-1234-1234-1234-123456789abc', 'en', ['index.html' => ['bytes' => '<html>', 'mime' => 'text/html; charset=utf-8'], 'css/site.css' => ['bytes' => 'body{}', 'mime' => 'text/css; charset=utf-8']]));
assert(HTMLPress_Bundle::read($root, '12345678-1234-1234-1234-123456789abc', 'en', 'css/site.css')['bytes'] === 'body{}');
assert(HTMLPress_Bundle::store($root, '12345678-1234-1234-1234-123456789abc', 'en', ['css/site.css' => ['bytes' => 'body{color:red}', 'mime' => 'text/css; charset=utf-8']]));
assert(HTMLPress_Bundle::read($root, '12345678-1234-1234-1234-123456789abc', 'en', 'css/site.css')['bytes'] === 'body{color:red}');
assert(HTMLPress_Bundle::store($root, '12345678-1234-1234-1234-123456789abc', 'en', ['index.html' => ['bytes' => '<html>', 'mime' => 'text/html; charset=utf-8']]));
assert(HTMLPress_Bundle::read($root, '12345678-1234-1234-1234-123456789abc', 'en', 'css/site.css') === null);
assert(HTMLPress_Bundle::read($root, '12345678-1234-1234-1234-123456789abc', 'en', '../secret') === null);
assert(!HTMLPress_Bundle::store($root, '12345678-1234-1234-1234-123456789abc', 'en', ['bad.php' => ['bytes' => '<?php', 'mime' => 'application/x-php']]));
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
rmdir($root);
assert(HTMLPress_Bundle::store($root, '12345678-1234-1234-1234-123456789abc', 'pt', ['index.html' => ['bytes' => '<html>']]));
assert(HTMLPress_Bundle::store($root, '12345678-1234-1234-1234-123456789abc', 'pt', []));
assert(HTMLPress_Bundle::read($root, '12345678-1234-1234-1234-123456789abc', 'pt', 'index.html') === null);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
rmdir($root);

$html = '<!doctype html><html><head></head><body>Test</body></html>';
$archive = sys_get_temp_dir() . '/htmlpress-zip-' . bin2hex(random_bytes(5)) . '.zip';
$checkZip = static function (array $entries, bool $valid) use ($archive): array|false {
    $zip = new ZipArchive();
    assert($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
    foreach ($entries as $path => $bytes) $zip->addFromString($path, $bytes);
    $zip->close();
    try {
        $files = HTMLPress_Bundle::readZip($archive);
        assert(($files !== false) === $valid);
        return $files;
    } finally { unlink($archive); }
};
$files = $checkZip(['index.html' => $html, 'assets/main.js' => 'console.log(1)', 'images/a.png' => 'image'], true);
assert($files['index.html']['bytes'] === $html && isset($files['assets/main.js'], $files['images/a.png']));
$files = $checkZip(['project/index.html' => $html, 'project/css/site.css' => 'body{}'], true);
assert(isset($files['index.html'], $files['css/site.css']) && !isset($files['project/index.html']));
$checkZip(['index.html' => $html, '../bad.css' => 'bad'], false);
$checkZip(['index.html' => $html, '/bad.css' => 'bad'], false);
$checkZip(['index.html' => $html, 'run.php' => '<?php'], false);
$checkZip(['index.html' => 'invalid'], false);
$checkZip(['main.js' => 'console.log(1)'], false);
$checkZip(['index.html' => $html, 'big.js' => str_repeat('x', HTMLPress_Bundle::MAX_FILE_SIZE + 1)], false);
$entries = ['index.html' => $html];
for ($i = 0; $i < 100; $i++) $entries['file' . $i . '.js'] = 'x';
$checkZip($entries, false);
$entries = ['index.html' => $html];
for ($i = 0; $i < 5; $i++) $entries['file' . $i . '.js'] = str_repeat('x', HTMLPress_Bundle::MAX_FILE_SIZE);
$checkZip($entries, false);
$zip = new ZipArchive();
assert($zip->open($archive, ZipArchive::CREATE) === true);
$zip->addFromString('index.html', $html);
$zip->addFromString('link.js', 'target.js');
$zip->setExternalAttributesName('link.js', ZipArchive::OPSYS_UNIX, 0120777 << 16);
$zip->close();
try { assert(HTMLPress_Bundle::readZip($archive) === false); } finally { unlink($archive); }
echo "bundle checks passed\n";
