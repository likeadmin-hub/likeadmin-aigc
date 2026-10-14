<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use app\common\service\point\PointService;
use app\common\service\open_platform\{OpenCharacterInputPricing,OpenSchema,OpenPolicy,OpenSafeHttp,OpenVault};
use app\common\service\update\UpdateSourceClient;
use app\common\service\ai\AiTaskResultAssetService;
use app\common\service\app\aigc_music\AigcMusicAssetService;
use think\facade\Db;

/** Short default-voice speech. Receipt recovery never resubmits synthesis. */
final class AgentSpeechTool
{
    public const CODE = 'speech_synthesis';
    private const ACTION = 'agent_speech';
    public static function schema(): array
    {
        return ['type'=>'object','properties'=>['text'=>['type'=>'string','minLength'=>1,'maxLength'=>500,'description'=>'需要实际朗读的正文，不是生成指令；最多500字符']],'required'=>['text'],'additionalProperties'=>false];
    }
    private static function offers(int $tenant): array
    {
        $out=[];
        $products=Db::name('power_market_product')->where(['resource_type'=>'app_api','upstream_app_code'=>'voice_tts','upstream_api_code'=>'tts','status'=>1])->select()->toArray();
        foreach($products as $product){
            foreach(Db::name('power_market_sku')->where(['product_id'=>$product['id'],'status'=>1,'sale_status'=>1,'usage_unit'=>'character'])->order('id')->select()->toArray() as $sku){
                $retail=Db::name('tenant_power_market_sku_price')->where(['tenant_id'=>$tenant,'sku_id'=>$sku['id']])->find();
                if($retail&&(int)$retail['sale_status']!==1)continue;
                // Only a deterministic supplier-default voice contract is exposed.
                if(AgentStore::json($sku['selectable_params'])||AgentStore::json($sku['locked_params']))continue;
                try{$config=OpenCharacterInputPricing::configuration($product);$effective=OpenCharacterInputPricing::transportParameters($config,['text'=>'验收','format'=>'mp3']);
                    if(($effective['format']??'')!=='mp3')continue;
                    OpenCharacterInputPricing::quote($product,$sku,(float)($retail['sale_points']??$sku['sale_points']),['text'=>'验收','format'=>'mp3'],16384,strlen(AgentStore::encode($effective)),$config);
                }catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException){continue;}
                $out['speech_sku:'.$sku['id']]=['product'=>$product,'sku'=>$sku,'retail'=>(float)($retail['sale_points']??$sku['sale_points']),'config'=>$config];
            }
        }
        return $out;
    }
    public static function models(int $tenant): array
    {
        $rows=[];foreach(self::offers($tenant) as $code=>$offer)$rows[]=['code'=>$code,'name'=>$offer['product']['name'].'（平台默认模型/音色）','description'=>'短文本朗读，最多500字符；按字符计费','unit_price'=>$offer['retail'],'unit_size'=>(float)$offer['sku']['usage_unit_size']];
        return ['models'=>$rows,'defaults'=>['model'=>$rows[0]['code']??'']];
    }
    public static function describe(array $run): array
    {
        $selected=(string)(AgentStore::json($run['preferences'])['models']['voice']??'');
        $rows=self::models((int)$run['tenant_id'])['models'];$model=null;
        foreach($rows as $row)if($row['code']===$selected)$model=$row;
        return ['code'=>self::CODE,'schema'=>self::schema(),'selected_model'=>$model??['available'=>false],
            'instruction'=>'仅将用户确认的正文朗读为语音，最多500字符，MP3；使用配音偏好中选定服务的平台默认模型和默认音色，不保证性别、音色或时长。不能克隆音色，不能把音乐工具用于配音。缺正文或用户要求指定音色时先澄清；必须 plan_creation 获得报价并按运行模式执行。'];
    }
    public static function prepare(array $run,array $params): array
    {
        OpenSchema::validateRequest($params,self::schema(),true);OpenPolicy::validateInput($params);
        if(trim($params['text'])==='')throw new \InvalidArgumentException('请输入需要朗读的正文');
        $selection=(string)(AgentStore::json($run['preferences'])['models']['voice']??'');
        $offer=self::offers((int)$run['tenant_id'])[$selection]??null;
        if(!$offer)throw new \InvalidArgumentException('请在配音偏好中选择当前可用的配音服务');
        $raw=['text'=>$params['text'],'format'=>'mp3'];
        $effective=OpenCharacterInputPricing::transportParameters($offer['config'],$raw);
        $quote=OpenCharacterInputPricing::quote($offer['product'],$offer['sku'],$offer['retail'],$raw,16384,strlen(AgentStore::encode($raw)),$offer['config']);
        $quote['snapshot']+=['product_id'=>(int)$offer['product']['id'],'sku_id'=>(int)$offer['sku']['id'],'model_name'=>$offer['product']['name'].'（平台默认模型/音色）','selection'=>$selection];
        return ['run_id'=>(int)$run['id'],'capability'=>['code'=>self::CODE,'name'=>'文字配音','version'=>'home-speech-1'],'inputs'=>$params,
            'params'=>['prompt'=>$params['text'],'characters'=>$quote['snapshot']['character_input']['quantity'],'format'=>'mp3','model_name'=>$quote['snapshot']['model_name']],
            'speech_payload'=>$effective,'quote'=>$quote,'constraints'=>['description'=>'使用平台默认模型和默认音色，最多500字符；按字符计费，音频时长由内容决定。']];
    }
    private static function directory(): string
    {
        $dir=runtime_path().'home-agent-speech-receipts';
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('无法保存配音回执');
        if(!is_writable($dir))throw new \RuntimeException('配音回执目录不可写');
        return $dir;
    }
    private static function journal(array $log): string
    {
        if(!preg_match('/^CSP[a-f0-9]{24}$/D',(string)$log['consume_no']))throw new \RuntimeException('配音记录无效');
        return self::directory().'/'.$log['consume_no'].'.json';
    }
    private static function record(array $log,array $wire): void
    {
        $path=self::journal($log);$temp=$path.'.'.bin2hex(random_bytes(6));
        $value=OpenVault::seal(AgentStore::encode(['id'=>(int)$log['id'],'tenant_id'=>(int)$log['tenant_id'],'consume_no'=>$log['consume_no'],'wire'=>$wire]));
        $f=fopen($temp,'x');if(!$f)throw new \RuntimeException('配音回执保存失败');
        try{chmod($temp,0600);$offset=0;while($offset<strlen($value)){$n=fwrite($f,substr($value,$offset));if(!$n)throw new \RuntimeException('配音回执保存失败');$offset+=$n;}fflush($f);if(function_exists('fsync'))fsync($f);}finally{fclose($f);}
        if(!rename($temp,$path))throw new \RuntimeException('配音回执保存失败');
    }
    public static function submit(array $prepared): array
    {
        $tenant=(int)request()->tenantId;$user=(int)request()->userId;AgentStore::access($tenant,$user);
        $source=UpdateSourceClient::getSource();$key=trim((string)($source['active_api_key']??$source['api_key']??$source['license_key']??''));
        $parts=parse_url((string)($source['active_base_url']??$source['base_url']??''));
        if(!$key||empty($parts['host']))throw new \InvalidArgumentException('配音服务未配置');
        self::directory();
        $reservation=Db::transaction(function()use($tenant,$user,$prepared){
            $run=AgentStore::owner('run',$tenant,$user)->where('id',$prepared['run_id'])->lock(true)->find();
            if(!$run||(int)$run['cancel_requested']||$run['status']!=='running'||$run['phase']!=='tools')throw new \InvalidArgumentException('配音已停止');
            $step=AgentStore::owner('step',$tenant,$user)->where(['id'=>$prepared['step_id'],'run_id'=>$run['id'],'tool_code'=>self::CODE])->lock(true)->find();
            if(!$step||$step['status']!=='submitting')throw new \InvalidArgumentException('配音步骤状态已变化');
            if((int)$step['task_id'])return ['fresh'=>false,'task_id'=>(int)$step['task_id']];
            $q=$prepared['quote'];$now=time();$no='CSP'.bin2hex(random_bytes(12));
            PointService::assertCanConsumeAmounts($tenant,$user,$q['tenant'],$q['user']);
            $task=AiAppTask::create(['task_no'=>'ASP'.bin2hex(random_bytes(12)),'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_llm','action_code'=>self::ACTION,'business_table'=>'aigc_llm_agent_step','business_id'=>$step['id'],'status'=>'running','progress'=>10,'request_summary'=>$prepared['params'],'result_summary'=>[],'estimated_tenant_cost'=>$q['tenant'],'estimated_user_price'=>$q['user'],'idempotency_key'=>sha1('agent-speech|'.$tenant.'|'.$step['id']),'create_time'=>$now,'update_time'=>$now]);
            $log=AiConsumptionLog::create(['consume_no'=>$no,'app_task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_llm','action_code'=>self::ACTION,'resource_type'=>'app_api','product_id'=>$q['snapshot']['product_id'],'sku_id'=>$q['snapshot']['sku_id'],'api_code'=>'tts','protocol'=>'application_api','provider'=>'power_market','quantity'=>$prepared['params']['characters'],'usage_unit'=>'character','price_snapshot'=>$q,'request_summary'=>$prepared['params'],'response_summary'=>[],'usage_snapshot'=>['characters'=>$prepared['params']['characters']],'run_status'=>'running','billing_status'=>'reserved','reserved_tenant_cost'=>$q['tenant'],'reserved_user_price'=>$q['user'],'tenant_point_sn'=>$no.'-reserve','user_point_sn'=>$no.'-reserve','create_time'=>$now,'update_time'=>$now]);
            PointService::reserveBusinessAmountsInCurrentTransaction($tenant,$user,$q['tenant'],$q['user'],$no.'-reserve','文字配音预占',self::extra($task->toArray(),$log->toArray()));
            AgentStore::table('step')->where('id',$step['id'])->update(['task_id'=>$task['id'],'update_time'=>$now]);
            return ['fresh'=>true,'task_id'=>(int)$task['id'],'log'=>$log->toArray()];
        });
        if($reservation['fresh']){
            $log=$reservation['log'];
            $url=($parts['scheme']??'https').'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'').'/api/v1/apps/voice_tts/tts';
            try{self::record($log,self::request($url,$key,$prepared['speech_payload'],$source));self::reconcile((int)$log['id'],$tenant,true);}
            catch(\Throwable $e){self::uncertain((int)$log['id']);\think\facade\Log::error('Home Agent speech '.$log['id'].': '.get_class($e));}
        }
        return self::query($tenant,$user,$reservation['task_id']);
    }
    private static function extra(array $task,array $log): array
    {
        return ['app_code'=>'aigc_llm','app_task_id'=>(int)$task['id'],'app_task_no'=>$task['task_no'],'consumption_id'=>(int)$log['id'],'consume_no'=>$log['consume_no']];
    }
    private static function uncertain(int $id): void
    {
        Db::transaction(function()use($id){$log=AiConsumptionLog::where('id',$id)->lock(true)->find();if(!$log||$log['billing_status']!=='reserved')return;
            $log->save(['run_status'=>'needs_review','error_code'=>'speech_receipt_uncertain','error_message'=>'配音结果或保存状态待核对，请勿重复提交','update_time'=>time()]);
            AiAppTask::where('id',$log['app_task_id'])->update(['status'=>'needs_review','update_time'=>time()]);});
    }
    /** Pure receipt classifier: an absent result or a transport error is not a refund signal. */
    public static function classify(array $wire): ?array
    {
        if(empty($wire['complete']))return null;
        $status=(int)($wire['http_status']??0);$data=json_decode((string)($wire['body']??''),true);
        if(!is_array($data)||$status<200||$status>=500)return null;
        if((isset($data['code'])&&(string)$data['code']==='0')||isset($data['error']))return ['success'=>false];
        $url=$data['data']['audio_url']??$data['data']['result']['audio_url']??null;
        if($status!==200||(string)($data['code']??'')!=='1'||!is_string($url)||!preg_match('~^https?://~i',$url))return null;
        return ['success'=>true,'audio_url'=>$url];
    }
    public static function reconcile(int $id,int $tenant,bool $received=false): array
    {
        $log=AiConsumptionLog::where(['id'=>$id,'tenant_id'=>$tenant,'app_code'=>'aigc_llm','action_code'=>self::ACTION])->find();
        if(!$log)throw new \InvalidArgumentException('配音记录不存在');$log=$log->toArray();
        if(in_array($log['billing_status'],['settled','refunded'],true)){self::wake($log);return ['resolved'=>true];}
        $created=is_numeric($log['create_time'])?(int)$log['create_time']:strtotime((string)$log['create_time']);
        if(!$received&&$log['run_status']==='running'&&$created>time()-240)return ['resolved'=>false];
        $path=self::journal($log);$lock=fopen($path.'.lock','c');if(!$lock)throw new \RuntimeException('配音恢复锁不可用');chmod($path.'.lock',0600);
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['resolved'=>false];}
        try{
            $log=AiConsumptionLog::find($id)->toArray();if(in_array($log['billing_status'],['settled','refunded'],true))return ['resolved'=>true];
            if(!is_file($path)){self::uncertain($id);return ['resolved'=>false];}
            $journal=AgentStore::json(OpenVault::open(file_get_contents($path)));
            if(($journal['id']??0)!==$id||($journal['tenant_id']??0)!==$tenant||($journal['consume_no']??'')!==$log['consume_no'])throw new \RuntimeException('配音回执归属不匹配');
            $wire=$journal['wire'];$result=self::classify($wire);
            $summary=AgentStore::json($log['response_summary']);$summary['receipt']=['http_status'=>$wire['http_status'],'complete'=>$wire['complete'],'transport_errno'=>$wire['transport_errno']??0];
            $data=json_decode($wire['body'],true)?:[];
            AiConsumptionLog::where('id',$id)->update(['response_summary'=>AgentStore::encode($summary),'upstream_request_id'=>substr((string)($wire['headers']['x-request-id']??$data['request_id']??''),0,100),'upstream_task_id'=>substr((string)($data['data']['task_id']??''),0,100),'update_time'=>time()]);
            if($result===null){self::uncertain($id);return ['resolved'=>false];}
            if($result['success']&&empty($summary['items'])){
                $summary['items']=[self::storeAudio($result['audio_url'],$tenant,(int)$log['user_id'])];
                // Persist the stored result before accounting so recovery cannot regenerate or redownload it.
                AiConsumptionLog::where('id',$id)->update(['response_summary'=>AgentStore::encode($summary),'update_time'=>time()]);
            }
            if($result['success'])AiTaskResultAssetService::recordConsumptionAssets($id);
            Db::transaction(function()use($id,$result,$summary){
                $row=AiConsumptionLog::where('id',$id)->lock(true)->find();if(in_array($row['billing_status'],['settled','refunded'],true))return;
                $task=AiAppTask::find($row['app_task_id']);$extra=self::extra($task->toArray(),$row->toArray());$t=(float)$row['reserved_tenant_cost'];$u=(float)$row['reserved_user_price'];
                if($result['success'])PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$row['tenant_id'],(int)$row['user_id'],$t,$u,$t,$u,$row['consume_no'],'文字配音结算',$extra);
                else PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$row['tenant_id'],(int)$row['user_id'],$t,$u,$row['consume_no'].'-release','文字配音失败退回',$extra);
                $row->save(['run_status'=>$result['success']?'success':'failed','billing_status'=>$result['success']?'settled':'refunded','actual_tenant_cost'=>$result['success']?$t:0,'actual_user_price'=>$result['success']?$u:0,'error_code'=>'','error_message'=>'','finish_time'=>time(),'update_time'=>time()]);
                $task->save(['status'=>$result['success']?'success':'failed','progress'=>100,'result_summary'=>['results'=>$summary['items']??[]],'actual_tenant_cost'=>$result['success']?$t:0,'actual_user_price'=>$result['success']?$u:0,'finish_time'=>time(),'update_time'=>time()]);
            });
            self::wake($log);@unlink($path);return ['resolved'=>true];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function storeAudio(string $url,int $tenant,int $user): array
    {
        $wire=OpenSafeHttp::get($url,20*1024*1024,45);$mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($wire['body']);
        if(!in_array($mime,['audio/mpeg','audio/mp3'],true))throw new \RuntimeException('配音返回的不是有效 MP3 音频');
        $stored=AigcMusicAssetService::persistGeneratedAudio('data:audio/mp3;base64,'.base64_encode($wire['body']),$tenant,$user);
        if(empty($stored['stored']))throw new \RuntimeException('配音音频保存失败');
        return ['audio_uri'=>$stored['uri'],'mime'=>'audio/mpeg','size'=>$stored['file_size'],'duration'=>$stored['duration'],'storage_scope'=>$stored['storage_scope'],'storage_engine'=>$stored['storage_engine'],'storage_domain'=>$stored['storage_domain'],'tenant_id'=>$tenant,'user_id'=>$user];
    }
    public static function query(int $tenant,int $user,int $task): array
    {
        $row=AiAppTask::where(['id'=>$task,'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_llm','action_code'=>self::ACTION])->find();
        if(!$row)throw new \InvalidArgumentException('配音任务不存在');
        return ['task_id'=>$task,'status'=>$row['status'],'results'=>((array)$row['result_summary'])['results']??[]];
    }
    private static function wake(array $log): void
    {
        Db::transaction(function()use($log){
            $task=AiAppTask::find($log['app_task_id']);$step=AgentStore::owner('step',(int)$log['tenant_id'],(int)$log['user_id'])->where(['id'=>$task['business_id'],'task_id'=>$task['id'],'tool_code'=>self::CODE])->find();if(!$step)return;
            $run=AgentStore::owner('run',(int)$log['tenant_id'],(int)$log['user_id'])->where('id',$step['run_id'])->lock(true)->find();
            if(!$run||(int)$run['cancel_requested']||!in_array($run['status'],['needs_review','waiting_tasks'],true)||($run['lease_token']!==''&&(int)$run['lease_until']>time())||in_array($step['status'],['success','failed','canceled'],true))return;
            AgentStore::table('step')->where('id',$step['id'])->update(['status'=>'running','error'=>'','update_time'=>time()]);
            AgentStore::table('run')->where('id',$run['id'])->update(['status'=>'waiting_tasks','phase'=>'tools','error'=>'','next_run_time'=>0,'update_time'=>time()]);
        });
    }
    public static function scan(): void
    {
        $rows=AiConsumptionLog::where(['app_code'=>'aigc_llm','action_code'=>self::ACTION,'billing_status'=>'reserved'])->where('update_time','<',time()-60)->order('update_time')->limit(5)->select();
        foreach($rows as $row)try{self::reconcile((int)$row['id'],(int)$row['tenant_id']);}catch(\Throwable $e){self::uncertain((int)$row['id']);}
        // A crash after settlement but before waking the Agent must also recover.
        $tasks=AgentStore::table('step')->where('tool_code',self::CODE)->whereIn('status',['running','needs_review','submitting'])->where('task_id','>',0)->column('task_id');
        if($tasks)foreach(AiConsumptionLog::where(['app_code'=>'aigc_llm','action_code'=>self::ACTION])->whereIn('app_task_id',$tasks)->whereIn('billing_status',['settled','refunded'])->select() as $row)self::wake($row->toArray());
    }
    private static function request(string $url,string $key,array $payload,array $source): array
    {
        $ch=curl_init($url);$body='';$headers=[];
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>AgentStore::encode($payload),CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json','Accept: application/json'],CURLOPT_SSL_VERIFYPEER=>UpdateSourceClient::sslVerify($source),CURLOPT_SSL_VERIFYHOST=>UpdateSourceClient::sslVerify($source)?2:0,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$headers){if(preg_match('~^HTTP/~i',$line))$headers=[];$pair=explode(':',$line,2);$name=strtolower(trim($pair[0]));if(count($pair)===2&&in_array($name,['x-request-id','request-id','x-task-id'],true))$headers[$name]=substr(trim($pair[1]),0,200);return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$body){if(strlen($body)+strlen($part)>2097152)return 0;$body.=$part;return strlen($part);}]);
        try{$complete=curl_exec($ch)!==false;return ['http_status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'body'=>$body,'headers'=>$headers,'complete'=>$complete,'transport_errno'=>curl_errno($ch)];}finally{curl_close($ch);}
    }
}
