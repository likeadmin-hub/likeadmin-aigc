<?php
namespace app\common\service\open_platform;

use think\facade\Db;

/** Read only: every edge comes from an owned, persisted reference. Never executes a task. */
final class OpenAdminTrace
{
    private const LIMIT = 50;
    private array $trace = ['nodes'=>[], 'timeline'=>[], 'gaps'=>[], 'consumptions'=>[], 'user_point_logs'=>[], 'tenant_point_logs'=>[], 'webhook_deliveries'=>[]];
    private array $owner;
    private array $fields = [];
    private array $nodes = [];
    private array $native = [];
    private array $pointSources = [];
    private int $nativeCount = 0;
    private int $applicationCount = 0;
    private array $appInProgress = [];

    private function __construct(private bool $platform, array $owner) { $this->owner = $owner; }

    public static function build(array $row, bool $platform): array
    {
        $owner = ['tenant_id'=>(int)($row['tenant_id']??0), 'user_id'=>(int)($row['user_id']??0)];
        $trace = new self($platform, $owner);
        // Do not trust links supplied by the caller, even if its request ID is real.
        $request = OpenStore::owner('request', $owner['tenant_id'], $owner['user_id'])
            ->where('request_id', (string)($row['request_id']??''))->find();
        if (!$request || $owner['tenant_id'] <= 0 || $owner['user_id'] <= 0 || (isset($row['id']) && (int)$row['id'] !== (int)$request['id'])) {
            $trace->gap('请求不存在或归属不匹配，未展开关联记录');
            return $trace->trace;
        }
        $trace->request($request);
        usort($trace->trace['timeline'], static fn($a,$b)=>[$a['time'],$a['node_id'],$a['label']] <=> [$b['time'],$b['node_id'],$b['label']]);
        return $trace->trace;
    }

    private function request(array $row): void
    {
        $root = 'request:'.$row['request_id'];
        $data = $this->pick($row, ['request_id','tenant_id','user_id','key_id','kind','app_code','method','path','status','billing_status','active','response_code','duration_ms','budget_user','actual_user','error_code','finish_time']);
        $data += $this->pick($row, ['budget_tenant','actual_tenant']);
        $data['price_summary']=$this->priceSummary(OpenStore::json($row['snapshot']??[]));
        $this->node($root, null, 'request', 'API 请求', $row['request_id'], $row['status'], $row, $data);
        $app = (string)$row['app_code'];
        $model = OpenToolRegistry::all()[$app]['task_model']??'';
        $taskId = $this->positiveId($row['tool_task_id']??'');
        if ($taskId && $model !== '') $this->nativeTask($model, $taskId, $root, 0);
        elseif ($row['kind'] === 'tool') $this->gap('请求尚无已确认的业务任务映射');
        $ids = $this->ids(OpenStore::json($row['consumption_ids']??[]), '执行消耗关联');
        $consumptions = $ids ? $this->owned('ai_consumption_log', $ids, ['id','consume_no','app_task_id','app_code','action_code','resource_type','model_code','api_code','quantity','usage_unit','usage_snapshot','price_snapshot','request_summary','response_summary','run_status','billing_status','reserved_user_price','actual_user_price','reserved_tenant_cost','actual_tenant_cost','upstream_task_id','user_point_sn','tenant_point_sn','error_code','create_time','update_time','finish_time']) : [];
        if ($ids && count($ids) !== count($consumptions)) $this->gap('部分执行消耗记录缺失或归属不匹配');
        if (!$ids) $this->gap('未记录执行消耗关联；可能由业务直接计费或尚未执行');
        foreach ($consumptions as $consumption) {
            $parent = $this->appTask((int)($consumption['app_task_id']??0), $root) ?: $root;
            $id = 'consumption:'.$consumption['id'];
            $data = $this->pick($consumption, ['id','consume_no','app_task_id','app_code','action_code','resource_type','model_code','api_code','quantity','usage_unit','run_status','billing_status','reserved_user_price','actual_user_price','error_code','create_time','update_time','finish_time']);
            $data['usage_summary'] = $this->numericSummary(OpenStore::json($consumption['usage_snapshot']??[]));
            $data['price_summary'] = $this->priceSummary(OpenStore::json($consumption['price_snapshot']??[]));
            $data['input_summary'] = $this->requestSummary(OpenStore::json($consumption['request_summary']??[]));
            $data['response_summary'] = $this->numericSummary(OpenStore::json($consumption['response_summary']??[]));
            $data += $this->pick($consumption, ['reserved_tenant_cost','actual_tenant_cost']);
            if ($this->platform) $data += $this->pick($consumption, ['upstream_task_id']);
            $this->trace['consumptions'][] = $data;
            $this->node($id, $parent, 'consumption', '执行消耗', (string)($consumption['consume_no']??$consumption['id']), (string)($consumption['run_status']??''), $consumption, $data);
            $this->upstream((string)($consumption['upstream_task_id']??''), $id, $consumption, '执行消耗记录');
            foreach (['user','tenant'] as $side) {
                $source = (string)($consumption[$side.'_point_sn']??'');
                if ($source !== '') $this->source($source, $side, null, $id, null);
            }
        }
        $this->executionEvidence($consumptions);
        $this->upstream((string)($row['upstream_task_id']??''), $root, $row, '请求记录');
        $events = OpenStore::json($row['snapshot']??[])['point_events']??[];
        if (is_array($events)) {
            if (count($events)>self::LIMIT) $this->gap('积分事件关联超过展示上限，仅展示前 50 项');
            foreach (array_slice($events,0,self::LIMIT,true) as $event=>$amounts) {
                [$kind,$source] = array_pad(explode(':',(string)$event,2),2,'');
                if (!in_array($kind,['consume','refund'],true) || $source === '') continue;
                foreach (['user','tenant'] as $side) $this->source($source,$side,$kind==='consume'?2:1,$root,is_array($amounts)?($amounts[$side]??null):null);
            }
        }
        $this->points();
        $this->callbacks($row,$root);
        if (!$this->trace['user_point_logs'] && (float)($row['actual_user']??0)>0) $this->gap('请求存在已扣用户积分，但未找到已关联的用户积分流水');
    }

