<?php
namespace app\common\service\open_platform;
use think\facade\Db;
final class OpenManagement
{
    public static function saveSettings(int $tenantId,array $input,bool $platform=false): array
    {
        $enabled=(bool)($input['enabled']??false);$old=OpenStore::settings($tenantId);$config=$old;unset($config['enabled']);
        foreach(['rpm','concurrency','user_concurrency','tenant_concurrency'] as $field){$value=(int)($input[$field]??$config[$field]);if($value<1||$value>100000)throw new OpenException('invalid_limits','请配置有效请求频率和并发上限');$config[$field]=$value;}
        $global=OpenStore::settings(0);
        if(!$platform)foreach(['rpm','concurrency','user_concurrency','tenant_concurrency'] as $field)$config[$field]=min($config[$field],$global[$field]);
        $apps=array_values(array_unique(array_map('strval',(array)($input['apps']??$config['apps']))));foreach($apps as $app){OpenToolRegistry::get($app);if(!$platform&&!in_array($app,$global['apps'],true))throw new OpenException('permission_denied','工具超出平台开放范围',403);}$config['apps']=$apps;
        $config['models']=array_values(array_unique(array_map('strval',(array)($input['models']??$config['models']))));if(!$platform&&array_diff($config['models'],$global['models']))throw new OpenException('permission_denied','商城能力超出平台范围',403);
        foreach($config['features'] as $name=>$value){$config['features'][$name]=(bool)($input['features'][$name]??$value)&&($platform||!empty($global['features'][$name]));}
        // Only platform can declare executor safety limits; they are never prices.
        if($platform&&isset($input['tool_budgets']))$config['tool_budgets']=self::budgets((array)$input['tool_budgets']);
        if($enabled&&$tenantId>0&&empty($input['rpm']))throw new OpenException('invalid_limits','开通前必须明确配置频率和并发');
        self::persistSettings($tenantId,$enabled,$config);return OpenStore::settings($tenantId);
    }
    private static function budgets(array $rules): array
    {
        foreach($rules as $app=>$ops){$tool=OpenToolRegistry::get($app);foreach($ops as $route=>$rule){if(!OpenToolRegistry::executable($tool['operations'][$route]??['available'=>false])||empty($tool['operations'][$route]['billable'])||!isset($rule['user'],$rule['tenant'])||!is_numeric($rule['user'])||!is_numeric($rule['tenant'])||(float)$rule['user']<=0||(float)$rule['tenant']<=0)throw new OpenException('invalid_budget','额度边界必须绑定已注册计费操作');}}return $rules;
    }
    public static function persistSettings(int $tenantId,bool $enabled,array $config): void
    {
        Db::transaction(function()use($tenantId,$enabled,$config){$row=OpenStore::table('setting')->where('tenant_id',$tenantId)->lock(true)->find();$values=['enabled'=>$enabled?1:0,'config'=>OpenStore::encode($config),'update_time'=>time()];if($row)OpenStore::table('setting')->where('id',$row['id'])->update($values);else OpenStore::table('setting')->insert($values+['tenant_id'=>$tenantId,'create_time'=>time()]);});
    }
    public static function savePages(int $tenantId,array $pages): array
    {
        $setting=OpenStore::settings($tenantId);$enabled=$setting['enabled'];unset($setting['enabled']);$setting['pages']=OpenPages::validate($pages);self::persistSettings($tenantId,$enabled,$setting);return OpenPages::configured($tenantId);
    }
    public static function contractsForTenant(int $tenantId): array
    {
        $local=OpenStore::settings($tenantId);$global=OpenStore::settings(0);$rows=[];
        foreach(OpenStore::table('contract')->where('status','published')->order('id','desc')->limit(1000)->select()->toArray() as $row){
            $config=OpenStore::json($row['config']);$app=(string)($config['app_code']??'');$product=(string)$row['product_id'];
            if($row['kind']==='tool'&&!OpenToolRegistry::supported($row['method'],$row['path']))continue;
            if($row['kind']==='tool'&&(!in_array($app,$local['apps'],true)||!in_array($app,$global['apps'],true)))continue;
            if($row['kind']==='market'&&(int)$product>0&&(!in_array($product,$local['models'],true)||!in_array($product,$global['models'],true)))continue;
            unset($config['verification']);$row['config']=OpenStore::encode($config);$rows[]=$row;
        }return $rows;
    }
    public static function requests(int $tenantId,?int $userId,array $filters): array
    {
        $q=OpenStore::table('request');if($tenantId>=0)$q->where('tenant_id',$tenantId);if($userId!==null)$q->where('user_id',$userId);
        foreach(['request_id','key_id','app_code','status','billing_status','kind','contract_id'] as $f)if(isset($filters[$f])&&$filters[$f]!=='')$q->where($f,$filters[$f]);
        if(!empty($filters['start']))$q->where('create_time','>=',(int)$filters['start']);if(!empty($filters['end']))$q->where('create_time','<=',(int)$filters['end']);
        if(!empty($filters['tasks']))$q->where('method','POST')->whereNotLike('path','%/cancel')->where(function($q){$q->where('tool_task_id','<>','')->whereOr('upstream_task_id','<>','');});
        if(!empty($filters['exceptions']))$q->whereIn('billing_status',['pending_confirmation','pending_usage','settlement_failed']);
        $count=(clone $q)->count();$rows=$q->withoutField('response_blob,snapshot,idempotency_hash,payload_hash,lease_token')->order('id','desc')->page(max(1,(int)($filters['page_no']??1)),min(100,max(1,(int)($filters['page_size']??20))))->select()->toArray();
        if($userId!==null)foreach($rows as &$row){unset($row['budget_tenant'],$row['actual_tenant']);$row['consumption_ids']=OpenStore::json($row['consumption_ids']);}
        return ['lists'=>$rows,'count'=>$count];
    }
    public static function detail(int $tenantId,?int $userId,string $id): array
    {
        $q=OpenStore::table('request')->where('request_id',$id);if($tenantId>=0)$q->where('tenant_id',$tenantId);if($userId!==null)$q->where('user_id',$userId);$row=$q->withoutField('response_blob,lease_token,idempotency_hash,payload_hash')->find();if(!$row)throw new OpenException('not_found','请求不存在',404);
        $row['consumption_ids']=OpenStore::json($row['consumption_ids']);$row['snapshot']=OpenStore::json($row['snapshot']);if($userId!==null){
            $row['consumptions']=$row['consumption_ids']?Db::name('ai_consumption_log')->where(['tenant_id'=>$tenantId,'user_id'=>$userId])->whereIn('id',$row['consumption_ids'])->field('consume_no,run_status,billing_status,actual_user_price,create_time')->select()->toArray():[];
            $row['point_logs']=self::pointLogs($row,$tenantId,$userId);
            unset($row['actual_tenant'],$row['budget_tenant'],$row['snapshot']);
        }return $row;
    }
    /** Read-only diagnosis. Resuming tracking can settle an existing admitted request. */
    public static function recoveryPreview(int $tenantId,string $id): array
    {
        $row=self::detail($tenantId,null,$id);$snapshot=$row['snapshot'];
        $query=false;
        if($row['kind']==='market')$query=$row['upstream_task_id']!==''&&str_starts_with((string)($snapshot['contract']['query_path']??''),'/api/v1/')&&!str_contains((string)($snapshot['contract']['query_path']??''),'..');
        elseif($row['tool_task_id']!==''){
            $tool=OpenToolRegistry::all()[$row['app_code']]??[];
            $query=isset($tool['operations']['GET tasks/{task_id}'])&&OpenToolRegistry::executable($tool['operations']['GET tasks/{task_id}']);
        }
        $active=(int)$row['active']===1;$lease=(int)$row['lease_expire']>time();
        $mayCharge=$active&&(float)$row['budget_user']>0;
        $mayRefund=$active&&(float)$row['actual_user']>0&&!in_array($row['billing_status'],['settled','refunded','none'],true);
        // Keep encrypted credentials, private receipts, examples and prompts server-side.
        unset($row['snapshot']);
        return ['preview'=>$row,'operation'=>'diagnose_only','will_query'=>false,'will_charge'=>false,'will_modify'=>false,
            'scope'=>['requests'=>1,'tenant_id'=>(int)$row['tenant_id'],'user_id'=>(int)$row['user_id']],
            'tracking'=>['active'=>$active,'query_available'=>$query,'lease_active'=>$lease,'lease_expires_at'=>(int)$row['lease_expire'],
                'manual_review_required'=>$active&&!$query,'resubmission_allowed'=>false],
            'resume_impact'=>['may_charge_existing_request'=>$mayCharge,'maximum_remaining_user_points'=>(float)$row['budget_user'],
                'may_refund_existing_request'=>$mayRefund,'already_charged_user_points'=>(float)$row['actual_user'],
                'historical_backcharge_allowed'=>false]];
    }
    private static function pointLogs(array $row,int $tenantId,int $userId): array
    {
        $logs=[];
        foreach($row['snapshot']['point_events']??[] as $event=>$amounts){
            [$kind,$source]=array_pad(explode(':',(string)$event,2),2,'');
            if(!in_array($kind,['consume','refund'],true)||$source==='')continue;
            // Native tools can bill without AiConsumptionLog. Resolve their
            // actual existing wallet entries, never manufacture a second bill.
            foreach(Db::name('user_account_log')->where(['tenant_id'=>$tenantId,'user_id'=>$userId,'source_sn'=>$source,'action'=>$kind==='consume'?2:1])->field('id,source_sn,action,change_amount,create_time')->order('id','asc')->select()->toArray() as $log)$logs[$log['id']]=$log;
        }
        ksort($logs);return array_values($logs);
    }
    public static function usage(int $tenantId,int $userId,array $filters): array
    {
        $q=OpenStore::owner('request',$tenantId,$userId);if(!empty($filters['start']))$q->where('create_time','>=',(int)$filters['start']);if(!empty($filters['end']))$q->where('create_time','<=',(int)$filters['end']);
        foreach(['app_code','kind','key_id','contract_id','path','billing_status','status'] as $field)if(isset($filters[$field])&&$filters[$field]!=='')$q->where($field,$filters[$field]);
        // Aggregate durable snapshots rather than today's mutable model/pricing catalog.
        $snapshot="CASE WHEN JSON_VALID(snapshot) THEN snapshot ELSE '{}' END";
        $model="COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.native_text_model.model_code')), 'null'), ''),NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.contract.model')), 'null'), ''),'')";
        if(isset($filters['model'])&&$filters['model']!=='')$q->whereRaw($model.' = ?',[(string)$filters['model']]);
        $rows=$q->fieldRaw("app_code,kind,key_id,contract_id,path,$model AS model,COUNT(*) AS calls,SUM(actual_user) AS charged,SUM(CASE WHEN active=1 THEN budget_user ELSE 0 END) AS pending_budget")->group('app_code,kind,key_id,contract_id,path,model')->select()->toArray();
        return ['groups'=>$rows,'charged'=>array_sum(array_column($rows,'charged')),'pending_budget'=>array_sum(array_column($rows,'pending_budget')),'calls'=>array_sum(array_column($rows,'calls'))];
    }
    public static function callbacks(int $tenantId,int $userId,string $requestId): array
    {
        self::detail($tenantId,$userId,$requestId);
        $events=OpenStore::owner('webhook',$tenantId,$userId)->where('request_id',$requestId)->withoutField('secret_blob,payload_blob,lease_token')->order('id','desc')->select()->toArray();
        foreach($events as &$event)$event['deliveries']=OpenStore::table('webhook_delivery')->where('webhook_id',$event['id'])->withoutField('lease_token')->order('id','desc')->select()->toArray();
        return $events;
    }

}
