<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL.$error->getTraceAsString());exit(1);});
use think\facade\Db;
use app\common\enum\FileEnum;
use app\common\service\decorate\agent\{DecorationAgentService as S,DecorationAgentCatalog as C};
$checks=0;function captureCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function captureReject(callable $call,string $label): void {try{$call();}catch(Exception $error){captureCheck(true,$label);return;}throw new RuntimeException('accepted: '.$label);}
$paths=[];Db::startTrans();try {
 request()->tenantId=1;$asset=static function(string $source)use(&$paths): array {$uri='uploads/capture_'.bin2hex(random_bytes(8)).'.png';$path=public_path().$uri;copy(public_path().$source,$path);$paths[]=$path;return ['image_uri'=>$uri,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>''];};
 $fileAsset=$asset('resource/image/tenantapi/default/nav01.png');$generated=$asset('resource/image/tenantapi/default/banner001.png');
 $file=(int)Db::name('tenant_file')->insertGetId(['tenant_id'=>1,'source'=>FileEnum::SOURCE_ADMIN,'type'=>FileEnum::IMAGE_TYPE,'uri'=>$fileAsset['image_uri'],'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']);
 $slot=(int)Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>0,'run_id'=>0,'status'=>'success','slot_key'=>'hero:content.data.0.image','request_json'=>'{}','result_json'=>S::json(['status'=>'success','billing_status'=>'settled','images'=>[$generated]])]);
 $task=(int)Db::name('ai_app_task')->insertGetId(['tenant_id'=>1,'user_id'=>0,'app_code'=>'system_default','business_table'=>'decorate_agent_image_slot','business_id'=>$slot,'status'=>'success']);$receipt=(int)Db::name('ai_consumption_log')->insertGetId(['tenant_id'=>1,'user_id'=>0,'app_task_id'=>$task,'app_code'=>'system_default','protocol'=>'image_generate','run_status'=>'success','billing_status'=>'settled','response_summary'=>S::json(['images'=>[$generated]])]);Db::name('decorate_agent_image_slot')->where('id',$slot)->update(['consumption_id'=>$receipt]);
 $banner=C::create('banner','hero');$stable='/'.$generated['image_uri'];$banner['content']['data'][0]['image']=$stable;$banner['_decoration_asset_refs']=['content.data.0.image'=>['kind'=>'slot','id'=>$slot,'expected_value'=>$stable]];
 $meta=C::pageMeta()['defaults'];$meta['content']['bg_image']='/'.$fileAsset['image_uri'];$meta['_decoration_asset_refs']=['content.bg_image'=>['kind'=>'file','id'=>$file,'expected_value'=>$meta['content']['bg_image']]];
 $payload=['data'=>[$banner],'meta'=>[$meta],'name'=>'capture fixture','new_page'=>false];$id=(int)Db::name('decorate_agent_candidate')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>0,'run_id'=>0,'status'=>'ready','base_hash'=>S::hash($payload),'revision'=>2,'payload_json'=>S::json($payload),'base_json'=>'{}','changes_json'=>'[]']);$p=['candidate_id'=>$id,'revision'=>2,'candidate_hash'=>S::hash($payload)];
 $before=Db::name('decorate_agent_candidate')->where('id',$id)->find();request()->tenantId=777;$result=S::candidateCaptureAssets(1,1,$p);
 captureCheck(count($result['assets'])===2&&isset($result['image_data_urls'][$stable],$result['image_data_urls'][$meta['content']['bg_image']]),'actual generated img and page-meta CSS background refs resolve to capture bytes');
 foreach($result['assets'] as $row)captureCheck(hash('sha256',base64_decode(explode(',',$result['image_data_urls'][$row['value']],2)[1]))===$row['sha256']&&str_starts_with($row['delivery_url'],'/uploads/'),'capture bytes match authoritative stored raster and root-relative delivery identity');
 captureCheck(request()->tenantId===777,'explicit asset read restores caller tenant context');
 captureCheck(Db::name('decorate_agent_candidate')->where('id',$id)->find()===$before,'byte transport does not mutate raw candidate or payload hash');
 captureReject(fn()=>S::candidateCaptureAssets(2,1,$p),'cross-tenant capture denied');captureReject(fn()=>S::candidateCaptureAssets(1,2,$p),'cross-admin capture denied');
 captureReject(fn()=>S::candidateCaptureAssets(1,1,array_replace($p,['revision'=>1])),'stale revision denied');captureReject(fn()=>S::candidateCaptureAssets(1,1,array_replace($p,['candidate_hash'=>str_repeat('0',64)])),'stale full hash denied');
 captureReject(fn()=>S::candidateCaptureAssets(1,1,$p+['url'=>'https://arbitrary.invalid/secret.png']),'arbitrary URL proxy parameter denied');
 $change=static function(array $payload)use($id): array {Db::name('decorate_agent_candidate')->where('id',$id)->update(['payload_json'=>S::json($payload)]);return ['candidate_id'=>$id,'revision'=>2,'candidate_hash'=>S::hash($payload)];};
 $forged=$payload;$forged['data'][0]['_decoration_asset_refs']['content.data.0.image']['id']=$slot+100000;captureReject(fn()=>S::candidateCaptureAssets(1,1,$change($forged)),'forged identity denied even with matching payload hash');
 $changed=$payload;$changed['data'][0]['content']['data'][0]['image']='https://arbitrary.invalid/never-proxied.png';$r=S::candidateCaptureAssets(1,1,$change($changed));captureCheck(!isset($r['image_data_urls'][$stable]),'replaced field cannot read its stale ref or fetch arbitrary URL');
 $change($payload);Db::startTrans();try{Db::name('tenant_file')->where('id',$file)->update(['tenant_id'=>2]);captureReject(fn()=>S::candidateCaptureAssets(1,1,$p),'foreign-tenant file reference is denied');}finally{Db::rollback();}
 Db::startTrans();try{Db::name('tenant_file')->where('id',$file)->update(['source'=>FileEnum::SOURCE_USER]);captureReject(fn()=>S::candidateCaptureAssets(1,1,$p),'ordinary private USER image file cannot be read by capture');}finally{Db::rollback();}
 $original=file_get_contents($paths[0]);try{file_put_contents($paths[0],'invalid raster bytes');captureReject(fn()=>S::candidateCaptureAssets(1,1,$p),'invalid raster cannot pass the trusted metadata byte transport');}finally{file_put_contents($paths[0],$original);}
 $change($payload);Db::name('decorate_agent_image_slot')->where('id',$slot)->update(['request_json'=>S::json(['purpose'=>'design'])]);captureReject(fn()=>S::candidateCaptureAssets(1,1,$p),'whole UI design slot cannot become capture decoration material');Db::name('decorate_agent_image_slot')->where('id',$slot)->update(['request_json'=>'{}']);
 Db::name('decorate_agent_run')->insert(['tenant_id'=>1,'admin_id'=>1,'session_id'=>0,'input_json'=>'{}','context_json'=>S::json(['visual_review'=>['screenshot_file_id'=>$file]]),'result_json'=>'{}','events_json'=>'[]','idempotency_key'=>'capture-evidence:'.bin2hex(random_bytes(8))]);captureReject(fn()=>S::candidateCaptureAssets(1,1,$p),'globally registered review screenshot cannot be copied as decoration capture material');
}finally{Db::rollback();foreach($paths as $path)unlink($path);}
echo "candidate capture: $checks checks passed (DB rolled back, local raster bytes only)\n";
