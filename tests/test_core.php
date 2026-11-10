<?php
/** In-memory unit checks for HTMLPress; this is not WordPress integration. */
if (ini_get('zend.assertions') !== '1') { fwrite(STDERR, "Run with: php -d zend.assertions=1 -d assert.exception=1 tests/test_core.php\n"); exit(1); }
ini_set('assert.exception', '1');
define('ABSPATH', __DIR__ . '/');
define('OBJECT', 'OBJECT');

class WP_Error { public function __construct(public string $code = '', public string $message = '') {} }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
class WP_Post { public function __construct(public int $ID, public string $post_type='page', public string $post_status='publish', public string $post_title='', public string $post_name='', public int $post_parent=0, public string $post_content='') {} }
class WP { public string $request=''; public array $query_vars=[]; }
$GLOBALS['core_posts'] = []; $GLOBALS['core_meta'] = []; $GLOBALS['core_options'] = ['permalink_structure'=>'/%postname%/','show_on_front'=>'posts','page_on_front'=>0]; $GLOBALS['core_caps'] = array_fill_keys(['manage_options','unfiltered_html','edit_pages','publish_pages','upload_files'], true); $GLOBALS['core_actions']=[]; $GLOBALS['core_filters']=[]; $GLOBALS['core_admin']=false;
function add_action($hook,$cb,...$args): void { $GLOBALS['core_actions'][$hook][]=$cb; }
function add_filter($hook,$cb,...$args): void { $GLOBALS['core_filters'][$hook][]=$cb; }
function apply_filters($hook,$value,...$args) { foreach ($GLOBALS['core_filters'][$hook]??[] as $cb) $value=$cb($value,...$args); return $value; }
function __($text,...$args): string { return $text; }
function current_user_can($cap,...$args): bool { if ($cap==='edit_post' || $cap==='delete_post') return user_can(1,$cap,...$args); return (bool)($GLOBALS['core_caps'][$cap] ?? false); }
function user_can($id,$cap,...$args): bool { if (($cap==='edit_post'||$cap==='delete_post') && isset($args[0]) && HTMLPress::is_managed((int)$args[0])) return HTMLPress::managed_caps([], $cap, (int)$id, [$args[0]]) !== ['do_not_allow']; return (bool)($GLOBALS['core_caps'][$cap] ?? false); }
function is_admin(): bool { return $GLOBALS['core_admin']; }
function sanitize_text_field($s): string { return trim(strip_tags($s)); }
function sanitize_title($s): string { return strtolower(trim(preg_replace('/[^a-z0-9-]+/i','-', $s), '-')); }
function get_option($key) { return $GLOBALS['core_options'][$key] ?? false; }
function update_option($key,$value): bool { $GLOBALS['core_options'][$key]=$value; return true; }
function add_option($key,$value,...$args): bool { if (array_key_exists($key,$GLOBALS['core_options'])) return false; $GLOBALS['core_options'][$key]=$value; return true; }
function delete_option($key): bool { unset($GLOBALS['core_options'][$key]); return true; }
function home_url($path=''): string { return 'https://example.test' . $path; }
function wp_slash($v) { return is_array($v) ? array_map('wp_slash',$v) : (is_string($v) ? addslashes($v) : $v); }
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash',$v) : (is_string($v) ? stripslashes($v) : $v); }
function wp_generate_uuid4(): string { static $n=0; return 'unit-group-' . ++$n; }
function update_post_meta($id,$key,$value): bool { $GLOBALS['core_meta'][$id][$key]=wp_unslash($value); return true; }
function get_post_meta($id,$key,$single=true) { return $GLOBALS['core_meta'][$id][$key] ?? ''; }
function delete_post_meta($id,$key): bool { unset($GLOBALS['core_meta'][$id][$key]); return true; }
function get_post($id) { return $GLOBALS['core_posts'][$id] ?? null; }
function get_post_field($field,$id) { return get_post($id)->{$field} ?? ''; }
function get_posts($args=[]): array { $out=[]; foreach ($GLOBALS['core_posts'] as $p) { if (isset($args['post_type']) && $args['post_type']!=='any' && $p->post_type!==$args['post_type']) continue; if (isset($args['post_status']) && $args['post_status']!=='any' && !in_array($p->post_status,(array)$args['post_status'],true)) continue; if (isset($args['name']) && $p->post_name!==$args['name']) continue; if (isset($args['meta_key']) && isset($args['meta_value']) && get_post_meta($p->ID,$args['meta_key'],true)!=$args['meta_value']) continue; if (isset($args['meta_key']) && ($args['meta_compare']??'')==='EXISTS' && get_post_meta($p->ID,$args['meta_key'],true)==='') continue; $out[]=$p; } return ($args['fields']??'')==='ids' ? array_map(fn($p)=>$p->ID,$out) : $out; }
function get_page_by_path($path,...$args) { foreach ($GLOBALS['core_posts'] as $p) if ($p->post_type==='page' && $p->post_name===$path) return $p; return null; }
function wp_insert_post($data,$error=false) { $id=$data['ID']??(empty($GLOBALS['core_posts'])?1:max(array_keys($GLOBALS['core_posts']))+1); $data=HTMLPress::protect_post_data($data,$data); $p=$GLOBALS['core_posts'][$id]??new WP_Post($id); foreach ($data as $k=>$v) if(property_exists($p,$k)) $p->$k=$v; $GLOBALS['core_posts'][$id]=$p; return $id; }
function wp_delete_post($id,...$args) { unset($GLOBALS['core_posts'][$id],$GLOBALS['core_meta'][$id]); return true; }
function wp_update_post($data,...$args) { return wp_insert_post(wp_unslash($data)); }
function wp_trash_post($id) { $post=get_post($id); if (!$post || apply_filters('pre_trash_post',null,$post)!==null) return false; return wp_update_post(['ID'=>$id,'post_status'=>'trash']); }
function wp_untrash_post($id) { return wp_update_post(['ID'=>$id,'post_status'=>'draft']); }
function wp_delete_managed($id) { $post=get_post($id); if (!$post || apply_filters('pre_delete_post',null,$post)!==null) return false; return wp_delete_post($id,true); }
function wp_unique_post_slug($slug,...$args): string { return $slug; }
require __DIR__.'/../htmlpress.php';

