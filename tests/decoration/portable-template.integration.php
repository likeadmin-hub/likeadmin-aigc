<?php
// Local z_cn only. Every fixture is inside one outer transaction; physical copies are removed in finally.
$root=getenv('AIGC_SERVER_ROOT')?:dirname(__DIR__,2);
require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
use app\common\service\decorate\{DecorateTemplateService as Templates,DecorationPortableAssets as Assets,DecorationPortableDocument as Document,DecorationPortablePackage as Package,DecorationTransferProgress as Progress};
use app\common\service\storage\{StorageConfigService,Driver};
use app\common\enum\AdminTerminalEnum;
use think\facade\Db;
$config=config('database.connections.mysql');if(($config['hostname']??'')!=='127.0.0.1'||($config['database']??'')!=='z_cn')throw new RuntimeException('Local z_cn required');
$request=request();$request->source=AdminTerminalEnum::TENANT;$request->tenantId=1;$request->adminId=0;$request->withServer(['HTTP_HOST'=>'portable.fixture.test','SERVER_PORT'=>'80','REQUEST_SCHEME'=>'http']);
$checks=0;function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);++$checks;}
function packageBytes(array $payload,array $embedded=[]):string{$tmp=tempnam(sys_get_temp_dir(),'portable_fixture_');$z=new ZipArchive();$z->open($tmp,ZipArchive::OVERWRITE);$z->addFromString('manifest.json',json_encode(['package_type'=>'likeadmin.decorate.template','schema_version'=>'1.0']));$z->addFromString('template.json',json_encode($payload));foreach($embedded as $entry=>$body)$z->addFromString($entry,$body);$z->close();try{return base64_encode(file_get_contents($tmp));}finally{unlink($tmp);}}
function sourceSnapshot():string{return hash('sha256',json_encode([Db::name('decorate_template')->where(['tenant_id'=>1,'id'=>46])->find(),Db::name('decorate_page')->where(['tenant_id'=>1,'template_id'=>46])->order('id')->select()->toArray()]));}
$before=sourceSnapshot();check(Db::name('decorate_template')->where(['tenant_id'=>1,'id'=>46])->count()===1,'source template46 required');
$tenant=214700146;$created=[];$driverConfig=null;$operation=bin2hex(random_bytes(16));
check(Db::name('tenant')->where('id',$tenant)->count()===0,'fixture tenant id already exists');
Db::startTrans();
try{
    $exportSteps=[];
    $export=Templates::exportPackage(1,46,function($message)use(&$exportSteps){$exportSteps[]=$message;});
    check(count($exportSteps)>2&&str_contains(end($exportSteps),'ZIP'),'real export reports preparation and packaging');$package=Package::read($export['content']);$original=$package['payload'];
    $assets=[];Document::rewrite($original,function($url,$location)use(&$assets){check((bool)preg_match('~^https?://~',$url),'all exported media absolute');$assets[$url]=true;return $url;});check(count($assets)===$export['asset_count'],'manifest resource count matches media leaves');
    Db::name('tenant')->insert(['id'=>$tenant,'sn'=>'portable-rollback-'.$tenant,'name'=>'portable rollback fixture','tactics'=>0,'create_time'=>time(),'allow_custom_storage'=>0,'allow_local_storage'=>1]);
    $request->tenantId=$tenant;$driverConfig=StorageConfigService::getEffectiveConfig($tenant);check($driverConfig['default']==='local','fixture requires local target storage; never send test media to cloud');
    $importSteps=[];$transfer=Progress::begin($tenant,$tenant,$operation,'import');
    $copy=Templates::importPackage($tenant,$export['content'],$export['filename'],function($message)use(&$importSteps,$transfer){$importSteps[]=$message;$transfer->step($message);});
    check(Progress::read($tenant,$tenant,$operation)['status']==='running','progress never claims success during database writes');
    $transfer->succeed($copy);$snapshot=Progress::read($tenant,$tenant,$operation);
    check($snapshot['status']==='success'&&$snapshot['result']['asset_import']['files']===$copy['asset_import']['files'],'real cache supports committed import reconciliation');
    check(Progress::read($tenant+1,$tenant,$operation)['status']==='pending','real cache tenant isolation');
    check(str_contains($importSteps[0],'解压')&&str_contains(implode('|',$importSteps),'已处理')&&str_contains(end($importSteps),'已保存页面'),'real import reports validation, stored assets and page writes');$id=(int)$copy['id'];check((int)$copy['is_active']===0&&$copy['publish_status']==='draft','import inactive draft');
    $created=Db::name('tenant_file')->where('tenant_id',$tenant)->select()->toArray();check(count($created)===$copy['asset_import']['files']&&count($created)>0,'each copied asset has tenant file metadata');
    foreach($created as $file){check(str_starts_with($file['uri'],'uploads/decoration/'.$tenant.'/'),'target namespace isolation');check(is_file(public_path().$file['uri']),'physical copied file exists');check((int)$file['source']===0,'sourceAdmin ownership');}
    $pages=Db::name('decorate_page')->where(['tenant_id'=>$tenant,'template_id'=>$id])->order(['terminal'=>'asc','is_home'=>'desc','sort'=>'asc','id'=>'asc'])->select()->toArray();check(count($pages)===count($original['pages']),'all original terminals/pages retained');
    $strip=function(array $p):array{return Document::rewrite($p,fn()=>'/ASSET');};
    foreach($pages as $i=>$page){
        foreach(['data','meta','draft_data','draft_meta','published_data','published_meta'] as $field){$a=$strip(['pages'=>[[$field=>$original['pages'][$i][$field]??'']]])['pages'][0][$field]??'';$b=$strip(['pages'=>[[$field=>$page[$field]??'']]])['pages'][0][$field]??'';check(json_decode($a)==json_decode($b),'geometry/content preserved '.$i.'/'.$field);}
        check((int)$page['draft_deleted']===(int)($original['pages'][$i]['draft_deleted']??0),'draft deletion flag preserved');
    }
    $stored=Db::name('decorate_template')->where('id',$id)->find();
    foreach(['draft_settings','published_settings'] as $field){$a=$strip(['template'=>[$field=>$original['template'][$field]]])['template'][$field];$b=$strip(['template'=>[$field=>$stored[$field]]])['template'][$field];check(json_decode($a)==json_decode($b),'settings geometry preserved '.$field);}
    check(!str_contains(json_encode($stored), 'token='),'stable settings contain no signatures');
    // A deleted draft still has a last published page identity, remapped to its new row.
    $fixture=$original;$packageId=count($fixture['pages'])+1;$deleted=$fixture['pages'][0];$deleted['page_code']='deleted_fixture';$deleted['is_system']=0;$deleted['is_home']=0;$deleted['draft_deleted']=1;$deleted['package_page_id']=$packageId;$fixture['pages'][]=$deleted;
    $fixture['template']['published_pages'][]=['package_page_id'=>$packageId,'terminal'=>$deleted['terminal'],'channel'=>$deleted['channel'],'page_code'=>'published_deleted_fixture','page_type'=>'custom','route_path'=>'','is_home'=>0,'is_system'=>0,'status'=>1,'sort'=>999,'type'=>0,'name'=>'deleted fixture'];
    $other=Templates::importPackage($tenant,packageBytes($fixture));$deletedRow=Db::name('decorate_page')->where(['tenant_id'=>$tenant,'template_id'=>$other['id'],'page_code'=>'deleted_fixture'])->find();check((int)$deletedRow['draft_deleted']===1,'deleted draft does not resurrect');$snapshot=json_decode($other['published_pages'],true);check((int)end($snapshot)['id']===(int)$deletedRow['id'],'last published identity remapped');
    // Invalid remote input after local copies must compensate both DB and physical objects.
    $count=Db::name('tenant_file')->where('tenant_id',$tenant)->count();$templates=Db::name('decorate_template')->where('tenant_id',$tenant)->count();$dirs=glob(public_path().'uploads/decoration/'.$tenant.'/*')?:[];
    $bad=$original;$bad['pages'][0]['data']=json_encode([['name'=>'image-hotspot','content'=>['image'=>'http://127.0.0.1/deny.png','areas'=>[]],'styles'=>[]]]);
    $rollbackSteps=[];
    try{Templates::importPackage($tenant,packageBytes($bad),'',function($message)use(&$rollbackSteps){$rollbackSteps[]=$message;});throw new LogicException('SSRF import accepted');}catch(RuntimeException $error){check(str_contains($error->getMessage(),'素材导入失败'),'safe explicit asset failure');}
    check(str_contains(end($rollbackSteps),'回滚'),'real rollback reported before failed import returns');
    check(Db::name('tenant_file')->where('tenant_id',$tenant)->count()===$count&&Db::name('decorate_template')->where('tenant_id',$tenant)->count()===$templates,'failed import creates no rows');check((glob(public_path().'uploads/decoration/'.$tenant.'/*')?:[])===$dirs,'failed import leaves no local object directory');
    // Embedded private bytes are preferred even when the source URL is expired/unresolvable.
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1sAAAAASUVORK5CYII=');$hash=hash('sha256',$png);$entry='assets/'.$hash.'.png';$url='https://expired.private.invalid/a.png?e=1';$tiny=['template'=>['name'=>'embedded private fixture','settings'=>[]],'pages'=>[['terminal'=>'mobile','channel'=>'common','page_code'=>'fixture','data'=>json_encode([['name'=>'image-hotspot','content'=>['image'=>$url,'areas'=>[]],'styles'=>[]]]),'meta'=>'[]']]];
    $resources=['version'=>1,'items'=>[['url'=>$url,'entry'=>$entry,'sha256'=>$hash,'size'=>strlen($png),'mime'=>'image/png']]];$embedded=Templates::importPackage($tenant,packageBytes($tiny,[$entry=>$png,'assets/resources.json'=>json_encode($resources)]));check($embedded['asset_import']['files']===1,'embedded expired private source imports without network');
    // A validated portable SVG must retain a target-owned identity through a real editor save.
    $svg='<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12"><rect width="12" height="12" fill="#55ccaa"/></svg>';
    $svgHash=hash('sha256',$svg);$svgEntry='assets/'.$svgHash.'.svg';$svgUrl='https://expired.private.invalid/source.svg?e=1';
    $svgFixture=['template'=>['name'=>'source graphic SVG fixture','settings'=>[]],'pages'=>[['terminal'=>'mobile','channel'=>'common','page_code'=>'svg_fixture','data'=>json_encode([['id'=>'svg-source','name'=>'app-collection','content'=>['source_graphics'=>[['app_code'=>'micro_video','entry_key'=>'main','mode'=>'image','image'=>$svgUrl]]],'styles'=>[]]]),'meta'=>'[]']]];
    $svgResources=['version'=>1,'items'=>[['url'=>$svgUrl,'entry'=>$svgEntry,'sha256'=>$svgHash,'size'=>strlen($svg),'mime'=>'image/svg+xml']]];
    $svgTemplate=Templates::importPackage($tenant,packageBytes($svgFixture,[$svgEntry=>$svg,'assets/resources.json'=>json_encode($svgResources)]));
    $svgPage=Db::name('decorate_page')->where(['tenant_id'=>$tenant,'template_id'=>$svgTemplate['id'],'page_code'=>'svg_fixture'])->find();$svgData=json_decode($svgPage['draft_data'],true);
    $svgRef=$svgData[0]['_decoration_asset_refs']['content.source_graphics.0.image']??null;
    check(is_array($svgRef)&&$svgRef['kind']==='file'&&$svgRef['id']>0,'imported SVG source graphic has a fresh file identity');
    $svgData[0]['content']['source_graphics'][0]['id']='edited-source-row'; // Force managed normalization instead of the unchanged-state fast path.
    $savedSvg=Templates::savePage($tenant,['template_id'=>$svgTemplate['id'],'id'=>$svgPage['id'],'data'=>json_encode($svgData),'meta'=>'[]']);
    check(json_decode($savedSvg['draft_data'],true)[0]['_decoration_asset_refs']['content.source_graphics.0.image']===$svgRef,'SVG source graphic imports and saves with identity intact');
    $svgFile=Db::name('tenant_file')->where('id',$svgRef['id'])->find();
    foreach([['tenant_id'=>1],['type'=>\app\common\enum\FileEnum::FILE_TYPE],['uri'=>'uploads/legacy.svg']] as $invalidIdentity){
        Db::name('tenant_file')->where('id',$svgRef['id'])->update($invalidIdentity);
        try{\app\common\service\decorate\agent\DecorationAgentAssetReferences::normalizeSourceGraphics(['data'=>$svgData],[],$tenant);throw new LogicException('invalid SVG identity accepted');}catch(InvalidArgumentException $error){check(true,'foreign tenant/non-image/legacy SVG claim rejected');}
        Db::name('tenant_file')->where('id',$svgRef['id'])->update(['tenant_id'=>$tenant,'type'=>$svgFile['type'],'uri'=>$svgFile['uri']]);
    }
    // No supplier calls: sign one target record with a transaction-local Qiniu configuration.
    $file=$created[0];Db::name('tenant_file')->where('id',$file['id'])->update(['storage_scope'=>'tenant','storage_engine'=>'qiniu','storage_domain'=>'https://private.fixture.invalid']);
    Db::name('tenant_config')->insert(['tenant_id'=>$tenant,'type'=>'storage','name'=>'qiniu','value'=>json_encode(['access_key'=>'fixture-ak','secret_key'=>'fixture-sk','bucket'=>'fixture-bucket','domain'=>'https://private.fixture.invalid','private_access'=>true])]);
    $stable='/'.$file['uri'];$old='https://private.fixture.invalid/'.$file['uri'].'?e=1&token=old#symbol';
    $values=['cover'=>$old,'mobile_tabbar'=>['style'=>['icon_atlas'=>$old]],'html'=>'<img src="'.$old.'">','background'=>'url('.$old.')'];
    $wire=Assets::refreshValue($values,$tenant);check(!str_contains(json_encode($wire),'token=old')&&str_contains(json_encode($wire),'token='),'private CSS/HTML/settings refreshed offline');check(str_ends_with($wire['cover'],'#symbol'),'private fragment preserved');$normalized=Assets::normalizeValue($wire,$tenant);check($normalized['cover']===$stable.'#symbol'&&str_contains($normalized['html'],$stable.'#symbol'),'private save normalizes signatures to stable URI with fragment');
    try{Assets::refreshValue($values,1);throw new LogicException('foreign tenant signed');}catch(RuntimeException $error){check(true,'cross tenant private read denied');}
    // Deleting a referenced material must not lock the tenant out of decoration.
    $settings=json_encode(['mobile_tabbar'=>['list'=>[['icon'=>$stable.'#icon']]]]);
    Db::name('decorate_template')->where('id',$svgTemplate['id'])->update(['cover'=>$old,'draft_settings'=>$settings,'published_settings'=>$settings]);
    $missingData=json_encode([['id'=>'missing-image','name'=>'image-hotspot','content'=>['image'=>$old,'areas'=>[]],'styles'=>['width'=>375],'future'=>(object)[]]]);
    Db::name('decorate_page')->where('id',$svgPage['id'])->update(['data'=>$missingData,'draft_data'=>$missingData,'published_data'=>$missingData]);
    Db::name('tenant_file')->where('id',$file['id'])->update(['delete_time'=>time()]);
    $beforeMissingRead=Db::name('decorate_page')->where('id',$svgPage['id'])->find();
    $missing=Assets::refreshValue($values,$tenant);
    check($missing['cover']===''&&$missing['mobile_tabbar']['style']['icon_atlas']==='','deleted images return empty without an old signature or fragment');
    check(!str_contains($missing['html'],'token=')&&!str_contains($missing['background'],'token='),'deleted HTML/CSS media never expose cached private delivery URLs');
    $listed=array_values(array_filter(Templates::lists($tenant),fn($row)=>(int)$row['id']===(int)$svgTemplate['id']));
    check(count($listed)===1&&$listed[0]['cover']==='','deleted template cover does not break template list');
    $detail=Templates::detail($tenant,$svgTemplate['id']);
    check($detail['settings']['mobile_tabbar']['list'][0]['icon']===''&&count($detail['pages'])>0,'editor opens with missing tabbar and page images');
    $editor=Templates::pageDetail($tenant,['template_id'=>$svgTemplate['id'],'id'=>$svgPage['id'],'terminal'=>'mobile']);
    $editorData=json_decode($editor['draft_data']);
    check($editorData[0]->content->image===''&&$editorData[0]->styles->width===375&&$editorData[0]->future instanceof stdClass,'editor clears only deleted media and preserves geometry/unknown objects');
    check(Db::name('decorate_page')->where('id',$svgPage['id'])->find()===$beforeMissingRead,'tolerant reads never rewrite persisted draft or published snapshots');
    Templates::savePage($tenant,['template_id'=>$svgTemplate['id'],'id'=>$svgPage['id'],'data'=>$editor['draft_data'],'meta'=>$editor['draft_meta']]);
    check(json_decode(Templates::pageDetail($tenant,['template_id'=>$svgTemplate['id'],'id'=>$svgPage['id']])['draft_data'],true)[0]['content']['image']==='','editor can save and reopen after removing missing material');
    try{Assets::normalizeValue(['cover'=>$old],$tenant);throw new LogicException('deleted file write accepted');}catch(RuntimeException $error){check(str_contains($error->getMessage(),'失效'),'stale deleted material cannot be submitted as a fresh valid reference');}
    try{Assets::refreshValue(['cover'=>$old],1);throw new LogicException('foreign deleted file accepted');}catch(RuntimeException $error){check(str_contains($error->getMessage(),'租户'),'cross tenant checks still reject deleted references');}
    check(Assets::refreshValue(['cover'=>'/'.$svgFile['uri']],$tenant)['cover']!=='','other valid imported media remain available');
}finally{
    \think\facade\Cache::delete('decoration_transfer:'.$tenant.':'.$tenant.':'.$operation);
    $request->tenantId=$tenant;
    $all=Db::name('tenant_file')->where('tenant_id',$tenant)->select()->toArray();
    Db::rollback();
    if($driverConfig&&$driverConfig['default']==='local')foreach($all??[] as $file){if(str_starts_with($file['uri'],'uploads/decoration/'.$tenant.'/')){(new Driver($driverConfig))->delete($file['uri']);@rmdir(dirname(public_path().$file['uri']));}}
    @rmdir(public_path().'uploads/decoration/'.$tenant);$request->tenantId=1;
}
check(sourceSnapshot()===$before,'source template46 unchanged after rollback');check(Db::name('tenant')->where('id',$tenant)->count()===0&&Db::name('tenant_file')->where('tenant_id',$tenant)->count()===0,'database fixtures rolled back');
echo "PASS portable template46/ownership/private/rollback: $checks checks; all fixture rows/files removed; no upstream calls\n";
