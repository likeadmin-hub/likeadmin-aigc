<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use app\common\service\ai\StudioMediaInputService;
use InvalidArgumentException;
use think\facade\Db;

/** One user operation, separately durable and billed model stages. Never hides a resubmission. */
final class ProductVideoPipeline
{
    public static function prepare(int $tenant,int $user,array $p): array
    {
        $plan=ProductVideoJobs::prepare($tenant,$user,array_replace($p,['action'=>'plan']));
        $body=$plan['request']['snapshot'];
        if($body['mode']!=='advanced')throw new InvalidArgumentException('请在高级模式使用智能分析');
        $quote=$plan['quote'];$quote['notice']='识别商品与生成视频描述按文字模型实际用量结算';
        $quote['phased_costs']=[['action'=>'plan','model_name'=>$quote['model_name'],'approximate'=>true,'user_charge_points'=>null]];
        $request=['action'=>'analysis','plan_request'=>$plan['request'],'snapshot'=>$body,'project_revision'=>$plan['project']['revision'],'quote'=>$quote];
        return ['project'=>$plan['project'],'request'=>$request,'quote'=>$quote,'hash'=>ProductVideoPolicy::requestKey($request)];
    }
    public static function child(array $root,string $stage,array $request): array
    {
        if(!in_array($stage,['plan','chapters'],true))throw new InvalidArgumentException('分析阶段不可用');
        return Db::transaction(static function()use($root,$stage,$request){
            ProductVideoJobs::table()->where('id',$root['id'])->lock(true)->find();
            $link=ProductVideoService::table('job_stage')->where(['root_job_id'=>$root['id'],'stage'=>$stage])->find();
            if($link)return ProductVideoJobs::table()->where('id',$link['child_job_id'])->find();
            $id=ProductVideoJobs::table()->insertGetId(['tenant_id'=>$root['tenant_id'],'user_id'=>$root['user_id'],'project_id'=>$root['project_id'],'project_revision'=>$root['project_revision'],'action'=>$stage,
                'idempotency_key'=>'pipeline_'.$root['id'].'_'.$stage,'input_hash'=>ProductVideoPolicy::requestKey($request),'status'=>'queued','request_json'=>ProductVideoPolicy::encode($request),'receipt_json'=>'{}','result_json'=>'{}','next_run_at'=>time(),'create_time'=>time(),'update_time'=>time()]);
            ProductVideoService::table('job_stage')->insert(['tenant_id'=>$root['tenant_id'],'user_id'=>$root['user_id'],'root_job_id'=>$root['id'],'stage'=>$stage,'child_job_id'=>$id,'create_time'=>time()]);
            return ProductVideoJobs::table()->where('id',$id)->find();
        });
    }
    public static function chapters(array $root,array $result): void
    {
        $request=ProductVideoPolicy::json($root['request_json']);if(empty($request['chapter_model']))return;
        try {
            $tenant=(int)$root['tenant_id'];$user=(int)$root['user_id'];
            $uri=$result['videos'][0]['video_uri']??$result['videos'][0]['uri']??'';
            $video=StudioMediaInputService::validateVideo($tenant,$user,(string)$uri);
            $child=['action'=>'chapters','snapshot'=>$request['snapshot'],'project_revision'=>$root['project_revision'],'video_job_id'=>(int)$root['id'],
                'selection'=>['model_id'=>$request['chapter_model']['id']],'model_fingerprint'=>$request['chapter_model']['fingerprint'],
                'images'=>[],'video'=>$video,'system'=>ProductVideoPrompts::system('chapters'),
                'content'=>ProductVideoPolicy::encode(['actual_duration'=>$video['duration']]),'quote'=>['approximate'=>true,'user_charge_points'=>null]];
            self::child($root,'chapters',$child);
        } catch(\Throwable $e) {
            // A chapter failure never invalidates a playable, settled video.
            $result['chapter_error']='成片已保存，自动章节暂未完成，可单独重新分析';
            ProductVideoWorker::write((int)$root['id'],['result_json'=>ProductVideoPolicy::encode($result)]);
        }
    }
    public static function run(array $root): void
    {
        $request=ProductVideoPolicy::json($root['request_json']);$plan=self::child($root,'plan',$request['plan_request']);
        $jobs=[$plan];$result=['stages'=>[]];$status='running';
        if($plan['status']==='success'){
            $result=ProductVideoPolicy::json($plan['result_json'])+['stages'=>[]];$status='success';
            // Historical accepted image work may settle, but no new image stage can be created.
            $link=ProductVideoService::table('job_stage')->where(['root_job_id'=>$root['id'],'stage'=>'storyboard'])->find();
            if($link){
                $old=ProductVideoJobs::table()->where('id',$link['child_job_id'])->find();
                if($old){$jobs[]=$old;if(!empty(ProductVideoPolicy::json($old['result_json'])['images']))$result['images']=ProductVideoPolicy::json($old['result_json'])['images'];
                    if(in_array($old['status'],['queued','running','unknown'],true))$status='running';}
            }
        }elseif(in_array($plan['status'],['failed','canceled'],true))$status='failed';elseif($plan['status']==='unknown')$status='unknown';
        $points=0.;$pending=false;
        foreach($jobs as $job){$points+=(float)$job['user_charge_points'];$pending=$pending||!in_array($job['billing_status'],['settled','refunded','none'],true);
            $result['stages'][]=['action'=>$job['action'],'status'=>$job['status'],'billing_status'=>$job['billing_status'],'user_charge_points'=>(float)$job['user_charge_points']];}
        ProductVideoWorker::write((int)$root['id'],['status'=>$status,'result_json'=>ProductVideoPolicy::encode($result),'billing_status'=>$pending?'pending':'settled','user_charge_points'=>$points,
            'error'=>$status==='failed'?'智能分析未完成':($status==='unknown'?'模型受理或费用待核对':''),'next_run_at'=>time()+3]);
    }
}