$html = static fn($lang) => '<!doctype html><html><head></head><body>' . $lang . ' \\" raw</body></html>';
$docs=['pt'=>$html('pt'),'en'=>$html('en'),'es'=>$html('es')];
$ids=HTMLPress::save_group(0,'Título editável','pagina-teste',$docs);
assert(is_array($ids) && array_keys($ids)===['pt','en','es']);
assert(HTMLPress::group($ids['pt']) && get_post_meta($ids['en'],HTMLPress::HTML,true)===$docs['en']);
assert(array_map(fn($lang)=>get_post_field('post_name',$ids[$lang]),['pt','en','es'])===['pagina-teste','htmlpress-unit-group-1-en','htmlpress-unit-group-1-es']);
assert(HTMLPress::url($ids['pt'])==='https://example.test/pagina-teste/' && HTMLPress::url($ids['en'])==='https://example.test/en/pagina-teste/' && HTMLPress::url($ids['es'])==='https://example.test/es/pagina-teste/');
foreach (['pt'=>'pagina-teste','en'=>'en/pagina-teste','es'=>'es/pagina-teste'] as $lang=>$path) { $wp=new WP(); $wp->request=$path; $wp->query_vars=['name'=>'old','page_id'=>'999']; HTMLPress::route_request($wp); assert(($wp->query_vars['page_id']??'')===(string)$ids[$lang] && !isset($wp->query_vars['name'])); }
$GLOBALS['core_caps']=['manage_options'=>true,'unfiltered_html'=>true,'edit_pages'=>true,'publish_pages'=>true,'upload_files'=>true,'edit_post'=>true,'delete_post'=>true];
$before=array_map([HTMLPress::class,'url'],$ids); $updated=HTMLPress::save_group($ids['en'],'Novo título','pagina-teste',['en'=>$html('EN atual')]);
assert($updated===$ids && array_map([HTMLPress::class,'url'],$ids)===$before && get_post_field('post_title',$ids['pt'])==='Novo título' && get_post_meta($ids['en'],HTMLPress::HTML,true)===$html('EN atual'));
$duplicate=HTMLPress::save_group(0,'Duplicada','pagina-teste',$docs); assert(is_wp_error($duplicate));
$titleOnly=HTMLPress::save_group($ids['pt'],'Só título','pagina-teste',[]); assert($titleOnly===$ids); foreach($ids as $id) assert(get_post_field('post_title',$id)==='Só título');
$old=get_post_meta($ids['pt'],HTMLPress::HTML,true); $bad=HTMLPress::save_group($ids['pt'],'Não salvar','x',['pt'=>$html('changed'),'en'=>$html('en'),'es'=>'invalid']); assert(is_wp_error($bad) && get_post_meta($ids['pt'],HTMLPress::HTML,true)===$old);
foreach (['manage_options','unfiltered_html','edit_pages','publish_pages','upload_files'] as $cap) { $caps=$GLOBALS['core_caps']; unset($GLOBALS['core_caps'][$cap]); assert(!HTMLPress::can_manage()); $GLOBALS['core_caps']=$caps; }
$GLOBALS['core_caps']['edit_post']=false; $normal=new WP_Post(99); assert(HTMLPress_Admin::use_editor(true,$normal)===true); assert(HTMLPress_Admin::use_editor(true,get_post($ids['pt']))===false); $GLOBALS['core_caps']['edit_post']=true;
update_option('show_on_front','page'); update_option('page_on_front',$ids['pt']); assert(HTMLPress::url($ids['pt'])==='https://example.test/' && HTMLPress::url($ids['en'])==='https://example.test/en/' && HTMLPress::url($ids['es'])==='https://example.test/es/');
foreach(['en'=>$ids['en'],'es'=>$ids['es']] as $lang=>$id) { $wp=new WP(); $wp->request=$lang; HTMLPress::route_request($wp); assert(($wp->query_vars['page_id']??'')===(string)$id); }
$wp=new WP(); $wp->request='en/unrelated'; $wp->query_vars=['name'=>'unrelated']; HTMLPress::route_request($wp); assert(($wp->query_vars['name']??'')==='unrelated' && !isset($wp->query_vars['page_id']));
$native=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'native','post_name'=>'native']); $protected=wp_update_post(['ID'=>$ids['pt'],'post_title'=>'intruder','post_name'=>'intruder']); assert($protected===$ids['pt'] && get_post_field('post_title',$ids['pt'])==='Só título' && get_post_field('post_name',$ids['pt'])==='pagina-teste');
$GLOBALS['core_admin']=false;
foreach (['pt'=>$ids['pt'],'en'=>$ids['en'],'es'=>$ids['es']] as $lang=>$id) {
 assert(count(HTMLPress::group($id))===3);
 assert(wp_trash_post($id)!==false); assert(get_post($id)->post_status==='trash');
 assert(count(HTMLPress::group($id))===2);
 assert(wp_untrash_post($id)!==false); assert(get_post($id)->post_status==='draft');
}
unset($GLOBALS['core_caps']['manage_options']); assert(!user_can(1,'delete_post',$ids['pt'])); $GLOBALS['core_caps']['manage_options']=true;
assert(wp_delete_managed($ids['pt'])===true && !get_post($ids['pt']));
assert(count(HTMLPress::group($ids['en']))===2);
$afterPtRemoval=HTMLPress::save_group($ids['en'],'Ainda editável','pagina-teste',['en'=>$html('EN após PT')]);
assert(is_array($afterPtRemoval) && $afterPtRemoval['en']===$ids['en']);
assert(wp_trash_post($ids['en'])!==false);
$duplicateTranslation=HTMLPress::save_group($ids['es'],'Não duplicar','pagina-teste',['en'=>$html('EN duplicado')]);
assert(is_wp_error($duplicateTranslation) && $duplicateTranslation->code==='htmlpress_translation_trashed');
assert(wp_untrash_post($ids['en'])!==false);
$single = HTMLPress::save_group(0, 'Sem traduções', 'somente-pt', ['pt'=>$html('PT')]);
assert(is_array($single) && array_keys($single) === ['pt']);
assert(count(HTMLPress::group($single['pt'])) === 1);
$singleUrl = HTMLPress::url($single['pt']);
$withEnglish = HTMLPress::save_group($single['pt'], 'Com inglês', '', ['en'=>$html('EN')]);
assert(array_keys($withEnglish) === ['pt','en'] && $withEnglish['pt'] === $single['pt']);
assert(HTMLPress::url($single['pt']) === $singleUrl);
$withSpanish = HTMLPress::save_group($withEnglish['en'], 'Completa', '', ['es'=>$html('ES')]);
assert(count($withSpanish) === 3 && $withSpanish['en'] === $withEnglish['en']);
assert(is_wp_error(HTMLPress::save_group(0, 'Sem PT', 'sem-pt', ['en'=>$html('EN')])));
$contentBefore = array_map(fn($id) => get_post_meta($id, HTMLPress::HTML, true), $withSpanish);
$renamed = HTMLPress::save_group($withSpanish['en'], 'Novo slug', 'Novo Caminho', []);
assert($renamed === $withSpanish);
assert(array_map(fn($id) => get_post_meta($id, HTMLPress::HTML, true), $renamed) === $contentBefore);
assert(get_post_field('post_name', $renamed['pt']) === 'novo-caminho');
foreach (['pt'=>'novo-caminho', 'en'=>'en/novo-caminho', 'es'=>'es/novo-caminho'] as $lang=>$path) {
 assert(HTMLPress::url($renamed[$lang]) === 'https://example.test/' . $path . '/');
 $wp = new WP(); $wp->request = $path; HTMLPress::route_request($wp);
 assert(($wp->query_vars['page_id'] ?? '') === (string)$renamed[$lang]);
}
$wp = new WP(); $wp->request = 'somente-pt'; HTMLPress::route_request($wp); assert(!isset($wp->query_vars['page_id']));
foreach (['en', 'pagina-teste', 'native'] as $conflict) {
 $rejected = HTMLPress::save_group($renamed['pt'], 'Não salvar', $conflict, []);
 assert(is_wp_error($rejected) && $rejected->code === 'htmlpress_slug_conflict');
 assert(HTMLPress::public_slug($renamed['pt']) === 'novo-caminho');
}
assert(HTMLPress::save_group($renamed['pt'], 'Mesmo slug', 'novo-caminho', []) === $renamed);
echo "HTMLPress in-memory core checks passed\n";
