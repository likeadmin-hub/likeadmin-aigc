<?php
require __DIR__.'/lifecycle.php';
use think\facade\Db;
use app\common\service\app\aigc_copywriting\{CopywritingStore as S,CopywritingToolService as Tools,CopywritingSeeder as Seed,CopywritingDocument as Document};
Db::startTrans();
try {
    Document::assertValid(Seed::homeDocument());
    $icons=array_column(Seed::catalog(),'icon');check(count(array_unique($icons))===12,'distinct built-in icons');foreach($icons as $icon)check(is_file(dirname(__DIR__,2).'/public/'.$icon),'packaged icon exists');
    $category=(int)S::table('category')->where('tenant_id',$tenant)->value('id');
    $created=Tools::create($tenant,1,['name'=>'图标配置验收','category_id'=>$category,'icon'=>'https://example.com/custom-icon.png']);
    $tool=S::owned('tool',$tenant,$created['id']);
    check($tool['icon']==='https://example.com/custom-icon.png','create persists configured icon');
    $tool['icon']='uploads/copywriting/custom.png';Tools::save($tenant,1,$tool);
    check(S::owned('tool',$tenant,$created['id'])['icon']===$tool['icon'],'icon changes survive readback');
    $copy=Tools::copy($tenant,1,$created['id']);
    check(S::owned('tool',$tenant,$copy['id'])['icon']===$tool['icon'],'copy preserves configured icon');
    $invalid=$tool;$invalid['icon']='javascript:alert(1)';$rejected=false;
    try {Tools::save($tenant,1,$invalid);}catch(InvalidArgumentException $e){$rejected=true;}
    check($rejected,'unsafe icon scheme rejected');
    $page=S::table('page')->where(['tenant_id'=>$tenant,'page_code'=>'home'])->find();
    $legacy=Document::normalize(json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_copywriting/data/home-v1.json'),true));
    $json=S::json($legacy);S::table('page')->where('id',$page['id'])->update(['draft_json'=>$json]);
    S::table('version')->where('id',$page['published_version_id'])->update(['document_json'=>$json]);
    Seed::ensureTenant($tenant);$upgraded=S::table('page')->where('id',$page['id'])->find();
    check($upgraded['published_version_id']!=$page['published_version_id'],'untouched legacy homepage upgraded as new immutable version');
    $doc=S::decode($upgraded['draft_json']);$nodes=array_column($doc['page']['nodes'],null,'id');
    check($nodes['home-title']['styles']['terminals']['h5']['display']==='none','no duplicate mobile title');
    check($nodes['home-categories']['styles']['terminals']['mp-weixin']['display']==='none','group headings replace default mobile category chips');
    check($nodes['home-tools']['styles']['parts']['option']['terminals']['h5']['display']==='flex','mobile cards use horizontal icon title caption');
    Seed::ensureTenant($tenant);check(S::owned('tool',$tenant,$created['id'])['icon']===$tool['icon'],'configured icon preserved during repair');check(S::table('page')->where('id',$page['id'])->value('published_version_id')==$upgraded['published_version_id'],'presentation upgrade idempotent');
    $custom=$legacy;$custom['page']['nodes'][0]['content']['text']='租户自己的首页';
    S::table('page')->where('id',$page['id'])->update(['draft_json'=>S::json($custom)]);Seed::ensureTenant($tenant);
    check(S::table('page')->where('id',$page['id'])->value('draft_json')===S::json($custom),'tenant custom homepage not overwritten');
    echo "copywriting presentation and icon configuration PASS (rolled back; no model calls)\n";
} finally {Db::rollback();}
