<?php
declare(strict_types=1);
namespace app\common\service\storage {
    /** Deterministic private-delivery clock; ownership/storage rows are real DB fixtures. */
    final class StorageSignedUrlService {
        public static int $now=1000;
        public static array $calls=[];
        public static function resolve(string $uri,string $scope,string $engine,string $domain,?int $tenantId=null): ?string {
            self::$calls[]=compact('uri','scope','engine','domain','tenantId');
            return $engine==='qiniu' ? rtrim($domain,'/').'/'.ltrim($uri,'/').'?expires='.(self::$now+300) : null;
        }
    }
}
namespace {
    $root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new \AigcRuntimeApp($root))->initialize();
    use think\facade\Db;
    use app\common\enum\FileEnum;
    use app\common\service\decorate\agent\DecorationAgentAssetReferences as R;
    use app\common\service\decorate\agent\DecorationAgentCatalog as C;
    use app\common\service\decorate\agent\DecorationAgentService as S;
    use app\common\service\storage\StorageSignedUrlService as Signed;
    $checks=0;
    function assetCheck(bool $ok,string $message): void {global $checks;if(!$ok)throw new \RuntimeException($message);$checks++;}
    function assetReject(callable $call,string $message): void {try{$call();}catch(\InvalidArgumentException $e){assetCheck(true,$message);return;}throw new \RuntimeException($message);}
    assetCheck(class_exists(R::class),'Asset-reference implementation must exist');
    Db::startTrans();
    try {
        $asset=['image_uri'=>'uploads/aigc_image/fixture/generated.png','storage_scope'=>'tenant','storage_engine'=>'qiniu','storage_domain'=>'https://asset-fixture.invalid','url'=>'https://asset-fixture.invalid/uploads/aigc_image/fixture/generated.png?expires=999'];
        $request=['widget_id'=>'banner','field'=>'content.data.0.image'];
        $slotId=(int)Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>1,'admin_id'=>1,'session_id'=>0,'run_id'=>0,'slot_key'=>'banner:content.data.0.image','status'=>'success','request_json'=>S::json($request),'result_json'=>S::json(['status'=>'success','images'=>[$asset],'billing_status'=>'settled'])]);
        $taskId=(int)Db::name('ai_app_task')->insertGetId(['tenant_id'=>1,'user_id'=>0,'app_code'=>'system_default','business_table'=>'decorate_agent_image_slot','business_id'=>$slotId,'status'=>'success']);
        $consumptionId=(int)Db::name('ai_consumption_log')->insertGetId(['tenant_id'=>1,'user_id'=>0,'app_task_id'=>$taskId,'app_code'=>'system_default','protocol'=>'image_generate','run_status'=>'success','billing_status'=>'settled','response_summary'=>S::json(['images'=>[$asset]])]);
        Db::name('decorate_agent_image_slot')->where('id',$slotId)->update(['consumption_id'=>$consumptionId]);
        $slot=Db::name('decorate_agent_image_slot')->where('id',$slotId)->find();
        $banner=C::create('banner','banner');$banner['content']['data'][0]['image']=$asset['url'];
        $page=['data'=>[$banner],'meta'=>[C::pageMeta()['defaults']],'name'=>'asset fixture'];$before=$page;
        $attached=R::attachGenerated($page,$slot,$asset);$stable='/'.$asset['image_uri'];
        assetCheck($page===$before,'attachment does not modify input');
        assetCheck($attached['data'][0]['content']['data'][0]['image']===$stable,'generated image stores durable root-relative value');
        $ref=$attached['data'][0]['_decoration_asset_refs']['content.data.0.image'];
        assetCheck($ref['kind']==='slot'&&$ref['id']===$slotId&&$ref['expected_value']===$stable,'only immutable authoritative identity is persisted');
        assetCheck(!str_contains(S::json($attached),'expires=')&&!isset($ref['storage_domain']),'signed URL and caller storage metadata stay out of persisted reference');
        $hash=S::hash($attached);$databaseBefore=Db::name('decorate_agent_image_slot')->where('id',$slotId)->find();request()->tenantId=777;
        $first=R::resolveUrls($attached,1);
        assetCheck(str_contains($first[$stable]??'','expires=1300'),'private image signs at first wire read');
        Signed::$now=1400;$second=R::resolveUrls($attached,1);
        assetCheck(str_contains($second[$stable]??'','expires=1700')&&$second[$stable]!==$first[$stable],'expired private URL is regenerated from current read clock');
        assetCheck(S::hash($attached)===$hash&&request()->tenantId===777,'read preserves raw hash and restores caller tenant scope');
        assetCheck(Db::name('decorate_agent_image_slot')->where('id',$slotId)->find()===$databaseBefore,'resolve and attach do not mutate durable task/slot/billing rows');
        $call=end(Signed::$calls);assetCheck($call['uri']===$asset['image_uri']&&$call['tenantId']===1,'read signs authoritative object key under requested tenant');
        assetCheck(R::resolveUrls($attached,2)===[],'cross-tenant reference cannot resolve');
        $forged=$attached;$forged['data'][0]['_decoration_asset_refs']['content.data.0.image']['image_uri']='uploads/other.png';$forged['data'][0]['_decoration_asset_refs']['content.data.0.image']['storage_domain']='https://invented.invalid';
        assetCheck(R::resolveUrls($forged,1)===$second,'embedded metadata cannot redirect signing');
        $replaced=$attached;$replaced['data'][0]['content']['data'][0]['image']='/manual.png';assetCheck(R::resolveUrls($replaced,1)===[],'manual replacement makes stale reference inert');
        $wrongField=$attached;$wrongField['data'][0]['content']['data'][0]['name']=$stable;$wrongField['data'][0]['_decoration_asset_refs']=['content.data.0.name'=>$ref];assetCheck(R::resolveUrls($wrongField,1)===[],'only exact catalog image fields may resolve');
        $wrongSlot=$slot;$wrongSlot['admin_id']=2;assetReject(fn()=>R::attachGenerated($page,$wrongSlot,$asset),'caller cannot forge generated admin ownership');
        $wrongAsset=$asset;$wrongAsset['image_uri']='uploads/forged.png';assetReject(fn()=>R::attachGenerated($page,$slot,$wrongAsset),'asset must match successful authoritative result');
        Db::name('ai_consumption_log')->where('id',$consumptionId)->update(['billing_status'=>'pending_usage']);assetCheck(R::resolveUrls($attached,1)===[],'unsettled generation cannot sign');Db::name('ai_consumption_log')->where('id',$consumptionId)->update(['billing_status'=>'settled']);
        Db::name('decorate_agent_image_slot')->where('id',$slotId)->update(['status'=>'regenerated']);assetCheck(R::resolveUrls($attached,1)===$second,'original confirmed image remains readable during regeneration');Db::name('decorate_agent_image_slot')->where('id',$slotId)->update(['status'=>'success']);
        $generatedMaterial=R::attachMaterials($page,[['slot_id'=>$slotId,'url'=>$asset['url'],'asset'=>$asset]],1);assetCheck($generatedMaterial===$attached,'previously generated material reattaches only through authoritative slot identity');
        $untrusted=$page;$untrusted['data'][0]['content']['data'][0]['image']='https://unknown.invalid/image.png';assetCheck(R::attachMaterials($untrusted,[['slot_id'=>$slotId,'url'=>$untrusted['data'][0]['content']['data'][0]['image'],'asset'=>$wrongAsset]],1)===$untrusted,'forged material metadata never approves an unrelated image');
        Db::name('ai_consumption_log')->where('id',$consumptionId)->update(['user_id'=>9]);assetCheck(R::resolveUrls($attached,1)===[],'ordinary user generation never resolves through decoration refs');Db::name('ai_consumption_log')->where('id',$consumptionId)->update(['user_id'=>0]);
        Db::name('ai_app_task')->where('id',$taskId)->update(['business_id'=>$slotId+1]);assetCheck(R::resolveUrls($attached,1)===[],'wrong task/slot business link is refused');Db::name('ai_app_task')->where('id',$taskId)->update(['business_id'=>$slotId]);
        $fileId=(int)Db::name('tenant_file')->insertGetId(['tenant_id'=>1,'source'=>FileEnum::SOURCE_ADMIN,'type'=>FileEnum::IMAGE_TYPE,'uri'=>'uploads/fixture/admin.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']);
        $userFile=(int)Db::name('tenant_file')->insertGetId(['tenant_id'=>1,'source'=>FileEnum::SOURCE_USER,'source_id'=>0,'type'=>FileEnum::IMAGE_TYPE,'uri'=>'uploads/fixture/user.png','storage_scope'=>'tenant','storage_engine'=>'local']);
        $nested=C::create('section','section');$nested['children']=[C::create('image-showcase','showcase')];$nested['children'][0]['content']['data'][0]['image']='https://delivery-fixture.invalid/admin.png?signature=temporary';
        $materialPage=['data'=>[$nested],'meta'=>[C::pageMeta()['defaults']]];$materialPage['meta'][0]['content']['bg_image']='https://delivery-fixture.invalid/admin.png?signature=temporary';
        $materials=[['file_id'=>$fileId,'url'=>'https://delivery-fixture.invalid/admin.png?signature=temporary'],['file_id'=>$userFile,'url'=>'uploads/fixture/user.png']];
        $withFiles=R::attachMaterials($materialPage,$materials,1);$fileStable='/uploads/fixture/admin.png';
        assetCheck($withFiles['data'][0]['children'][0]['content']['data'][0]['image']===$fileStable&&$withFiles['meta'][0]['content']['bg_image']===$fileStable,'nested widgets and page meta receive exact file references');
        assetCheck(R::resolveUrls($withFiles,1)===[$fileStable=>$fileStable],'local delivery is root-relative even under CLI');
        $userPage=['data'=>[C::create('image-showcase','user')],'meta'=>[]];$userPage['data'][0]['content']['data'][0]['image']='uploads/fixture/user.png';
        assetCheck(R::attachMaterials($userPage,$materials,1)===$userPage,'USER source_id zero is not an approved shared file');
        $badFile=$withFiles;$badFile['meta'][0]['_decoration_asset_refs']['content.bg_image']['id']=$userFile;assetCheck(R::resolveUrls(['data'=>[],'meta'=>$badFile['meta']],1)===[],'forged USER file reference refuses signing');
        Db::name('tenant_file')->where('id',$fileId)->update(['delete_time'=>time()]);assetCheck(R::resolveUrls($withFiles,1)===[],'deleted material references fail closed');
        assetCheck($materialPage['data'][0]['children'][0]['content']['data'][0]['image']===$materials[0]['url'],'raw material input remains unchanged');
    } finally {Db::rollback();}
    echo "asset references: $checks checks passed (DB rolled back; no provider/storage calls)\n";
}
