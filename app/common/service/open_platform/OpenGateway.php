<?php
namespace app\common\service\open_platform;
use app\common\service\update\UpdateSourceClient;
use think\facade\Db;
final class OpenGateway
{
    public static function error(\Throwable $error,string $path,string $id='')
    {
        $type=$error instanceof OpenException?$error->errorType:($error instanceof \InvalidArgumentException?'invalid_request':'service_unavailable');$status=$error instanceof OpenException?$error->httpStatus:($error instanceof \InvalidArgumentException?400:503);$message=$error instanceof OpenException||$error instanceof \InvalidArgumentException?$error->getMessage():'开放平台暂不可用';
        $tool=$path==='/api/v1/tools'||str_starts_with($path,'/api/v1/tools/')||str_starts_with($path,'/api/v1/assets');
        $payload=$tool?['request_id'=>$id,'error'=>['type'=>$type,'message'=>$message]]:(str_ends_with($path,'/messages')?['type'=>'error','error'=>['type'=>$type,'message'=>$message]]:(in_array($path,['/api/v1/chat/completions','/api/v1/responses'],true)?['error'=>['type'=>$type,'message'=>$message,'code'=>$type]]:['code'=>0,'msg'=>$message,'data'=>[]]));return json($payload,$status)->header(['X-Request-Id'=>$id]);
    }
    public static function handle($request,string $path)
    {
        $key=OpenKeyService::authenticate($request);OpenRateLimiter::admit($key);$method=strtoupper($request->method());$params=$method==='GET'?$request->get():$request->post();$params=(array)$params;
        $contentType=strtolower((string)$request->header('content-type',''));if($method!=='GET'&&str_contains($contentType,'application/json')){if(strlen($request->getInput())>(int)OpenStore::limits((int)$key['tenant_id'])['body_limit'])throw new OpenException('payload_too_large','请求过大',413);$decoded=json_decode($request->getInput(),false,64);if(!str_starts_with(ltrim($request->getInput()),'{')||json_last_error()!==JSON_ERROR_NONE||!($decoded instanceof \stdClass))throw new OpenException('invalid_request','请求必须是 JSON 对象');$params=(array)$decoded;}
        OpenPolicy::validateInput($params);$originalParams=$params;
        if((string)$request->header('x-open-debug','')==='1'){OpenStore::requireEnabled((int)$key['tenant_id']);if(empty(OpenStore::settings((int)$key['tenant_id'])['features']['playground'])||empty(OpenStore::settings(0)['features']['playground']))throw new OpenException('feature_disabled','在线调试已关闭',403);}
        $previewToken=(string)$request->header('x-open-contract-preview','');$idem=OpenDirectory::supports($method,$path)&&$previewToken===''?'':(string)$request->header('idempotency-key','');$preview=$previewToken!==''?OpenContractPreview::resolve($previewToken,$key,$method,$path,$params,$idem,(array)$request->file()):null;$existing=OpenAdmission::replay($key,$method,$path,$params,$idem,(array)$request->file());if($existing)return self::replayResponse($existing,$path);
        if($method==='GET'&&$path==='/api/v1/tools'){$op=['kind'=>'market','executor'=>'directory','billable'=>false,'role'=>'resource','product_id'=>0];$budget=['user'=>0,'tenant'=>0,'snapshot'=>['contract'=>$op]];}
        elseif(str_starts_with($path,'/api/v1/assets')){if($path==='/api/v1/assets'&&$method==='POST')$op=['kind'=>'asset','app_code'=>'assets','billable'=>false,'role'=>'upload','params'=>[]];elseif($method==='GET'&&preg_match('#^/api/v1/assets/(of_[a-f0-9]{32})(/download)?$#D',$path,$m))$op=['kind'=>'asset','app_code'=>'assets','billable'=>false,'history'=>true,'role'=>isset($m[2])?'download':'detail','params'=>['file_id'=>$m[1]]];else throw new OpenException('not_found','素材接口不存在',404);$budget=['user'=>0,'tenant'=>0,'snapshot'=>[]];}
        elseif(str_starts_with($path,'/api/v1/tools/')){$registered=OpenToolRegistry::match($method,$path);$op=$preview??(in_array($registered['route'],['GET tasks','GET tasks/{task_id}','GET results'],true)?$registered+['kind'=>'tool']:OpenContracts::tool($method,$path));$op['history']=in_array($op['route'],['GET tasks','GET tasks/{task_id}','GET results'],true);if(!$op['history'])OpenToolAdapter::authorize((int)$key['tenant_id'],(int)$key['user_id'],$op['app_code']);if($method==='GET'||str_contains($contentType,'multipart/form-data'))$params=OpenSchema::query($params,$op['input']);OpenSchema::validateRequest(OpenFiles::forSchema($params,(array)$request->file(),$op['input']),$op['input'],$method!=='GET'&&str_contains($contentType,'application/json'));$params=OpenMaterials::nativeInputs($op['app_code'],$params);$params=OpenMaterials::resolve((int)$key['tenant_id'],(int)$key['user_id'],$params);$budget=OpenToolAdapter::budget($request,$op,$params);}
        else{$op=$preview??self::historicalMarket($key,$method,$path)??OpenContracts::match($method,$path,$params);$op['history']=in_array($op['role'],['query','cancel'],true);$directory=($op['executor']??'')==='directory';if($method==='GET'||str_contains($contentType,'multipart/form-data'))$params=OpenSchema::query($params,$op['input']);OpenSchema::validateRequest(OpenFiles::forSchema($params,(array)$request->file(),$op['input']),$op['input'],$method!=='GET'&&str_contains($contentType,'application/json'));if($directory){$op['history']=OpenDirectory::history($path);$budget=['user'=>0,'tenant'=>0,'snapshot'=>['contract'=>$op]];}else $budget=OpenMarketBilling::quote((int)$key['tenant_id'],$op,$params);if($op['history']&&!$directory){$owner=self::ownedTask($key,$op);$ownerSnapshot=OpenStore::json($owner['snapshot']);$op['scope']=(string)($ownerSnapshot['product_id']??$ownerSnapshot['contract']['product_id']??'market');}}
        if(isset($params['callback_url'])||isset($params['webhook_url']))throw new OpenException('invalid_callback','请通过 X-Open-Callback-URL 设置客户通知地址');
        OpenSafeHttp::checkInputs($params);
        $callback=(string)$request->header('x-open-callback-url','');if($callback!==''){if((empty(OpenStore::settings((int)$key['tenant_id'])['features']['webhooks'])||empty(OpenStore::settings(0)['features']['webhooks'])))throw new OpenException('feature_disabled','客户回调已关闭',403);OpenSafeHttp::resolve($callback);$secret=(string)$request->header('x-open-callback-secret','');if(strlen($secret)<32||strlen($secret)>256)throw new OpenException('invalid_callback','回调签名密钥须为 32 至 256 个字符');$budget['snapshot']['customer_callback']=['url'=>$callback,'secret_blob'=>OpenVault::seal($secret)];}
        if(($method==='GET'||str_contains($contentType,'multipart/form-data'))&&isset($op['input']))$originalParams=OpenSchema::query($originalParams,$op['input']);
        $admission=OpenAdmission::begin($key,$method,$path,$originalParams,$op,$budget,$idem,(array)$request->file());$row=$admission['row'];
        if($admission['replay'])return self::replayResponse($row,$path);
        if(($op['executor']??'')==='sse'||($op['service']??'')==='streamText')return new OpenStreamResponse(function()use($request,$row,$op,$params,$budget,$path){try{self::execute($request,$row,$op,$params,$budget,true);}catch(\Throwable $e){if(!headers_sent()){http_response_code($e instanceof OpenException?$e->httpStatus:503);header('Content-Type: application/json');echo self::error($e,$path,$row['request_id'])->getContent();}}},['X-Request-Id'=>$row['request_id']]);
        try{return self::execute($request,$row,$op,$params,$budget,false);}catch(\Throwable $e){return self::error($e,$path,$row['request_id']);}
    }

