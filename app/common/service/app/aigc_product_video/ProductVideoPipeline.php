<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use app\common\service\app\aigc_micro_video\{MicroVideoFrameCatalog,MicroVideoMedia};
use app\common\service\power\MarketImageModelRuntimeService as Image;
use InvalidArgumentException;
use think\facade\Db;

/** One user operation, separately durable and billed model stages. Never hides a resubmission. */
final class ProductVideoPipeline
{
    public static function prepare(int $tenant,int $user,array $p): array
    {
        $plan=ProductVideoJobs::prepare($tenant,$user,array_replace($p,['action'=>'plan']));
        $body=$plan['request']['snapshot'];if($body['mode']!=='advanced')throw new InvalidArgumentException('请在高级模式使用智能分析');$settings=ProductVideoService::settings($tenant);
        $wanted=$body['image_selection'];$id=(string)($wanted['model_id']??$settings['default_image_model_id']);
        $models=MicroVideoFrameCatalog::models($tenant,1);$selected=null;
        foreach($models as $m){if($id!==''&&(string)$m['id']!==$id)continue;
            foreach($m['specs'] as $spec){
                if(isset($wanted['quality'])&&$wanted['quality']!==$spec['quality'])continue;
                if(($wanted['ratio']??'16:9')!==$spec['ratio'])continue;
                $counts=array_values(array_filter($spec['reference_counts'],fn($n)=>$n>0&&$n<=min(10,count($body['images']))));
                if(!$counts)continue;$count=max($counts);$images=array_slice($plan['request']['images'],0,$count);
                $selection=['model_id'=>(string)$m['id'],'market_sku_id'=>(int)$spec['market_sku_id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio']];
                // The public runtime rejects meters whose unknown future prompt cannot be quoted safely.
                try{$quote=Image::quoteForFuturePrompt($tenant,$selection+['reference_images'=>MicroVideoMedia::visionInputs($images)],1);}catch(\Throwable){continue;}
                $selected=['selection'=>$selection,'images'=>$images,'label'=>$m['label'],'quote'=>array_intersect_key($quote,array_flip(['tenant_cost_points','user_charge_points','market_sku_id']))];break 2;
            }
        }
        if(!$selected)throw new InvalidArgumentException('请选择支持当前商品图和预估价的分镜图片模型');
        $quote=$plan['quote'];$quote['tenant_cost_points']=$selected['quote']['tenant_cost_points']+0.01;$quote['precheck_user_points']=$selected['quote']['user_charge_points']+0.01;$quote['notice']='商品识别与文字方案按实际用量结算；分镜图单独按确认规格生成一次';
        $quote['phased_costs']=[['action'=>'plan','model_name'=>$quote['model_name'],'approximate'=>true,'user_charge_points'=>null],['action'=>'storyboard','model_name'=>$selected['label'],'approximate'=>false,'user_charge_points'=>$selected['quote']['user_charge_points']]];
        $request=['action'=>'analysis','plan_request'=>$plan['request'],'image_stage'=>$selected,'snapshot'=>$body,'project_revision'=>$plan['project']['revision'],'quote'=>$quote];
        return ['project'=>$plan['project'],'request'=>$request,'quote'=>$quote,'hash'=>ProductVideoPolicy::requestKey($request)];
    }
    private static function child(array $root,string $stage,array $request): array
    {
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
    public static function run(array $root): void
    {
        $request=ProductVideoPolicy::json($root['request_json']);$plan=self::child($root,'plan',$request['plan_request']);
        $jobs=[$plan];$result=['stages'=>[]];$status='running';
        if($plan['status']==='success'){
            $data=ProductVideoPolicy::json($plan['result_json']);$result=$data+['stages'=>[]];
            $body=$request['snapshot'];if(!empty($data['product_info']))$body['product_info']=$data['product_info'];
            $body['plan']=['data'=>$data,'input_key'=>ProductVideoPolicy::planKey($body),'job_id'=>(int)$root['id']];
            $image=$request['image_stage'];$imageRequest=['action'=>'storyboard','snapshot'=>$body,'project_revision'=>$root['project_revision'],'selection'=>$image['selection'],'images'=>$image['images'],
                'prompt'=>'为商品视频绘制一张按顺序排列的镜头故事板。参考商品外观必须一致，每格对应一个镜头，避免新增标识、文字和水印。分镜：'.ProductVideoPolicy::encode($data),
                'plan_key'=>ProductVideoPolicy::hash($body['plan']),'quote'=>$image['quote']];
            $pictures=self::child($root,'storyboard',$imageRequest);$jobs[]=$pictures;
            if($pictures['status']==='success'){$result['images']=ProductVideoPolicy::json($pictures['result_json'])['images'];$status='success';}
            elseif(in_array($pictures['status'],['failed','canceled'],true)){$status='partial';$result['notice']='文字方案已完成，分镜图未完成。可采用方案后单独重试分镜图。';}
            elseif($pictures['status']==='unknown')$status='unknown';
        }elseif(in_array($plan['status'],['failed','canceled'],true))$status='failed';elseif($plan['status']==='unknown')$status='unknown';
        $points=0.;$pending=false;
        foreach($jobs as $job){$points+=(float)$job['user_charge_points'];$pending=$pending||!in_array($job['billing_status'],['settled','refunded','none'],true);
            $result['stages'][]=['action'=>$job['action'],'status'=>$job['status'],'billing_status'=>$job['billing_status'],'user_charge_points'=>(float)$job['user_charge_points']];}
        ProductVideoWorker::write((int)$root['id'],['status'=>$status,'result_json'=>ProductVideoPolicy::encode($result),'billing_status'=>$pending?'pending':'settled','user_charge_points'=>$points,
            'error'=>$status==='failed'?'智能分析未完成':($status==='unknown'?'模型受理或费用待核对':''),'next_run_at'=>time()+3]);
    }
}
