<?php
/**
 * Plugin Name: HTMLPress
 * Description: Páginas HTML e PHP em português, inglês e espanhol, com assets e integração ao WordPress.
 * Version: 1.4.1
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Text Domain: htmlpress
 */

if (!defined('ABSPATH')) {
	exit;
}

require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v6\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/gvntrck/HTMLPress',
	__FILE__,
	'htmlpress'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('master');





require_once __DIR__ . '/class-htmlpress-document.php';
require_once __DIR__ . '/class-htmlpress-php.php';
require_once __DIR__ . '/class-htmlpress-bundle.php';
require_once __DIR__ . '/class-htmlpress-admin.php';

final class HTMLPress
{
	public const GROUP = '_htmlpress_group';
	public const LANG = '_htmlpress_lang';
	public const HTML = '_htmlpress_html';
	public const SLUG = '_htmlpress_slug';
	public const TYPE = '_htmlpress_type';

	private const LANGUAGES = ['pt', 'en', 'es'];
	private static bool $writing = false;

	public static function init(): void
	{
		add_action('parse_request', [self::class, 'route_request']);
		add_action('template_redirect', [self::class, 'render'], 0);
		add_filter('template_include', [self::class, 'render_php']);
		add_filter('page_link', [self::class, 'page_link'], 10, 2);
		add_filter('wp_insert_post_data', [self::class, 'protect_post_data'], 10, 2);
		add_filter('map_meta_cap', [self::class, 'managed_caps'], 10, 4);
		add_filter('pre_update_option_page_on_front', [self::class, 'protect_front_page'], 10, 2);
		add_filter('pre_update_option_show_on_front', [self::class, 'protect_front_mode'], 10, 2);
		add_filter('pre_update_option_page_for_posts', [self::class, 'protect_posts_page'], 10, 2);
		add_action('admin_init', [self::class, 'normalize_front_page']);

		HTMLPress_Admin::init();
	}

	public static function can_manage(): bool
	{
		foreach (['manage_options', 'unfiltered_html', 'edit_pages', 'publish_pages', 'upload_files'] as $capability) {
			if (!current_user_can($capability)) {
				return false;
			}
		}

		return true;
	}

	public static function can_upload_php(): bool
	{
		return self::can_manage() && current_user_can('edit_themes')
			&& (!is_multisite() || is_super_admin())
			&& !(defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT)
			&& !(defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS);
	}

	public static function is_managed(int $id): bool
	{
		return (string) get_post_meta($id, self::GROUP, true) !== '';
	}

	/** @return array<string, WP_Post> */
	public static function group(int $id): array
	{
		$group = (string) get_post_meta($id, self::GROUP, true);
		if ($group === '') {
			return [];
		}

		$pages = get_posts([
			'post_type' => 'page',
			'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
			'numberposts' => -1,
			'meta_key' => self::GROUP,
			'meta_value' => $group,
		]);
		$documents = [];
		foreach ($pages as $page) {
			$language = (string) get_post_meta($page->ID, self::LANG, true);
			if (in_array($language, self::LANGUAGES, true)) {
				$documents[$language] = $page;
			}
		}

		return $documents;
	}

	public static function public_slug(int $id): string
	{
		$slug = (string) get_post_meta($id, self::SLUG, true);
		return $slug !== '' ? $slug : (string) get_post_field('post_name', $id);
	}

	public static function url(int $id): string
	{
		$language = (string) get_post_meta($id, self::LANG, true);
		$slug = self::public_slug($id);
		if ($language === 'pt' && get_option('show_on_front') === 'page' && (int) get_option('page_on_front') === $id) {
			return home_url('/');
		}
		if (in_array($language, ['en', 'es'], true) && self::front_group_has($id)) {
			return home_url('/' . $language . '/');
		}

		$path = $language === 'pt' ? '/' . $slug . '/' : '/' . $language . '/' . $slug . '/';
		return home_url($path);
	}

