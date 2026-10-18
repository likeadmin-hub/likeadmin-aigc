<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\app\AppAccessService;
use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\FileService;
use app\common\enum\FileEnum;
use think\facade\Db;
use InvalidArgumentException;

/** Thin workbench adapters around existing execution/material authorities. */
final class MicroVideoWorkbench
{
    public static function capabilities(int $tenant): array
    {
        self::seed($tenant);$config=MicroVideoSettings::detail($tenant);$text=array_map(fn($m)=>['id'=>(string)$m['id'],'name'=>(string)$m['name']],MarketTextModelRuntimeService::textOptions($tenant));$cross=[];
        foreach(['aigc_short_drama'=>'短剧创作','image_human'=>'数字人','aigc_image'=>'商品图片','aigc_fitting'=>'AI服饰'] as $code=>$label)$cross[]=['app_code'=>$code,'label'=>$label,'available'=>AppAccessService::tenantCanUse($tenant,$code,false)];
        return ['config'=>$config,'text_models'=>$text,'image_models'=>array_values(array_filter(MicroVideoFrameCatalog::models($tenant),fn($m)=>in_array($m['id'],(array)MicroVideoSettings::get($tenant)['frame_model_ids'],true))),'cross_apps'=>$cross];
    }
    /** First-use catalog DML only; dedicated tenant file lock prevents duplicates even without config. */
    public static function seed(int $tenant): void
    {MicroVideoWorkbenchTemplates::seed($tenant,MicroVideoSettings::directions($tenant,true));}
    public static function assets(int $tenant,int $user,array $p): array
    {
        $q=Db::name('tenant_file')->where(['tenant_id'=>$tenant,'source'=>FileEnum::SOURCE_USER,'source_id'=>$user,'type'=>FileEnum::IMAGE_TYPE])->whereRaw('(delete_time IS NULL OR delete_time=0)');if(!empty($p['keyword']))$q->whereLike('name','%'.mb_substr((string)$p['keyword'],0,100).'%');$page=max(1,(int)($p['page_no']??1));$size=min(100,max(1,(int)($p['page_size']??20)));$count=(clone $q)->count();$rows=$q->order('id','desc')->page($page,$size)->select()->toArray();$images=[];
        foreach($rows as $r){try{$images[]=MicroVideoMedia::validateImages($tenant,$user,[['uri'=>$r['uri'],'name'=>$r['name']]],1)[0]+['id'=>(int)$r['id']];}catch(\Throwable){/* Historical files lacking valid storage cannot become execution references. */}}
        return ['lists'=>array_map([MicroVideoWorkbenchRecords::class,'publicAsset'],$images),'count'=>(int)$count,'page_no'=>$page,'page_size'=>$size];
    }
    public static function execution(int $tenant,int $user,array $p): array
    {
        $key=MicroVideoWorkbenchPolicy::key((string)($p['idempotency_key']??''));
        return Db::transaction(function()use($tenant,$user,$p,$key){$project=MicroVideoWorkbenchRecords::owned($tenant,$user,'project',(int)($p['project_id']??0),false,true,true);$prior=MicroVideoWorkbenchRecords::table('execution')->where(['tenant_id'=>$tenant,'user_id'=>$user,'idempotency_key'=>$key])->find();
            if($prior){if((int)$prior['project_id']!==(int)$project['id']||(int)$prior['project_revision']!==(int)($p['project_revision']??0))throw new InvalidArgumentException('请求标识已用于其他项目版本');return ['project_id'=>(int)$project['id'],'project_revision'=>(int)$prior['project_revision'],'task'=>MicroVideoService::detail($tenant,$user,(int)$prior['task_id'])];}
            MicroVideoWorkbenchPolicy::revision($project,(int)($p['project_revision']??0));$body=MicroVideoStore::json($project['body_json']);[$segments,$images]=self::executionReferences($body);if(!$segments)throw new InvalidArgumentException('请先保存含完整镜头时间轴的分镜剧本');
            $brief=(array)($body['brief']??[]);$params=['model_id'=>$body['model_id']??MicroVideoSettings::get($tenant)['default_model_id'],'quality'=>$body['quality']??MicroVideoSettings::get($tenant)['default_quality'],'ratio'=>$body['ratio']??$body['template_snapshot']['body']['ratio']??'9:16','target_duration'=>$body['target_duration']??$brief['target_duration']??30,'mode'=>'prompt','theme'=>$project['title'],'prompt'=>(string)($body['script']['text']??''),'images'=>$images,'direction_id'=>(int)($body['template_snapshot']['body']['direction_id']??0),'idempotency_key'=>hash('sha256',$key),'continuity'=>(string)($body['script']['continuity']??$body['template_snapshot']['body']['reference_rules']??MicroVideoSettings::defaults()['continuity_template'])];
            $snapshot=['project_id'=>(int)$project['id'],'project_revision'=>(int)$project['revision'],'body'=>$body];$task=MicroVideoService::importWorkbenchPlan($tenant,$user,$params,$segments,$snapshot);MicroVideoWorkbenchRecords::table('execution')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$project['id'],'project_revision'=>$project['revision'],'idempotency_key'=>$key,'task_id'=>$task['id'],'snapshot_json'=>MicroVideoStore::encode($snapshot),'create_time'=>time()]);
            return ['project_id'=>(int)$project['id'],'project_revision'=>(int)$project['revision'],'task'=>$task];
        });
    }
    /** Resolve every explicit role/product reference before existing model-specific validation. */
    public static function executionReferences(array $body): array
    {
        if(!empty($body['script_stale']))throw new InvalidArgumentException('商品或模板已更新，请确认并重新保存剧本后再执行');
        $segments=(array)($body['script']['segments']??[]);$scenes=[];foreach((array)($body['scenes']??[]) as $s)$scenes[(int)($s['sort']??0)]=$s;$roles=array_column((array)($body['roles']??[]),null,'role_id');$all=[];$out=[];
        foreach(array_values($segments) as $i=>$segment){$refs=(array)($body['images']??[]);$scene=$scenes[$i+1]??null;
            if($scene){if(empty($scene['confirmed'])||($scene['status']??'')==='stale')throw new InvalidArgumentException('请确认每段实际参考图；过期分镜需重新检查');$refs=(array)($scene['images']??[]);
                if(($scene['mode']??'reference')!=='scene'||!$refs){foreach((array)($scene['role_ids']??[]) as $rid){$r=$roles[$rid]??null;if(!$r)throw new InvalidArgumentException('分镜角色引用无效');if(!empty($r['fixed_identity'])&&empty($r['images']))throw new InvalidArgumentException('固定身份角色尚未绑定参考图');$refs=array_merge($refs,(array)($r['images']??[]));}if(!empty($scene['product_visible'])){$product=(array)($body['product_snapshot']['body']['images']??[]);if(!$product)throw new InvalidArgumentException('商品分镜缺少真实商品图片');$refs=array_merge($refs,$product);}}
            }
            $refs=MicroVideoWorkbenchJobs::uniqueImages($refs);if(!$refs)throw new InvalidArgumentException('每段至少需要一张已授权参考图，请绑定角色、商品或分镜图片');$indices=[];foreach($refs as $r){$uri=(string)($r['uri']??'');$index=null;foreach($all as $j=>$existing)if(($existing['uri']??'')===$uri)$index=$j;if($index===null){$index=count($all);$all[]=$r;}$indices[]=$index;}
            $segment['sort']=$i+1;$segment['image_indices']=$indices;$out[]=$segment;
        }return [$out,$all];
    }
    public static function variants(int $tenant,int $user,array $p): array
    {
        $project=MicroVideoWorkbenchRecords::owned($tenant,$user,'project',(int)($p['project_id']??0),false,true);MicroVideoWorkbenchPolicy::revision($project,(int)($p['project_revision']??0));$templates=array_values(array_unique(array_map('intval',(array)($p['template_ids']??[]))));$products=array_values(array_unique(array_map('intval',(array)($p['product_ids']??[]))));if(!$templates&&!$products)throw new InvalidArgumentException('请至少选择一个模板或商品');if(max(1,count($templates))*max(1,count($products))>20)throw new InvalidArgumentException('每批最多20个草稿变体');
        return Db::transaction(function()use($tenant,$user,$project,$templates,$products){$out=[];$base=MicroVideoStore::json($project['body_json']);foreach($templates?:[0] as $tid)foreach($products?:[0] as $pid){$body=$base;unset($body['task_id']);$body['stage']='draft';$body['variant_source']=['id'=>(int)$project['id'],'revision'=>(int)$project['revision']];if($tid){$t=MicroVideoWorkbenchRecords::owned($tenant,$user,'template',$tid);$body['template']=['id'=>$tid,'revision'=>(int)$t['revision']];}if($pid){$r=MicroVideoWorkbenchRecords::owned($tenant,$user,'product',$pid);$body['product']=['id'=>$pid,'revision'=>(int)$r['revision']];}foreach($body['scenes']??[] as $i=>$s){$body['scenes'][$i]['confirmed']=false;$body['scenes'][$i]['status']='stale';}$out[]=MicroVideoWorkbenchRecords::save($tenant,$user,['kind'=>'project','title'=>mb_substr($project['title'].' 变体 '.(count($out)+1),0,120),'body'=>$body]);}return ['lists'=>$out];});
    }
    public static function usage(int $tenant): array
    {$rows=MicroVideoWorkbenchJobs::table()->where('tenant_id',$tenant)->field('action,COUNT(*) AS jobs,SUM(user_charge_points) AS user_charge_points')->group('action')->select()->toArray();return ['lists'=>$rows,'count'=>count($rows)];}
}