    private static function replayResponse(array $row,string $path)
    {
        if($row['response_blob']===''){
            // Recovery can conclusively close an interrupted receipt before an
            // HTTP response exists. Preserve that terminal outcome; a replay
            // must neither claim it is running nor dispatch it a second time.
            if((int)$row['active']===0&&in_array($row['billing_status'],['none','settled','refunded'],true)){
                if($row['status']==='failed')return self::error(new OpenException('request_failed','已有请求已失败，请通过请求 ID 查看调用记录',503),$path,$row['request_id']);
                if(in_array($row['status'],['cancelled','canceled'],true))return self::error(new OpenException('request_cancelled','已有请求已取消',409),$path,$row['request_id']);
            }
            return self::error(new OpenException('request_in_progress','已有请求正在执行或受理状态待确认',409),$path,$row['request_id']);
        }
        if($row['kind']==='asset'&&$row['method']==='GET'&&preg_match('#^/api/v1/assets/(of_[a-f0-9]{32})/download$#D',$row['path'],$match)){
            $asset=OpenAssets::detail((int)$row['tenant_id'],(int)$row['user_id'],$match[1]);return redirect($asset['url'])->header(['X-Request-Id'=>$row['request_id']]);
        }
        $snapshot=OpenStore::json($row['snapshot']);$headers=isset($snapshot['response_headers_blob'])?OpenStore::json(OpenVault::open($snapshot['response_headers_blob'])):[];
        return response(OpenVault::open($row['response_blob']),(int)$row['response_code'],$headers+['Content-Type'=>$row['response_type'],'X-Request-Id'=>$row['request_id']]);
    }