	/** @return array<string, int>|WP_Error */
	public static function save_group(int $id, string $title, string $slug, array $documents, ?callable $before_commit = null, array $types = []): array|WP_Error
	{
		if (!self::can_manage()) {
			return new WP_Error('htmlpress_forbidden', __('Permissão insuficiente.', 'htmlpress'));
		}

		$title = sanitize_text_field($title);
		$slug = sanitize_title($slug);
		if (!get_option('permalink_structure')) {
			return new WP_Error('htmlpress_permalinks_required', __('Ative links permanentes amigáveis antes de criar ou editar páginas HTMLPress.', 'htmlpress'));
		}
		$old = $id ? self::group($id) : [];
		if (($id && (!$old || $title === '')) || (!$id && ($title === '' || $slug === ''))) {
			return new WP_Error('htmlpress_invalid_group', __('Trio inválido.', 'htmlpress'));
		}
		if ($id) {
			foreach ($old as $document) {
				if (!current_user_can('edit_post', $document->ID)) {
					return new WP_Error('htmlpress_forbidden', __('Sem permissão para editar todos os documentos.', 'htmlpress'));
				}
			}
			$anchor = reset($old);
			if ($slug === '') {
				$slug = self::public_slug($anchor->ID);
			}
		}
		$changing_slug = !$id || $slug !== self::public_slug($anchor->ID);
		if ($changing_slug && self::slug_collides($slug)) {
			return new WP_Error('htmlpress_slug_conflict', __('Slug ou caminho já utilizado/reservado.', 'htmlpress'));
		}

		foreach (self::LANGUAGES as $language) {
			if (!array_key_exists($language, $documents)) {
				if (!$id && $language === 'pt') {
					return new WP_Error('htmlpress_missing_document', __('O documento PT-BR é obrigatório. EN e ES são opcionais.', 'htmlpress'));
				}
				continue;
			}
			if (!is_string($documents[$language])) {
				return new WP_Error('htmlpress_invalid_document', __('Documento inválido.', 'htmlpress'));
			}
			$type = $types[$language] ?? 'html';
			if (!in_array($type, ['html', 'php'], true)) {
				return new WP_Error('htmlpress_invalid_type', __('Formato inválido.', 'htmlpress'));
			}
			if ($type === 'php' && !self::can_upload_php()) {
				return new WP_Error('htmlpress_php_forbidden', __('Sem permissão para enviar código PHP. Verifique as permissões de edição de código do WordPress.', 'htmlpress'));
			}
			$valid = $type === 'php' ? HTMLPress_PHP::validate($documents[$language]) : HTMLPress_Document::validate($documents[$language]);
			if (is_wp_error($valid)) {
				return $valid;
			}
		}
		$anchor = $id ? reset($old) : null;
		$group_id = $id ? (string) get_post_meta($anchor->ID, self::GROUP, true) : wp_generate_uuid4();
		if ($id && array_diff(array_keys($documents), array_keys($old))) {
			$trashed = get_posts(['post_type' => 'page', 'post_status' => 'trash', 'numberposts' => -1, 'meta_key' => self::GROUP, 'meta_value' => $group_id]);
			foreach ($trashed as $page) {
				$language = (string) get_post_meta($page->ID, self::LANG, true);
				if (isset($documents[$language])) {
					return new WP_Error('htmlpress_translation_trashed', __('Restaure a tradução na lixeira antes de substituí-la.', 'htmlpress'));
				}
			}
		}
		if ($changing_slug && !self::acquire_creation_lock($group_id)) {
			return new WP_Error('htmlpress_busy', __('Outra criação HTMLPress está em andamento.', 'htmlpress'));
		}
		if ($changing_slug && self::slug_collides($slug)) {
			self::release_creation_lock($group_id);
			return new WP_Error('htmlpress_slug_conflict', __('Slug ou caminho já utilizado/reservado.', 'htmlpress'));
		}
		$created = [];
		$snapshots = [];
		$result = [];
		self::$writing = true;
		try {
		foreach (self::LANGUAGES as $language) {
			$existing = $old[$language] ?? null;
			if (!$existing && !array_key_exists($language, $documents)) {
				continue;
			}
			if ($existing) {
				$snapshots[$existing->ID] = [
					'post' => $existing,
					'meta' => [
						self::GROUP => get_post_meta($existing->ID, self::GROUP, true),
						self::LANG => get_post_meta($existing->ID, self::LANG, true),
						self::SLUG => get_post_meta($existing->ID, self::SLUG, true),
						self::HTML => get_post_meta($existing->ID, self::HTML, true),
						self::TYPE => get_post_meta($existing->ID, self::TYPE, true),
					],
				];
			}
			$post = [
				'post_type' => 'page',
				'post_status' => $existing ? $existing->post_status : 'publish',
				'post_title' => $title,
				'post_name' => $language === 'pt' ? $slug : 'htmlpress-' . $group_id . '-' . $language,
				'post_content' => '',
			];
			if ($existing) {
				$post['ID'] = $existing->ID;
			}
			$saved = wp_insert_post($post, true);
			if (is_wp_error($saved)) {
				self::rollback($created, $snapshots);
				if ($changing_slug) {
					self::release_creation_lock($group_id);
				}
				return $saved;
			}
			if (!$existing) {
				$created[] = $saved;
			}
			update_post_meta($saved, self::GROUP, $group_id);
			update_post_meta($saved, self::LANG, $language);
			update_post_meta($saved, self::SLUG, $slug);
			if (array_key_exists($language, $documents)) {
				update_post_meta($saved, self::HTML, wp_slash((string) $documents[$language]));
				update_post_meta($saved, self::TYPE, $types[$language] ?? 'html');
			}
			if ((string) get_post_meta($saved, self::GROUP, true) !== $group_id
				|| (string) get_post_meta($saved, self::LANG, true) !== $language
				|| (string) get_post_meta($saved, self::SLUG, true) !== $slug
				|| (array_key_exists($language, $documents) && ((string) get_post_meta($saved, self::HTML, true) !== $documents[$language]
					|| get_post_meta($saved, self::TYPE, true) !== ($types[$language] ?? 'html')))) {
				self::rollback($created, $snapshots);
				if ($changing_slug) {
					self::release_creation_lock($group_id);
				}
				return new WP_Error('htmlpress_save_failed', __('Falha ao salvar metadados HTMLPress.', 'htmlpress'));
			}
			$result[$language] = (int) $saved;
		}
		if ($before_commit) {
			$commit = $before_commit($group_id, $result);
			if (is_wp_error($commit) || $commit === false) {
				self::rollback($created, $snapshots);
				return is_wp_error($commit) ? $commit : new WP_Error('htmlpress_bundle_failed', __('Falha ao armazenar a pasta de assets.', 'htmlpress'));
			}
		}
		} finally {
			self::$writing = false;
			if ($changing_slug) {
				self::release_creation_lock($group_id);
			}
		}
		return $result;
	}