    private function nativeTask(string $model, int $id, string $parent, int $depth): ?string
    {
        if (!class_exists($model) || !is_subclass_of($model, \think\Model::class)) { $this->gap('已注册的业务任务模型不可用'); return null; }
        $table = (new $model())->getName();
        $key = 'business:'.$table.':'.$id;
        if (isset($this->nodes[$key])) return $key;
        if ($this->nativeCount>=self::LIMIT || $depth>3) { $this->gap('业务子任务超过展示上限，未继续展开'); return null; }
        $rows = $this->owned($table,[$id],['id','status','progress','quantity','user_charge_points','create_time','update_time','finish_time','app_task_id','image_task_id','image_task_ids','video_task_id','video_task_ids','provider_task_id']);
        if (!$rows) { $this->gap('业务任务或子任务缺失、不可用或归属不匹配'); return null; }
        $row = $rows[0]; ++$this->nativeCount;
        $data = $this->pick($row,['id','status','progress','quantity','user_charge_points','finish_time']);
        $data['task_type'] = substr(strrchr($model,'\\')?:$model,1);
        if ($this->platform) $data += $this->pick($row,['provider_task_id']);
        $this->node($key,$parent,'business_task','业务任务',(string)$id,(string)($row['status']??''),$row,$data);
        $this->native[$table.':'.$id]=$key;
        foreach (['image'=>'aigc_image','video'=>'aigc_video'] as $type=>$app) {
            $childModel = OpenToolRegistry::all()[$app]['task_model']??'';
            $childIds = array_merge(OpenStore::json($row[$type.'_task_ids']??[]), [$row[$type.'_task_id']??0]);
            foreach ($this->ids($childIds,'业务子任务关联') as $childId) if ($childModel !== '') $this->nativeTask($childModel,$childId,$key,$depth+1);
        }
        return $key;
    }

