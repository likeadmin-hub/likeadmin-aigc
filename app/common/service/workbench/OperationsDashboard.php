<?php
namespace app\common\service\workbench;

use think\facade\Db;
use think\facade\Log;
use RuntimeException;

/** Read-only, bounded aggregates. No provider calls, price recalculation or per-app table scans. */
final class OperationsDashboard
{
    private int $tenant;
    private array $range;
    private array $metrics = [];
    private array $series = [];
    private array $sources = [];
    private array $distributions = [];
    private array $rankings = [];
    private array $tables = [];
    private ?array $tenantRow = null;

    public static function build(int $tenant, array $params = [], ?int $now = null): array
    {
        if ($tenant < 0) throw new RuntimeException('租户身份无效');
        $self = new self();
        $self->tenant = $tenant;
        $self->range = OperationsRange::resolve($params, $now);
        return $self->run($now ?? time());
    }

    private function run(int $now): array
    {
        $this->source('accounts', '账户与余额', '总量和余额为查询时快照；新增按注册时间，已删除账户不计入。', function () {
            $q = $this->query('tenant')->whereNull('delete_time');
            if ($this->tenant) {
                $this->tenantRow = (clone $q)->find();
                if (!$this->tenantRow) throw new RuntimeException('Tenant unavailable');
                $this->metric('tenant_point_balance', '本租户可用点数', (float)$this->tenantRow['point_balance'], null, '点', 'accounts');
                $users = $this->query('user', true)->whereNull('delete_time');
                $this->metric('user_total', '用户总数', (int)(clone $users)->count(), null, '人', 'accounts');
                $this->metric('user_point_balance', '用户可用点数合计', (float)(clone $users)->sum('user_money'), null, '点', 'accounts');
                $this->aggregate($users, 'create_time', ['user_new'=>'COUNT(*)'], ['user_new'=>['新增用户','人']], 'accounts');
            } else {
                $this->metric('tenant_total', '租户总数', (int)(clone $q)->count(), null, '个', 'accounts');
                $this->metric('tenant_enabled', '正常租户', (int)(clone $q)->where('disable', 0)->count(), null, '个', 'accounts');
                $this->metric('tenant_point_balance', '租户可用点数合计', (float)(clone $q)->sum('point_balance'), null, '点', 'accounts');
                $this->aggregate($q, 'create_time', ['tenant_new'=>'COUNT(*)'], ['tenant_new'=>['新增租户','个']], 'accounts');
            }
        }, $this->tenant ? ['tenant_point_balance'=>['本租户可用点数','点'],'user_total'=>['用户总数','人'],'user_point_balance'=>['用户可用点数合计','点'],'user_new'=>['新增用户','人']] : ['tenant_total'=>['租户总数','个'],'tenant_enabled'=>['正常租户','个'],'tenant_point_balance'=>['租户可用点数合计','点'],'tenant_new'=>['新增租户','个']]);

        $this->source('apps', '应用开通记录', '当前已启用应用为快照；新增开通包括续费，按应用订单创建时间，不计作现金收入。', function () {
            $q = $this->query('tenant_app');
            $this->metric('apps_enabled', '当前启用应用', (int)$q->where('enable_status', 'enabled')->count(), null, '项', 'apps');
            $this->aggregate($this->query('tenant_app_order')->where('pay_status', 1), 'create_time', ['app_openings'=>'COUNT(*)'], ['app_openings'=>['应用开通与续费','笔']], 'apps');
        }, ['apps_enabled'=>['当前启用应用','项'],'app_openings'=>['应用开通与续费','笔']]);

        $taskDefs = ['task_total'=>['统一任务数','个'],'task_success'=>['成功任务','个'],'task_failed'=>['失败任务','个'],
            'task_running'=>['未结束任务','个'],'task_success_rate'=>['已结束任务成功率','%'],'active_users'=>['任务活跃用户','人']];
        if (!$this->tenant) $taskDefs['active_tenants'] = ['任务活跃租户','个'];
        $this->source('tasks', '统一任务记录', '仅统计接入统一记录的顶层任务，按创建时间归属；旧应用独立任务不包含。成功率=成功/(成功+部分成功+失败+取消)；无已结束任务显示暂无数据。任务活跃用户不等同访问或登录活跃。', function () use ($taskDefs) {
            $q = $this->query('ai_app_task')->where('parent_task_id', 0);
            $expr = ['task_total'=>'COUNT(*)','task_success'=>"SUM(status='success')",'task_failed'=>"SUM(status='failed')",
                'task_running'=>"SUM(status NOT IN ('success','partial_success','failed','canceled','cancelled'))",
                'active_users'=>"COUNT(DISTINCT CASE WHEN user_id>0 THEN CONCAT(tenant_id,':',user_id) END)"];
            if (!$this->tenant) $expr['active_tenants']='COUNT(DISTINCT tenant_id)';
            $this->aggregate($q, 'create_time', $expr, $taskDefs, 'tasks');
            $rate=[];
            foreach ([false,true] as $previous) {
                $v=$this->window(clone $q,'create_time',$previous)->fieldRaw("SUM(status='success') success, SUM(status IN ('success','partial_success','failed','canceled','cancelled')) terminal")->find();
                $rate[]=(int)($v['terminal']??0)>0?round(100*(int)$v['success']/(int)$v['terminal'],2):null;
            }
            $this->metric('task_success_rate','已结束任务成功率',$rate[0],$rate[1],'%', 'tasks');
            $names=['success'=>'成功','partial_success'=>'部分成功','failed'=>'失败','canceled'=>'已取消','cancelled'=>'已取消','running'=>'进行中','pending'=>'等待中','queued'=>'排队中','waiting'=>'等待中','waiting_continue'=>'待继续'];
            $rows=$this->window(clone $q,'create_time')->fieldRaw('status,COUNT(*) value')->group('status')->select()->toArray();
            $this->distributions[]=['key'=>'task_status','label'=>'任务状态分布','unit'=>'个','status'=>'ready','data'=>array_map(fn($r)=>['key'=>$r['status'],'name'=>$names[$r['status']]??'其他状态','value'=>(int)$r['value']],$rows)];
            $appRows=$this->window(clone $q,'create_time')->fieldRaw('app_code,COUNT(*) value')->group('app_code')->order('value','desc')->limit(10)->select()->toArray();
            $this->ranking('apps_by_tasks','应用任务排行','个',$appRows,'app_code');
            if ($this->tenant) {
                $rows=$this->window(clone $q,'create_time')->where('user_id','>',0)->fieldRaw('user_id,COUNT(*) value')->group('user_id')->order('value','desc')->limit(10)->select()->toArray();
                $this->ranking('users_by_tasks','用户任务排行','个',$rows,'user_id','用户 #');
            }
        }, $taskDefs);

        $this->source('settlements','统一结算账单','仅已结算账单的实际点数，按结算完成时间统计；预占、退款、旧独立应用账单不包含。点数不是人民币成本，不计算利润。', function () {
            $q=$this->query('ai_consumption_log')->where('billing_status','settled')->where('finish_time','>',0);
            $this->aggregate($q,'finish_time',['settled_tenant_points'=>'SUM(actual_tenant_cost)','settled_user_points'=>'SUM(actual_user_price)'],['settled_tenant_points'=>['租户已结算点数','点'],'settled_user_points'=>['用户已结算点数','点']],'settlements');
            $rows=$this->window(clone $q,'finish_time')->fieldRaw('app_code,SUM(actual_tenant_cost) value,SUM(actual_user_price) secondary_value')->group('app_code')->order('value','desc')->limit(10)->select()->toArray();
            $this->ranking('apps_by_points','应用结算点数排行','点',$rows,'app_code');
            if (!$this->tenant) {
                $rows=$this->window(clone $q,'finish_time')->fieldRaw('tenant_id,SUM(actual_tenant_cost) value')->group('tenant_id')->order('value','desc')->limit(10)->select()->toArray();
                $this->ranking('tenants_by_points','租户结算点数排行','点',$rows,'tenant_id','租户 #');
            }
        }, ['settled_tenant_points'=>['租户已结算点数','点'],'settled_user_points'=>['用户已结算点数','点']]);

        $cashNote=$this->tenant?'充值和会员已支付订单，按支付时间统计；仅微信、支付宝、虚拟支付，排除点数支付、后台赠送和卡密。退款单列，不从原收款回溯扣除。':'算力商城、合约、贴牌额度采购已支付订单，按支付时间统计；仅外部支付，应用点数开通不算现金。贴牌终端客户付款属于租户，不包含。退款单列。';
        $this->source('payments','外部支付订单',$cashNote,function () {
            $tables=$this->tenant?['recharge_order'=>'充值','membership_order'=>'会员']:['tenant_power_order'=>'算力商城','tenant_contract_order'=>'合约','tenant_brand_quota_order'=>'贴牌额度'];
            $current=['cash_received'=>0,'paid_orders'=>0];$previous=$current;$line=array_fill(0,count($this->range['labels']),0);$pie=[];
            foreach ($tables as $table=>$name) {
                $q=$this->query($table)->where('pay_status',1)->whereIn('pay_way',[2,3,4])->where('order_amount','>',0);
                [$c,$p,$b]=$this->values($q,'pay_time',['cash_received'=>'SUM(order_amount)','paid_orders'=>'COUNT(*)']);
                foreach ($current as $key=>$_) {$current[$key]+=(float)($c[$key]??0);$previous[$key]+=(float)($p[$key]??0);}
                foreach ($b as $row) $line[(int)$row['bucket']]+=(float)$row['cash_received'];
                $pie[]=['key'=>$table,'name'=>$name,'value'=>round((float)($c['cash_received']??0),2)];
            }
            $this->metric('cash_received','已支付订单金额',round($current['cash_received'],2),round($previous['cash_received'],2),'元','payments');
            $this->metric('paid_orders','外部支付订单数',(int)$current['paid_orders'],(int)$previous['paid_orders'],'笔','payments');
            $this->series[]=['key'=>'cash_received','label'=>'已支付订单金额','unit'=>'元','data'=>array_map(fn($v)=>round($v,2),$line)];
            $this->distributions[]=['key'=>'cash_sources','label'=>'收款来源','unit'=>'元','status'=>'ready','data'=>$pie];
        },['cash_received'=>['已支付订单金额','元'],'paid_orders'=>['外部支付订单数','笔']]);

        $this->source('refunds','已确认退款',$this->tenant?'合并充值/会员传统成功退款及虚拟支付已确认全额退款；同一虚拟订单优先使用确认回执，兼容退款记录不重复累加。传统退款按最后更新时间，虚拟退款按首次确认回执时间；重复或未入订单收款的交易退款不计入。':'仅贴牌额度采购的原订单已确认退款；重复支付退款不冲减订单收款，算力商城和合约未有统一退款账本，因此不能将此值视为全部平台退款。',function () {
            if ($this->tenant) {
                $virtual=$this->virtualRefundSql();
                $q=$this->query('refund_record')->where('refund_status',1)->whereIn('order_type',['recharge','membership']);
                // Include only external-pay orders already included in the revenue denominator.
                $prefix=(string)config('database.connections.mysql.prefix');
                $q->whereRaw("EXISTS (SELECT 1 FROM `{$prefix}recharge_order` o WHERE o.id=order_id AND o.tenant_id=`{$prefix}refund_record`.tenant_id AND o.pay_way IN (2,3,4) AND order_type='recharge') OR EXISTS (SELECT 1 FROM `{$prefix}membership_order` o WHERE o.id=order_id AND o.tenant_id=`{$prefix}refund_record`.tenant_id AND o.pay_way IN (2,3,4) AND order_type='membership')");
                // Full virtual refunds are authoritative across all dates, not only this window.
                $q->whereRaw("NOT EXISTS (SELECT 1 FROM ({$virtual}) vr WHERE vr.tenant_id=`{$prefix}refund_record`.tenant_id AND vr.biz_type=`{$prefix}refund_record`.order_type AND vr.order_id=`{$prefix}refund_record`.order_id)");
                $virtualQuery=Db::table("({$virtual}) virtual_refunds")->pk('confirmed_at')->setFieldType(['confirmed_at'=>'int','amount'=>'decimal']);
                $current=0;$previous=0;$line=array_fill(0,count($this->range['labels']),0);
                foreach ([[$q,'update_time','refund_amount'],[$virtualQuery,'confirmed_at','amount']] as [$refundQuery,$date,$amount]) {
                    [$c,$p,$b]=$this->values($refundQuery,$date,['refund_amount'=>'SUM('.$amount.')']);
                    $current+=(float)($c['refund_amount']??0);$previous+=(float)($p['refund_amount']??0);
                    foreach ($b as $row) $line[(int)$row['bucket']]+=(float)$row['refund_amount'];
                }
                $this->metric('refund_amount','已确认订单退款',round($current,2),round($previous,2),'元','refunds');
                $this->series[]=['key'=>'refund_amount','label'=>'已确认订单退款','unit'=>'元','data'=>array_map(fn($v)=>round($v,2),$line)];
                return;
            } else {
                $q=$this->query('tenant_brand_refund')->alias('r')->join('tenant_brand_payment p','p.id=r.payment_id AND p.tenant_id=r.tenant_id')->where(['r.status'=>'success','r.order_from'=>'tenant_brand_quota','p.disposition'=>'primary']);
                $time='r.confirmed_time';$expr='SUM(r.amount_cents)/100';
            }
            $this->aggregate($q,$time,['refund_amount'=>$expr],['refund_amount'=>[$this->tenant?'已确认订单退款':'已确认额度订单退款','元']],'refunds');
        },['refund_amount'=>[$this->tenant?'已确认订单退款':'已确认额度订单退款','元']]);

        if ($this->tenant) $this->userPoints();
        $ready=count(array_filter($this->sources,fn($s)=>$s['status']==='ready'));
        $error=count($this->sources)-$ready;
        $hasData=count(array_filter($this->metrics,fn($m)=>$m['value']!==null&&(float)$m['value']!==0))>0;
        $range=$this->range;unset($range['step'],$range['labels']);
        return ['schema_version'=>1,'scope'=>$this->tenant?'tenant':'platform','updated_at'=>$now,'range'=>$range,
            'status'=>$ready===0?'error':($error?'partial':($hasData?'ready':'empty')),
            'metrics'=>array_values($this->metrics),'trend'=>['labels'=>$this->range['labels'],'series'=>$this->series],
            'distributions'=>$this->distributions,'rankings'=>$this->rankings,'sources'=>$this->sources,
            'warnings'=>array_values(array_map(fn($s)=>$s['label'].'：'.$s['note'],array_filter($this->sources,fn($s)=>$s['status']!=='ready')))];
    }