	public static function route_request(WP $wp): void
	{
		$path = trim((string) $wp->request, '/');
		if (preg_match('~^(?:(en|es)/)?(?:(.+)/)?_htmlpress-assets/(pt|en|es)/(.+)$~', $path, $asset)) {
			$owner_language = $asset[1] ?: 'pt';
			$page = !empty($asset[2]) ? self::find_public_page($asset[2], $owner_language) : get_post(self::front_id_for_language($owner_language));
			if ($page && self::group($page->ID)) self::serve_asset($page->ID, $asset[3], $asset[4]);
			return;
		}
		$language = 'pt';
		$slug = $path;
		if (preg_match('~^(en|es)(?:/(.*))?$~', $path, $matches)) {
			$language = $matches[1];
			$slug = $matches[2] ?? '';
		}
		$front = $slug === '' && self::front_id_for_language($language);
		if (!$front && ($slug === '' || str_contains($slug, '/'))) {
			return;
		}

		$page = $front ? get_post(self::front_id_for_language($language)) : self::find_public_page($slug, $language);
		if (!$page) {
			return;
		}
		$wp->query_vars = [
			'page_id' => (string) $page->ID,
			'post_type' => 'page',
		];
	}

	private static function serve_asset(int $id, string $language, string $path): void
	{
		if (!self::can_serve_assets() || !HTMLPress_Bundle::path($path)) self::not_found();
		$group = (string) get_post_meta($id, self::GROUP, true);
		$documents = self::group($id);
		if (!isset($documents[$language]) || $documents[$language]->post_status !== 'publish') self::not_found();
		if (post_password_required($documents[$language])) self::not_found();
		$uploads = wp_upload_dir();
		$file = HTMLPress_Bundle::read($uploads['basedir'] . '/htmlpress', $group, $language, $path);
		if (!$file) self::not_found();
		header('X-Content-Type-Options: nosniff');
		header('Content-Type: ' . $file['mime']);
		header('Cache-Control: public, max-age=3600');
		echo $file['bytes'];
		exit;
	}