    private function appTask(int $id, string $root, int $depth=0): ?string
    {
        if ($id<=0) return null;
        $key = 'app_task:'.$id;
        if (isset($this->nodes[$key])) return $key;
        if(isset($this->appInProgress[$id])){$this->gap('应用父子任务关联出现循环，未继续展开');return null;}
        if($depth>3||$this->applicationCount>=self::LIMIT){$this->gap('应用父子任务超过展示上限，未继续展开');return null;}
        ++$this->applicationCount;$this->appInProgress[$id]=true;
        $rows=$this->owned('ai_app_task',[$id],['id','task_no','app_code','action_code','business_table','business_id','parent_task_id','status','progress','estimated_user_price','actual_user_price','estimated_tenant_cost','actual_tenant_cost','create_time','update_time','finish_time']);
        if (!$rows) { unset($this->appInProgress[$id]);$this->gap('执行消耗引用的应用任务缺失或归属不匹配'); return null; }
        $row=$rows[0];$table=(string)($row['business_table']??'');$businessId=(int)($row['business_id']??0);
        $parent=$this->native[$table.':'.$businessId]??null;
        if($parent===null&&$businessId>0&&$table!==''){
            // Resolve only a persisted business reference to a known registry model.
            foreach(OpenToolRegistry::all() as $tool){
                $model=$tool['task_model']??'';
                if($model!==''&&class_exists($model)&&is_subclass_of($model,\think\Model::class)&&(new $model())->getName()===$table){
                    $parent=$this->nativeTask($model,$businessId,$root,0);break;
                }
            }
        }
        $parentId=$this->positiveId($row['parent_task_id']??0);
        if($parentId)$parent=$this->appTask($parentId,$root,$depth+1)??$parent;
        $parent??=$root;
        $data=$this->pick($row,['id','task_no','app_code','action_code','status','progress','estimated_user_price','actual_user_price','finish_time']);
        $data+=$this->pick($row,['estimated_tenant_cost','actual_tenant_cost']);
        $this->node($key,$parent,'app_task','应用执行任务',(string)($row['task_no']??$id),(string)($row['status']??''),$row,$data);
        unset($this->appInProgress[$id]);return $key;
    }

    private function executionEvidence(array $consumptions): void
    {
        if (!$consumptions) return;
        $ids=array_column($consumptions,'id');
        foreach (['ai_consumption_event'=>['id','consumption_id','event_type','event_status','attempt_no','http_status','elapsed_ms','create_time'], 'ai_task_result_asset'=>['id','consumption_id','asset_type','transfer_status','transfer_attempts','storage_scope','create_time','update_time']] as $table=>$fields) {
            $available=$this->columns($table);
            if (!isset($available['consumption_id'])) { $this->gap($table==='ai_consumption_event'?'执行事件表不可用，无法展示执行阶段':'结果转存记录表不可用'); continue; }
            $q=Db::name($table)->whereIn('consumption_id',$ids);
            if ($table==='ai_task_result_asset') {
                if (!isset($available['tenant_id'],$available['user_id'])) continue;
                $q->where($this->owner);
            }
            $rows=$q->field($this->fieldList($available,$fields))->order('id','asc')->limit(101)->select()->toArray();
            if(count($rows)>100){$this->gap('执行阶段或转存记录超过展示上限，仅展示前 100 项');$rows=array_slice($rows,0,100);}
            foreach ($rows as $row) {
                $event=$table==='ai_consumption_event';$type=$event?'execution_event':'result_asset';
                $label=$event?'执行阶段':'结果转存';$status=(string)($row[$event?'event_status':'transfer_status']??'');
                $this->node($type.':'.$row['id'],'consumption:'.$row['consumption_id'],$type,$label,(string)$row['id'],$status,$row,$row);
            }
            if ($table==='ai_consumption_event' && !$rows) $this->gap('已关联执行消耗尚无持久化执行阶段记录');
        }
    }

    private function upstream(string $id, string $parent, array $row, string $source): void
    {
        if($id==='')return;
        $key='upstream:'.substr(hash('sha256',$parent.' '.$id),0,20);
        $data=['recorded_in'=>$source,'observation_only'=>true];
        if($this->platform)$data['upstream_task_id']=$id;
        $this->node($key,$parent,'upstream_task','上游任务受理记录',$this->platform?$id:'已记录（内部标识隐藏）','recorded',['create_time'=>0,'update_time'=>0],$data);
        $this->gap('上游节点仅表示已保存的任务标识；未查询供应商实时状态');
    }

    private function source(string $source,string $side,?int $action,string $parent,mixed $expected): void
    {
        if(strlen($source)>255)return;
        $key=$side.':'.$source.':'.($action??'any');
        $this->pointSources[$key]=['source'=>$source,'side'=>$side,'action'=>$action,'parent'=>$parent,'expected'=>$expected];
    }

