<?php
/** Safe bundle path and asset policy, kept independent of WordPress. */
final class HTMLPress_Bundle {
	public const MAX_FILES = 100;
	public const MAX_FILE_SIZE = 2097152;
	public const MAX_TOTAL_SIZE = 10485760;
	private const TYPES = [
		'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
		'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
		'mjs' => 'text/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8',
		'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml; charset=utf-8',
		'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
		'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
		'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
		'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'pdf' => 'application/pdf',
	];

	public static function path(string $path): bool {
		return $path !== '' && strlen($path) <= 240 && !str_contains($path, "\\")
			&& !str_contains($path, "\0") && !str_starts_with($path, '/')
			&& !preg_match('~(?:^|/)\.{1,2}(?:/|$)|[<>:"|?*]|%(?:2e|2f|5c)~i', $path)
			&& !preg_match('~(?:^|/)\.[^/]*$~', $path)
			&& !preg_match('~(?:^|/)[^/]+\.php[0-9s]*$~i', $path);
	}

	public static function mime(string $path): ?string {
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return self::path($path) ? (self::TYPES[$ext] ?? null) : null;
	}

	public static function validAsset(string $path, string $bytes): bool {
		return self::mime($path) !== null && strlen($bytes) > 0 && strlen($bytes) <= self::MAX_FILE_SIZE;
	}

	public static function store(string $root, string $group, string $language, array $files): bool {
		if (!preg_match('/^[a-f0-9-]{36}$/i', $group) || !in_array($language, ['pt', 'en', 'es'], true)) return false;
		$base = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR . $language;
		if (!is_dir($base) && !wp_mkdir_p($base)) return false;
		file_put_contents(dirname($base) . DIRECTORY_SEPARATOR . 'index.php', "<?php http_response_code(404); exit;\n", LOCK_EX);
		file_put_contents(dirname($base) . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n<IfModule mod_php.c>php_flag engine off</IfModule>\n", LOCK_EX);
		$staged = []; $backups = [];
		$published = [];
		try {
			foreach ($files as $path => $file) {
				if (!self::path((string) $path) || !self::validAsset((string) $path, (string) ($file['bytes'] ?? ''))) throw new RuntimeException('invalid asset');
				$target = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
				$parent = dirname($target);
				if (!is_dir($parent) && !wp_mkdir_p($parent)) throw new RuntimeException('mkdir failed');
				$real_base = realpath($base); $real_parent = realpath($parent);
				if ($real_base === false || $real_parent === false || ($real_parent !== $real_base && !str_starts_with($real_parent, $real_base . DIRECTORY_SEPARATOR)) || is_link($target)) throw new RuntimeException('symlink');
				$tmp = $target . '.htmlpress-' . bin2hex(random_bytes(8));
				if (file_put_contents($tmp, $file['bytes'], LOCK_EX) !== strlen($file['bytes'])) throw new RuntimeException('write failed');
				$staged[$target] = $tmp;
			}
			foreach ($staged as $target => $tmp) {
				if (is_file($target)) { $backup = $target . '.backup-' . bin2hex(random_bytes(8)); if (!rename($target, $backup)) throw new RuntimeException('backup failed'); $backups[$target] = $backup; }
				if (!rename($tmp, $target)) throw new RuntimeException('publish failed');
				$published[] = $target;
			}
			$keep = array_fill_keys(array_keys($staged), true);
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($iterator as $entry) {
				$path = $entry->getPathname();
				if ($entry->isDir()) {
					if (!(new FilesystemIterator($path))->valid()) @rmdir($path);
				} elseif (!isset($keep[$path]) && !str_contains(basename($path), '.htmlpress-') && !str_contains(basename($path), '.backup-')) {
					@unlink($path);
				}
			}
			foreach ($backups as $backup) @unlink($backup);
			return true;
		} catch (Throwable $e) {
			foreach ($staged as $tmp) if (is_file($tmp)) @unlink($tmp);
			foreach ($published as $target) if (is_file($target)) @unlink($target);
			foreach ($backups as $target => $backup) { if (is_file($target)) @unlink($target); @rename($backup, $target); }
			return false;
		}
	}

	public static function read(string $root, string $group, string $language, string $path): ?array {
		if (!preg_match('/^[a-f0-9-]{36}$/i', $group) || !in_array($language, ['pt', 'en', 'es'], true) || !self::mime($path)) return null;
		$root = realpath($root);
		$base = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR . $language);
		$file = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
		if ($root === false || $base === false || !str_starts_with($base, $root . DIRECTORY_SEPARATOR) || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || is_link($file) || !is_file($file)) return null;
		$bytes = file_get_contents($file);
		return is_string($bytes) && strlen($bytes) <= self::MAX_FILE_SIZE ? ['bytes' => $bytes, 'mime' => self::mime($path)] : null;
	}

	/** Read bounded entries without extracting untrusted archive paths. */
	public static function readZip(string $filename): array|false {
		if (!class_exists('ZipArchive')) return false;
		$zip = new ZipArchive();
		if ($zip->open($filename, ZipArchive::RDONLY) !== true) return false;
		try {
			if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES * 3) return false;
			$files = []; $total = 0;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i);
				if (!$stat) return false;
				$path = $stat['name'];
				if (!self::path(rtrim($path, '/'))) return false;
				if (!$zip->getExternalAttributesIndex($i, $os, $attributes)) return false;
				if ($os === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) return false;
				if (str_ends_with($path, '/')) continue;
				$size = $stat['size'];
				$total += $size;
				if ($size < 1 || $size > self::MAX_FILE_SIZE || $total > self::MAX_TOTAL_SIZE
					|| count($files) >= self::MAX_FILES || isset($files[$path]) || !self::mime($path)) return false;
				$bytes = $zip->getFromIndex($i, self::MAX_FILE_SIZE + 1);
				if (!is_string($bytes) || strlen($bytes) !== $size || !self::validAsset($path, $bytes)) return false;
				if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['html', 'htm'], true)
					&& is_wp_error(HTMLPress_Document::validate($bytes))) return false;
				$files[$path] = ['bytes' => $bytes, 'mime' => self::mime($path)];
			}
			// Accept both ZIP contents and a ZIP containing one enclosing project folder.
			if (!isset($files['index.html']) && $files) {
				$prefix = explode('/', array_key_first($files))[0] . '/';
				$normalized = [];
				foreach ($files as $path => $file) {
					if (!str_starts_with($path, $prefix)) return false;
					$normalized[substr($path, strlen($prefix))] = $file;
				}
				$files = $normalized;
			}
			return isset($files['index.html']) ? $files : false;
		} finally {
			$zip->close();
		}
	}
}