	private static function can_serve_assets(): bool
	{
		return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
	}

	public static function render_php(string $template): string
	{
		self::render(true);
		return $template;
	}

	public static function render(bool $php = false): void
	{
		$page = get_queried_object();
		if (!$page instanceof WP_Post || $page->post_type !== 'page' || !self::is_managed($page->ID)) {
			return;
		}
		// Dynamic pages wait until template_include so WooCommerce and redirect hooks run.
		if ((get_post_meta($page->ID, self::TYPE, true) === 'php') !== $php) return;
		if ($page->post_status !== 'publish') {
			if (!is_preview() || !current_user_can('edit_post', $page->ID)) {
				self::not_found();
			}
			nocache_headers();
		}
		if (post_password_required($page)) {
			nocache_headers();
			echo get_the_password_form($page);
			exit;
		}
		$canonical = self::url($page->ID);
		$requested = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
		$canonical_path = wp_parse_url($canonical, PHP_URL_PATH);
		if ($page->post_status === 'publish' && !is_preview() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
			&& !is_admin() && is_string($requested) && is_string($canonical_path) && untrailingslashit($requested) !== untrailingslashit($canonical_path)) {
			wp_safe_redirect(add_query_arg(wp_unslash($_GET), $canonical), 301);
			exit;
		}
		$group = self::group($page->ID);
		if (!$group) {
			self::not_found();
		}
		$language = (string) get_post_meta($page->ID, self::LANG, true);
		$alternates = [];
		foreach ($group as $code => $document) {
			if ($document->post_status === 'publish') {
				$alternates[$code === 'pt' ? 'pt-BR' : $code] = self::url($document->ID);
			}
		}
		$html = (string) get_post_meta($page->ID, self::HTML, true);
		$canonical = self::url($page->ID);
		$uploads = wp_upload_dir();
		$group_id = (string) get_post_meta($page->ID, self::GROUP, true);
		$is_php = get_post_meta($page->ID, self::TYPE, true) === 'php';
		$base = trailingslashit($canonical) . '_htmlpress-assets/' . $language . '/';
		if ($is_php) {
			nocache_headers();
			try {
				$html = HTMLPress_PHP::execute($html, ['assets_url' => $base, 'page_url' => $canonical], [
					ABSPATH, $uploads['basedir'], defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH,
					$_SERVER['DOCUMENT_ROOT'] ?? ABSPATH,
				]);
			} catch (Throwable $error) {
				// Never expose uploaded source, paths or exception messages to visitors.
				error_log('HTMLPress PHP page ' . $page->ID . ' failed (' . get_class($error) . ').');
				status_header(500);
				wp_die(esc_html__('Não foi possível carregar esta página.', 'htmlpress'), 'HTMLPress', ['response' => 500]);
			}
		}
		if (($is_php && HTMLPress_Bundle::hasAssets($uploads['basedir'] . '/htmlpress', $group_id, $language))
			|| (!$is_php && HTMLPress_Bundle::read($uploads['basedir'] . '/htmlpress', $group_id, $language, 'index.html'))) {
			$html = preg_replace('~(<head\b[^>]*>)~i', '$1<base href="' . esc_url($base) . '">', $html, 1);
		}
		if (!$is_php) status_header(200);
		header('Content-Type: text/html; charset=UTF-8');
		echo HTMLPress_Document::transform($html, $language === 'pt' ? 'pt-BR' : $language, $canonical, $alternates);
		exit;
	}