    private function points(): void
    {
        $shown=0;
        foreach($this->pointSources as $ref){
            if($shown>=200){$this->gap('积分流水总数超过展示上限，未继续展开');break;}
            $side=$ref['side'];$table=$side==='user'?'user_account_log':'tenant_point_log';$available=$this->columns($table);
            if(!isset($available['tenant_id'],$available['source_sn'])||($side==='user'&&!isset($available['user_id']))){$this->gap('已关联的积分流水表不可用');continue;}
            $q=Db::name($table)->where(['tenant_id'=>$this->owner['tenant_id'],'source_sn'=>$ref['source']]);
            if($side==='user')$q->where('user_id',$this->owner['user_id']);
            if($ref['action']!==null)$q->where('action',$ref['action']);
            $rows=$q->field($this->fieldList($available,['id','sn','source_sn','action','change_type','change_amount','left_amount','create_time','update_time']))->order('id','asc')->limit(min(51,201-$shown))->select()->toArray();
            if(count($rows)>50){$this->gap('积分流水超过展示上限，仅展示前 50 项');$rows=array_slice($rows,0,50);}
            if(!$rows && ($ref['expected']===null || abs((float)$ref['expected'])>0))$this->gap('已记录的'.($side==='user'?'用户':'租户').'积分关联未找到对应流水');
            if($ref['expected']!==null && $rows && abs(array_sum(array_column($rows,'change_amount'))-(float)$ref['expected'])>0.000001)$this->gap('已记录的积分事件金额与关联流水合计不一致');
            foreach($rows as $row){
                $key=$side.'_point:'.$row['id'];if(isset($this->nodes[$key]))continue;
                if($shown>=200){$this->gap('积分流水总数超过展示上限，未继续展开');break;}
                ++$shown;
                $this->trace[$side.'_point_logs'][]=$row;
                $this->node($key,$ref['parent'],$side.'_point_log',$side==='user'?'用户积分流水':'租户积分流水',$row['source_sn'],(int)$row['action']===2?'consume':'refund',$row,$row);
            }
        }
    }

    private function callbacks(array $request,string $root): void
    {
        $fields=$this->columns('open_platform_webhook');
        if(!isset($fields['tenant_id'],$fields['user_id'],$fields['request_id'])){$this->gap('回调事件表不可用');return;}
        // The webhook has no key_id: ownership is inherited through this exact persisted request.
        $events=OpenStore::owner('webhook',$this->owner['tenant_id'],$this->owner['user_id'])->where('request_id',$request['request_id'])
            ->field($this->fieldList($fields,['id','event_id','request_id','status','attempts','http_code','next_run_time','create_time','update_time']))->order('id','asc')->limit(51)->select()->toArray();
        if(count($events)>50){$this->gap('回调事件超过展示上限，仅展示前 50 项');$events=array_slice($events,0,50);}
        if(!$events){$this->gap('未记录该请求的客户回调事件');return;}
        foreach($events as $event)$this->node('webhook:'.$event['id'],$root,'webhook','客户回调事件',$event['event_id'],$event['status'],$event,$event);
        $deliveryFields=$this->columns('open_platform_webhook_delivery');
        if(!isset($deliveryFields['webhook_id'],$deliveryFields['event_id'])){$this->gap('回调投递记录表不可用');return;}
        $deliveries=OpenStore::table('webhook_delivery')->whereIn('webhook_id',array_column($events,'id'))
            ->field($this->fieldList($deliveryFields,['id','webhook_id','event_id','attempt','http_code','create_time']))->order('id','asc')->limit(101)->select()->toArray();
        if(count($deliveries)>100){$this->gap('回调投递记录超过展示上限，仅展示前 100 项');$deliveries=array_slice($deliveries,0,100);}
        $eventIds=array_column($events,'event_id','id');
        foreach($deliveries as $delivery){
            if($eventIds[$delivery['webhook_id']]!==$delivery['event_id']){$this->gap('回调投递的事件标识不匹配，未展示该记录');continue;}
            $status=$delivery['http_code']>=200&&$delivery['http_code']<300?'delivered':'failed';
            $delivery['status']=$status;$this->trace['webhook_deliveries'][]=$delivery;
            $this->node('delivery:'.$delivery['id'],'webhook:'.$delivery['webhook_id'],'webhook_delivery','回调投递尝试',(string)$delivery['attempt'],$status,$delivery,$delivery);
        }
        if(!$this->trace['webhook_deliveries'])$this->gap('回调事件尚无已保存的投递尝试');
    }

    private function owned(string $table,array $ids,array $fields): array
    {
        $available=$this->columns($table);
        if(!isset($available['id'],$available['tenant_id'],$available['user_id']))return [];
        return Db::name($table)->where($this->owner)->whereIn('id',$ids)->field($this->fieldList($available,$fields))->order('id','asc')->limit(self::LIMIT)->select()->toArray();
    }