    public static function recordWire(string $id,array $fields,array $headers): void
    {
        Db::transaction(function()use($id,$fields,$headers){$row=OpenStore::table('request')->where('request_id',$id)->lock(true)->find();if(!$row)throw new OpenException('not_found','响应请求不存在',404);OpenCallContext::assertLease($row);$snapshot=OpenStore::json($row['snapshot']);$snapshot['response_headers_blob']=OpenVault::seal(OpenStore::encode($headers));OpenAdmission::finish($id,$fields+['snapshot'=>OpenStore::encode($snapshot)]);});
    }
    /** Preserve private evidence and known lifecycle even when public projection is unsupported. */
    private static function marketWire(array $row,array $financeRow,array $wire,array $fields): array
    {
        return Db::transaction(function()use($row,$financeRow,$wire,$fields){
            $owner=OpenStore::table('request')->where('request_id',$row['request_id'])->lock(true)->find();if(!$owner)throw new OpenException('not_found','响应请求不存在',404);OpenCallContext::assertLease($owner);
            $snapshot=OpenStore::json($owner['snapshot']);$snapshot['market_response_blob']=OpenVault::seal($wire['body']);$snapshot['market_response_status']=(int)$wire['status'];
            OpenAdmission::finish($row['request_id'],['snapshot'=>OpenStore::encode($snapshot)]);
            try{
                if(!empty($wire['acceptance_uncertain']))throw new OpenException('upstream_uncertain','上游未返回可信受理协议，原请求保留等待确认',502);
                if(!OpenMarketResponse::bodySupported($wire['body']))throw new OpenException('response_adapter_required','上游响应协议尚未适配，已保留原请求等待核对',502);
                $wire['body']=OpenMarketResponse::project($wire['body'],OpenMarketResponse::amounts($financeRow));
            }catch(OpenException $error){
                if(!in_array($error->errorType,['response_adapter_required','upstream_uncertain'],true))throw $error;
                $wire['body']=self::error($error,$row['path'],$row['request_id'])->getContent();$wire['status']=502;$wire['headers']=['content-type'=>'application/json'];$wire['adapter_blocked']=true;
                // This is a response-adaptation failure, not a failed upstream task.
                $fields['error_code']=$error->errorType;$fields['error_message']='上游协议尚未确认，原任务及计费状态保留';
            }
            $fields['response_blob']=OpenVault::seal($wire['body']);$fields['response_code']=$wire['status'];$fields['response_type']=$wire['headers']['content-type']??'application/json';
            self::recordWire($row['request_id'],$fields,$wire['headers']);return $wire;
        });
    }
    /** A transport-level 200 is not proof that a paid JSON operation was accepted. */
    private static function marketAccepted(array $operation,array $wire): bool
    {
        if(!preg_match('#^application/(?:[a-z0-9._+-]+\+)?json(?:\s*;|$)#i',(string)($wire['headers']['content-type']??'')))return false;
        $body=json_decode($wire['body'],false,64);if(json_last_error()!==JSON_ERROR_NONE||!($body instanceof \stdClass)||!(array)$body)return false;$data=(array)$body;
        if(($operation['response_adapter']??'')===OpenMarketResponse::ADAPTER&&!OpenMarketResponse::bodySupported($wire['body']))return false;
        if(!self::marketSchemaAccepted($operation,$wire,$body))return false;
        if(isset($operation['success_path']))return OpenSchema::get($data,$operation['success_path'])===($operation['success_value']??1);
        if(in_array($data['code']??null,[1,'1'],true))return true;
        $task=OpenSchema::get($data,$operation['task_id_path']??'data.task_id');if((is_string($task)||is_int($task))&&(string)$task!=='')return true;
        $schema=$operation['responses'][(string)$wire['status']]['content']['application/json']['schema']??[];
        // Explicit response dictionaries can confirm synchronous operations;
        // an unconstrained object or incidental envelope metadata cannot.
        $required=(array)($schema['required']??[]);$declared=array_intersect_key($data,(array)($schema['properties']??[]));
        if(!$required&&!array_diff(array_keys($declared),['code','msg','message','data']))return false;
        return true; // The declared schema was validated before the envelope shortcuts.
    }