	public static function page_link(string $url, int $id): string
	{
		return self::is_managed($id) ? self::url($id) : $url;
	}

	public static function protect_post_data(array $data, array $postarr): array
	{
		$id = (int) ($postarr['ID'] ?? 0);
		if (self::is_managed($id)) {
			if (self::$writing) {
				return $data;
			}
			$managed = get_post($id);
			if (in_array($data['post_status'] ?? '', ['trash', 'auto-draft'], true)) {
				return $data;
			}
			$restoring = $managed->post_status === 'trash';
			if (!$restoring) {
				$data['post_name'] = $managed->post_name;
			}
			$data['post_parent'] = $managed->post_parent;
			$data['post_content'] = $managed->post_content;
			$data['post_title'] = $managed->post_title;
			if (!$restoring) {
				$data['post_status'] = $managed->post_status;
			}
			return $data;
		}
		if (($data['post_type'] ?? '') === 'page' && !in_array($data['post_status'] ?? '', ['auto-draft', 'trash'], true)) {
			$slug = sanitize_title((string) ($data['post_name'] ?? ''));
			$paths = self::public_paths();
			$old_page = $id ? get_post($id) : null;
			$new_parent_id = (int) ($data['post_parent'] ?? 0);
			if ($old_page instanceof WP_Post && ($old_page->post_parent !== $new_parent_id || $old_page->post_name !== $slug)) {
				$old_uri = (string) get_page_uri($id);
				$old_parent_uri = $old_page->post_parent ? (string) get_page_uri($old_page->post_parent) : '';
				$new_parent_uri = $new_parent_id ? (string) get_page_uri($new_parent_id) : '';
				$descendants = get_pages(['child_of' => $id, 'post_status' => 'publish', 'post_type' => 'page']);
				foreach ($descendants as $descendant) {
					$uri = (string) get_page_uri($descendant->ID);
					$relative = ltrim(substr($uri, strlen($old_uri)), '/');
					$proposed = trim(($new_parent_uri === '' ? '' : $new_parent_uri . '/') . $slug . '/' . $relative, '/');
					if (isset($paths[$proposed])) {
						$data['post_parent'] = $old_page->post_parent;
						$data['post_name'] = $old_page->post_name;
						$slug = $old_page->post_name;
						$new_parent_id = $old_page->post_parent;
						break;
					}
				}
			}
			foreach ($paths as $path => $managed_id) {
				if ($managed_id !== $id && $slug !== '' && self::candidate_path($data, $slug) === $path) {
					$suffix = 2;
					do {
						$candidate = $slug . '-' . $suffix++;
					} while (isset($paths[self::candidate_path($data, $candidate)])
						|| wp_unique_post_slug($candidate, $id, $data['post_status'], 'page', (int) ($data['post_parent'] ?? 0)) !== $candidate);
					$data['post_name'] = $candidate;
					break;
				}
			}
		}
		if (($data['post_type'] ?? '') === 'post' && trim((string) get_option('permalink_structure'), '/') === '%postname%') {
			$slug = sanitize_title((string) ($data['post_name'] ?? ''));
			$paths = self::public_paths();
			if ($slug !== '' && isset($paths[$slug])) {
				$suffix = 2;
				do {
					$candidate = $slug . '-' . $suffix++;
				} while (isset($paths[$candidate]) || wp_unique_post_slug($candidate, $id, $data['post_status'], 'post', 0) !== $candidate);
				$data['post_name'] = $candidate;
			}
		}
		return $data;
	}