    private function virtualRefundSql(): string
    {
        // Missing confirmation tables must make refunds unavailable, never silently report zero.
        $this->query('virtual_pay_intent');$this->query('virtual_pay_receipt');
        $prefix=(string)config('database.connections.mysql.prefix');$parts=[];
        foreach (['recharge'=>'recharge_order','membership'=>'membership_order'] as $kind=>$table) {
            $this->query($table);
            $parts[]="SELECT '{$kind}' AS biz_type,id,tenant_id,user_id,order_amount,transaction_id FROM `{$prefix}{$table}` WHERE tenant_id={$this->tenant} AND pay_status=1 AND pay_way=4";
        }
        $orders=implode(' UNION ALL ',$parts);
        return "SELECT i.tenant_id,i.biz_type,i.order_id,MAX(r.amount_cents)/100 AS amount,MIN(r.created_at) AS confirmed_at
                FROM `{$prefix}virtual_pay_intent` i
                JOIN `{$prefix}virtual_pay_receipt` r ON r.intent_id=i.id AND r.tenant_id=i.tenant_id AND r.app_id=i.app_id AND r.env=i.env
                JOIN ({$orders}) o ON o.id=i.order_id AND o.tenant_id=i.tenant_id AND o.user_id=i.user_id AND o.biz_type=i.biz_type
                WHERE i.tenant_id={$this->tenant} AND i.refund_status='success'
                  AND r.kind='refund' AND r.amount_cents=i.refund_cents AND i.refund_cents=i.amount_cents AND i.amount_cents>0
                  AND o.transaction_id=i.wx_order_id AND i.wx_order_id<>''
                  AND o.order_amount*100=i.amount_cents
                GROUP BY i.tenant_id,i.biz_type,i.order_id";
    }

