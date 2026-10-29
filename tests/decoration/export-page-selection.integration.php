<?php
// Real local ZIP export; all fixture rows roll back and no external media is fetched.
$root = getenv('AIGC_SERVER_ROOT') ?: dirname(__DIR__, 2);
require $root . '/runtime-loader.php';
(new AigcRuntimeApp($root))->initialize();
use app\common\service\decorate\DecorateTemplateService as Templates;
use app\common\service\decorate\DecorationPortablePackage as Package;
use think\facade\Db;

set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); });
$config = config('database.connections.mysql');
if (($config['hostname'] ?? '') !== '127.0.0.1' || ($config['database'] ?? '') !== 'z_cn') throw new RuntimeException('Local z_cn only');
request()->tenantId = 1;
request()->withServer(['HTTP_HOST'=>'export.fixture.test','SERVER_PORT'=>80,'REQUEST_SCHEME'=>'http']);
$checks = 0;
$check = static function ($ok, $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
$before = hash('sha256', json_encode(Db::name('decorate_template')->where('tenant_id',1)->order('id')->select()->toArray()));
Db::startTrans();
try {
    $template = Templates::add(1, ['name'=>'export selection rollback fixture']);
    $id = (int)$template['id'];
    Db::name('decorate_page')->where(['tenant_id'=>1,'template_id'=>$id])->delete();
    Db::name('decorate_template')->where('id',$id)->update(['draft_settings'=>'{}','published_settings'=>'{}']);
    $page = ['tenant_id'=>1,'template_id'=>$id,'terminal'=>'mobile','channel'=>'common','page_type'=>'custom','name'=>'live','page_code'=>'live','data'=>'[]','draft_data'=>'[]','published_data'=>'[]','meta'=>'[]','draft_meta'=>'[]','published_meta'=>'[]','draft_deleted'=>0,'create_time'=>time(),'update_time'=>time()];
    $live = Db::name('decorate_page')->insertGetId($page + ['sort'=>100]);
    $bad = json_encode([['name'=>'image','content'=>['image'=>'/uploads/images/obsolete-export-fixture.png']]]);
    $obsolete = Db::name('decorate_page')->insertGetId(array_replace($page,['name'=>'obsolete','page_code'=>'obsolete','draft_deleted'=>1,'data'=>$bad,'draft_data'=>$bad]));
    $published = Db::name('decorate_page')->insertGetId(array_replace($page,['name'=>'published only','page_code'=>'published_only','draft_deleted'=>1]));
    $structure = [['id'=>$published,'terminal'=>'mobile','channel'=>'common','page_code'=>'published_only','name'=>'published only']];
    Db::name('decorate_template')->where('id',$id)->update(['published_pages'=>json_encode($structure)]);
    $steps = [];
    $out = Templates::exportPackage(1,$id,static function ($message) use (&$steps): void { $steps[]=$message; });
    $zip = Package::read($out['content']);
    $rows = $zip['payload']['pages'];
    $check(count($rows)===2,'Deleted unpublished page was exported');
    $check(!str_contains(json_encode($zip),'obsolete-export-fixture'),'Obsolete image was scanned into the package');
    $check(str_contains($steps[0],'2个页面'),'Progress count includes obsolete pages');
    $kept = array_values(array_filter($rows,fn($p)=>$p['page_code']==='published_only'))[0];
    $check((int)$kept['draft_deleted']===1,'Published-only page was resurrected as a draft');
    $check($zip['payload']['template']['published_pages'][0]['package_page_id']===$kept['package_page_id'],'Published page ID was not remapped after filtering');
    $check(array_column($rows,'package_page_id')===[1,2],'Exported page IDs are not contiguous');
    $check(Db::name('decorate_page')->where('id',$obsolete)->count()===1,'Export deleted source data');

    // Published and active invalid media must be excluded with a visible report.
    Db::name('decorate_template')->where('id',$id)->update(['published_pages'=>json_encode([['id'=>$obsolete]])]);
    $publishedExport = Templates::exportPackage(1,$id);
    $check($publishedExport['export_warnings']['assets']===1,'Published invalid media lacks warning');
    $check($publishedExport['export_warnings']['items'][1]['page']==='obsolete','Warning lacks page context');
    $body = Package::read($publishedExport['content'])['payload'];
    $check(!str_contains(json_encode($body),'obsolete-export-fixture'),'Invalid published media retained');
    $check(count($body['pages'])===2,'Published page itself must remain');
    // The deleted support QR must no longer block an active page export.
    Db::name('decorate_template')->where('id',$id)->update(['published_pages'=>'[]']);
    Db::name('decorate_page')->where('id',$obsolete)->update(['draft_deleted'=>0]);
    $activeExport = Templates::exportPackage(1,$id);
    $check($activeExport['export_warnings']['assets']===1,'Active invalid media lacks warning');
    $check(count($activeExport['export_warnings']['items'][1]['locations'])===2,'Repeated invalid media not deduplicated with locations');
    $temp = tempnam(sys_get_temp_dir(),'export_report_');file_put_contents($temp,base64_decode($activeExport['content']));
    $archive = new ZipArchive();$archive->open($temp);
    $manifest = json_decode($archive->getFromName('manifest.json'),true);$archive->close();unlink($temp);
    $check($manifest['export_warnings']===$activeExport['export_warnings'],'ZIP lacks durable warning report');
    $check(str_contains($activeExport['export_warnings']['items'][1]['reason'],'归属'),'Warning lacks reason');
    Db::name('decorate_page')->where('id',$obsolete)->update(['draft_deleted'=>1]);
    $legacy = Package::read(Templates::exportPackage(1,$id)['content']);
    $check(count($legacy['payload']['pages'])===1,'Legacy empty published structure must keep active drafts only');
    Db::name('decorate_template')->where('id',$id)->update(['published_pages'=>json_encode([['id'=>2147000999]])]);
    try { Templates::exportPackage(1,$id); throw new LogicException('Missing published page was silently omitted'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'发布页面结构不完整'),'Published integrity failure changed'); }
} finally {
    Db::rollback();
}
$check($before===hash('sha256',json_encode(Db::name('decorate_template')->where('tenant_id',1)->order('id')->select()->toArray())),'Source template data changed');
echo "PASS export page selection: $checks checks, actual ZIP, transaction rolled back, no external calls.\n";