	public static function managed_caps(array $caps, string $cap, int $user_id, array $args): array
	{
		if (!in_array($cap, ['edit_post', 'delete_post'], true) || empty($args[0]) || !self::is_managed((int) $args[0])) {
			return $caps;
		}
		return user_can($user_id, 'manage_options') && user_can($user_id, 'unfiltered_html') ? ['manage_options'] : ['do_not_allow'];
	}

	public static function protect_front_page($new, $old)
	{
		$new = (int) $new;
		if (self::front_selection($new)) {
			self::settings_error('front_language', __('A página inicial HTMLPress deve usar a versão em português.', 'htmlpress'));
			return $old;
		}
		if ($new && self::normal_root_conflict($new)) {
			self::settings_error('front_conflict', __('A raiz /en/ ou /es/ já pertence a uma página normal.', 'htmlpress'));
			return $old;
		}
		return $new;
	}

	public static function protect_front_mode($new, $old)
	{
		if ($new === 'page' && self::front_selection((int) get_option('page_on_front'))) {
			self::settings_error('front_language', __('Selecione a versão em português antes de ativar uma página inicial estática.', 'htmlpress'));
			return $old;
		}
		if ($new === 'page' && self::normal_root_conflict((int) get_option('page_on_front'))) {
			self::settings_error('front_conflict', __('Não é possível ativar a página inicial estática: /en/ ou /es/ já pertence a uma página normal.', 'htmlpress'));
			return $old;
		}
		return $new;
	}

	public static function protect_posts_page($new, $old)
	{
		return self::is_managed((int) $new) ? $old : $new;
	}

	public static function normalize_front_page(): void
	{
		$id = (int) get_option('page_on_front');
		if ($id && self::is_managed($id) && get_post_meta($id, self::LANG, true) !== 'pt') {
			$group = self::group($id);
			if (isset($group['pt'])) {
				if (!self::normal_root_conflict($group['pt']->ID)) {
					update_option('page_on_front', $group['pt']->ID);
					self::settings_error('front_language', __('A página inicial HTMLPress foi convertida para português.', 'htmlpress'), 'warning');
				} else {
					self::settings_error('front_conflict', __('Não foi possível converter a página inicial: /en/ ou /es/ já pertence a uma página normal.', 'htmlpress'));
				}
			}
		}
	}

	private static function front_selection($id): bool
	{
		$id = (int) $id;
		return $id > 0 && self::is_managed($id) && get_post_meta($id, self::LANG, true) !== 'pt';
	}

	private static function front_group_has(int $id): bool
	{
		$group = self::group($id);
		return isset($group['pt']) && get_option('show_on_front') === 'page' && (int) get_option('page_on_front') === $group['pt']->ID;
	}

	private static function front_id_for_language(string $language): int
	{
		$id = (int) get_option('page_on_front');
		if (get_option('show_on_front') !== 'page' || !$id || !self::is_managed($id)) {
			return 0;
		}
		$group = self::group($id);
		return (int) ($group[$language]->ID ?? 0);
	}

	private static function find_public_page(string $slug, string $language): ?WP_Post
	{
		$pages = get_posts([
			'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1,
			'meta_key' => self::SLUG, 'meta_value' => sanitize_title($slug),
	]);
		foreach ($pages as $page) {
			if (get_post_meta($page->ID, self::LANG, true) === $language && self::group($page->ID)) {
				return $page;
			}
		}
		return null;
	}