    private function userPoints(): void
    {
        $defs=['points_issued'=>['发放用户点数','点'],'points_consumed'=>['用户消费扣减','点'],'points_returned'=>['消费退回点数','点']];
        $this->source('user_points','用户点数流水','发放包含后台增加、充值、会员赠送、注册赠送、卡密；消费扣减与消费退回分开显示，包含尚未最终结算的预占，不能当现金收入或最终成本。',function () use ($defs) {
            $q=$this->query('user_account_log',true)->where('change_object',1);
            $this->aggregate($q,'create_time',[
                'points_issued'=>'SUM(CASE WHEN action=1 AND change_type IN (200,201,202,203,205) THEN change_amount ELSE 0 END)',
                'points_consumed'=>'SUM(CASE WHEN action=2 AND change_type=102 THEN change_amount ELSE 0 END)',
                'points_returned'=>'SUM(CASE WHEN action=1 AND change_type=204 THEN change_amount ELSE 0 END)'], $defs,'user_points');
            $names=[200=>'后台增加',201=>'充值到账',202=>'会员赠送',203=>'注册赠送',205=>'卡密兑换'];
            $rows=$this->window(clone $q,'create_time')->where('action',1)->whereIn('change_type',array_keys($names))->fieldRaw('change_type,SUM(change_amount) value')->group('change_type')->select()->toArray();
            $this->distributions[]=['key'=>'point_issuance','label'=>'点数发放来源','unit'=>'点','status'=>'ready','data'=>array_map(fn($r)=>['key'=>(string)$r['change_type'],'name'=>$names[$r['change_type']],'value'=>round((float)$r['value'],2)],$rows)];
        },$defs);
    }

