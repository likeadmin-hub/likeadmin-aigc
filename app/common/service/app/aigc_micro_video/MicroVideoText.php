<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\power\MarketTextModelRuntimeService;
use RuntimeException;

/** Persist the receipt before settlement; restarts never silently repeat a paid text call. */
final class MicroVideoText
{
    public static function run(array $task,string $kind,string $model,string $system,string $content,array $images=[],?callable $validate=null): array
    {
        self::reconcile((int)$task['id']);
        $prior=MicroVideoStore::table('attempt')->where(['task_id'=>$task['id'],'kind'=>$kind,'segment_id'=>0])->order('attempt_no','desc')->find();
        if($prior){
            $result=MicroVideoStore::json($prior['result_json']);
            if($result&&($prior['status']==='success'||self::settled((int)$prior['consumption_id'])))return $result;
            if(!in_array($prior['status'],['failed'],true))throw new RuntimeException('模型调用状态待核对，已保留进度，请稍后查看');
        }
        if($model==='')throw new \InvalidArgumentException('请先配置图片理解和脚本规划模型');
        $now=time();$attempt=MicroVideoStore::table('attempt')->insertGetId(['tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],'task_id'=>$task['id'],'segment_id'=>0,'kind'=>$kind,'attempt_no'=>(int)($prior['attempt_no']??0)+1,'status'=>'submitting','request_json'=>MicroVideoStore::encode(['model_id'=>$model,'content'=>$content,'system'=>$system,'image_count'=>count($images)]),'result_json'=>'{}','create_time'=>$now,'update_time'=>$now]);
        try{
            $result=MarketTextModelRuntimeService::generate((int)$task['tenant_id'],(int)$task['user_id'],[
                'content'=>$content,'system_prompt'=>$system,'model_id'=>$model,'reference_images'=>$images,'requires_vision'=>$images!==[],
                'source_app_code'=>MicroVideoStore::APP,'business_table'=>'aigc_micro_video_task','business_id'=>(int)$task['id'],'action_code'=>'micro_'.$kind,'_disable_model_fallback'=>true,'_require_final_content'=>true,'request_timeout_seconds'=>180,'_micro_video_preserve_uncertain'=>true,
                'model_config'=>['max_tokens'=>8000],
                '_result_validator'=>function(array $r)use($attempt,$validate){if(trim((string)($r['content']??''))==='')throw new RuntimeException('模型未返回完整创作内容');if($validate)$validate($r);MicroVideoStore::table('attempt')->where('id',$attempt)->update(['result_json'=>MicroVideoStore::encode($r),'update_time'=>time()]);}
            ],function($event,$data)use($attempt){if($event==='app_task')MicroVideoStore::table('attempt')->where('id',$attempt)->update(['app_task_id'=>(int)$data['app_task_id'],'consumption_id'=>(int)$data['consumption_id'],'update_time'=>time()]);});
            MicroVideoStore::table('attempt')->where('id',$attempt)->update(['status'=>'success','result_json'=>MicroVideoStore::encode($result),'user_charge_points'=>(float)($result['billing']['user_charge_points']??0),'billing_status'=>(string)($result['billing']['billing_status']??'pending_usage'),'update_time'=>time()]);return $result;
        }catch(\Throwable $e){
            $row=MicroVideoStore::table('attempt')->where('id',$attempt)->find();$c=(int)$row['consumption_id']>0?\think\facade\Db::name('ai_consumption_log')->where('id',$row['consumption_id'])->find():null;
            $known=!$c||in_array($c['run_status']??'',['failed','canceled'],true);
            MicroVideoStore::table('attempt')->where('id',$attempt)->update(['status'=>$known?'failed':'unknown','error'=>mb_substr($e->getMessage(),0,1000),'billing_status'=>$c['billing_status']??'none','update_time'=>time()]);throw $e;
        }
    }
    private static function settled(int $id): bool{return $id>0&&\think\facade\Db::name('ai_consumption_log')->where(['id'=>$id,'run_status'=>'success','billing_status'=>'settled'])->count()>0;}
    public static function reconcile(int $id): void
    {
        $rows=MicroVideoStore::table('attempt')->where('task_id',$id)->where('kind','<>','video')->whereIn('status',['unknown','submitting'])->select()->toArray();
        foreach($rows as $a){if(!(int)$a['consumption_id'])continue;$c=\think\facade\Db::name('ai_consumption_log')->where('id',$a['consumption_id'])->find();if(!$c)continue;
            $state=null;if(in_array($c['run_status'],['failed','canceled'],true))$state='failed';elseif($c['run_status']==='success'&&$c['billing_status']==='settled'&&MicroVideoStore::json($a['result_json']))$state='success';
            if($state)MicroVideoStore::table('attempt')->where('id',$a['id'])->update(['status'=>$state,'billing_status'=>$c['billing_status'],'user_charge_points'=>(float)$c['actual_user_price'],'update_time'=>time()]);}
    }
    public static function parse(string $content): array
    {
        $content=trim($content);$content=preg_replace('/^```(?:json)?\s*|\s*```$/u','',$content);$start=strpos($content,'{');$end=strrpos($content,'}');
        if($start===false||$end===false)throw new \InvalidArgumentException('分段结果格式不完整，请重新规划');
        $json=json_decode(substr($content,$start,$end-$start+1),true,512,JSON_THROW_ON_ERROR);if(!is_array($json)||!isset($json['segments']))throw new \InvalidArgumentException('分段结果缺少详细镜头信息');return $json;
    }
}
