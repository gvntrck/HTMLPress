<?php
/** Admin UI and upload handling for HTMLPress pages. */
if (!defined('ABSPATH')) {
	exit;
}

final class HTMLPress_Admin {
	private static bool $editor_rendered = false;
	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'menu']);
		add_filter('use_block_editor_for_post', [__CLASS__, 'use_editor'], 10, 2);
		add_filter('replace_editor', [__CLASS__, 'replace_editor'], 10, 2);
		add_action('admin_post_htmlpress_save', [__CLASS__, 'save']);
		add_filter('page_row_actions', [__CLASS__, 'row_actions'], 10, 2);
		add_filter('manage_pages_columns', [__CLASS__, 'page_columns']);
		add_action('manage_pages_custom_column', [__CLASS__, 'page_column'], 10, 2);
		add_filter('list_pages', [__CLASS__, 'reading_dropdown_title'], 10, 2);
	}

	public static function menu(): void {
		if (!HTMLPress::can_manage()) {
			return;
		}
		add_submenu_page(
			'edit.php?post_type=page',
			__('Adicionar página HTML', 'htmlpress'),
			__('Adicionar página HTML', 'htmlpress'),
			'manage_options',
			'post-new.php?post_type=page&htmlpress=1'
		);
	}

	public static function use_editor($use, $post) {
		if (!$post || $post->post_type !== 'page') {
			return $use;
		}
		return HTMLPress::is_managed((int) $post->ID) || self::is_new_html_page() ? false : $use;
	}

	public static function replace_editor($replace, $post) {
		// Other plugins may probe this filter while our admin header is loading.
		// Claim the editor before rendering to prevent nested headers and forms.
		if ($replace || self::$editor_rendered) {
			return true;
		}
		if (!$post || $post->post_type !== 'page' || (!HTMLPress::is_managed((int) $post->ID) && !self::is_new_html_page())) {
			return $replace;
		}
		if (!HTMLPress::can_manage()) {
			wp_die(esc_html__('Permissão insuficiente para gerenciar páginas HTML.', 'htmlpress'));
		}
		self::$editor_rendered = true;
		self::render_form($post);
		return true;
	}

	private static function is_new_html_page(): bool {
		global $pagenow;
		return $pagenow === 'post-new.php' && ($_GET['post_type'] ?? '') === 'page' && isset($_GET['htmlpress']) && (string) $_GET['htmlpress'] === '1';
	}

	private static function render_form($post): void {
		if (!HTMLPress::can_manage()) {
			wp_die(esc_html__('Permissão insuficiente para gerenciar páginas HTML.', 'htmlpress'));
		}
		require_once ABSPATH . 'wp-admin/admin-header.php';
		$id = HTMLPress::is_managed((int) $post->ID) ? (int) $post->ID : 0;
		$group = $id ? HTMLPress::group($id) : [];
		if ($id && !$group) {
			wp_die(esc_html__('O grupo HTMLPress está incompleto.', 'htmlpress'));
		}
		$pt = $group['pt'] ?? $post;
		echo '<div class="wrap"><h1>' . esc_html($id ? __('Editar página HTML', 'htmlpress') : __('Nova página HTML', 'htmlpress')) . '</h1>';
		if (isset($_GET['htmlpress_saved'])) {
			echo '<div class="notice notice-success"><p>' . esc_html__('Página HTML salva.', 'htmlpress') . '</p></div>';
		}
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="htmlpress_save"><input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
		wp_nonce_field('htmlpress_save');
		$title = !$id && $pt->post_title === 'Auto Draft' ? '' : $pt->post_title;
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="htmlpress-title">' . esc_html__('Título compartilhado', 'htmlpress') . '</label></th><td><input class="regular-text" id="htmlpress-title" name="title" value="' . esc_attr($title) . '" required></td></tr>';
		echo '<tr><th scope="row"><label for="htmlpress-slug">' . esc_html__('Slug público', 'htmlpress') . '</label></th><td><input class="regular-text" id="htmlpress-slug" name="slug" value="' . esc_attr($id ? HTMLPress::public_slug($id) : '') . '" aria-describedby="htmlpress-slug-help" required>';
		echo '<p id="htmlpress-slug-help" class="description">' . esc_html__('Compartilhado entre os idiomas. Alterá-lo muda as URLs públicas, exceto as da página inicial. Links antigos não são redirecionados automaticamente; atualize os links no HTML.', 'htmlpress') . '</p>';
		echo '</td></tr></tbody></table>';
		if ($id) {
			echo '<h2>' . esc_html__('Páginas por idioma', 'htmlpress') . '</h2><ul>';
			foreach (['pt' => 'Português', 'en' => 'English', 'es' => 'Español'] as $lang => $label) {
				if (!isset($group[$lang])) continue;
				echo '<li><a href="' . esc_url(HTMLPress::url((int) $group[$lang]->ID)) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '<h2>' . esc_html__('Conteúdo por idioma', 'htmlpress') . '</h2>';
		echo '<p id="htmlpress-upload-help" class="description">' . esc_html__('Envie um HTML ou um ZIP por idioma. No ZIP, inclua index.html e suas pastas de imagens, CSS e JS. Limites: HTML de 2 MB; ZIP de 10 MB, até 100 arquivos de 2 MB cada (10 MB descompactados). Publique somente conteúdo confiável.', 'htmlpress') . '</p>';
		if ($id) echo '<p id="htmlpress-replace-help" class="description">' . esc_html__('Deixe o campo vazio para manter o conteúdo atual. Um novo envio substitui o conteúdo e os assets daquele idioma.', 'htmlpress') . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach (['pt' => 'Português (Brasil)', 'en' => 'English', 'es' => 'Español'] as $lang => $label) {
			$name = 'html_' . $lang;
			$required = !$id && $lang === 'pt';
			$caption = $required ? __('%s (obrigatório)', 'htmlpress') : __('%s (opcional)', 'htmlpress');
			echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html(sprintf($caption, $label)) . '</label></th><td>';
			echo '<input type="file" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" accept=".html,.htm,.zip" aria-describedby="htmlpress-upload-help' . ($id ? ' htmlpress-replace-help' : '') . '"' . ($required ? ' required' : '') . '></td></tr>';
		}
		echo '</tbody></table><p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Salvar página HTML', 'htmlpress') . '</button></p></form></div>';
	}

	public static function save(): void {
		if (!HTMLPress::can_manage()) {
			wp_die(esc_html__('Permissão insuficiente.', 'htmlpress'));
		}
		check_admin_referer('htmlpress_save');
		if (isset($_POST['id']) && !is_scalar($_POST['id']) || isset($_POST['title']) && !is_scalar($_POST['title']) || isset($_POST['slug']) && !is_scalar($_POST['slug'])) self::fail(__('Dados do formulário inválidos.', 'htmlpress'));
		$id = absint($_POST['id'] ?? 0);
		$group = $id ? HTMLPress::group($id) : [];
		if ($id && (!$group || !HTMLPress::is_managed($id))) {
			self::fail(__('Grupo HTMLPress inválido.', 'htmlpress'));
		}
		if ($group) {
			foreach ($group as $document) {
				if (!current_user_can('edit_post', $document->ID)) {
					self::fail(__('Sem permissão para editar todas as páginas.', 'htmlpress'));
				}
			}
		}

		$title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
		$slug = sanitize_title(wp_unslash($_POST['slug'] ?? ''));
		if ($title === '' || $slug === '') {
			self::fail(__('Título e slug são obrigatórios.', 'htmlpress'));
		}
		if (!class_exists('finfo')) self::fail(__('A validação MIME não está disponível no servidor.', 'htmlpress'));
		$documents = [];
		$bundles = [];
		foreach (['pt', 'en', 'es'] as $lang) {
			$file = $_FILES['html_' . $lang] ?? null;
			if ($file !== null && (!is_array($file) || array_diff(['name', 'tmp_name', 'error', 'size'], array_keys($file)) || !is_scalar($file['name']) || !is_scalar($file['tmp_name']) || !is_scalar($file['error']) || !is_scalar($file['size']))) self::fail(__('Upload inválido.', 'htmlpress'));
			if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
				if (!$id && $lang === 'pt') self::fail(__('Envie o arquivo HTML PT-BR. Os demais idiomas são opcionais.', 'htmlpress'));
				continue;
			}
			$tmp = $file['tmp_name'] ?? '';
			$size = is_uploaded_file($tmp) ? filesize($tmp) : false;
			$is_zip = preg_match('/\.zip$/i', (string) $file['name']) === 1;
			$limit = $is_zip ? HTMLPress_Bundle::MAX_TOTAL_SIZE : HTMLPress_Bundle::MAX_FILE_SIZE;
			if ((int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp) || $size === false || $size < 1 || $size > $limit || (int) ($file['size'] ?? 0) !== $size || !preg_match('/\.(?:html?|zip)$/i', (string) $file['name'])) self::fail(__('Envie um HTML de até 2 MB ou um ZIP de até 10 MB.', 'htmlpress'));
			if ($is_zip) {
				if (!class_exists('ZipArchive')) self::fail(__('O servidor precisa da extensão PHP zip para receber pacotes ZIP. Você ainda pode enviar HTML individual.', 'htmlpress'));
				$files = HTMLPress_Bundle::readZip($tmp);
				if ($files === false) self::fail(__('ZIP inválido: inclua index.html e arquivos permitidos, sem caminhos inseguros. Limites: 100 arquivos, 2 MB cada e 10 MB descompactados.', 'htmlpress'));
				$bundles[$lang] = $files;
				$documents[$lang] = $files['index.html']['bytes'];
				continue;
			}
			$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
			if (!in_array($mime, ['text/html', 'text/plain', 'application/xhtml+xml'], true)) self::fail(__('Tipo de arquivo inválido.', 'htmlpress'));
			$html = file_get_contents($tmp);
			if (!is_string($html)) self::fail(__('Não foi possível ler o arquivo.', 'htmlpress'));
			$valid = HTMLPress_Document::validate($html);
			if (is_wp_error($valid)) self::fail($valid->get_error_message());
			$documents[$lang] = $html;
			// A standalone HTML replaces any previously uploaded assets too.
			$bundles[$lang] = [];
		}
		if (!$id && !isset($documents['pt'])) self::fail(__('Selecione o arquivo HTML PT-BR.', 'htmlpress'));
		$upload_dir = wp_upload_dir();
		$result = HTMLPress::save_group($id, $title, $slug, $documents, static function (string $group_id) use ($bundles, $upload_dir): bool {
			foreach ($bundles as $lang => $files) {
				if (!HTMLPress_Bundle::store($upload_dir['basedir'] . '/htmlpress', $group_id, $lang, $files)) return false;
			}
			return true;
		});
		if (is_wp_error($result)) self::fail($result->get_error_message());
		$target = $result['pt'] ?? $id;
		wp_safe_redirect(add_query_arg('htmlpress_saved', '1', admin_url('post.php?post=' . absint($target) . '&action=edit')));
		exit;
	}

	private static function fail($message): void {
		wp_die(esc_html($message), esc_html__('HTMLPress', 'htmlpress'), ['back_link' => true]);
	}

	public static function row_actions($actions, $post) {
		if ($post->post_type === 'page' && HTMLPress::is_managed((int) $post->ID) && HTMLPress::can_manage()) {
		$actions['htmlpress'] = '<a href="' . esc_url(get_edit_post_link($post->ID, 'raw')) . '">' . esc_html__('Editar trio HTML', 'htmlpress') . '</a>';
		}
		return $actions;
	}

	public static function page_columns($columns) {
		$columns['htmlpress_language'] = __('Idioma HTMLPress', 'htmlpress');
		return $columns;
	}

	public static function page_column($column, $post_id): void {
		if ($column !== 'htmlpress_language' || !HTMLPress::is_managed((int) $post_id)) {
			return;
		}
		$labels = ['pt' => 'PT-BR', 'en' => 'EN', 'es' => 'ES'];
		$lang = get_post_meta($post_id, HTMLPress::LANG, true);
		echo esc_html($labels[$lang] ?? '');
	}

	public static function reading_dropdown_title($title, $page) {
		if (!is_admin() || !HTMLPress::is_managed((int) $page->ID)) {
			return $title;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->id !== 'options-reading') {
			return $title;
		}
		$labels = ['pt' => 'PT-BR', 'en' => 'EN', 'es' => 'ES'];
		$lang = get_post_meta($page->ID, HTMLPress::LANG, true);
		return $title . ' — HTMLPress ' . ($labels[$lang] ?? '');
	}
}
