<?php
$dir = dirname(__DIR__, 2) . '/app/common/service/decorate/agent/';
foreach (['DecorationAgentCatalog', 'DecorationAgentOperations', 'DecorationAgentMerge'] as $class) {
    if (!is_file($dir . $class . '.php')) { fwrite(STDERR, "FAIL: deterministic engine missing: $class\n"); exit(1); }
    require_once $dir . $class . '.php';
}
use app\common\service\decorate\agent\DecorationAgentCatalog as C;
use app\common\service\decorate\agent\DecorationAgentOperations as O;
use app\common\service\decorate\agent\DecorationAgentMerge as M;
$n=0;
function check($ok,$label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; }
function rejects($fn,$label) { try {$fn();} catch (InvalidArgumentException $e) {check(true,$label);return;} check(false,$label); }
function page($names=['title-bar','banner']) { $data=[];foreach($names as $i=>$name)$data[]=C::create($name,'w'.$i);return ['data'=>$data,'meta'=>[],'name'=>'首页']; }
check(count(C::summary()['components'])===46,'46 contracts');
check(strlen(C::hash())===64,'hash');
$p=page(); $p['data'][0]['content']['future_vendor']=['secret'=>'keep']; $p['future_page']='keep';
$r=O::apply($p,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.title'=>'欢迎']]],['scope'=>'selected','selected_id'=>'w0']);
check($p['data'][0]['content']['title']!==$r['candidate']['data'][0]['content']['title'],'input isolation');
check($r['candidate']['data'][0]['content']['future_vendor']===$p['data'][0]['content']['future_vendor'],'unknown field preserved');
check($r['candidate']['future_page']==='keep','unknown page preserved');
rejects(fn()=>O::apply($p,[['type'=>'update_component','widget_id'=>'w1','fields'=>['styles.opacity'=>50]]],['scope'=>'selected','selected_id'=>'w0']),'selected isolation');
rejects(fn()=>O::apply($p,[['type'=>'replace_page','components'=>[]]],['scope'=>'selected','selected_id'=>'w0']),'replace selected forbidden');
rejects(fn()=>O::apply($p,[['type'=>'update_component','widget_id'=>'w0','fields'=>['styles.opacity'=>'20']]],['scope'=>'page']),'number type');
rejects(fn()=>O::apply($p,[['type'=>'update_component','widget_id'=>'w0','fields'=>['styles.opacity'=>101]]],['scope'=>'page']),'number bound');
rejects(fn()=>O::apply($p,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.future_vendor.secret'=>'overwrite']]],['scope'=>'page']),'unknown writes forbidden');
rejects(fn()=>O::apply($p,[['type'=>'bind_source','widget_id'=>'w0','source_key'=>'evil','params'=>[]]],['scope'=>'page']),'unknown source');
rejects(fn()=>O::apply(page(['button-group']),[['type'=>'bind_source','widget_id'=>'w0','source_key'=>'article','params'=>[]]],['scope'=>'page']),'manual component source cap');
rejects(fn()=>O::apply(page(['news']),[['type'=>'bind_source','widget_id'=>'w0','source_key'=>'article','params'=>['limit'=>51]]],['scope'=>'page']),'source limit');
$r=O::apply($p,[['type'=>'add_component','name'=>'spacer','id'=>'model-id','index'=>1]],['scope'=>'page']);
check($r['candidate']['data'][1]['id']!=='model-id','server ids');
$r=O::apply($p,[['type'=>'request_image','widget_id'=>'w1','field'=>'content.data.0.image','prompt'=>'抽象蓝色光线','ratio'=>'16:9','reference_file_ids'=>[7]]],['scope'=>'page']);
check(count($r['image_requests'])===1 && $r['candidate']===$p,'image request no mutation');
rejects(fn()=>O::apply($p,[['type'=>'request_image','widget_id'=>'w0','field'=>'content.title','prompt'=>'x']],['scope'=>'page']),'image slot');
rejects(fn()=>O::apply($p,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.link'=>['path'=>'https://evil.test']]]],['scope'=>'page']),'link allowlist');
$rich=page(['rich-text']);
rejects(fn()=>O::apply($rich,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.html'=>'<img onerror="alert(1)">']]],['scope'=>'page']),'scripted html');
$current=$p;$current['data'][0]['styles']['color']='#000000';
$candidate=$p;$candidate['data'][0]['content']['title']='AI';
$m=M::merge($p,$current,$candidate);check(!$m['conflicts'] && $m['merged']['data'][0]['styles']['color']==='#000000' && $m['merged']['data'][0]['content']['title']==='AI','disjoint merge');
$current['data'][0]['content']['title']='human';$m=M::merge($p,$current,$candidate);check(count($m['conflicts'])===1,'same field conflict');
$current=$p;array_shift($current['data']);$m=M::merge($p,$current,$candidate);check(count($m['conflicts'])>0,'delete modify conflict');
$current=$p;$current['data']=array_reverse($current['data']);$m=M::merge($p,$current,$candidate);check(count($m['conflicts'])>0,'move modify conflict');
$m=M::merge($candidate,$candidate,$p);check(!$m['conflicts'] && $m['merged']===$p,'reverse rollback');
$legacy=$p;unset($legacy['data'][0]['id']);$r=O::apply($legacy,[['type'=>'update_component','widget_id'=>'legacy_title-bar_0','fields'=>['content.title'=>'legacy']]],['scope'=>'page']);check(!isset($r['candidate']['data'][0]['id']),'legacy identity preserved');

$news=page(['news']);
rejects(fn()=>O::apply($news,[['type'=>'bind_source','widget_id'=>'w0','source_key'=>'user_profile','params'=>['limit'=>5]]],['scope'=>'page']),'source-specific params');
$r=O::apply($news,[['type'=>'bind_source','widget_id'=>'w0','source_key'=>'','mode'=>'manual','params'=>[]]],['scope'=>'page']);check($r['candidate']['data'][0]['content']['data_mode']==='manual','manual mode');
rejects(fn()=>O::apply($p,[['type'=>'update_component','widget_id'=>'w0','fields'=>['styles.padding_top'=>241]]],['scope'=>'page']),'style bound');
$buttons=page(['button-group']);$buttons['data'][0]['content']['data'][0]['future_row']='keep';
$r=O::apply($buttons,[['type'=>'edit_list','widget_id'=>'w0','field'=>'data','action'=>'update','index'=>0,'fields'=>['title'=>'创作']]],['scope'=>'page']);check($r['candidate']['data'][0]['content']['data'][0]['future_row']==='keep','row extensions');
$r=O::apply($buttons,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.data'=>[['title'=>'创作']]]]],['scope'=>'page']);check($r['candidate']['data'][0]['content']['data'][0]['future_row']==='keep','list replacement extension preservation');
rejects(fn()=>O::apply($buttons,[['type'=>'edit_list','widget_id'=>'w0','action'=>'update','index'=>0,'fields'=>['title'=>'十一二三四五六七八九十十一']]],['scope'=>'page']),'row title limit');
$tree=page(['section']);$tree['data'][0]['children']=[C::create('title-bar','child')];
$r=O::apply($tree,[['type'=>'update_component','widget_id'=>'child','fields'=>['content.title'=>'子标题']]],['scope'=>'selected','selected_id'=>'w0']);check($r['candidate']['data'][0]['children'][0]['content']['title']==='子标题','selected subtree');
rejects(fn()=>O::apply($tree,[['type'=>'move_component','widget_id'=>'w0','parent_id'=>'child']],['scope'=>'page']),'cycle rejected');
$r=O::apply($tree,[['type'=>'copy_component','widget_id'=>'w0']],['scope'=>'page']);check($r['candidate']['data'][1]['children'][0]['id']!=='child','copy reidentifies descendants');
$duplicate=$p;$duplicate['data'][1]['id']='w0';rejects(fn()=>O::apply($duplicate,[],['scope'=>'page']),'duplicate ids');
$deep=page(['section']);$node=&$deep['data'][0];for($i=0;$i<4;$i++){$node['children']=[C::create('section','d'.$i)];$node=&$node['children'][0];}unset($node);rejects(fn()=>O::apply($deep,[],['scope'=>'page']),'max depth');
$current=$p;$candidate=$p;$current['meta']=[['name'=>'page-meta','content'=>['title'=>'human','bg_color'=>'#fff']]];$base=$p;$base['meta']=[['name'=>'page-meta','content'=>['title'=>'base','bg_color'=>'#fff']]];$candidate['meta']=[['name'=>'page-meta','content'=>['title'=>'base','bg_color'=>'#000']]];$m=M::merge($base,$current,$candidate);check(!$m['conflicts']&&$m['merged']['meta'][0]['content']['title']==='human'&&$m['merged']['meta'][0]['content']['bg_color']==='#000','page meta disjoint merge');
$r=O::apply($rich,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.html'=>'<p><strong>欢迎</strong></p>']]],['scope'=>'page']);check(strpos($r['candidate']['data'][0]['content']['html'],'欢迎')!==false,'safe text formatting');
rejects(fn()=>O::apply($rich,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.html'=>'<a href="java&#x73;cript:alert(1)">click</a>']]],['scope'=>'page']),'encoded HTML URL');

$trusted=['id'=>12,'name'=>'自定义','path'=>'/pages/diy/diy','query'=>['code'=>'demo'],'type'=>'decorate','terminal'=>'mobile','canTab'=>false];
$r=O::apply($p,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.link'=>['path'=>'/pages/diy/diy','query'=>['code'=>'demo']]]]],['scope'=>'page','allowed_links'=>[$trusted]]);check($r['candidate']['data'][0]['content']['link']['query']['code']==='demo','catalog link subset');
rejects(fn()=>O::apply($p,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.link'=>['path'=>'/pages/diy/diy','query'=>['code'=>'other']]]]],['scope'=>'page','allowed_links'=>[$trusted]]),'custom link identity');
$r=O::apply($p,[['type'=>'request_image','widget_id'=>'w1','field'=>'content.data.0.image','prompt'=>'蓝色横幅']],['scope'=>'page']);check($r['image_requests'][0]['old_value']===$p['data'][1]['content']['data'][0]['image'],'late image baseline');
rejects(fn()=>O::apply(page(['search']),[['type'=>'add_component','name'=>'search']],['scope'=>'page']),'singleton component');
$legacyButtons=$buttons;for($i=0;$i<31;$i++)$legacyButtons['data'][0]['content']['data'][]=$legacyButtons['data'][0]['content']['data'][0];
$r=O::apply($legacyButtons,[['type'=>'edit_list','widget_id'=>'w0','action'=>'update','index'=>0,'fields'=>['title'=>'编辑']]],['scope'=>'page']);check(count($r['candidate']['data'][0]['content']['data'])===count($legacyButtons['data'][0]['content']['data']),'legacy oversized list');

$notice=page(['notice']);unset($notice['data'][0]['content']['font_size_mode']);
$r=O::apply($notice,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.font_size'=>20]]],['scope'=>'page']);check(($r['candidate']['data'][0]['content']['font_size_mode']??'')==='configured','notice explicit font edit');
$r=O::apply($notice,[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.text'=>'新公告']]],['scope'=>'page']);check(!isset($r['candidate']['data'][0]['content']['font_size_mode']),'notice legacy font preserved');
foreach(C::summary()['components'] as $definition){$created=C::create($definition['name'],'factory');unset($created['id']);check($created===$definition['defaults'],'exact factory '.$definition['name']);}

require_once __DIR__.'/evaluate-result.php';
$fixtures=json_decode(file_get_contents(__DIR__.'/evaluation-fixtures.json'),true,512,JSON_THROW_ON_ERROR);check(count($fixtures['cases'])>=20,'real-model fixtures');
$fixture=$fixtures['cases'][0];$evaluated=O::apply($fixture['initial'],[['type'=>'update_component','widget_id'=>'w0','fields'=>['content.title'=>'开始创作']]],$fixture['scope']);check(evaluateDecorationAgentFixture($fixture,$evaluated)['passed'],'evaluation assertions real engine');
$evaluated['candidate']['data'][1]['content']['image_height']=500;check(!evaluateDecorationAgentFixture($fixture,$evaluated)['passed'],'evaluation detects collateral edit');
$stylePage=page(['news']);$styleResult=O::apply($stylePage,[['type'=>'update_component','widget_id'=>'w0','fields'=>['styles.margin_top'=>30,'styles.color'=>'#123456']]],['scope'=>'page']);check($styleResult['candidate']['data'][0]['styles']['margin_top']===30,'declared style spacing accepted');check($styleResult['candidate']['data'][0]['styles']['color']==='#123456','declared style color accepted');

$r=O::apply(page([]),[['type'=>'add_component','name'=>'title-bar','content'=>['title'=>'新标题'],'styles'=>['color'=>'#123456']]],['scope'=>'page']);check($r['candidate']['data'][0]['content']['title']==='新标题','add content alias applies');check($r['candidate']['data'][0]['styles']['color']==='#123456','add styles alias applies');
rejects(fn()=>O::apply(page([]),[['type'=>'add_component','name'=>'title-bar','content'=>['unsafe_unknown'=>'x']]],['scope'=>'page']),'add alias unknown rejected');
$r=O::apply($buttons,[['type'=>'edit_list','widget_id'=>'w0','action'=>'add','item'=>['title'=>'追加']]],['scope'=>'page']);check(end($r['candidate']['data'][0]['content']['data'])['title']==='追加','list item alias');
$r=O::apply(page([]),[['type'=>'replace_page','name'=>'新画布','components'=>[['name'=>'title-bar','content'=>['title'=>'Hello']]]]],['scope'=>'new_page']);check($r['candidate']['name']==='新画布'&&$r['candidate']['data'][0]['content']['title']==='Hello','replacement name/content');
check(C::pageMeta()['defaults']['content']['bg_type']==='0'&&C::pageMeta()['defaults']['content']['title_type']==='1','real page meta defaults');
$r=O::apply(page([]),[['type'=>'configure_page','fields'=>['title_type'=>'2','bg_type'=>'4','show_back'=>1,'gradient_colors'=>['#ffffff','#000000'],'gradient_direction'=>'135deg']]],['scope'=>'page']);check($r['candidate']['meta'][0]['content']['bg_type']==='4'&&$r['candidate']['meta'][0]['content']['show_back']===1,'real page meta fields');
rejects(fn()=>O::apply(page([]),[['type'=>'configure_page','fields'=>['gradient_colors'=>['#fff']]]],['scope'=>'page']),'gradient count');
rejects(fn()=>O::apply(page([]),[['type'=>'configure_page','fields'=>['gradient_direction'=>'url(javascript:evil)']]],['scope'=>'page']),'gradient enum');
rejects(fn()=>O::apply(page([]),[['type'=>'configure_page','fields'=>['bg_video'=>'https://evil.test/a.mp4']]],['scope'=>'page']),'video asset approval');
$r=O::apply(page([]),[['type'=>'configure_page','fields'=>['bg_video'=>'https://assets.test/a.mp4']]],['scope'=>'page','approved_urls'=>['https://assets.test/a.mp4']]);check($r['candidate']['meta'][0]['content']['bg_video']==='https://assets.test/a.mp4','trusted video');

rejects(fn()=>O::apply($p,[['type'=>'update_component','widget_id'=>'w1','fields'=>['content.data.0.image'=>'https://invented.test/a.png']]],['scope'=>'page']),'invented image rejected');
$r=O::apply($p,[['type'=>'update_component','widget_id'=>'w1','fields'=>['content.data.0.image'=>'https://assets.test/a.png']]],['scope'=>'page','approved_urls'=>['https://assets.test/a.png']]);check($r['candidate']['data'][1]['content']['data'][0]['image']==='https://assets.test/a.png','approved image accepted');
$historic=$p;$historic['data'][1]['content']['data'][0]['image']='https://historic.test/banner.png';
$r=O::apply($historic,[['type'=>'update_component','widget_id'=>'w1','fields'=>['content.data'=>[['image'=>'https://historic.test/banner.png','name'=>'旧图新标题']]]]],['scope'=>'page']);check($r['candidate']['data'][1]['content']['data'][0]['image']==='https://historic.test/banner.png','historic image list edit accepted');
rejects(fn()=>O::apply(page(['title-bar']),[['type'=>'update_component','widget_id'=>'w0','fields'=>['styles.background'=>'url(https://invented.invalid/x.png)']]],['scope'=>'page']),'style cannot bypass image allowlist');
echo "PASS $n deterministic decoration assertions\n";

