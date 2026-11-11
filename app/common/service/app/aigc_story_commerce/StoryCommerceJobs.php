<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_story_commerce;
use app\common\service\app\aigc_micro_video\MicroVideoCatalog;
use app\common\service\power\{MarketTextModelRuntimeService as Text,TextModelExecutionPolicy};
use app\common\service\point\PointService;
use app\common\service\ai\AiTaskJobService as Queue;
use InvalidArgumentException;
use think\facade\Db;
final class StoryCommerceJobs
{
    public static function table(){return StoryCommerceService::table('job');}
    public static function owned(int $t,int $u,int $id,bool $admin=false): array {$q=self::table()->where(['id'=>$id,'delete_time'=>0]);if(!$admin||$t>0)$q->where('tenant_id',$t);if(!$admin)$q->where('user_id',$u);$r=$q->find();if(!$r)throw new InvalidArgumentException('任务不存在或无权访问');return $r;}
    public static function dto(array $r,bool $admin=false): array {
        $req=StoryCommercePolicy::json($r['request_json']);$t=(int)$r['tenant_id'];$b=$req['snapshot']??[];
        $v=['id'=>(int)$r['id'],'project_id'=>(int)$r['project_id'],'project_revision'=>(int)$r['project_revision'],'action'=>$r['action'],'status'=>$r['status'],'billing_status'=>$r['billing_status'],'user_charge_points'=>(float)$r['user_charge_points'],
            'configuration'=>StoryCommerceService::publicBody($b,$t),'result'=>StoryCommerceService::publicBody(StoryCommercePolicy::json($r['result_json']),$t),
            'error'=>$r['error']===''?'':($r['status']==='unknown'?'任务受理或费用待核对，请勿重复提交':'创作未完成，请检查配置、素材或余额后重试'),'create_time'=>(int)$r['create_time']];
        if($admin){$c=!empty($r['consumption_id'])?Db::name('ai_consumption_log')->where(['id'=>$r['consumption_id'],'tenant_id'=>$t,'user_id'=>$r['user_id'],'app_code'=>StoryCommercePolicy::APP])->find():[];$v+=['tenant_id'=>$t,'user_id'=>(int)$r['user_id'],'app_task_id'=>(int)$r['app_task_id'],'consumption_id'=>(int)$r['consumption_id'],'upstream_task_id'=>$c['upstream_task_id']??'','model_name'=>$req['quote']['model_name']??''];}return $v;
    }
    public static function lists(int $t,int $u,array $p,bool $admin=false): array {
        $q=self::table()->where('delete_time',0);if(!$admin||$t>0)$q->where('tenant_id',$t);if(!$admin)$q->where('user_id',$u);
        $group=$p['group']??'video';if(!in_array($group,['video','helper'],true))throw new InvalidArgumentException('任务分类无效');$q->where('action',$group==='video'?'video':'analysis');
        if($admin&&$t===0&&!empty($p['tenant_id']))$q->where('tenant_id',(int)$p['tenant_id']);if(!empty($p['user_id'])&&$admin)$q->where('user_id',(int)$p['user_id']);
        if(($p['status']??'')!==''){if(!in_array($p['status'],['queued','running','unknown','success','failed','canceled'],true))throw new InvalidArgumentException('任务状态无效');$q->where('status',$p['status']);}
        if(!empty($p['project_id']))$q->where('project_id',(int)$p['project_id']);if(!empty($p['keyword'])&&ctype_digit((string)$p['keyword']))$q->where('id',(int)$p['keyword']);
        foreach(['start_time'=>'>=','end_time'=>'<='] as $k=>$op)if(!empty($p[$k]))$q->where('create_time',$op,(int)$p[$k]);$count=(clone $q)->count();
        return ['lists'=>array_map(static fn($r)=>self::dto($r,$admin),$q->order('id','desc')->page(max(1,(int)($p['page_no']??1)),max(1,min(50,(int)($p['page_size']??20))))->select()->toArray()),'count'=>$count];
    }
    public static function prepare(int $t,int $u,array $p): array {
        StoryCommerceService::available($t,$u);$project=StoryCommerceService::owned($t,$u,(int)($p['project_id']??0));if((int)$project['revision']!==(int)($p['revision']??0))throw new InvalidArgumentException('草稿已修改，请重新报价');
        $action=(string)($p['action']??'');if(!in_array($action,['analysis','video'],true))throw new InvalidArgumentException('操作无效');
        $b=StoryCommerceService::normalize($t,$u,StoryCommercePolicy::json($project['body_json']));$selection=StoryCommerceCatalog::selection($t,$b);
        $r=['action'=>$action,'snapshot'=>$b,'project_revision'=>(int)$project['revision'],'images'=>$b['images']];
        if($action==='video'){
            $prompt=StoryCommercePolicy::prompt($b);$assets=[];foreach($b['images'] as $i=>$image)$assets[]=array_replace($image,['type'=>'image','role'=>$selection['generation_method']==='image_to_video'?'first_frame_image':'reference_image']);
            $r+=['selection'=>$selection,'runtime'=>MicroVideoCatalog::runtime($selection),'prompt'=>$prompt,'reference_images'=>array_column($assets,'uri'),'reference_assets'=>$assets,'selected_mentions'=>array_map(static fn($a)=>['name'=>$a['mention_name'],'type'=>'image','reference_slot_id'=>$a['reference_slot_id'],'uri'=>$a['uri']],$assets)];
            $quote=$r['runtime']::quote($t,$selection+['prompt'=>$prompt,'reference_images'=>$r['reference_images'],'reference_assets'=>$assets]);$quote['approximate']=false;$r['selection']['market_sku_id']=(int)$quote['market_sku_id'];
        }else{
            $id=(string)StoryCommerceService::settings($t)['planning_model_id'];$options=Text::mediaOptions($t,false);if($id==='')$id=(string)($options[0]['id']??'');if(!$id||!in_array($id,array_column($options,'id'),true))throw new InvalidArgumentException('暂无已上架的图像理解模型，请联系管理员');
            $m=Text::resolveModel($t,$id,true);$r+=['selection'=>['model_id'=>$id],'model_fingerprint'=>TextModelExecutionPolicy::fingerprint($m),'system'=>StoryCommercePrompts::system(),
                'content'=>StoryCommercePolicy::encode(['product_info'=>$b['product_info'],'requirements'=>$b['requirements'],'genre'=>$b['genre_snapshot'],'visual_style'=>$b['style_snapshot'],'duration'=>(int)$selection['duration'],'ratio'=>$selection['ratio'],'language'=>$b['language'],'market_region'=>$b['market_region'],'audio'=>$b['audio'],'image_slots'=>array_column($b['images'],'reference_slot_id')])];
            $quote=['approximate'=>true,'user_charge_points'=>null,'tenant_cost_points'=>0,'model_name'=>$m['name'],'input'=>$m['input'],'output'=>$m['output'],'notice'=>'剧情分析按实际输入和输出用量结算'];
        }
        $r['quote']=$quote;$stable=$r;unset($stable['images']);$clean=static function($x)use(&$clean){if(!is_array($x))return $x;unset($x['url'],$x['video_url'],$x['image_url']);foreach($x as $k=>$v)if(is_array($v))$x[$k]=$clean($v);return $x;};
        return ['project'=>$project,'request'=>$r,'quote'=>$quote,'hash'=>StoryCommercePolicy::hash($clean($stable))];
    }
    public static function estimate(int $t,int $u,array $p): array {$i=self::prepare($t,$u,$p);$token=bin2hex(random_bytes(24));$expires=time()+600;StoryCommerceService::table('quote')->insert(['tenant_id'=>$t,'user_id'=>$u,'project_id'=>$i['project']['id'],'token'=>$token,'input_hash'=>$i['hash'],'request_json'=>StoryCommercePolicy::encode($i['request']),'expires_at'=>$expires,'create_time'=>time()]);return ['quote_token'=>$token,'expires_at'=>$expires]+$i['quote'];}
    public static function create(int $t,int $u,array $p): array {
        $key=(string)($p['idempotency_key']??'');if(!preg_match('/^[a-zA-Z0-9_-]{16,100}$/D',$key))throw new InvalidArgumentException('提交标识无效');
        $old=self::table()->where(['tenant_id'=>$t,'user_id'=>$u,'idempotency_key'=>$key])->find();if($old){if((int)$old['project_id']!==(int)($p['project_id']??0)||(int)$old['project_revision']!==(int)($p['revision']??0)||$old['action']!==($p['action']??''))throw new InvalidArgumentException('提交标识已用于其他配置');return self::dto($old);}
        $i=self::prepare($t,$u,$p);$q=$i['quote'];PointService::assertCanConsumeAmounts($t,$u,(float)($q['tenant_cost_points']?:0.01),(float)($q['precheck_user_points']??$q['user_charge_points']??0.01));
        return Db::transaction(static function()use($t,$u,$p,$key,$i){$project=StoryCommerceService::owned($t,$u,(int)$p['project_id'],true);if((int)$project['revision']!==(int)$p['revision'])throw new InvalidArgumentException('草稿已修改');
            $old=self::table()->where(['tenant_id'=>$t,'user_id'=>$u,'idempotency_key'=>$key])->find();if($old)return self::dto($old);
            $quote=StoryCommerceService::table('quote')->where(['tenant_id'=>$t,'user_id'=>$u,'project_id'=>$project['id'],'token'=>(string)($p['quote_token']??''),'input_hash'=>$i['hash']])->lock(true)->find();if(!$quote||(int)$quote['expires_at']<time()||$quote['consumed_job_id'])throw new InvalidArgumentException('报价已变化、过期或使用，请重新核对');
            $id=self::table()->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'project_id'=>$project['id'],'project_revision'=>$project['revision'],'action'=>$i['request']['action'],'idempotency_key'=>$key,'input_hash'=>$i['hash'],'status'=>'queued','request_json'=>StoryCommercePolicy::encode($i['request']),'receipt_json'=>'{}','result_json'=>'{}','next_run_at'=>time(),'create_time'=>time(),'update_time'=>time()]);
            StoryCommerceService::table('quote')->where('id',$quote['id'])->update(['consumed_job_id'=>$id]);Queue::enqueue(Queue::TYPE_STORY_COMMERCE,0,0,['story_job_id'=>$id,'tenant_id'=>$t,'user_id'=>$u],0,false,'story_commerce:'.$id);return self::dto(self::owned($t,$u,$id));});
    }
    public static function recover(int $t,int $u,int $id): array {$r=self::owned($t,$u,$id);StoryCommerceWorker::reconcile($r);return self::dto(self::owned($t,$u,$id));}
    public static function adminDelete(int $scope,int $id): void {$r=self::owned($scope,0,$id,true);if($r['action']!=='video')throw new InvalidArgumentException('仅管理最终视频作品');self::delete((int)$r['tenant_id'],(int)$r['user_id'],$id);}
    public static function delete(int $t,int $u,int $id): void {Db::transaction(static function()use($t,$u,$id){self::owned($t,$u,$id);$r=self::table()->where('id',$id)->lock(true)->find();if(in_array($r['status'],['queued','running','unknown'],true)||!in_array($r['billing_status'],['none','settled','refunded'],true))throw new InvalidArgumentException('任务或费用待核对，暂不能删除');self::table()->where('id',$id)->update(['delete_time'=>time(),'update_time'=>time()]);});}
}
