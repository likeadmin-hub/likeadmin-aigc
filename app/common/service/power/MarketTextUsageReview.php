<?php
namespace app\common\service\power;
use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiConsumptionEvent};
use app\common\service\open_platform\OpenVault;
use think\facade\Db;

/** Operator reconciliation marker. No estimated usage, balance write or paid replay. */
final class MarketTextUsageReview
{
    /** Shared by live runtime and operator repair of already recorded native rejection receipts. */
    public static function resolveNativeRejection(int $tenant,int $user,int $id,array $result,bool $apply=false):array
    {
        if(!MarketTextFailurePolicy::preGenerationRejection($result))throw new \RuntimeException('不是可确认的生成前拒绝回执');
        return Db::transaction(static function()use($tenant,$user,$id,$result,$apply):array{
            $c=AiConsumptionLog::where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user])->lock($apply)->find();
            if(!$c)throw new \RuntimeException('消费归属不一致');
            $request=(string)$result['provider_request_id'];
            if($c['upstream_request_id']!==''&&$c['upstream_request_id']!==$request)throw new \RuntimeException('拒绝回执请求标识不一致');
            foreach(['actual_user_price','actual_tenant_cost','reserved_user_price','reserved_tenant_cost']as$key)
                if(abs((float)$c[$key])>0.000001)throw new \RuntimeException('非零金额不能使用生成前拒绝关闭');
            $summary=(array)$c['response_summary'];if(($summary['output_length']??0)>0)throw new \RuntimeException('该消费已经有正文输出');
            $identity=['request_id'=>$request,'proof_sha256'=>self::hash($result),'disposition'=>'refunded','reason'=>'confirmed_pre_generation_rejection'];
            if($c['billing_status']==='refunded'){
                if(($summary['usage_resolution']['proof_sha256']??'')!==$identity['proof_sha256'])throw new \RuntimeException('已关闭记录的凭据不一致');
                return $identity+['applied'=>false,'already_closed'=>true];
            }
            if(!in_array($c['billing_status'],['pending_usage','reserved','settlement_failed'],true))throw new \RuntimeException('该记录不能按拒绝回执关闭');
            if(!$apply)return $identity+['applied'=>false];
            $app=AiAppTask::where(['id'=>$c['app_task_id'],'tenant_id'=>$tenant,'user_id'=>$user])->lock(true)->find();
            if(!$app)throw new \RuntimeException('应用任务归属不一致');
            $summary['usage_resolution']=$identity;$now=time();
            $c->save(['run_status'=>'failed','billing_status'=>'refunded','upstream_request_id'=>$request,'response_summary'=>$summary,
                'error_code'=>'confirmed_provider_rejection','error_message'=>'模型渠道在生成前拒绝请求，本次不收费','finish_time'=>$c['finish_time']?:$now,'update_time'=>$now]);
            $app->save(['status'=>'failed','progress'=>100,'finish_time'=>$app['finish_time']?:$now,'update_time'=>$now]);
            AiConsumptionEvent::create(['consumption_id'=>$id,'event_type'=>'usage_resolved','event_status'=>'closed','attempt_no'=>1,'payload_summary'=>$identity,
                'payload_ciphertext'=>OpenVault::seal(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'http_status'=>0,'elapsed_ms'=>0,'create_time'=>$now]);
            return $identity+['applied'=>true];
        });
    }

    /** Trusted operator entry only. No HTTP endpoint, provider call or balance mutation. */
    public static function resolveFailedPlanning(int $tenant,int $user,int $id,int $unitId,array $proof,bool $apply=false):array
    {
        return Db::transaction(static function()use($tenant,$user,$id,$unitId,$proof,$apply):array{
            $c=AiConsumptionLog::where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user])->lock($apply)->find();
            $unit=Db::name('aigc_short_drama_planning_unit')->where(['id'=>$unitId,'tenant_id'=>$tenant,'user_id'=>$user])->find();
            if(!$c||!$unit||$c['app_code']!=='aigc_short_drama')throw new \RuntimeException('消费与剧本回执归属不一致');
            $receipt=json_decode((string)$unit['result_json'],true,512,JSON_THROW_ON_ERROR);$receipt=$receipt['result']??$receipt;
            if((int)($receipt['consumption_id']??0)!==$id||($receipt['provider_request_id']??'')!==$c['upstream_request_id'])throw new \RuntimeException('原始回执未严格关联到该消费');
            $input=json_decode((string)$unit['request_json'],true,512,JSON_THROW_ON_ERROR);
            $wire=self::planningWire($input);$task=$proof['task']??[];$call=$proof['factory']??[];
            $received=$task['request_params']??[];if(is_string($received))$received=json_decode($received,true,512,JSON_THROW_ON_ERROR);
            if(self::hash($wire)!==self::hash($received)||$c['model_code']!==($wire['model']??''))throw new \RuntimeException('上游原始请求与冻结请求不一致');
            if(($task['task_id']??'')===''||(int)($task['status']??0)!==3||($call['status']??'')!=='failed'
                ||($task['error_code']??'')===''||abs((float)($task['actual_points']??-1))>0.000001||abs((float)($call['actual_points']??-1))>0.000001)
                throw new \RuntimeException('缺少上游失败且零结算的证明');
            foreach(['task_id','tenant_id','user_id','model_code']as$key)if(($call[$key]??null)!==($task[$key]??null))throw new \RuntimeException('上游任务与工厂账务身份不一致');
            self::assertRefunded($task,(array)($proof['user_logs']??[]),false);
            self::assertRefunded($task,(array)($proof['tenant_logs']??[]),true);
            foreach(['actual_user_price','actual_tenant_cost','reserved_user_price','reserved_tenant_cost']as$key)
                if(abs((float)$c[$key])>0.000001)throw new \RuntimeException('非零本地金额必须通过独立的账务补偿流程');
            $identity=['unit_id'=>$unitId,'upstream_task_id'=>$task['task_id'],'request_sha256'=>self::hash($wire),
                'proof_sha256'=>self::hash($proof),'disposition'=>'refunded','reason'=>'confirmed_provider_failure'];
            $saved=(array)$c['response_summary'];
            if($c['billing_status']==='refunded'){
                if(($saved['usage_resolution']['proof_sha256']??'')!==$identity['proof_sha256'])throw new \RuntimeException('已关闭记录的凭据不一致');
                return $identity+['applied'=>false,'already_closed'=>true];
            }
            if(!in_array($c['billing_status'],['pending_usage','settlement_failed'],true))throw new \RuntimeException('该消费不属于待处理用量记录');
            if(!$apply)return $identity+['applied'=>false];
            $app=AiAppTask::where(['id'=>$c['app_task_id'],'tenant_id'=>$tenant,'user_id'=>$user])->lock(true)->find();
            if(!$app)throw new \RuntimeException('应用任务归属不一致');
            $saved['usage_resolution']=$identity;$now=time();
            $c->save(['run_status'=>'failed','billing_status'=>'refunded','upstream_task_id'=>$task['task_id'],
                'response_summary'=>$saved,'error_code'=>'confirmed_provider_failure','error_message'=>'已确认模型调用失败，本次不收费；已有创作内容保留','update_time'=>$now]);
            // The consumption attempt failed; completed business scripts and immutable unit results remain intact.
            $app->save(['status'=>'failed','progress'=>100,'update_time'=>$now,'finish_time'=>$app['finish_time']?:$now]);
            AiConsumptionEvent::create(['consumption_id'=>$id,'event_type'=>'usage_resolved','event_status'=>'closed','attempt_no'=>1,
                'payload_summary'=>$identity,'payload_ciphertext'=>OpenVault::seal(json_encode($proof,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
                'http_status'=>0,'elapsed_ms'=>0,'create_time'=>$now]);
            AiConsumptionEvent::where(['consumption_id'=>$id,'event_type'=>'usage_review','event_status'=>'pending'])->update(['event_status'=>'closed']);
            return $identity+['applied'=>true];
        });
    }

    private static function planningWire(array $input):array
    {
        if(!is_string($input['content']??null)||!is_string($input['system_prompt']??null)||!empty($input['messages'])||!empty($input['reference_images']))
            throw new \RuntimeException('只支持有完整冻结文本的规划请求');
        $content=$input['content'];if(!empty($input['_repair_reason']))$content.="\n这是用户要求继续修复的同一部分。只修复以下校验问题，保留原任务事实和其他已完成部分：".$input['_repair_reason'];
        $config=(array)($input['model_config']??[]);
        return ['model'=>$input['model_selection']['model_code']??'','stream'=>true,
            'messages'=>[['role'=>'system','content'=>trim($input['system_prompt'])],['role'=>'user','content'=>trim($content)]],
            'stream_options'=>['include_usage'=>true]]+$config;
    }

    private static function assertRefunded(array $task,array $logs,bool $tenant):void
    {
        $frozen=0.0;$released=0.0;
        if($logs===[])throw new \RuntimeException('缺少上游冻结及退回流水');
        foreach($logs as$log){
            if(($log['task_id']??'')!==$task['task_id']||(int)($log['tenant_id']??0)!==(int)$task['tenant_id']
                ||(!$tenant&&(int)($log['user_id']??0)!==(int)$task['user_id']))throw new \RuntimeException('上游流水归属不一致');
            $action=$log['action']??null;$amount=(float)($log['points']??0);
            if($tenant){if((int)$action===2)$frozen+=$amount;elseif((int)$action===4)$released+=$amount;else throw new \RuntimeException('上游存在其他扣费动作');}
            else{if($action==='freeze'&&$amount<=0)$frozen-=$amount;elseif($action==='unfreeze'&&$amount>=0)$released+=$amount;else throw new \RuntimeException('上游存在其他扣费动作');}
        }
        if(abs($frozen-$released)>0.000001)throw new \RuntimeException('上游预扣尚未全额退回');
    }

    private static function hash($value):string
    {
        $sort=static function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);return array_map($sort,$v);};
        return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    public static function flag(int $tenant,int $user,int $id,string $requestId,array $evidence):void
    {
        Db::transaction(static function()use($tenant,$user,$id,$requestId,$evidence):void{
            $c=AiConsumptionLog::where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user])->lock(true)->find();
            if(!$c||$requestId===''||$c['upstream_request_id']!==$requestId)throw new \RuntimeException('用量核对记录归属或请求标识不一致');
            if($c['billing_status']==='settlement_failed'&&$c['error_code']==='provider_usage_missing')return;
            if($c['run_status']!=='success'||$c['billing_status']!=='pending_usage')throw new \RuntimeException('该记录不属于已交付但缺失用量的待核对记录');
            $c->save(['billing_status'=>'settlement_failed','error_code'=>'provider_usage_missing',
                'error_message'=>'已保留创作结果；上游未返回最终用量，费用待核对，未估算扣费','update_time'=>time()]);
            AiConsumptionEvent::create(['consumption_id'=>$id,'event_type'=>'usage_review','event_status'=>'pending','attempt_no'=>1,
                'payload_summary'=>['reason'=>'missing_final_usage','request_id'=>$requestId,'requires_exact_receipt'=>true],
                'payload_ciphertext'=>OpenVault::seal(json_encode(['consumption_id'=>$id,'request_id'=>$requestId,'evidence'=>$evidence],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),
                'http_status'=>0,'elapsed_ms'=>0,'create_time'=>time()]);
        });
    }
}