    /** A shared supplier account must echo the locally owned task identity. */
    public static function marketQueryAccepted(array $wire,string $taskId,array $operation): bool
    {
        $data=OpenStore::json($wire['body']);$actual=OpenSchema::get($data,'data.task_id');
        $query=$operation;unset($query['responses']);if(!empty($operation['query_responses']))$query['responses']=$operation['query_responses'];
        return (is_string($actual)||is_int($actual))&&(string)$actual===$taskId&&self::marketAccepted($query,$wire);
    }
    /** Failed queries cannot publish another task's business data. Plain errors have no task result. */
    public static function marketQueryFailureSafe(array $wire,string $taskId,array $operation): bool
    {
        if(!preg_match('#^application/(?:[a-z0-9._+-]+\\+)?json(?:\\s*;|$)#i',(string)($wire['headers']['content-type']??'')))return false;
        $body=json_decode($wire['body'],false,64);if(json_last_error()!==JSON_ERROR_NONE||!($body instanceof \stdClass)||!(array)$body)return false;
        if(($operation['response_adapter']??'')===OpenMarketResponse::ADAPTER&&!OpenMarketResponse::bodySupported($wire['body']))return false;
        $actual=OpenSchema::get((array)$body,'data.task_id');
        if($actual!==null){if(!(is_string($actual)||is_int($actual))||(string)$actual!==$taskId)return false;$query=$operation;unset($query['responses']);if(!empty($operation['query_responses']))$query['responses']=$operation['query_responses'];return self::marketSchemaAccepted($query,$wire,$body);}
        if(array_diff(array_keys((array)$body),['code','msg','message','error','data']))return false;
        $data=$body->data??null;return $data===null||$data instanceof \stdClass&&!(array)$data||is_array($data)&&$data===[];
    }
    private static function marketSchemaAccepted(array $operation,array $wire,\stdClass $body): bool
    {
        if(empty($operation['responses']))return true;
        $responses=$operation['responses'];
        if(($operation['response_adapter']??'')===OpenMarketResponse::ADAPTER){if(!OpenMarketResponse::schemaSupported($responses))return false;$responses=OpenMarketResponse::retailSchemas($responses);}
        $response=$responses[(string)$wire['status']]??null;if(!$response)return false;
        $schema=$response['content']['application/json']['schema']??null;if($schema===null)return true;if(!is_array($schema))return false;
        try{OpenSchema::validate($body,$schema,'$',0,null,true);return true;}catch(OpenException){}
        if(($operation['response_adapter']??'')!==OpenMarketResponse::ADAPTER)return false;
        // Decimal DB columns may serialize as numeric strings. Only registered
        // monetary nodes are coerced in this validation copy; the wire stays typed.
        $copy=json_decode(OpenStore::encode($body));
        $coerce=static function($value,string $path='')use(&$coerce){if(OpenMarketResponse::retailPath($path)&&is_string($value)&&is_numeric($value)&&is_finite((float)$value))return (float)$value;if($value instanceof \stdClass)foreach($value as $key=>$child)$value->$key=$coerce($child,$path===''?(string)$key:$path.'.'.$key);elseif(is_array($value))foreach($value as $key=>$child)$value[$key]=$coerce($child,$path.'.[]');return $value;};
        try{OpenSchema::validate($coerce($copy),$schema,'$',0,null,true);return true;}catch(OpenException){return false;}
    }

    /** A bound supplier channel is server routing, not an extra SDK argument. */
    private static function marketParameters(array $operation,array $params): array
    {
        if(empty($operation['history'])&&($operation['native_pricing']??'')===OpenCharacterInputPricing::ADAPTER)$params=OpenCharacterInputPricing::transportParameters($operation,$params);
        if(empty($operation['history'])&&isset($operation['model'])&&!empty($operation['routing_channel'])){
            if(isset($params['channel'])&&$params['channel']!==$operation['routing_channel'])throw new OpenException('invalid_sku','渠道与所选接口不一致');
            $params['channel']=$operation['routing_channel'];
        }
        return $params;
    }

