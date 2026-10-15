<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

use InvalidArgumentException;
use think\facade\Db;

/** Only server-owned current image references can enter the byte reader. */
final class DecorationAgentImageInspection
{
    public static function inspect(int $tenant,int $admin,array $page,array $input,array $op,int $runId=0): array
    {
        if(array_diff(array_keys($op),['type','widget_id','field'])||!is_string($op['widget_id']??null)||!is_string($op['field']??null))self::reject('图片检查只接受组件和图片字段');
        [$slot,$asset,$value]=self::resolve($tenant,$admin,$page,$input,$op);
        $snapshot=self::snapshot($tenant,$admin,$runId);
        $request=DecorationAgentService::decode($slot['request_json']);
        if(!DecorationAgentIconGeometry::eligible($request))return self::response($op,$slot,$value,['analysis_version'=>DecorationAgentIconGeometry::VERSION,'status'=>'unsupported','reason'=>'only_settled_solid_extracted_icons']);
        // Always read fresh bytes before cache reuse: an unchanged URI is not a checksum.
        $geometry=self::measure($tenant,$asset,$request);
        self::assertSnapshot($tenant,$admin,$runId,$snapshot);
        [$fresh,$freshAsset,$freshValue]=self::resolve($tenant,$admin,$page,$input,$op);
        if($fresh['request_json']!==$slot['request_json']||$fresh['result_json']!==$slot['result_json']||self::identity($freshAsset)!==self::identity($asset)||$freshValue!==$value)self::reject('图片在检查期间发生变化，请重新检查');
        $result=DecorationAgentService::decode($slot['result_json']);$updated=$result;
        foreach($updated['images']??[] as $i=>$image)if(is_array($image)&&self::identity($image)===self::identity($asset)){
            $cached=$image['visual_geometry']??null;
            if(is_array($cached)&&$cached===$geometry)$geometry=$cached;
            $updated['images'][$i]['visual_geometry']=$geometry;
        }
        if($updated!==$result){
            // Compare-and-swap only result metadata. Never replace newer settlement, retry or asset data.
            self::cache($slot,$updated);
        }
        self::assertSnapshot($tenant,$admin,$runId,$snapshot);
        return self::response($op,$slot,$value,$geometry);
    }

    /** Metadata failure must never change a successfully settled generation outcome. No DB locks or writes here. */
    public static function enrichCompletion(array $slot,array $result): array
    {
        $request=DecorationAgentService::decode($slot['request_json']??$slot['request']??[]);
        if(!DecorationAgentIconGeometry::eligible($request)||($result['status']??'')!=='success'||($result['billing_status']??'')!=='settled')return $result;
        try {
            $tenant=(int)$slot['tenant_id'];$fresh=Db::name('decorate_agent_image_slot')->where(['id'=>$slot['id'],'tenant_id'=>$tenant,'admin_id'=>$slot['admin_id']])->find();
            if(!$fresh||!in_array($fresh['status'],['running','submitting','success'],true)||$fresh['request_json']!==($slot['request_json']??DecorationAgentService::json($request)))return $result;
            $receipt=Db::name('ai_consumption_log')->where(['id'=>$fresh['consumption_id'],'tenant_id'=>$tenant,'user_id'=>0,'app_code'=>'system_default','protocol'=>'image_generate','run_status'=>'success','billing_status'=>'settled'])->find();
            if(!$receipt||!Db::name('ai_app_task')->where(['id'=>$receipt['app_task_id'],'tenant_id'=>$tenant,'user_id'=>0,'app_code'=>'system_default','status'=>'success','business_table'=>'decorate_agent_image_slot','business_id'=>$slot['id']])->find())return $result;
            $confirmed=DecorationAgentService::decode($receipt['response_summary'])['images']??[];
            foreach($result['images']??[] as $i=>$asset){
                if(!is_array($asset)||!self::validIdentity($asset))continue;
                foreach($confirmed as $proof)if(is_array($proof)&&self::identity($proof)===self::identity($asset)){
                    try{$result['images'][$i]['visual_geometry']=self::measure($tenant,$asset,$request);}
                    catch(\Throwable $e){$result['images'][$i]['visual_geometry']=['analysis_version'=>DecorationAgentIconGeometry::VERSION,'status'=>'uncertain','reason'=>'stored_image_unavailable'];}
                    break;
                }
            }
        } catch(\Throwable $e){/* Optional metadata cannot turn a paid success into an uncertain task. */}
        return $result;
    }

    private static function cache(array $slot,array $updated): void
    {
        $count=Db::name('decorate_agent_image_slot')->where(['id'=>$slot['id'],'tenant_id'=>$slot['tenant_id'],'admin_id'=>$slot['admin_id'],'status'=>$slot['status'],'consumption_id'=>$slot['consumption_id']])
            ->whereRaw('BINARY request_json = ?',[$slot['request_json']])->whereRaw('BINARY result_json = ?',[$slot['result_json']])
            ->update(['result_json'=>DecorationAgentService::json($updated)]);
        if($count!==1)self::reject('图片检查结果已过期，请重新检查');
    }

