<?php
namespace app\common\service\open_platform;
use app\common\service\update\UpdateSourceClient;
use think\facade\Db;
final class OpenGateway
{
    public static function error(\Throwable $error,string $path,string $id='')
    {
        $type=$error instanceof OpenException?$error->errorType:($error instanceof \InvalidArgumentException?'invalid_request':'service_unavailable');$status=$error instanceof OpenException?$error->httpStatus:($error instanceof \InvalidArgumentException?400:503);$message=$error instanceof OpenException||$error instanceof \InvalidArgumentException?$error->getMessage():'开放平台暂不可用';
        $payload=str_contains($path,'/tools/')?['request_id'=>$id,'error'=>['type'=>$type,'message'=>$message]]:(str_ends_with($path,'/messages')?['type'=>'error','error'=>['type'=>$type,'message'=>$message]]:['error'=>['type'=>$type,'message'=>$message,'code'=>$type]]);return json($payload,$status)->header(['X-Request-Id'=>$id]);
    }
    public static function handle($request,string $path)
    {
        $key=OpenKeyService::authenticate($request);$method=strtoupper($request->method());$params=$method==='GET'?$request->get():$request->post();$params=(array)$params;
        $contentType=strtolower((string)$request->header('content-type',''));if($method!=='GET'&&str_contains($contentType,'application/json')){if(strlen($request->getInput())>(int)OpenStore::settings((int)$key['tenant_id'])['body_limit'])throw new OpenException('payload_too_large','请求过大',413);$decoded=json_decode($request->getInput(),true,64);if(json_last_error()!==JSON_ERROR_NONE||!is_array($decoded)||($decoded!==[]&&array_is_list($decoded)))throw new OpenException('invalid_request','请求必须是 JSON 对象');$params=$decoded;}
        OpenPolicy::validateInput($params);
        if((string)$request->header('x-open-debug','')==='1'){OpenStore::requireEnabled((int)$key['tenant_id']);if(empty(OpenStore::settings((int)$key['tenant_id'])['features']['playground'])||empty(OpenStore::settings(0)['features']['playground']))throw new OpenException('feature_disabled','在线调试已关闭',403);}
        $existing=OpenAdmission::replay($key,$method,$path,$params,(string)$request->header('idempotency-key',''),(array)$request->file());if($existing)return self::replayResponse($existing,$path);
        if($method==='GET'&&$path==='/api/v1/tools')return json(['data'=>array_values(array_filter(OpenCatalog::forUser((int)$key['tenant_id'],(int)$key['user_id']),fn($item)=>$item['kind']==='tool'))]);
        if(str_starts_with($path,'/api/v1/assets')){if($path==='/api/v1/assets'&&$method==='POST')$op=['kind'=>'asset','app_code'=>'assets','billable'=>false,'role'=>'upload','params'=>[]];elseif($method==='GET'&&preg_match('#^/api/v1/assets/(of_[a-f0-9]{32})(/download)?$#D',$path,$m))$op=['kind'=>'asset','app_code'=>'assets','billable'=>false,'history'=>true,'role'=>isset($m[2])?'download':'detail','params'=>['file_id'=>$m[1]]];else throw new OpenException('not_found','素材接口不存在',404);$budget=['user'=>0,'tenant'=>0,'snapshot'=>[]];}
        elseif(str_starts_with($path,'/api/v1/tools/')){$registered=OpenToolRegistry::match($method,$path);$op=in_array($registered['route'],['GET tasks','GET tasks/{task_id}','GET results'],true)?$registered+['kind'=>'tool']:OpenContracts::tool($method,$path);$op['history']=in_array($op['route'],['GET tasks','GET tasks/{task_id}','GET results'],true);if(!$op['history'])OpenToolAdapter::authorize((int)$key['tenant_id'],(int)$key['user_id'],$op['app_code']);OpenSchema::validate(OpenFiles::forSchema($params,(array)$request->file(),$op['input']),$op['input']);$budget=OpenToolAdapter::budget($request,$op,$params);}
        else{$op=self::historicalMarket($key,$method,$path)??OpenContracts::match($method,$path,$params);$op['history']=in_array($op['role'],['query','cancel'],true);$budget=OpenMarketBilling::quote((int)$key['tenant_id'],$op,$params);OpenSchema::validate(OpenFiles::forSchema($params,(array)$request->file(),$op['input']),$op['input']);if($op['history']){$owner=self::ownedTask($key,$op);$ownerSnapshot=OpenStore::json($owner['snapshot']);$op['scope']=(string)($ownerSnapshot['product_id']??$ownerSnapshot['contract']['product_id']??'market');}}
        if(isset($params['callback_url'])||isset($params['webhook_url']))throw new OpenException('invalid_callback','请通过 X-Open-Callback-URL 设置客户通知地址');
        OpenSafeHttp::checkInputs($params);
        $callback=(string)$request->header('x-open-callback-url','');if($callback!==''){if((empty(OpenStore::settings((int)$key['tenant_id'])['features']['webhooks'])||empty(OpenStore::settings(0)['features']['webhooks'])))throw new OpenException('feature_disabled','客户回调已关闭',403);OpenSafeHttp::resolve($callback);$secret=(string)$request->header('x-open-callback-secret','');if(strlen($secret)<32||strlen($secret)>256)throw new OpenException('invalid_callback','回调签名密钥须为 32 至 256 个字符');$budget['snapshot']['customer_callback']=['url'=>$callback,'secret_blob'=>OpenVault::seal($secret)];}
        $admission=OpenAdmission::begin($key,$method,$path,$params,$op,$budget,(string)$request->header('idempotency-key',''),(array)$request->file());$row=$admission['row'];
        if($admission['replay'])return self::replayResponse($row,$path);
        if(($op['executor']??'')==='sse'||($op['service']??'')==='streamText')return new OpenStreamResponse(function()use($request,$row,$op,$params,$budget,$path){try{self::execute($request,$row,$op,$params,$budget,true);}catch(\Throwable $e){if(!headers_sent()){http_response_code($e instanceof OpenException?$e->httpStatus:503);header('Content-Type: application/json');echo self::error($e,$path,$row['request_id'])->getContent();}}},['X-Request-Id'=>$row['request_id']]);
        try{return self::execute($request,$row,$op,$params,$budget,false);}catch(\Throwable $e){return self::error($e,$path,$row['request_id']);}
    }