	private static function slug_collides(string $slug): bool
	{
		$reserved = ['en', 'es', 'feed', 'wp-json', 'wp-admin', 'wp-content', 'wp-includes', 'xmlrpc.php', 'sitemap.xml', 'robots.txt', 'wp-sitemap.xml'];
		if (in_array($slug, $reserved, true) || get_page_by_path($slug, OBJECT, 'page')) {
			return true;
		}
		$managed_slugs = get_posts(['post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => self::SLUG, 'meta_value' => $slug, 'fields' => 'ids']);
		if ($managed_slugs) {
			return true;
		}
		foreach (['en', 'es'] as $language) {
			if (get_page_by_path($language . '/' . $slug, OBJECT, 'page')) {
				return true;
			}
		}
		return (bool) get_posts(['post_type' => 'any', 'post_status' => 'publish', 'name' => $slug, 'numberposts' => 1]);
	}

	/** @return array<string, int> */
	private static function public_paths(): array
	{
		$paths = [];
		$pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => self::GROUP, 'meta_compare' => 'EXISTS']);
		foreach ($pages as $page) {
			$language = (string) get_post_meta($page->ID, self::LANG, true);
			$slug = self::public_slug($page->ID);
			if ($slug !== '') {
				$paths[($language === 'pt' ? '' : $language . '/') . $slug] = $page->ID;
			}
		}
		$front_id = self::front_id_for_language('pt');
		if ($front_id) {
			foreach (['en', 'es'] as $language) {
				$group = self::group($front_id);
				if (isset($group[$language])) {
					$paths[$language] = $group[$language]->ID;
				}
			}
		}
		return $paths;
	}

	private static function candidate_path(array $data, string $slug): string
	{
		$parent = (int) ($data['post_parent'] ?? 0);
		$parent_path = $parent ? (string) get_page_uri($parent) : '';
		return ($parent_path === '' ? '' : $parent_path . '/') . $slug;
	}

	private static function rollback(array $created, array $snapshots): void
	{
		$was_writing = self::$writing;
		self::$writing = true;
		try {
		foreach ($created as $id) {
			wp_delete_post($id, true);
		}
		foreach ($snapshots as $id => $snapshot) {
			wp_update_post(wp_slash((array) $snapshot['post']));
			foreach ($snapshot['meta'] as $key => $value) {
				update_post_meta($id, $key, wp_slash($value));
			}
		}
		} finally {
			self::$writing = $was_writing;
		}
	}

	private static function normal_root_conflict(int $front_id): bool
	{
		$group = self::group($front_id);
		if (!isset($group['pt'])) {
			return false;
		}
		foreach (['en', 'es'] as $language) {
			$page = get_page_by_path($language, OBJECT, 'page');
			if ($page && !self::is_managed($page->ID)) {
				return true;
			}
		}
		return false;
	}

	private static function acquire_creation_lock(string $token): bool
	{
		$key = '_htmlpress_creation_lock';
		if (add_option($key, ['token' => $token, 'time' => time()], '', false)) {
			return true;
		}
		$existing = get_option($key);
		if (is_array($existing) && (int) ($existing['time'] ?? 0) < time() - 60) {
			delete_option($key);
			return add_option($key, ['token' => $token, 'time' => time()], '', false);
		}
		return false;
	}

	private static function release_creation_lock(string $token): void
	{
		$lock = get_option('_htmlpress_creation_lock');
		if (is_array($lock) && ($lock['token'] ?? '') === $token) {
			delete_option('_htmlpress_creation_lock');
		}
	}

	private static function settings_error(string $code, string $message, string $type = 'error'): void
	{
		if (function_exists('add_settings_error')) {
			add_settings_error('htmlpress', $code, $message, $type);
		}
	}

	private static function not_found(): void
	{
		global $wp_query;
		$wp_query->set_404();
		status_header(404);
		nocache_headers();
		exit;
	}
}

HTMLPress::init();
