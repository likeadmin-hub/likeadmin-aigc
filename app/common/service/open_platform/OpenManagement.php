<?php
namespace app\common\service\open_platform;
use think\facade\Db;
final class OpenManagement
{
    public static function saveSettings(int $tenantId,array $input,bool $platform=false): array
    {
        return Db::transaction(function()use($tenantId,$input,$platform){
            OpenStore::table('setting')->where('tenant_id',$tenantId)->lock(true)->find();
            return self::saveLockedSettings($tenantId,$input,$platform);
        });
    }
    private static function saveLockedSettings(int $tenantId,array $input,bool $platform): array
    {
        if(!$platform&&$tenantId<=0)throw new OpenException('permission_denied','租户范围无效',403);
        if($tenantId<0||($tenantId>0&&!Db::name('tenant')->where('id',$tenantId)->find()))throw new OpenException('not_found','租户不存在',404);
        $old=OpenStore::settings($tenantId);$config=$old;unset($config['enabled'],$config['authorized']);
        // The durable enabled column is a platform grant; tenant pause is separate.
        $enabled=$platform?(bool)($input['enabled']??$old['authorized']):$old['authorized'];
        if(!$platform)$config['tenant_enabled']=(bool)($input['enabled']??$old['tenant_enabled']);
        foreach(['rpm','concurrency','user_concurrency','tenant_concurrency'] as $field){$value=(int)($input[$field]??$config[$field]);if($value<1||$value>100000)throw new OpenException('invalid_limits','请配置有效请求频率和并发上限');$config[$field]=$value;}
        $global=OpenStore::settings(0);
        if(!$platform)foreach(['rpm','concurrency','user_concurrency','tenant_concurrency'] as $field)$config[$field]=min($config[$field],$global[$field],$old['grant_limits'][$field]??$global[$field]);
        $apps=array_values(array_unique(array_map('strval',(array)($input['apps']??$config['apps']))));foreach($apps as $app){OpenToolRegistry::get($app);if(!$platform&&!in_array($app,$old['apps'],true)&&(!in_array($app,$global['apps'],true)||!in_array($app,$old['grant_apps']??$global['apps'],true)))throw new OpenException('permission_denied','工具超出平台开放范围',403);}$config['apps']=$apps;
        $config['models']=array_values(array_unique(array_map('strval',(array)($input['models']??$config['models']))));if(!$platform&&array_diff($config['models'],$old['models'],$global['models']))throw new OpenException('permission_denied','商城能力超出平台范围',403);
        foreach($config['features'] as $name=>$value){$config['features'][$name]=(bool)($input['features'][$name]??$value)&&($platform||(!empty($global['features'][$name])&&($old['grant_features'][$name]??true)));}
        if($platform&&$tenantId>0){
            $config['grant_limits']=array_intersect_key($config,array_flip(['rpm','concurrency','user_concurrency','tenant_concurrency']));
            $config['grant_features']=$config['features'];$config['grant_apps']=$config['apps'];
        }
        // Only platform can declare executor safety limits; they are never prices.
        if($platform&&isset($input['tool_budgets']))$config['tool_budgets']=self::budgets((array)$input['tool_budgets']);
        if($enabled&&$tenantId>0&&empty($input['rpm']))throw new OpenException('invalid_limits','开通前必须明确配置频率和并发');
        self::persistSettings($tenantId,$enabled,$config);return OpenStore::settings($tenantId);
    }
    /** Platform-only grant toggle: preserve quotas, capabilities, pages and tenant pause. */
    public static function setTenantAuthorization(int $tenantId,bool $enabled): array
    {
        if($tenantId<=0||!Db::name('tenant')->where('id',$tenantId)->find())throw new OpenException('not_found','租户不存在',404);
        return Db::transaction(function()use($tenantId,$enabled){
            $row=OpenStore::table('setting')->where('tenant_id',$tenantId)->lock(true)->find();
            if($row)OpenStore::table('setting')->where('id',$row['id'])->update(['enabled'=>$enabled?1:0,'update_time'=>time()]);
            else { $config=OpenStore::settings($tenantId);unset($config['enabled'],$config['authorized']);self::persistSettings($tenantId,$enabled,$config); }
            return self::adminSettings($tenantId);
        });
    }
    public static function authorizationInput(mixed $value): bool
    {
        if(!in_array($value,[true,false,0,1,'0','1'],true))throw new OpenException('invalid_request','请传入有效 API 开关状态');
        return (bool)$value;
    }
    public static function adminContracts(int $tenantId,bool $platform,array $filters): array
    {
        $q=OpenStore::table('contract');
        if(!$platform){
            // The published visibility policy remains authoritative for tenant admins.
            $ids=array_column(self::contractsForTenant($tenantId),'id');
            $q->whereIn('id',$ids?:[0]);
        }
        self::adminListFilters($q,$filters,['code','path']);
        $result=self::adminPage($q,$filters);
        foreach($result['lists'] as &$row){
            $config=OpenContracts::decodeConfig($row['config']);$row['title']=(string)($config['title']??$row['code']);
            if(!$platform){$config=array_intersect_key($config,array_flip(['title','description','input','responses','example','content_type','role','billable','billing_description','lifecycle']));$row['config']=OpenPolicy::publicData(json_decode(OpenContracts::encodeConfig($config),true));}
        }
        unset($row);return $result;
    }
    public static function adminDocuments(int $tenantId,array $filters): array
    {
        $q=OpenStore::table('document')->where('tenant_id',$tenantId);
        self::adminListFilters($q,$filters,['title','slug','content']);return self::adminPage($q,$filters);
    }
    private static function adminListFilters($q,array $filters,array $fields): void
    {
        $status=(string)($filters['status']??'');if($status!=='')$q->where('status',$status);
        $keyword=mb_substr(trim((string)($filters['keyword']??'')),0,160);
        if($keyword!==''){
            $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$keyword).'%';
            $q->where(function($sub)use($fields,$like){
                $sub->whereLike(implode('|',$fields),$like);
                if(in_array('code',$fields,true))$sub->whereOrRaw("JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(config), config, '{}'), '$.title')) LIKE :open_title",['open_title'=>$like]);
            });
        }
    }
    private static function adminPage($q,array $filters): array
    {
        $size=min(100,max(1,(int)($filters['page_size']??20)));$count=(clone $q)->count();
        $page=min(max(1,(int)($filters['page_no']??1)),max(1,(int)ceil($count/$size)));
        return ['lists'=>$q->order('id','desc')->page($page,$size)->select()->toArray(),'count'=>$count,'page_no'=>$page,'page_size'=>$size];
    }
    /** Admin projection is separate from internal recovery's full owned snapshot. */
    public static function adminRequestDetail(int $tenantId,string $id,bool $platform): array
    {
        $row=self::detail($tenantId,null,$id);$trace=OpenAdminTrace::build($row,$platform);
        $snapshot=$row['snapshot'];$row['platform_scope']=$platform;$row['safe_snapshot']=['interface'=>array_intersect_key((array)($snapshot['contract']??[]),array_flip(['code','version','kind','title','role','billable'])),'recorded_response'=>['http_status'=>(int)$row['response_code'],'content_type'=>$row['response_type']],'remaining_budget'=>['user_points'=>$row['budget_user']]];
        $row['safe_snapshot']['remaining_budget']['tenant_points']=$row['budget_tenant'];
        $row['safe_snapshot']['frozen_pricing']=$trace['nodes'][0]['data']['price_summary']??[];
        unset($row['snapshot']);
        // Private upstream error messages can quote credentials or submitted content.
        $row['error_message']=$row['error_code']!==''?'错误详情请结合已记录的执行状态和关联记录核对。':'';
        if(!$platform)unset($row['upstream_task_id']);
        $row['trace']=$trace;
        foreach(['consumptions','user_point_logs','tenant_point_logs','webhook_deliveries'] as $name)$row[$name]=$trace[$name];
        return $row;
    }
    public static function adminSettings(int $tenantId): array
    {
        if($tenantId<0||($tenantId>0&&!Db::name('tenant')->where('id',$tenantId)->find()))throw new OpenException('not_found','租户不存在',404);
        $global=OpenStore::settings(0);
        return OpenStore::settings($tenantId)+['platform_enabled'=>$global['enabled'],'platform_models'=>$global['models'],'platform_apps'=>$global['apps']];
    }
    /** Independent API exposure; never changes price, SKU shelf, or contract publication. */
    public static function setMarketExposure(int $tenantId,array $productIds,bool $enabled,bool $platform,array $appCodes=[]): array
    {
        if($appCodes){
            if(count($appCodes)>500)throw new OpenException('invalid_request','所选应用过多');
            foreach($appCodes as $code)if(!is_string($code)||!preg_match('/^[a-z0-9_]{1,100}$/D',$code))throw new OpenException('invalid_request','应用编码无效');
            // Cancellation includes historically selected children that are now off-shelf.
            if(!$enabled)$productIds=array_merge($productIds,Db::name('power_market_product')->where('resource_type','app_api')->whereIn('upstream_app_code',array_unique($appCodes))->column('id'));
        }
        $ids=array_values(array_unique(array_map('intval',$productIds)));
        if(!$ids||count($ids)>500||min($ids)<1)throw new OpenException('invalid_request','请选择有效商城能力');
        if(!$platform&&$tenantId<=0)throw new OpenException('permission_denied','租户范围无效',403);
        $count=Db::name('power_market_product')->whereIn('id',$ids)->whereIn('resource_type',['model','app_api'])->count();
        if($count!==count($ids))throw new OpenException('not_found','商城能力不存在',404);
        return Db::transaction(function()use($tenantId,$ids,$enabled,$platform){
            OpenStore::table('setting')->where('tenant_id',$tenantId)->lock(true)->find();
            $setting=self::adminSettings($tenantId);$global=OpenStore::settings(0);$strings=array_map('strval',$ids);
            if($enabled&&!$platform&&(!$setting['authorized']||array_diff($strings,$global['models'])))throw new OpenException('permission_denied','该能力未获平台 API 授权',403);
            $models=$enabled?array_values(array_unique(array_merge($setting['models'],$strings))):array_values(array_diff($setting['models'],$strings));
            $config=OpenStore::settings($tenantId);$authorized=$config['authorized'];unset($config['enabled'],$config['authorized']);$config['models']=$models;
            self::persistSettings($tenantId,$authorized,$config);return self::adminSettings($tenantId);
        });
    }
    private static function budgets(array $rules): array
    {
        foreach($rules as $app=>$ops){$tool=OpenToolRegistry::get($app);foreach($ops as $route=>$rule){if(!OpenToolRegistry::executable($tool['operations'][$route]??['available'=>false])||empty($tool['operations'][$route]['billable'])||!isset($rule['user'],$rule['tenant'])||!is_numeric($rule['user'])||!is_numeric($rule['tenant'])||(float)$rule['user']<=0||(float)$rule['tenant']<=0)throw new OpenException('invalid_budget','额度边界必须绑定已注册计费操作');}}return $rules;
    }
    public static function persistSettings(int $tenantId,bool $enabled,array $config): void
    {
        unset($config['enabled'],$config['authorized']);
        Db::transaction(function()use($tenantId,$enabled,$config){$row=OpenStore::table('setting')->where('tenant_id',$tenantId)->lock(true)->find();$values=['enabled'=>$enabled?1:0,'config'=>OpenStore::encode($config),'update_time'=>time()];if($row)OpenStore::table('setting')->where('id',$row['id'])->update($values);else OpenStore::table('setting')->insert($values+['tenant_id'=>$tenantId,'create_time'=>time()]);});
    }
    public static function savePages(int $tenantId,array $pages): array
    {
        return Db::transaction(function()use($tenantId,$pages){
            OpenStore::table('setting')->where('tenant_id',$tenantId)->lock(true)->find();
            $setting=OpenStore::settings($tenantId);$enabled=$setting['authorized'];unset($setting['enabled'],$setting['authorized']);$setting['pages']=OpenPages::validate($pages);self::persistSettings($tenantId,$enabled,$setting);return OpenPages::configured($tenantId);
        });
    }
    public static function contractsForTenant(int $tenantId): array
    {
        $local=OpenStore::settings($tenantId);$global=OpenStore::settings(0);$rows=[];
        foreach(OpenStore::table('contract')->where('status','published')->order('id','desc')->select()->toArray() as $row){
            $config=OpenContracts::decodeConfig($row['config']);$app=(string)($config['app_code']??'');$product=(string)$row['product_id'];
            if($row['kind']==='tool'&&!OpenToolRegistry::supported($row['method'],$row['path']))continue;
            if($row['kind']==='tool'&&(!in_array($app,$local['apps'],true)||!in_array($app,$global['apps'],true)))continue;
            if($row['kind']==='market'&&(int)$product>0&&(!in_array($product,$local['models'],true)||!in_array($product,$global['models'],true)))continue;
            unset($config['verification']);$row['config']=OpenContracts::encodeConfig($config);$rows[]=$row;
        }return $rows;
    }
    public static function requests(int $tenantId,?int $userId,array $filters,bool $platform=false): array
    {
        $q=OpenStore::table('request');if($tenantId>=0)$q->where('tenant_id',$tenantId);if($userId!==null)$q->where('user_id',$userId);
        foreach(['request_id','key_id','user_id','app_code','status','billing_status','kind','contract_id'] as $f)if(isset($filters[$f])&&$filters[$f]!=='')$q->where($f,$filters[$f]);
        if(!empty($filters['path']))$q->whereLike('path','%'.mb_substr((string)$filters['path'],0,255).'%');
        if(!empty($filters['task_id']))$q->where(function($sub)use($filters){$sub->where('tool_task_id',(string)$filters['task_id'])->whereOr('upstream_task_id',(string)$filters['task_id']);});
        if(!empty($filters['start']))$q->where('create_time','>=',(int)$filters['start']);if(!empty($filters['end']))$q->where('create_time','<=',(int)$filters['end']);
        if(!empty($filters['tasks']))$q->where('method','POST')->whereNotLike('path','%/cancel')->where(function($q){$q->where('tool_task_id','<>','')->whereOr('upstream_task_id','<>','');});
        if(!empty($filters['exceptions']))$q->whereIn('billing_status',['pending_confirmation','pending_usage','settlement_failed']);
        $count=(clone $q)->count();$rows=$q->withoutField('response_blob,snapshot,idempotency_hash,payload_hash,lease_token')->order('id','desc')->page(max(1,(int)($filters['page_no']??1)),min(100,max(1,(int)($filters['page_size']??20))))->select()->toArray();
        if($userId!==null)foreach($rows as &$row){unset($row['budget_tenant'],$row['actual_tenant']);$row['consumption_ids']=OpenStore::json($row['consumption_ids']);}
        if($userId===null)foreach($rows as &$adminRow){$adminRow['platform_scope']=$platform;if(!$platform)unset($adminRow['upstream_task_id']);$adminRow['error_message']=$adminRow['error_code']!==''?'错误详情请结合关联记录核对。':'';}unset($adminRow);
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
        $preview=['preview'=>$row,'operation'=>'diagnose_only','will_query'=>false,'will_charge'=>false,'will_modify'=>false,
            'scope'=>['requests'=>1,'tenant_id'=>(int)$row['tenant_id'],'user_id'=>(int)$row['user_id']],
            'tracking'=>['active'=>$active,'query_available'=>$query,'lease_active'=>$lease,'lease_expires_at'=>(int)$row['lease_expire'],
                'manual_review_required'=>$active&&!$query,'resubmission_allowed'=>false],
            'resume_impact'=>['may_charge_existing_request'=>$mayCharge,'maximum_remaining_user_points'=>(float)$row['budget_user'],
                'may_refund_existing_request'=>$mayRefund,'already_charged_user_points'=>(float)$row['actual_user'],
                'historical_backcharge_allowed'=>false]];
        $preview['resume']=OpenRecoveryAction::offer($preview,$snapshot);return $preview;
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
    public static function usage(int $tenantId,int $userId,array $filters,bool $paginate=false): array
    {
        $q=OpenStore::owner('request',$tenantId,$userId);if(!empty($filters['start']))$q->where('create_time','>=',(int)$filters['start']);if(!empty($filters['end']))$q->where('create_time','<=',(int)$filters['end']);
        foreach(['app_code','kind','key_id','contract_id','path','billing_status','status'] as $field)if(isset($filters[$field])&&$filters[$field]!=='')$q->where($field,$filters[$field]);
        // Aggregate durable snapshots rather than today's mutable model/pricing catalog.
        $snapshot="CASE WHEN JSON_VALID(snapshot) THEN snapshot ELSE '{}' END";
        $model="COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.native_text_model.model_code')), 'null'), ''),NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT($snapshot, '$.contract.model')), 'null'), ''),'')";
        if(isset($filters['model'])&&$filters['model']!=='')$q->whereRaw($model.' = ?',[(string)$filters['model']]);
        $fields="app_code,kind,key_id,contract_id,path,$model AS model,COUNT(*) AS calls,SUM(actual_user) AS charged,SUM(CASE WHEN active=1 THEN budget_user ELSE 0 END) AS pending_budget";
        $grouped=(clone $q)->fieldRaw($fields)->group('app_code,kind,key_id,contract_id,path,model');
        if($paginate){
            $page=max(1,(int)($filters['page_no']??1));$size=min(100,max(1,(int)($filters['page_size']??20)));
            $count=(clone $grouped)->count();
            $totals=(clone $q)->fieldRaw('COUNT(*) AS calls,SUM(actual_user) AS charged,SUM(CASE WHEN active=1 THEN budget_user ELSE 0 END) AS pending_budget')->find();
            $rows=$grouped->order(['app_code'=>'asc','kind'=>'asc','key_id'=>'asc','contract_id'=>'asc','path'=>'asc','model'=>'asc'])->page($page,$size)->select()->toArray();
            return ['groups'=>$rows,'charged'=>round((float)$totals['charged'],2),'pending_budget'=>round((float)$totals['pending_budget'],2),'calls'=>(int)$totals['calls'],'total_groups'=>(int)$count,'page_no'=>$page,'page_size'=>$size];
        }
        $rows=$grouped->select()->toArray();
        return ['groups'=>$rows,'charged'=>round(array_sum(array_column($rows,'charged')),2),'pending_budget'=>round(array_sum(array_column($rows,'pending_budget')),2),'calls'=>array_sum(array_column($rows,'calls'))];
    }
    public static function callbacks(int $tenantId,int $userId,string $requestId): array
    {
        self::detail($tenantId,$userId,$requestId);
        $events=OpenStore::owner('webhook',$tenantId,$userId)->where('request_id',$requestId)->withoutField('secret_blob,payload_blob,lease_token')->order('id','desc')->select()->toArray();
        foreach($events as &$event)$event['deliveries']=OpenStore::table('webhook_delivery')->where('webhook_id',$event['id'])->withoutField('lease_token')->order('id','desc')->select()->toArray();
        return $events;
    }

    /** Account-only durable request statistics; never reprice or inspect private payloads. */
    public static function analytics(int $tenantId,int $userId,int $days=7): array
    {
        if(!in_array($days,[7,30],true))throw new OpenException('invalid_request','统计周期仅支持 7 或 30 天');
        $today=new \DateTimeImmutable('today');$start=$today->modify('-'.($days-1).' days');$end=$today->modify('+1 day');$previousStart=$start->modify('-'.$days.' days');
        $owner=OpenStore::owner('request',$tenantId,$userId);
        $current=(clone $owner)->where('create_time','>=',$start->getTimestamp())->where('create_time','<',$end->getTimestamp());
        $prior=(clone $owner)->where('create_time','>=',$previousStart->getTimestamp())->where('create_time','<',$start->getTimestamp());
        $success="status IN ('success','completed','partial_success')";$failed="status IN ('failed','partial_failed')";
        $fields="COUNT(*) AS calls,COALESCE(SUM(actual_user),0) AS charged,SUM(CASE WHEN $success THEN 1 ELSE 0 END) AS success,SUM(CASE WHEN $failed THEN 1 ELSE 0 END) AS failed,SUM(CASE WHEN active=1 THEN 1 ELSE 0 END) AS pending,AVG(CASE WHEN ($success OR $failed) AND duration_ms>0 THEN duration_ms ELSE NULL END) AS average_latency_ms";
        $normalize=static function(array $row): array {
            $success=(int)($row['success']??0);$failed=(int)($row['failed']??0);
            return ['calls'=>(int)($row['calls']??0),'charged'=>round((float)($row['charged']??0),2),'success'=>$success,'failed'=>$failed,'pending'=>(int)($row['pending']??0),'success_rate'=>$success+$failed?round(100*$success/($success+$failed),2):null,'average_latency_ms'=>isset($row['average_latency_ms'])?round((float)$row['average_latency_ms'],2):null];
        };
        $metrics=$normalize((clone $current)->fieldRaw($fields)->find()??[]);$previous=$normalize((clone $prior)->fieldRaw($fields)->find()??[]);
        $growth=[];foreach(['calls','charged'] as $name)$growth[$name]=['delta'=>$name==='calls'?$metrics[$name]-$previous[$name]:round($metrics[$name]-$previous[$name],2),'percent'=>$previous[$name]>0?round(100*($metrics[$name]-$previous[$name])/$previous[$name],2):null];
        // Explicit local-day timestamp buckets avoid dependence on the DB session timezone
        // and also preserve actual calendar days if a deployment uses daylight saving time.
        $buckets=[];$trend=[];for($i=0;$i<$days;$i++){$day=$start->modify('+'.$i.' days');$next=$day->modify('+1 day');$buckets[]='WHEN create_time>='.$day->getTimestamp().' AND create_time<'.$next->getTimestamp().' THEN '.$i;$trend[]=['date'=>$day->format('Y-m-d'),'calls'=>0,'charged'=>0.,'success'=>0,'failed'=>0,'pending'=>0];}
        $bucket='CASE '.implode(' ',$buckets).' END';
        foreach((clone $current)->fieldRaw($bucket.' AS day_bucket,'.$fields)->group('day_bucket')->select()->toArray() as $row){$i=(int)$row['day_bucket'];$values=$normalize($row);unset($values['success_rate'],$values['average_latency_ms']);$trend[$i]=array_replace($trend[$i],$values);}
        $distribution=(clone $current)->fieldRaw('kind,COUNT(*) AS calls,SUM(actual_user) AS charged')->group('kind')->order('kind','asc')->select()->toArray();
        $route="CASE WHEN path LIKE '/api/v1/tools/%/tasks/%' THEN CONCAT(SUBSTRING_INDEX(path,'/tasks/',1),'/tasks/{task_id}',IF(path LIKE '%/cancel','/cancel','')) WHEN path LIKE '/api/v1/tasks/%' THEN CONCAT('/api/v1/tasks/{task_id}',IF(path LIKE '%/cancel','/cancel','')) WHEN path LIKE '/api/v1/assets/%' THEN CONCAT('/api/v1/assets/{file_id}',IF(path LIKE '%/download','/download','')) ELSE path END";
        $top=(clone $current)->fieldRaw("kind,app_code,$route AS route_path,COUNT(*) AS calls,SUM(actual_user) AS charged")->group('kind,app_code,route_path')->order(['calls'=>'desc','kind'=>'asc','app_code'=>'asc','route_path'=>'asc'])->limit(8)->select()->toArray();
        foreach($top as &$item){$item['path']=$item['route_path'];unset($item['route_path']);}unset($item);
        $normalizeGroup=static fn($r)=>array_replace($r,['calls'=>(int)$r['calls'],'charged'=>round((float)$r['charged'],2)]);
        $distribution=array_map($normalizeGroup,$distribution);$top=array_map($normalizeGroup,$top);
        return ['days'=>$days,'timezone'=>date_default_timezone_get(),'period'=>['start'=>$start->format('Y-m-d'),'end'=>$today->format('Y-m-d')],'previous_period'=>['start'=>$previousStart->format('Y-m-d'),'end'=>$start->modify('-1 day')->format('Y-m-d')],'metrics'=>$metrics,'previous'=>$previous,'growth'=>$growth,'trend'=>$trend,'distribution'=>$distribution,'top_capabilities'=>$top];
    }

    /** Retail estimate uses the same published SKU rules as submission and never calls upstream. */
    public static function quote(int $tenantId,int $userId,string $code,array $params): array
    {
        $settings=OpenStore::requireEnabled($tenantId);
        if(empty($settings['features']['playground'])||empty(OpenStore::settings(0)['features']['playground']))throw new OpenException('feature_disabled','在线调试已关闭',403);
        $contract=OpenStore::table('contract')->where(['code'=>$code,'kind'=>'market','status'=>'published'])->order('version','desc')->find();
        if(!$contract)throw new OpenException('not_found','请选择已发布的商城接口',404);
        $config=OpenContracts::decodeConfig($contract['config']);
        if(!OpenContracts::selected($config,$params))throw new OpenException('invalid_request','参数不符合当前选择的接口');
        try{OpenPolicy::validateInput($params);}catch(\InvalidArgumentException $e){throw new OpenException('invalid_request',$e->getMessage());}
        OpenSchema::validate($params,$config['input']);OpenSafeHttp::checkInputs($params);
        $op=$config+['kind'=>'market','product_id'=>(int)$contract['product_id']];
        // Directory and history do not reserve a new generation budget.
        if(($config['executor']??'')==='directory'||empty($config['billable']))return ['billable'=>false,'reserved_points'=>0.,'pricing_mode'=>'free','items'=>[],'currency'=>'points'];
        if(!in_array($config['role'],['submit','resource'],true))throw new OpenException('invalid_request','任务操作请查询原任务账单');
        $budget=OpenMarketBilling::quote($tenantId,$op,$params);$items=[];$variable=false;
        foreach($budget['snapshot']['rules']??[] as $rule){$variable=$variable||$rule['basis']==='usage';$items[]=['sku_key'=>$rule['sku_key'],'unit_price'=>(float)$rule['prices']['user'],'usage_unit'=>$rule['usage_unit'],'usage_unit_size'=>(float)$rule['unit_size'],'reserved_units'=>(float)$rule['units']];}
        return ['billable'=>true,'reserved_points'=>$budget['user'],'pricing_mode'=>$variable?'reservation':'fixed','items'=>$items,'currency'=>'points'];
    }

}