    private static function replayResponse(array $row,string $path)
    {
        if($row['response_blob']==='')return self::error(new OpenException('request_in_progress','已有请求正在执行或受理状态待确认',409),$path,$row['request_id']);
        return response(OpenVault::open($row['response_blob']),(int)$row['response_code'],['Content-Type'=>$row['response_type'],'X-Request-Id'=>$row['request_id']]);
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
        $start=microtime(true);OpenCallContext::enter($row['request_id']);$dispatched=false;
        try{
            if($op['kind']==='asset'){$data=$op['role']==='upload'?OpenAssets::upload((int)$row['tenant_id'],(int)$row['user_id'],(string)($params['type']??'file')):OpenAssets::detail((int)$row['tenant_id'],(int)$row['user_id'],$op['params']['file_id']);$body=OpenStore::encode(['request_id'=>$row['request_id'],'data'=>$data]);OpenAdmission::finish($row['request_id'],['status'=>'success','active'=>0,'billing_status'=>'none','response_code'=>200,'response_blob'=>OpenVault::seal($body),'next_run_time'=>0]);return $op['role']==='download'?redirect($data['url']):json(['request_id'=>$row['request_id'],'data'=>$data])->header(['X-Request-Id'=>$row['request_id']]);}
            if($op['kind']==='tool'){
                self::markDispatch($row['request_id']);$dispatched=true;$data=OpenToolAdapter::call($request,$op,$params,$stream?function($event){echo 'data: '.OpenStore::encode(OpenPolicy::publicData((array)$event))."\n\n";@flush();}:null);$dispatched=true;
                $native=$data['task_id']??null;$status=(string)($data['status']??($native!==null?'running':'success'));$body=OpenStore::encode(['request_id'=>$row['request_id'],'data'=>OpenToolTasks::publicResult($op['app_code'],$op,$data)]);$pending=in_array($status,['pending','queued','running','submitting','processing'],true);$latest=OpenStore::table('request')->where('id',$row['id'])->find();$billing=empty($op['billable'])?'none':OpenToolTasks::billing($latest);$pending=$pending||in_array($billing,['pending_usage','pending','settlement_failed'],true);
                OpenAdmission::finish($row['request_id'],['tool_task_id'=>(string)($native??''),'status'=>$status,'active'=>$pending?1:0,'billing_status'=>$pending&&$billing==='settled'?'pending':$billing,'response_blob'=>OpenVault::seal($body),'response_type'=>'application/json','response_code'=>200,'duration_ms'=>(int)((microtime(true)-$start)*1000),'next_run_time'=>$pending?time()+30:0]);
                if(!$pending)OpenWebhooks::enqueue(OpenStore::table('request')->where('id',$row['id'])->find(),OpenToolTasks::publicResult($op['app_code'],$op,$data));
                if($stream){echo 'event: done'."\n".'data: '.$body."\n\n";@flush();return response('');}return response($body,200,['Content-Type'=>'application/json','X-Request-Id'=>$row['request_id']]);
            }
            $source=UpdateSourceClient::getSource();$transportSource=['base_url'=>$source['active_base_url'],'api_key'=>$source['active_api_key'],'ssl_verify'=>UpdateSourceClient::sslVerify($source)];if(!empty($op['history'])){$owner=self::ownedTask(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id']],$op);$ownerSnapshot=OpenStore::json($owner['snapshot']);if(!empty($ownerSnapshot['source_blob']))$transportSource=OpenStore::json(OpenVault::open($ownerSnapshot['source_blob']));}
            if(empty($transportSource['base_url'])||empty($transportSource['api_key']))throw new OpenException('source_unavailable','上游渠道未配置',503);
            OpenMarketBilling::reserve($row,$budget);$snapshot=array_replace(OpenStore::json(OpenStore::table('request')->where('id',$row['id'])->value('snapshot')),$budget['snapshot']);$snapshot['source_blob']=OpenVault::seal(OpenStore::encode($transportSource));OpenAdmission::finish($row['request_id'],['snapshot'=>OpenStore::encode($snapshot)]);
            $headers=[];foreach(['accept','anthropic-version','anthropic-beta','openai-beta','idempotency-key'] as $name){$value=(string)$request->header($name,'');if($value!=='')$headers[$name]=$value;}
            self::markDispatch($row['request_id']);$dispatched=true;$wire=(new OpenTransport())->send($transportSource,$row['method'],$row['path'],$params,(array)$request->file(),$stream?function($chunk,$status,$responseHeaders){if(!headers_sent()){http_response_code($status);foreach($responseHeaders as $name=>$value)header($name.': '.$value);}echo $chunk;@flush();}:null,$headers);
            $data=json_decode($wire['body'],true);$data=is_array($data)?$data:[];$task=(string)(OpenSchema::get($data,$op['task_id_path']??'data.task_id')??'');$failed=self::wireFailed($wire['status'],$data,$op);$fields=['response_blob'=>OpenVault::seal($wire['body']),'response_type'=>$wire['headers']['content-type']??'application/json','response_code'=>$wire['status'],'duration_ms'=>(int)((microtime(true)-$start)*1000)];
            if($task!==''&&!$failed&&$op['role']==='submit'){$fields+=['upstream_task_id'=>$task,'status'=>'running','next_run_time'=>time()+30];\app\common\model\ai\AiConsumptionLog::where('consume_no',$row['request_id'])->update(['upstream_task_id'=>$task,'run_status'=>'running']);}
            else{$fields+=['status'=>$failed?'failed':'success'];if(!empty($op['billable'])){if($stream)$data=self::streamUsage($wire['body']);OpenMarketBilling::settle($row,$data,$failed);}else $fields+=['active'=>0,'next_run_time'=>0];}
            OpenAdmission::finish($row['request_id'],$fields);
            if(!$op['history']){$latest=OpenStore::table('request')->where('id',$row['id'])->find();if((int)$latest['active']===0)OpenWebhooks::enqueue($latest,$data);}
            if($op['history']&&!$failed){$owner=self::ownedTask(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id']],$op);self::observeTask($owner,$data);}
            return $stream?response(''):response($wire['body'],$wire['status'],$wire['headers']+['X-Request-Id'=>$row['request_id']]);
        }catch(\Throwable $e){$unknown=$dispatched;
            if(!$unknown&&$op['kind']==='market'&&!empty($op['billable'])){try{OpenMarketBilling::settle($row,[],true);}catch(\Throwable){$unknown=true;}}$fields=['status'=>$unknown?'pending_confirmation':'failed','billing_status'=>$unknown?'pending_confirmation':'none','error_code'=>$e instanceof OpenException?$e->errorType:'internal_error','error_message'=>$e instanceof OpenException?mb_substr($e->getMessage(),0,500):'服务执行失败','active'=>$unknown?1:0,'next_run_time'=>$unknown?time()+60:0];if(!$unknown){$error=self::error($e,$row['path'],$row['request_id']);$fields+=['response_blob'=>OpenVault::seal($error->getContent()),'response_type'=>'application/json','response_code'=>$error->getCode()];}
            OpenAdmission::finish($row['request_id'],$fields);throw $e;}finally{OpenCallContext::leave();}
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
        if(in_array($status,$contract['success_statuses']??['success','completed','succeeded'],true))OpenMarketBilling::settle($row,$data);
        elseif(in_array($status,$contract['failure_statuses']??['failed','cancelled','canceled'],true))OpenMarketBilling::settle($row,$data,true);
    }
}
