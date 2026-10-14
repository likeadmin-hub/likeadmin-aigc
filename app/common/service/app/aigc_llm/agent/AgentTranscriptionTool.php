<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use app\common\service\open_platform\OpenSchema;
use think\facade\Db;

/** Homepage-only, confirmed synchronous STT. A lost receipt is never resubmitted. */
final class AgentTranscriptionTool
{
    public const CODE='audio_transcription';
    public static function schema(): array
    {
        return ['type'=>'object','properties'=>['file_id'=>['type'=>'string','pattern'=>'^of_[a-f0-9]{32}$']],'required'=>['file_id'],'additionalProperties'=>false];
    }
    public static function quote(int $tenant): array
    {
        $products=Db::name('power_market_product')->where(['resource_type'=>'app_api','upstream_app_code'=>'voice_tts','upstream_api_code'=>'stt','status'=>1])->column('id');
        $skus=$products?Db::name('power_market_sku')->whereIn('product_id',$products)->where(['status'=>1,'sale_status'=>1,'usage_unit'=>'per_call','usage_unit_size'=>1])->order('id')->select()->toArray():[];
        foreach($skus as $sku){
            $price=Db::name('tenant_power_market_sku_price')->where(['tenant_id'=>$tenant,'sku_id'=>$sku['id']])->find();
            if($price&&(int)$price['sale_status']!==1)continue;
            if(AgentStore::json($sku['locked_params'])||AgentStore::json($sku['selectable_params']))continue;
            return ['tenant'=>(float)$sku['sale_points'],'user'=>(float)($price['sale_points']??$sku['sale_points']),'snapshot'=>['product_id'=>(int)$sku['product_id'],'sku_id'=>(int)$sku['id'],'usage_unit'=>'per_call','usage_unit_size'=>1,'api_code'=>'stt']];
        }
        throw new \InvalidArgumentException('语音转文字接口暂不可用');
    }
    public static function prepare(array $run,array $params): array
    {
        OpenSchema::validateRequest($params,self::schema(),true);
        $asset=AgentContextService::authorizedAsset($run,$params['file_id']);
        if(!preg_match('~^(audio|video)/~',$asset['mime']??''))throw new \InvalidArgumentException('转写需要本会话的音频或视频素材');
        $info=AgentMediaReader::withFile((int)$run['tenant_id'],(int)$run['user_id'],$params['file_id'],static fn($path)=>AgentMediaReader::probe($path));
        if(!$info['has_audio'])throw new \InvalidArgumentException('素材没有音轨，无需转写');
        return ['run_id'=>(int)$run['id'],'capability'=>['code'=>self::CODE,'name'=>'语音转文字','version'=>'home-stt-1'],'inputs'=>$params,'params'=>$params+['prompt'=>'转写「'.$asset['name'].'」中的语音','duration'=>round($info['duration'],2)],'quote'=>self::quote((int)$run['tenant_id']),'constraints'=>['description'=>'语音转文字按次计费；结果分析使用当前文本模型，模型费用另计。仅识别语音，不保证识别音乐、音效或说话人身份。']];
    }
    public static function submit(array $prepared): array
    {
        $tenant=(int)request()->tenantId;$user=(int)request()->userId;$step=(int)$prepared['step_id'];
        $run=AgentStore::run($tenant,$user,(int)$prepared['run_id']);
        AgentContextService::authorizedAsset($run,$prepared['inputs']['file_id']);
        // Decode and validate before reserving points or crossing the supplier boundary.
        return AgentMediaReader::withFile($tenant,$user,$prepared['inputs']['file_id'],function($path,$dir)use($prepared,$tenant,$user,$step){
            $audio=AgentMediaReader::audio($path,$dir);
            $source=UpdateSourceClient::getSource();$key=trim((string)($source['active_api_key']??$source['api_key']??$source['license_key']??''));
            $parts=parse_url((string)($source['active_base_url']??$source['base_url']??''));
            if(!$key||empty($parts['host']))throw new \InvalidArgumentException('语音识别服务未配置');
            $reservation=self::reserve($tenant,$user,$prepared);
            if(!$reservation['fresh'])return self::query($tenant,$user,$reservation['task_id']);
            $id=$reservation['task_id'];$cid=$reservation['consumption_id'];
            $url=($parts['scheme']??'https').'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'').'/api/v1/apps/voice_tts/stt';
            try{
                $wire=self::request($url,$key,$audio,$source);
                $data=json_decode($wire['body'],true);
                AiConsumptionLog::where('id',$cid)->update(['response_summary'=>AgentStore::encode(['http_status'=>$wire['status'],'provider_receipt'=>$data??mb_substr($wire['body'],0,200000)]),'update_time'=>time()]);
                $recognized=$data['data']['text']??$data['data']['result']['text']??null;
                if($wire['status']<500&&is_array($data)&&((isset($data['code'])&&(int)$data['code']===0)||isset($data['error']))){
                    self::finish($cid,false,['error'=>'语音识别服务拒绝了请求，请检查素材后重试']);
                }elseif($wire['status']===200&&(int)($data['code']??0)===1&&is_string($recognized)&&mb_strlen($recognized)<=100000){
                    // Empty text is a valid completed recognition (silence/no intelligible speech).
                    self::finish($cid,true,['text'=>$recognized,'language'=>(string)($data['data']['language']??$data['data']['result']['language']??''),'duration'=>(float)($data['data']['duration']??$data['data']['result']['duration']??0),'file_id'=>$prepared['inputs']['file_id']]);
                }else self::uncertain($cid);
            }catch(\Throwable $e){\think\facade\Log::error('Home Agent STT '.$cid.' failed: '.get_class($e).' '.$e->getMessage());self::uncertain($cid);}
            return self::query($tenant,$user,$id);
        });
    }
    private static function reserve(int $tenant,int $user,array $prepared): array
    {
        return Db::transaction(function()use($tenant,$user,$prepared){
            $run=AgentStore::owner('run',$tenant,$user)->where('id',$prepared['run_id'])->lock(true)->find();
            if(!$run||(int)$run['cancel_requested']||$run['status']!=='running'||$run['phase']!=='tools')throw new \RuntimeException('转写已停止');
            $step=AgentStore::owner('step',$tenant,$user)->where(['id'=>$prepared['step_id'],'run_id'=>$prepared['run_id'],'tool_code'=>self::CODE])->lock(true)->find();
            if(!$step||$step['status']!=='submitting')throw new \RuntimeException('转写步骤状态已变化');
            if((int)$step['task_id'])return ['fresh'=>false,'task_id'=>(int)$step['task_id']];
            $quote=$prepared['quote'];$now=time();$no='CSTT'.bin2hex(random_bytes(12));$taskNo='ASTT'.bin2hex(random_bytes(12));
            PointService::assertCanConsumeAmounts($tenant,$user,$quote['tenant'],$quote['user']);
            $task=AiAppTask::create(['task_no'=>$taskNo,'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_llm','action_code'=>'agent_transcribe','business_table'=>'aigc_llm_agent_step','business_id'=>$step['id'],'status'=>'running','progress'=>10,'request_summary'=>$prepared['inputs'],'result_summary'=>[],'estimated_tenant_cost'=>$quote['tenant'],'estimated_user_price'=>$quote['user'],'idempotency_key'=>sha1('agent-stt|'.$tenant.'|'.$step['id']),'create_time'=>$now,'update_time'=>$now]);
            $log=AiConsumptionLog::create(['consume_no'=>$no,'app_task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_llm','action_code'=>'agent_transcribe','resource_type'=>'app_api','product_id'=>$quote['snapshot']['product_id'],'sku_id'=>$quote['snapshot']['sku_id'],'api_code'=>'stt','protocol'=>'application_api','provider'=>'power_market','quantity'=>1,'usage_unit'=>'per_call','price_snapshot'=>$quote,'request_summary'=>$prepared['inputs'],'response_summary'=>[],'usage_snapshot'=>['calls'=>1],'run_status'=>'running','billing_status'=>'reserved','reserved_tenant_cost'=>$quote['tenant'],'reserved_user_price'=>$quote['user'],'tenant_point_sn'=>$no.'-reserve','user_point_sn'=>$no.'-reserve','create_time'=>$now,'update_time'=>$now]);
            PointService::reserveBusinessAmountsInCurrentTransaction($tenant,$user,$quote['tenant'],$quote['user'],$no.'-reserve','语音转写预占',self::extra($task->toArray(),$log->toArray(),'reserved'));
            AgentStore::table('step')->where('id',$step['id'])->update(['task_id'=>$task['id'],'update_time'=>$now]);
            return ['fresh'=>true,'task_id'=>(int)$task['id'],'consumption_id'=>(int)$log['id']];
        });
    }
    private static function extra(array $task,array $log,string $stage): array
    {
        return ['app_code'=>'aigc_llm','app_task_id'=>(int)$task['id'],'app_task_no'=>$task['task_no'],'consumption_id'=>(int)$log['id'],'consume_no'=>$log['consume_no'],'billing_stage'=>$stage];
    }
    private static function finish(int $id,bool $success,array $result): void
    {
        // Preserve returned text even if accounting fails; recovery must never dispatch STT again.
        AiConsumptionLog::where('id',$id)->whereNotIn('billing_status',['settled','refunded'])->update(['response_summary'=>AgentStore::encode($result),'update_time'=>time()]);
        Db::transaction(function()use($id,$success,$result){
            $log=AiConsumptionLog::where('id',$id)->lock(true)->find();$task=AiAppTask::find($log['app_task_id']);
            if(in_array($log['billing_status'],['settled','refunded'],true))return;
            $extra=self::extra($task->toArray(),$log->toArray(),$success?'settled':'refunded');
            $tenant=(float)$log['reserved_tenant_cost'];$user=(float)$log['reserved_user_price'];
            if($success)PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$log['tenant_id'],(int)$log['user_id'],$tenant,$user,$tenant,$user,$log['consume_no'],'语音转写结算',$extra);
            else PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$log['tenant_id'],(int)$log['user_id'],$tenant,$user,$log['consume_no'].'-release','语音转写失败退回',$extra);
            $log->save(['run_status'=>$success?'success':'failed','billing_status'=>$success?'settled':'refunded','actual_tenant_cost'=>$success?$tenant:0,'actual_user_price'=>$success?$user:0,'finish_time'=>time(),'update_time'=>time()]);
            $task->save(['status'=>$success?'success':'failed','progress'=>100,'result_summary'=>$result,'actual_tenant_cost'=>$success?$tenant:0,'actual_user_price'=>$success?$user:0,'finish_time'=>time(),'update_time'=>time()]);
        });
    }
    private static function uncertain(int $id): void
    {
        $log=AiConsumptionLog::find($id);if(!$log||in_array($log['billing_status'],['settled','refunded'],true))return;
        $log->save(['run_status'=>'needs_review','error_code'=>'stt_receipt_uncertain','error_message'=>'转写受理或结算结果待核对，请勿重复提交','update_time'=>time()]);
        AiAppTask::where('id',$log['app_task_id'])->update(['status'=>'needs_review','update_time'=>time()]);
    }
    public static function query(int $tenant,int $user,int $task): array
    {
        $row=AiAppTask::where(['id'=>$task,'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_llm','action_code'=>'agent_transcribe'])->find();
        if(!$row)throw new \InvalidArgumentException('转写任务不存在');
        return ['task_id'=>$task,'status'=>$row['status'],'transcription'=>(array)$row['result_summary']];
    }
    private static function request(string $url,string $key,string $audio,array $source): array
    {
        $ch=curl_init($url);$body='';
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['audio'=>new \CURLFile($audio,'audio/wav','speech.wav'),'ignore_timestamps'=>'1'],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Accept: application/json'],CURLOPT_SSL_VERIFYPEER=>UpdateSourceClient::sslVerify($source),CURLOPT_SSL_VERIFYHOST=>UpdateSourceClient::sslVerify($source)?2:0,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$body){if(strlen($body)+strlen($part)>2097152)return 0;$body.=$part;return strlen($part);}]);
        try{if(curl_exec($ch)===false)throw new \RuntimeException('转写连接中断');return ['status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'body'=>$body];}finally{curl_close($ch);}
    }
    public static function resume(array $run): bool
    {
        return Db::transaction(function()use($run){
            $current=AgentStore::owner('run',(int)$run['tenant_id'],(int)$run['user_id'])->where('id',$run['id'])->lock(true)->find();
            if(!$current||$current['lease_token']!==$run['lease_token']||(int)$current['cancel_requested'])return false;
            $p=AgentStore::json($current['preferences']);
            $last=(int)AgentStore::table('step')->where(['run_id'=>$run['id'],'tool_code'=>self::CODE,'status'=>'success'])->max('id');
            if($last<1||$last<=(int)($p['_transcription_floor']??0))return false;
            $p['_transcription_floor']=$last;
            AgentStore::update($run,['preferences'=>AgentStore::encode($p),'status'=>'queued','phase'=>'chat','approval_hash'=>'','next_run_time'=>0,'lease_token'=>'','lease_until'=>0]);
            AgentStore::event($run,'analysis.ready',['title'=>'语音转写完成，继续分析']);return true;
        });
    }
}
