<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use app\common\service\ai\StudioMediaInputService;
use app\common\service\power\{MarketTextModelRuntimeService as Text,MarketImageModelRuntimeService as LegacyImageRuntime,TextModelExecutionPolicy,TextSettlementPending};
use RuntimeException;
use think\facade\Db;

final class ProductVideoWorker
{
    /** Callback handoff only: preserve the stage worker's lease and never call a provider here. */
    public static function wake(array $consumption,int $id): bool
    {
        $row=ProductVideoJobs::table()->where('id',$id)->find();
        if(!$row||$row['delete_time'])return true;
        if(($consumption['app_code']??'')!==ProductVideoPolicy::APP||(int)$row['tenant_id']!==(int)$consumption['tenant_id']||(int)$row['user_id']!==(int)$consumption['user_id'])throw new RuntimeException('商品视频任务归属不一致');
        if((int)$row['consumption_id']!==(int)$consumption['id'])return false;
        if(in_array($row['status'],['queued','running','unknown'],true))ProductVideoJobs::table()->where(['id'=>$id,'consumption_id'=>$consumption['id'],'status'=>$row['status']])->update(['next_run_at'=>time(),'update_time'=>time()]);
        return true;
    }
    public static function tick(int $id=0): int
    {
        $q=ProductVideoJobs::table()->whereIn('status',['queued','running','unknown'])->where('delete_time',0)->where('next_run_at','<=',time())->where('lease_until','<',time());if($id)$q->where('id',$id);
        foreach($q->order('id')->limit(10)->select()->toArray() as $row){
            $lock=fopen(runtime_path().'product_video_'.$row['id'].'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);continue;}
            $lease=bin2hex(random_bytes(16));
            try{if(!ProductVideoJobs::table()->where('id',$row['id'])->where('lease_until','<',time())->update(['lease_until'=>time()+600,'lease_token'=>$lease]))continue;
                self::run(ProductVideoJobs::table()->where('id',$row['id'])->find());return 1;
            }finally{ProductVideoJobs::table()->where(['id'=>$row['id'],'lease_token'=>$lease])->update(['lease_until'=>0,'lease_token'=>'']);flock($lock,LOCK_UN);fclose($lock);}
        }return 0;
    }
    public static function write(int $id,array $data): void
    {ProductVideoJobs::table()->where('id',$id)->update($data+['update_time'=>time()]);}
    public static function reconcile(array $row): void
    {
        $lock=fopen(runtime_path().'product_video_'.$row['id'].'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return;}
        try{if($row['status']==='queued'&&!(int)$row['consumption_id'])return;self::run($row,true);}finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function consumption(array $row): ?array
    {
        $q=Db::name('ai_consumption_log')->where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'app_code'=>ProductVideoPolicy::APP]);
        if((int)$row['consumption_id'])return $q->where('id',$row['consumption_id'])->find();
        $task=Db::name('ai_app_task')->where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'app_code'=>ProductVideoPolicy::APP,'business_table'=>'aigc_product_video_job','business_id'=>$row['id']])->order('id','desc')->find();
        if(!$task)return null;$c=$q->where('app_task_id',$task['id'])->order('id','desc')->find();if($c)self::write((int)$row['id'],['app_task_id'=>$task['id'],'consumption_id'=>$c['id']]);return $c;
    }
    private static function run(array $row,bool $reconcile=false): void
    {
        if(in_array($row['status'],['success','canceled'],true))return;
        $id=(int)$row['id'];$tenant=(int)$row['tenant_id'];$user=(int)$row['user_id'];$r=ProductVideoPolicy::json($row['request_json']);$action=$row['action'];
        $media=$action==='video';$runtime=$r['runtime']??'';
        try{
            if($action==='storyboard'){self::retiredImage($row);return;}
            if($action==='analysis'){ProductVideoPipeline::run($row);return;}
            $c=self::consumption($row);$receipt=ProductVideoPolicy::json($row['receipt_json']);
            if($receipt){if($c&&$c['billing_status']==='settled'){self::finish($row,$receipt,$c);return;}}
            if($c){
                self::write($id,['billing_status'=>$c['billing_status'],'user_charge_points'=>(float)$c['actual_user_price']]);
                if(in_array($c['run_status'],['failed','canceled'],true))throw new RuntimeException('共享模型运行时已确认失败');
                if(!$media){self::write($id,['status'=>'unknown','error'=>'已保留任务，等待实际用量核对，不会自动重发','next_run_at'=>time()+30]);return;}
                if($c['run_status']==='reserved'&&$reconcile)return;
                $payload=self::mediaRequest($action,$r);
                $result=$c['run_status']==='reserved'?$runtime::submit((int)$c['id'],$payload):$runtime::refresh((int)$c['id']);
                self::mediaResult($row,$result);return;
            }
            if($reconcile||$row['status']!=='queued'){self::write($id,['status'=>'unknown','error'=>'受理记录待核对，未自动重复提交','next_run_at'=>time()+60]);return;}
            ProductVideoService::available($tenant,$user);
            self::write($id,['status'=>'running','error'=>'']);
            if($media){
                $payload=self::mediaRequest($action,$r);$fresh=$runtime::quote($tenant,$r['selection']+$payload);
                foreach(['tenant_cost_points','user_charge_points','market_sku_id'] as $field)if((string)($fresh[$field]??'')!==(string)($r['quote'][$field]??''))throw new RuntimeException('模型报价已变化，请重新确认');
                $reserve=Db::transaction(static function()use($id,$tenant,$user,$r,$action,$payload,$runtime){
                    $job=ProductVideoJobs::table()->where('id',$id)->lock(true)->find();if($job['consumption_id'])return ['consumption_id'=>(int)$job['consumption_id']];
                    $value=$runtime::reserve($tenant,$user,ProductVideoPolicy::APP,'video','aigc_product_video_job',(string)$id,$r['selection'],$payload);
                    self::write($id,['consumption_id'=>$value['consumption_id'],'app_task_id'=>$value['app_task_id']]);return $value;
                });
                self::mediaResult($row,$runtime::submit((int)$reserve['consumption_id'],$payload));return;
            }
            $model=Text::resolveModel($tenant,$r['selection']['model_id'],true);
            if(!hash_equals($r['model_fingerprint'],TextModelExecutionPolicy::fingerprint($model)))throw new RuntimeException('分析模型或费率已变化，请重新确认');
            $content=[['type'=>'text','text'=>$r['content']]];
            foreach(StudioMediaInputService::visionInputs($r['images']) as $url)$content[]=['type'=>'image_url','image_url'=>['url'=>$url]];
            if($r['video'])$content[]=['type'=>'video_url','video_url'=>['url'=>StudioMediaInputService::videoInput($r['video'])]];
            $result=Text::generate($tenant,$user,['content'=>$r['content'],'messages'=>[['role'=>'user','content'=>$content]],'system_prompt'=>$r['system'],
                'model_id'=>$r['selection']['model_id'],'requires_vision'=>true,'source_app_code'=>ProductVideoPolicy::APP,'business_table'=>'aigc_product_video_job','business_id'=>$id,
                'action_code'=>$action,'_disable_model_fallback'=>true,'_disable_transient_retry'=>true,'_disable_compatibility_retry'=>true,'_require_final_content'=>true,
                '_product_video_preserve_uncertain'=>true,'request_timeout_seconds'=>180,'model_config'=>['max_tokens'=>6000,'reasoning_effort'=>'low'],
                '_result_validator'=>static function(array $value)use($id){self::write($id,['receipt_json'=>ProductVideoPolicy::encode($value)]);}],
                static function($event,$data)use($id){if($event==='app_task')self::write($id,['app_task_id'=>(int)$data['app_task_id'],'consumption_id'=>(int)$data['consumption_id']]);});
            self::write($id,['receipt_json'=>ProductVideoPolicy::encode($result)]);$c=self::consumption(ProductVideoJobs::table()->where('id',$id)->find());
            if(!$c||$c['billing_status']!=='settled'){self::write($id,['status'=>'unknown','error'=>'已保留结果，等待实际用量结算','next_run_at'=>time()+30]);return;}
            self::finish($row,$result,$c);
        }catch(\Throwable $e){
            if($action==='analysis'){self::write($id,['status'=>'unknown','error'=>'分析阶段状态待核对，请保留原任务','next_run_at'=>time()+30]);return;}
            if($e instanceof TextSettlementPending)self::write($id,['receipt_json'=>ProductVideoPolicy::encode($e->result)]);
            $fresh=ProductVideoJobs::table()->where('id',$id)->find();$c=self::consumption($fresh);$known=!$c||in_array($c['run_status'],['failed','canceled'],true)||$c['billing_status']==='settled';
            self::write($id,['status'=>$known?'failed':'unknown','error'=>mb_substr($e->getMessage(),0,1000),'billing_status'=>$c['billing_status']??'none','next_run_at'=>time()+60,'user_charge_points'=>(float)($c['actual_user_price']??0)]);
            \think\facade\Log::warning('Product video job '.$id.': '.get_class($e));
        }
    }
    /** Settlement-only compatibility. Never reserve, submit or retry an image request. */
    private static function retiredImage(array $row): void
    {
        $id=(int)$row['id'];$c=self::consumption($row);
        if(!$c){self::write($id,['status'=>'canceled','billing_status'=>'none','user_charge_points'=>0,'error'=>'该功能已移除']);return;}
        Db::transaction(static function()use($row,$c){
            $locked=Db::name('ai_consumption_log')->where(['id'=>$c['id'],'tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'app_code'=>ProductVideoPolicy::APP])->lock(true)->find();
            if(!$locked||!empty($locked['upstream_task_id'])||!empty($locked['upstream_request_id']))return;
            if($locked['run_status']==='reserved')LegacyImageRuntime::cancel((int)$locked['id']);
            elseif($locked['run_status']==='retrying_submit')LegacyImageRuntime::fail((int)$locked['id'],'该功能已移除，未提交的任务已退回','feature_removed');
        });
        $c=self::consumption($row);
        if(in_array($c['run_status'],['failed','canceled'],true)){
            self::write($id,['status'=>$c['run_status'],'billing_status'=>$c['billing_status'],'user_charge_points'=>(float)$c['actual_user_price'],'error'=>'该功能已移除']);return;
        }
        self::mediaResult($row,LegacyImageRuntime::refresh((int)$c['id']));
    }
    private static function mediaRequest(string $action,array $r): array
    {return array_intersect_key($r,array_flip(['prompt','reference_images','reference_assets','selected_mentions']))+$r['selection'];}
    private static function mediaResult(array $row,array $result): void
    {
        $id=(int)$row['id'];$fresh=ProductVideoJobs::table()->where('id',$id)->find();$c=self::consumption($fresh);
        if(!empty($result['images'])||!empty($result['videos'])){self::write($id,['receipt_json'=>ProductVideoPolicy::encode($result)]);if($c&&$c['billing_status']==='settled'){self::finish($row,$result,$c);return;}}
        $uncertain=$c&&in_array($c['run_status'],['submitting','pending_confirmation'],true)&&empty($c['upstream_task_id']);
        self::write($id,['status'=>$uncertain?'unknown':'running','error'=>$uncertain?'模型受理状态待核对，请勿重复提交':'','next_run_at'=>time()+15,'billing_status'=>$c['billing_status']??'pending']);
    }
    public static function parse(array $row,array $receipt): array
    {
        $r=ProductVideoPolicy::json($row['request_json']);$action=$row['action'];
        if($action==='storyboard'){
            $refs=[];foreach($receipt['images']??[] as $image)$refs[]=['uri'=>$image['image_uri']??$image['uri']??''];
            if(!$refs)throw new RuntimeException('分镜图片尚未入库');return ['images'=>StudioMediaInputService::validateImages((int)$row['tenant_id'],(int)$row['user_id'],$refs,1)];
        }
        if($action==='video'){if(empty($receipt['videos']))throw new RuntimeException('视频结果尚未入库');return ['videos'=>$receipt['videos']];}
        $text=trim((string)($receipt['content']??''));$text=preg_replace('/^```(?:json)?\s*|\s*```$/u','',$text);$data=json_decode($text,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data)||array_is_list($data))throw new RuntimeException('分析结果格式不正确');
        if(in_array($action,['write','optimize'],true)){$value=ProductVideoPolicy::text($data['video_prompt']??'',12000);if($value==='')throw new RuntimeException('未返回有效视频描述');return ['video_prompt'=>$value];}
        if($action==='product'){$value=ProductVideoAnalysis::productInfo($data);if($value==='')throw new RuntimeException('未识别到商品信息');return ['product_info'=>$value];}
        if($action==='plan')return ProductVideoPolicy::plan($data,(int)$r['duration'],(array)($r['snapshot']??[]));
        $duration=(float)$r['video']['duration'];$shots=[];$last=0.;
        foreach((array)($data['shots']??[]) as $shot){$start=$shot['start']??null;$end=$shot['end']??null;if(!is_numeric($start)||!is_numeric($end)||!is_finite((float)$start)||!is_finite((float)$end)||(float)$start<$last-0.01||(float)$end<=(float)$start||(float)$end>$duration+0.1)throw new RuntimeException('参考视频或成片章节时间不正确');$shots[]=['start'=>(float)$start,'end'=>(float)$end,'description'=>ProductVideoPolicy::text($shot['description']??'',1800)];$last=(float)$end;}
        if(!$shots||count($shots)>80)throw new RuntimeException('尚未识别到有效的视频镜头');
        return ['summary'=>ProductVideoPolicy::text($data['summary']??'',1500),'style'=>ProductVideoPolicy::text($data['style']??'',1500),'audio'=>ProductVideoPolicy::text($data['audio']??'',1500),'shots'=>$shots,'duration'=>$duration,'video_job_id'=>$r['video_job_id']??0];
    }
    private static function finish(array $row,array $receipt,array $consumption): void
    {
        $result=self::parse($row,$receipt);
        Db::transaction(static function()use($row,$result,$consumption){
            self::write((int)$row['id'],['status'=>'success','result_json'=>ProductVideoPolicy::encode($result),'billing_status'=>'settled','user_charge_points'=>(float)$consumption['actual_user_price'],'error'=>'']);
            if($row['action']==='video')ProductVideoPipeline::chapters($row,$result);
        });
    }
}