    private function source(string $key,string $label,string $note,callable $fn,array $defs): void
    {
        $saved=[$this->metrics,$this->series,$this->distributions,$this->rankings];
        try {$fn();$status='ready';}
        catch (\Throwable $e) {
            [$this->metrics,$this->series,$this->distributions,$this->rankings]=$saved;
            $status=$e instanceof OperationsSourceUnavailable?'unavailable':'error';
            $note=$status==='unavailable'?'该统计来源尚未就绪，暂不能统计。':'统计读取失败，请刷新重试。';
            foreach ($defs as $metric=>[$title,$unit]) $this->metric($metric,$title,null,null,$unit,$key,$status);
            Log::warning('Workbench operations source '.$key.' unavailable: '.get_class($e));
        }
        $this->sources[]=['key'=>$key,'label'=>$label,'status'=>$status,'note'=>$note];
    }

    private function metric(string $key,string $label,int|float|null $value,int|float|null $previous,string $unit,string $source,string $status='ready'): void
    {
        $this->metrics[$key]=['key'=>$key,'label'=>$label,'value'=>$value===null?null:round($value,6),'previous'=>$previous===null?null:round($previous,6),
            'change_percent'=>$previous!==null&&$previous!=0&&$value!==null?round(($value-$previous)/abs($previous)*100,2):null,
            'unit'=>$unit,'source'=>$source,'status'=>$status,'note'=>$previous===null?'当前快照或无可比数据':($previous==0?'上期为0，不计算增长率':'与上期相同天数、相同已过时长比较')];
    }