    private static function historicalMarket(array $key,string $method,string $path): ?array
    {
        if($method!=='GET'||!preg_match('#^/api/v1/tasks/([^/]+)$#D',$path,$m))return null;
        $row=OpenStore::owner('request',(int)$key['tenant_id'],(int)$key['user_id'])->where(['kind'=>'market','upstream_task_id'=>$m[1]])->find();if(!$row)throw new OpenException('not_found','任务不存在',404);
        $snapshot=OpenStore::json($row['snapshot']);$original=$snapshot['contract']??[];
        if(($original['query_path']??'')!=='/api/v1/tasks/{task_id}')throw new OpenException('not_found','任务查询协议未注册',404);
        return array_replace($original,['kind'=>'market','method'=>'GET','role'=>'query','billable'=>false,'executor'=>'json','input'=>['type'=>'object'],'params'=>['task_id'=>$m[1]],'product_id'=>$snapshot['product_id']??$original['product_id']??0,'history'=>true]);
    }
    private static function ownedTask(array $key,array $op): array
    {
        $task=$op['params']['task_id']??'';$row=OpenStore::owner('request',(int)$key['tenant_id'],(int)$key['user_id'])->where(['kind'=>'market','upstream_task_id'=>$task])->find();if(!$row)throw new OpenException('not_found','任务不存在',404);return $row;
    }
    private static function execute($request,array $row,array $op,array $params,array $budget,bool $stream)
    {
        $start=microtime(true);OpenCallContext::enter($row['request_id']);$dispatched=false;$connectionLost=false;
        try{
            // Claim before dispatch_started or native preparation. Rejected
            // claims bypass the execution-error writer and preserve worker state.
            if(OpenImageBatchDispatch::supports($row)){
                $prepared=OpenImagePricing::prepared((int)$row['tenant_id'],(int)$row['user_id'],$row['app_code']);
                OpenImageBatchDispatch::start((int)$row['tenant_id'],(int)$row['user_id'],(int)($prepared['estimate']['quantity']??0));
            }
        try{
            if($op['kind']==='asset'){$data=$op['role']==='upload'?OpenAssets::upload((int)$row['tenant_id'],(int)$row['user_id'],(string)($params['type']??'file')):OpenAssets::detail((int)$row['tenant_id'],(int)$row['user_id'],$op['params']['file_id']);$body=OpenStore::encode(['request_id'=>$row['request_id'],'data'=>$data]);OpenAdmission::finish($row['request_id'],['status'=>'success','active'=>0,'billing_status'=>'none','response_code'=>$op['role']==='download'?302:200,'response_blob'=>OpenVault::seal($body),'next_run_time'=>0]);return $op['role']==='download'?redirect($data['url'])->header(['X-Request-Id'=>$row['request_id']]):json(['request_id'=>$row['request_id'],'data'=>$data])->header(['X-Request-Id'=>$row['request_id']]);}
            if($op['kind']==='tool'){
                self::markDispatch($row['request_id']);$dispatched=true;$streamBody='';$data=OpenToolAdapter::call($request,$op,$params,$stream?function($event)use(&$streamBody){$chunk='data: '.OpenStore::encode(OpenPolicy::publicData((array)$event))."\n\n";$streamBody.=$chunk;echo $chunk;@flush();}:null);$dispatched=true;
                if($op['app_code']==='aigc_llm'&&in_array($op['service']??'',['generateText','streamText'],true))$data+=['task_id'=>(string)$row['id'],'status'=>'success'];$native=$data['task_id']??OpenStore::table('request')->where('id',$row['id'])->value('tool_task_id');if($native==='')$native=null;$body=OpenStore::encode(['request_id'=>$row['request_id'],'data'=>OpenToolTasks::publicResult($op['app_code'],$op,$data)]);$done=$stream?'event: done'."\n".'data: '.$body."\n\n":'';$replayBody=$stream?$streamBody.$done:$body;$latest=OpenStore::table('request')->where('id',$row['id'])->find();$lifecycle=self::toolLifecycle($latest,$op,$data);$pending=(bool)$lifecycle['active'];
                OpenAdmission::finish($row['request_id'],$lifecycle+['tool_task_id'=>(string)($native??''),'response_blob'=>OpenVault::seal($replayBody),'response_type'=>$stream?'text/event-stream':'application/json','response_code'=>200,'duration_ms'=>(int)((microtime(true)-$start)*1000)]);
                if(!$pending)OpenWebhooks::enqueue(OpenStore::table('request')->where('id',$row['id'])->find(),OpenToolTasks::publicResult($op['app_code'],$op,$data));
                if($stream){echo $done;@flush();return response('');}return response($body,200,['Content-Type'=>'application/json','X-Request-Id'=>$row['request_id']]);
            }
            if(($op['executor']??'')==='directory'){$wire=OpenDirectory::execute(OpenStore::table('key')->where('id',$row['key_id'])->find(),$row['path'],$params);if($row['path']==='/api/v1/tools')$wire['body']=OpenStore::encode(['request_id'=>$row['request_id']]+OpenStore::json($wire['body']));OpenAdmission::finish($row['request_id'],['status'=>self::wireFailed($wire['status'],OpenStore::json($wire['body']),$op)?'failed':'success','billing_status'=>'none','active'=>0,'response_blob'=>OpenVault::seal($wire['body']),'response_type'=>$wire['headers']['content-type']??'application/json','response_code'=>$wire['status'],'duration_ms'=>(int)((microtime(true)-$start)*1000),'next_run_time'=>0]);return response($wire['body'],$wire['status'],$wire['headers']+['X-Request-Id'=>$row['request_id']]);}
            $source=UpdateSourceClient::getSource();$transportSource=['base_url'=>$source['active_base_url'],'api_key'=>$source['active_api_key'],'ssl_verify'=>UpdateSourceClient::sslVerify($source)];if(!empty($op['history'])){$owner=self::ownedTask(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id']],$op);$ownerSnapshot=OpenStore::json($owner['snapshot']);if(!empty($ownerSnapshot['source_blob']))$transportSource=OpenStore::json(OpenVault::open($ownerSnapshot['source_blob']));}
            if(empty($transportSource['base_url'])||empty($transportSource['api_key']))throw new OpenException('source_unavailable','上游渠道未配置',503);
            OpenMarketBilling::reserve($row,$budget);$snapshot=array_replace(OpenStore::json(OpenStore::table('request')->where('id',$row['id'])->value('snapshot')),$budget['snapshot']);$snapshot['source_blob']=OpenVault::seal(OpenStore::encode($transportSource));OpenAdmission::finish($row['request_id'],['snapshot'=>OpenStore::encode($snapshot)]);
            $headers=[];foreach(['accept','anthropic-version','anthropic-beta','openai-beta','idempotency-key'] as $name){$value=(string)$request->header($name,'');if($value!=='')$headers[$name]=$value;}
            self::markDispatch($row['request_id']);$dispatched=true;$wire=(new OpenTransport())->send($transportSource,$row['method'],$row['path'],self::marketParameters($op,$params),(array)$request->file(),$stream?function($chunk,$status,$responseHeaders){if(!headers_sent()){http_response_code($status);foreach($responseHeaders as $name=>$value)header($name.': '.$value);}echo $chunk;@flush();}:null,$headers,(string)$request->header('content-type',''));
            $data=json_decode($wire['body'],true);$data=is_array($data)?$data:[];$taskValue=OpenSchema::get($data,$op['task_id_path']??'data.task_id');$task=is_string($taskValue)||is_int($taskValue)?(string)$taskValue:'';$failed=self::wireFailed($wire['status'],$data,$op);$fields=['response_blob'=>OpenVault::seal($wire['body']),'response_type'=>$wire['headers']['content-type']??'application/json','response_code'=>$wire['status'],'duration_ms'=>(int)((microtime(true)-$start)*1000)];
            $untrusted=!empty($op['billable'])&&$op['role']==='submit'&&($op['executor']??'')==='json'&&!$failed&&!self::marketAccepted($op,$wire);
            if($untrusted)$wire['acceptance_uncertain']=true;
            if(!empty($op['billable'])&&$op['role']==='submit'&&(self::wireUncertain($wire['status'])||$untrusted)){self::deferMarketAcceptance($row,$task);$fields+=['status'=>$task!==''?'running':'pending_confirmation'];}
            elseif($task!==''&&!$failed&&$op['role']==='submit'){$fields+=['upstream_task_id'=>$task,'status'=>'running','next_run_time'=>time()+30];\app\common\model\ai\AiConsumptionLog::where('consume_no',$row['request_id'])->update(['upstream_task_id'=>$task,'run_status'=>'running']);}
            else{$fields+=['status'=>$failed?'failed':'success'];if(!empty($op['billable'])){if($stream)$data=self::streamUsage($wire['body']);self::settleKnownResult($row,$data,$failed);}else $fields+=['active'=>0,'next_run_time'=>0];}
            $financeRow=$row;
            if($op['history']){$owner=self::ownedTask(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id']],$op);$financeRow=$owner;if($failed){if(!self::marketQueryFailureSafe($wire,$owner['upstream_task_id'],$op))$wire['acceptance_uncertain']=true;}elseif(self::marketQueryAccepted($wire,$owner['upstream_task_id'],$op))self::observeTask($owner,$data);else $wire['acceptance_uncertain']=true;}
            if(($op['response_adapter']??'')===OpenMarketResponse::ADAPTER||!empty($wire['acceptance_uncertain'])){
                $financeRow=OpenStore::table('request')->where('id',$financeRow['id'])->find();
                $wire=self::marketWire($row,$financeRow,$wire,$fields);$data=OpenStore::json($wire['body']);
            }else self::recordWire($row['request_id'],$fields,$wire['headers']);
            if(!$op['history']&&empty($wire['adapter_blocked'])){$latest=OpenStore::table('request')->where('id',$row['id'])->find();if((int)$latest['active']===0)OpenWebhooks::enqueue($latest,$data);}
            return $stream?response(''):response($wire['body'],$wire['status'],$wire['headers']+['X-Request-Id'=>$row['request_id']]);
        }catch(\Throwable $e){if(OpenRecovery::connectionLost($e)||($e instanceof OpenException&&$e->errorType==='recovery_lease_lost'))throw $e;$unknown=$dispatched;
            if($op['kind']==='tool'){$latest=OpenStore::table('request')->where('id',$row['id'])->find();if(!empty(OpenStore::json($latest['snapshot'])['provider_rejection_confirmed']))$unknown=false;}
            if($op['kind']==='tool'&&$e instanceof OpenException&&$e->errorType==='tool_error'){$latest=OpenStore::table('request')->where('id',$row['id'])->find();if(empty($op['billable'])||($latest['tool_task_id']===''&&!OpenStore::json($latest['consumption_ids'])&&($op['controller']??'')==='Generate'&&!empty(OpenToolRegistry::get($op['app_code'])['task_model'])))$unknown=false;}
            if(!$unknown&&$op['kind']==='market'&&!empty($op['billable'])){try{OpenMarketBilling::settle($row,[],true);}catch(\Throwable $settlementError){if(OpenRecovery::connectionLost($settlementError)){$connectionLost=true;throw $settlementError;}$unknown=true;}}$failedLatest=OpenStore::table('request')->where('id',$row['id'])->find();$billing=self::failureBilling($failedLatest,$op,$unknown);$unresolved=$unknown||!in_array($billing,['none','settled','refunded'],true);$fields=['status'=>$unknown?'pending_confirmation':'failed','billing_status'=>$billing,'error_code'=>$e instanceof OpenException?$e->errorType:'internal_error','error_message'=>$e instanceof OpenException?mb_substr($e->getMessage(),0,500):'服务执行失败','active'=>$unresolved?1:0,'next_run_time'=>$unresolved?time()+60:0];if(!$unknown){$error=self::error($e,$row['path'],$row['request_id']);$fields+=['response_blob'=>OpenVault::seal($error->getContent()),'response_type'=>'application/json','response_code'=>$error->getCode()];}
            OpenAdmission::finish($row['request_id'],$fields);if(!$unknown&&!($e instanceof OpenException)&&$op['kind']==='tool'&&!empty(OpenStore::json($latest['snapshot']??'')['provider_rejection_confirmed']))throw new OpenException('provider_rejected','上游已拒绝该请求，请检查工具当前模型资格',400);throw $e;}
        }catch(\Throwable $error){$connectionLost=OpenRecovery::connectionLost($error);throw $error;}finally{try{if(!$connectionLost)OpenImageBatchDispatch::release($row['request_id']);}finally{OpenCallContext::leave();}}
    }
    public static function failureBilling(array $latest,array $op,bool $unknown): string
    {
        if($unknown)return 'pending_confirmation';if(empty($op['billable']))return 'none';
        if($op['kind']==='tool')return OpenToolTasks::billing(array_replace($latest,['status'=>'failed']));
        return in_array($latest['billing_status'],['settled','refunded','pending_usage','settlement_failed'],true)?$latest['billing_status']:((float)$latest['actual_user']>0?'settlement_failed':'none');
    }
    /** A free read is a receipt; an operation that created a native task has a lifecycle. */
    public static function toolLifecycle(array $row,array $op,array $data): array
    {
        $tracked=!empty($op['billable'])||($row['method']==='POST'&&$row['tool_task_id']!=='');
        if(!$tracked)return ['status'=>'success','active'=>0,'billing_status'=>'none','next_run_time'=>0,'finish_time'=>time()];
        $status=(string)($data['status']??($row['tool_task_id']!==''?'running':'success'));
        $billing=OpenToolTasks::billing(array_replace($row,['status'=>$status]));
        $pending=in_array($status,['pending','queued','running','submitting','processing','pending_confirmation'],true)||in_array($billing,['pending_usage','pending','pending_confirmation','settlement_failed'],true);
        return ['status'=>$status,'active'=>$pending?1:0,'billing_status'=>$pending&&$billing==='settled'?'pending':$billing,'next_run_time'=>$pending?time()+30:0,'finish_time'=>in_array($status,['success','completed','failed','cancelled','canceled','partial_success','partial_failed'],true)?time():0];
    }
    public static function settleKnownResult(array $row,array $data,bool $failed): void
    {
        $contract=OpenStore::json($row['snapshot'])['contract']??[];
        // An explicitly free asynchronous operation has no consumption to settle.
        // Close its receipt without manufacturing a bill or leaving a live job forever.
        if(($contract['billable']??null)===false&&!OpenStore::json($row['consumption_ids'])&&(float)$row['actual_user']===0.&&(float)$row['actual_tenant']===0.&&(float)$row['budget_user']===0.&&(float)$row['budget_tenant']===0.){
            OpenAdmission::finish($row['request_id'],['status'=>$failed?'failed':'success','billing_status'=>'none','active'=>0,'finish_time'=>time(),'next_run_time'=>0]);return;
        }
        try{OpenMarketBilling::settle($row,$data,$failed);}catch(\Throwable $error){if(OpenRecovery::connectionLost($error))throw $error;OpenAdmission::finish($row['request_id'],['status'=>$failed?'failed':'success','billing_status'=>'settlement_failed','active'=>1,'error_code'=>$error instanceof OpenException?$error->errorType:'settlement_failed','error_message'=>'结果已确认，结算待核对','next_run_time'=>time()+60]);}
    }
    public static function wireUncertain(int $status): bool { return in_array($status,[408,425],true)||$status>=500; }
    public static function deferMarketAcceptance(array $row,string $taskId=''): void
    {
        Db::transaction(function()use($row,$taskId){
            $status=$taskId!==''?'running':'pending_confirmation';
            \app\common\model\ai\AiConsumptionLog::where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'consume_no'=>$row['request_id']])->whereNotIn('billing_status',['settled','refunded'])->update(['run_status'=>$status,'upstream_task_id'=>$taskId,'update_time'=>time()]);
            OpenAdmission::finish($row['request_id'],['upstream_task_id'=>$taskId,'status'=>$status,'billing_status'=>'pending_confirmation','active'=>1,'error_code'=>'pending_confirmation','error_message'=>'上游受理状态待确认','next_run_time'=>time()+60]);
        });
    }
    private static function markDispatch(string $id): void
    {
        Db::transaction(function()use($id){$row=OpenStore::table('request')->where('request_id',$id)->lock(true)->find();$snapshot=OpenStore::json($row['snapshot']);$snapshot['dispatch_started']=true;OpenAdmission::finish($id,['snapshot'=>OpenStore::encode($snapshot)]);});
    }
    /** A definitive protocol rejection is refundable even when its HTTP envelope is 200. */
    public static function wireFailed(int $status,array $data,array $contract): bool
    {
        if($status>=400||isset($data['error']))return true;
        if(isset($contract['success_path']))return OpenSchema::get($data,$contract['success_path'])!==($contract['success_value']??1);
        return array_key_exists('code',$data)&&in_array($data['code'],[0,'0'],true);
    }
    public static function streamUsage(string $body): array
    {
        $result=[];foreach(preg_split('/\r?\n/',$body) as $line){if(!str_starts_with($line,'data:'))continue;$event=json_decode(trim(substr($line,5)),true);if(!is_array($event))continue;foreach([$event,$event['response']??[],$event['message']??[]] as $item)if(isset($item['usage'])&&is_array($item['usage']))$result['usage']=array_replace($result['usage']??[],$item['usage']);}return $result;
    }
    public static function observeTask(array $row,array $data): void
    {
        $snapshot=OpenStore::json($row['snapshot']);$contract=$snapshot['contract']??[];$status=(string)OpenSchema::get($data,$contract['status_path']??'data.status');
        if(in_array($status,$contract['success_statuses']??['success','completed','succeeded'],true))self::settleKnownResult($row,$data,false);
        elseif(in_array($status,$contract['failure_statuses']??['failed','cancelled','canceled'],true))self::settleKnownResult($row,$data,true);
    }
}