    private function columns(string $table): array
    {
        if(array_key_exists($table,$this->fields))return $this->fields[$table];
        // Registry and fixed table names only; no SQL identifiers originate in a request.
        if(!preg_match('/^[a-z][a-z0-9_]+$/D',$table))return [];
        try{return $this->fields[$table]=Db::name($table)->getFields();}
        catch(\Throwable $e){return $this->fields[$table]=[];}
    }
    private function fieldList(array $available,array $fields): string { return implode(',',array_values(array_intersect($fields,array_keys($available)))); }
    private function pick(array $row,array $fields): array { return array_intersect_key($row,array_flip($fields)); }
    private function positiveId(mixed $value): int { return (is_int($value)||is_string($value))&&preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value)?(int)$value:0; }
    private function ids(array $values,string $label): array
    {
        $ids=array_values(array_unique(array_filter(array_map(fn($v)=>$this->positiveId($v),$values))));
        if(count($ids)>self::LIMIT)$this->gap($label.'超过展示上限，仅展示前 50 项');
        return array_slice($ids,0,self::LIMIT);
    }
    private function priceSummary(array $snapshot): array
    {
        $summary=$this->pick($snapshot,['product_id','sku_id','sku_key','resource_type','model_type','usage_unit','usage_unit_size','billing_mode']);
        $summary=array_filter($summary,static fn($v)=>is_scalar($v));
        // This is the frozen user retail rate, never upstream or platform supplier cost.
        if(isset($snapshot['tenant_price'])&&is_numeric($snapshot['tenant_price']))$summary['retail_unit_points']=(float)$snapshot['tenant_price'];
        $rules=[];
        foreach(array_slice((array)($snapshot['rules']??[]),0,50) as $rule){
            if(!is_array($rule))continue;
            $entry=array_filter($this->pick($rule,['sku_id','sku_key','basis','units','max_units','usage_unit','unit_size']),static fn($v)=>is_scalar($v));
            foreach(['user'=>'retail_unit_points','tenant'=>'tenant_unit_points'] as $side=>$field)if(isset($rule['prices'][$side])&&is_numeric($rule['prices'][$side]))$entry[$field]=(float)$rule['prices'][$side];
            if($entry)$rules[]=$entry;
        }
        if($rules)$summary['frozen_skus']=$rules;
        return $summary;
    }
    private function requestSummary(array $snapshot): array
    {
        $summary=$this->numericSummary($snapshot);
        foreach(['prompt_length','reference_image_count'] as $key)if(isset($snapshot[$key])&&is_numeric($snapshot[$key]))$summary[$key]=(int)$snapshot[$key];
        foreach(['ratio','quality','resolution','mode'] as $key)if(isset($snapshot[$key])&&is_string($snapshot[$key])&&preg_match('/^[a-zA-Z0-9:_ .-]{1,60}$/D',$snapshot[$key]))$summary[$key]=$snapshot[$key];
        return $summary;
    }
    private function numericSummary(array $summary): array
    {
        $out=[];
        foreach(['requested_quantity','completed_quantity','quantity','input_tokens','output_tokens','total_tokens','duration','duration_seconds','seconds','images','characters','units'] as $key)if(isset($summary[$key])&&is_numeric($summary[$key]))$out[$key]=(float)$summary[$key];
        return $out;
    }
    private function node(string $id,?string $parent,string $type,string $label,string $reference,string $status,array $row,array $data): void
    {
        if(isset($this->nodes[$id]))return;
        $this->nodes[$id]=true;$created=(int)($row['create_time']??0);$updated=(int)($row['update_time']??$created);
        $this->trace['nodes'][]=['id'=>$id,'parent_id'=>$parent,'type'=>$type,'label'=>$label,'reference'=>$reference,'status'=>$status,'created_at'=>$created,'updated_at'=>$updated,'data'=>$data];
        if($created>0)$this->trace['timeline'][]=['time'=>$created,'label'=>$label.'记录创建','node_id'=>$id];
        if($updated>$created)$this->trace['timeline'][]=['time'=>$updated,'label'=>$label.'最后更新','node_id'=>$id];
        if((int)($row['finish_time']??0)>0)$this->trace['timeline'][]=['time'=>(int)$row['finish_time'],'label'=>$label.'记录完成','node_id'=>$id];
    }
    private function gap(string $message): void { if(!in_array($message,$this->trace['gaps'],true))$this->trace['gaps'][]=$message; }
}
