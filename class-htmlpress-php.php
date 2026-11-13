<?php
/** Trusted administrator PHP templates. This is not a sandbox. */
final class HTMLPress_PHP {
	public static function validate(string $source): bool|WP_Error {
		if ($source === '' || strlen($source) > 2097152 || str_contains($source, "\0") || !preg_match('//u', $source)) {
			return new WP_Error('htmlpress_invalid_php', 'Arquivo PHP inválido: use UTF-8 e no máximo 2 MB.');
		}
		try {
			$tokens = token_get_all($source, TOKEN_PARSE);
		} catch (ParseError $error) {
			return new WP_Error('htmlpress_invalid_php', 'Erro de sintaxe no PHP, linha ' . $error->getLine() . '.');
		}
		foreach ($tokens as $token) {
			if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) return true;
		}
		return new WP_Error('htmlpress_invalid_php', 'O arquivo deve conter código PHP com <?php ou <?=.');
	}

	/** Execute only after WordPress has loaded; never persist executable uploads. */
	public static function execute(string $source, array $args, array $public_roots = []): string {
		$valid = self::validate($source);
		if ($valid !== true) throw new RuntimeException('Invalid PHP template.');
		$file = tmpfile();
		if ($file === false) throw new RuntimeException('Cannot create private temporary template.');
		$level = ob_get_level();
		try {
			$path = stream_get_meta_data($file)['uri'];
			$real = realpath($path);
			foreach ($public_roots as $root) {
				$root = realpath($root);
				if ($root !== false && $real !== false) {
					$normalized = str_replace('\\', '/', $real);
					$prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
					if (strncasecmp($normalized, $prefix, strlen($prefix)) === 0) {
						throw new RuntimeException('PHP temporary directory must be outside public directories.');
					}
				}
			}
			if (fwrite($file, $source) !== strlen($source) || !fflush($file)) throw new RuntimeException('Cannot write PHP template.');
			ob_start();
			load_template($path, false, $args);
			if (ob_get_level() !== $level + 1) throw new RuntimeException('Unbalanced template output buffers.');
			return (string) ob_get_clean();
		} finally {
			while (ob_get_level() > $level) ob_end_clean();
			fclose($file);
		}
	}
}