    private function aggregate($query,string $time,array $expressions,array $defs,string $source): void
    {
        [$current,$previous,$rows]=$this->values($query,$time,$expressions);
        foreach ($expressions as $key=>$_) {
            [$label,$unit]=$defs[$key];
            $this->metric($key,$label,(float)($current[$key]??0),(float)($previous[$key]??0),$unit,$source);
            $data=array_fill(0,count($this->range['labels']),0);
            foreach ($rows as $row) $data[(int)$row['bucket']]=round((float)($row[$key]??0),6);
            $this->series[]=['key'=>$key,'label'=>$label,'unit'=>$unit,'data'=>$data];
        }
    }

    private function values($query,string $time,array $expressions): array
    {
        $fields=implode(',',array_map(fn($key)=>$expressions[$key].' AS '.$key,array_keys($expressions)));
        $current=$this->window(clone $query,$time)->fieldRaw($fields)->find()??[];
        $previous=$this->window(clone $query,$time,true)->fieldRaw($fields)->find()??[];
        $bucket='FLOOR(('.$time.'-'.$this->range['start_at'].')/'.$this->range['step'].')';
        $rows=$this->window(clone $query,$time)->fieldRaw($bucket.' AS bucket,'.$fields)->group('bucket')->order('bucket')->select()->toArray();
        return [$current,$previous,$rows];
    }