    private static function measure(int $tenant,array $asset,array $request): array
    {
        $read=DecorationAgentMediaService::readStoredImage($tenant,$asset);
        $identity=['request_hash'=>hash('sha256',DecorationAgentService::json($request)),'storage_identity_hash'=>hash('sha256',DecorationAgentService::json(self::identity($asset)))];
        $cached=$asset['visual_geometry']??null;
        if(is_array($cached)&&($cached['analysis_version']??null)===DecorationAgentIconGeometry::VERSION&&($cached['asset_sha256']??null)===$read['sha256']
            &&($cached['request_hash']??null)===$identity['request_hash']&&($cached['storage_identity_hash']??null)===$identity['storage_identity_hash']
            &&in_array($cached['status']??'',['measured','uncertain','unsupported'],true))return $cached;
        return DecorationAgentIconGeometry::measure($read['bytes'],$request)+$identity+['asset_sha256'=>$read['sha256']];
    }
    private static function resolve(int $tenant,int $admin,array $page,array $input,array $op): array
    {
        $nodes=DecorationAgentOperations::index($page['data']??[]);$node=$nodes[$op['widget_id']]??null;
        if(!$node)self::reject('图片组件不存在');
        if(($input['scope']??'')==='selected'){
            $selected=$nodes[$input['selected_id']??'']??null;
            if(!$selected||array_slice($node['path'],0,count($selected['path']))!==$selected['path'])self::reject('图片超出选中组件范围');
        }elseif(!in_array($input['scope']??'',['page','new_page'],true))self::reject('图片检查范围无效');
        $widget=$node['widget'];$value=DecorationAgentOperations::get($widget,$op['field']);$ref=$widget['_decoration_asset_refs'][$op['field']]??null;
        if(!is_string($value)||!is_array($ref)||($ref['kind']??'')!=='slot'||($ref['expected_value']??null)!==$value)self::reject('该图片不是当前有效的托管生成素材');
        $slot=Db::name('decorate_agent_image_slot')->where(['id'=>(int)($ref['id']??0),'tenant_id'=>$tenant,'admin_id'=>$admin])->find();
        if(!$slot)self::reject('图片不属于当前租户操作员');
        // The schema walker only accepts declared image leaves. Receipt/task/source proof is refreshed now.
        $probe=$widget;$probe['children']=[];$probe['_decoration_asset_refs']=[$op['field']=>$ref];
        $manifest=DecorationAgentAssetReferences::freezeMaterials(['data'=>[$probe],'meta'=>[]],$tenant,$input['sources']??[],$input['attachments']??[]);
        $valid=DecorationAgentAssetReferences::refreshMaterials($manifest,$tenant,$input['sources']??[],$input['attachments']??[]);
        $asset=null;
        foreach($valid as $entry)if(($entry['kind']??'')==='slot'&&(int)$entry['id']===(int)$slot['id']&&$entry['stable_uri']===$value)$asset=DecorationAgentAssetReferences::materialAsset($entry,$tenant);
        if(!$asset)self::reject('图片字段、来源授权或结算凭据已失效');
        return [$slot,$asset,$value];
    }
    private static function snapshot(int $tenant,int $admin,int $runId): ?array
    {
        if(!$runId)return null;
        $run=Db::name('decorate_agent_run')->where(['id'=>$runId,'tenant_id'=>$tenant,'admin_id'=>$admin])->find();
        if(!$run||!empty($run['cancel_requested'])||!in_array($run['status'],['queued','running'],true))self::reject('本轮已停止，图片检查已过期');
        $candidate=!empty($run['candidate_id'])?Db::name('decorate_agent_candidate')->where(['id'=>$run['candidate_id'],'tenant_id'=>$tenant,'admin_id'=>$admin])->find():null;
        if(!empty($run['candidate_id'])&&!$candidate)self::reject('方案不存在或不属于当前操作员');
        if($candidate&&in_array($candidate['status'],['discarded','rolled_back'],true))self::reject('方案已失效');
        return ['candidate_id'=>(int)$run['candidate_id'],'candidate'=>$candidate?array_intersect_key($candidate,array_flip(['id','revision','payload_json','status'])):null];
    }
    private static function assertSnapshot(int $tenant,int $admin,int $runId,?array $snapshot): void
    {if(self::snapshot($tenant,$admin,$runId)!==$snapshot)self::reject('方案在图片检查期间发生变化，请重新检查');}
    private static function identity(array $asset): array
    {return [ltrim((string)($asset['image_uri']??''),'/'),$asset['storage_scope']??null,$asset['storage_engine']??null,$asset['storage_domain']??''];}
    private static function validIdentity(array $asset): bool
    {return is_string($asset['image_uri']??null)&&preg_match('#^/?uploads/[a-zA-Z0-9_./-]+\.(?:png|jpe?g|webp)$#iD',$asset['image_uri'])===1&&!preg_match('#(?:^|/)\.\.?(/|$)#',$asset['image_uri'])&&in_array($asset['storage_scope']??'', ['tenant','platform'],true)&&in_array($asset['storage_engine']??'', ['local','qiniu','aliyun','qcloud'],true);}
    private static function response(array $op,array $slot,string $value,array $geometry): array
    {return ['widget_id'=>$op['widget_id'],'field'=>$op['field'],'slot_id'=>(int)$slot['id'],'expected_value'=>$value,'visual_geometry'=>$geometry];}
    private static function reject(string $message): never {throw new InvalidArgumentException($message);}
}