    private function window($query,string $field,bool $previous=false)
    {
        return $query->where($field,'>=',$this->range[$previous?'previous_start_at':'start_at'])->where($field,'<',$this->range[$previous?'previous_end_at':'end_at']);
    }

    private function query(string $name,bool $split=false)
    {
        $prefix=(string)config('database.connections.mysql.prefix');
        if (!preg_match('/^\w+$/D',$prefix)) throw new RuntimeException('Invalid table prefix');
        $table=$prefix.$name;
        if ($split && $this->tenant) {
            $tenant=$this->tenantRow??Db::name('tenant')->where('id',$this->tenant)->whereNull('delete_time')->find();
            if (!$tenant) throw new OperationsSourceUnavailable();
            if ((int)$tenant['tactics']===1) {
                if (!preg_match('/^\w+$/D',(string)$tenant['sn'])) throw new RuntimeException('Invalid tenant identifier');
                $table.='_'.$tenant['sn'];
            }
        }
        if (!array_key_exists($table,$this->tables)) $this->tables[$table]=Db::connect()->getTableInfo($table,'fields');
        if (!$this->tables[$table]) throw new OperationsSourceUnavailable();
        $q=Db::table($table);
        if ($this->tenant) $q->where($name==='tenant'?'id':'tenant_id',$this->tenant);
        return $q;
    }

    private function ranking(string $key,string $label,string $unit,array $rows,string $id,string $prefix=''): void
    {
        $names=[];
        if ($rows && $id==='app_code') {
            // One bounded registry lookup; display labels never depend on dynamic app tables.
            try {$names=Db::name('app')->whereIn('code',array_column($rows,$id))->column('name','code');} catch (\Throwable) {}
        }
        if ($rows && $id==='tenant_id' && !$this->tenant) {
            try {$names=Db::name('tenant')->whereIn('id',array_column($rows,$id))->column('name','id');} catch (\Throwable) {}
        }
        if ($rows && $id==='user_id' && $this->tenant) {
            try {
                foreach ($this->query('user',true)->whereIn('id',array_column($rows,$id))->field('id,nickname,account')->select()->toArray() as $row) {
                    $names[$row['id']]=$row['nickname']?:$row['account'];
                }
            } catch (\Throwable) {}
        }
        $this->rankings[]=['key'=>$key,'label'=>$label,'unit'=>$unit,'status'=>'ready','rows'=>array_map(function($r)use($id,$prefix,$names){
            $v=['id'=>$r[$id],'name'=>$names[$r[$id]]??($prefix.$r[$id]),'value'=>round((float)$r['value'],6)];
            if (isset($r['secondary_value'])) $v['secondary_value']=round((float)$r['secondary_value'],6);
            return $v;
        },$rows)];
    }
}

final class OperationsSourceUnavailable extends RuntimeException {}
