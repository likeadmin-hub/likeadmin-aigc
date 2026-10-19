<?php
namespace app\common\service\point_alert;

use think\facade\Db;
use think\facade\Log;

final class AlertService
{
    /** A durable, transactional hint only. Never make notification availability a billing dependency. */
    public static function touch(int $tenant,int $user=0): void
    {
        if($tenant<=0||$user<0)return;
        try{
            $prefix=(string)config('database.connections.mysql.prefix');
            if(!preg_match('/^\w+$/D',$prefix))return;
            Db::execute('INSERT INTO `'.$prefix.'point_alert_dirty` (tenant_id,user_id,version,due_at) VALUES (?,?,1,?) ON DUPLICATE KEY UPDATE version=version+1,due_at=LEAST(due_at,VALUES(due_at))',[$tenant,$user,time()]);
        }catch(\Throwable $e){ Log::warning('Point alert outbox unavailable; scheduled scan will reconcile'); }
    }
    public static function check(int $tenant,int $user=0,?int $now=null): array
    {
        $now??=time();$owner=$user?$tenant:0;
        return Db::transaction(function()use($tenant,$user,$owner,$now){
            $settings=AlertStore::settings($owner);$account=AlertStore::account($tenant,$user);
            if(!$account)return ['reason'=>'unavailable','send'=>false];
            $where=['tenant_id'=>$tenant,'user_id'=>$user];
            AlertStore::table('account')->duplicate($where)->insert($where+['state'=>'{}','balance'=>$account['balance'],'reason'=>'','update_time'=>$now]);
            $old=AlertStore::table('account')->where($where)->lock(true)->find();
            // Only one uncompleted round per account. Coalesce newer drops at dispatch time.
            if(AlertStore::table('round')->where($where)->where('status','pending')->find())return ['reason'=>'pending','send'=>false];
            $d=AlertPolicy::decide($settings['rules'],AlertStore::json($old['state']),AlertPolicy::cents($account['balance']),$now);
            AlertStore::table('account')->where($where)->update(['state'=>AlertStore::encode($d['state']),'balance'=>$account['balance'],'reason'=>$d['reason'],'update_time'=>$now]);
            if(!$d['send'])return $d;
            $brand=$owner?(string)AlertStore::tenant($owner)['name']:(string)AlertStore::config(0,'website','name','平台');
            $unit=$settings['unit'];$title='【'.$brand.'】'.($user?'用户':'租户').'点数预警';
            $content=$account['name'].'，当前可用'.$unit.'为'.$account['balance'].'，已降至'.$d['threshold'].'提醒档位，请及时补充'.$unit.'。';
            $id=AlertStore::table('round')->insertGetId($where+['owner_id'=>$owner,'cycle_no'=>$d['state']['cycle_no'],'sequence'=>$d['state']['cycle_count'],'rule_revision'=>$settings['revision'],'balance'=>$account['balance'],'threshold'=>$d['threshold'],'title'=>mb_substr($title,0,120),'content'=>$content,'status'=>'pending','create_time'=>$now,'update_time'=>$now]);
            $contact=AlertStore::contacts($tenant,$user);
            foreach($settings['rules']['level_channels'][$d['threshold']] as $channel){
                $recipients=$channel==='in_app'?['account']:($contact['sms_enabled']?($user?array_filter([$account['mobile']]):array_column($contact['contacts'],'mobile')):[]);
                if(!$recipients)$recipients=[''];
                foreach(array_unique($recipients) as $recipient)AlertStore::table('delivery')->insert(['round_id'=>$id,'channel'=>$channel,'recipient'=>$recipient,'status'=>$channel!=='in_app'&&!$recipient?'skipped':'pending','result'=>$channel!=='in_app'&&!$recipient?'未绑定接收地址或已关闭该渠道':'','update_time'=>$now]);
            }
            return $d+['round_id'=>$id];
        });
    }
    public static function dispatch(int $limit=100,?callable $transport=null): int
    {
        $now=time();$count=0;
        // A crashed or timed-out outbound call has unknown delivery, never auto-resend it.
        AlertStore::table('delivery')->where('status','sending')->where('lease_at','<',$now-300)->update(['status'=>'unknown','result'=>'发送结果待确认，请勿重复发送','update_time'=>$now]);
        $ids=AlertStore::table('delivery')->where('status','pending')->order('id')->limit(max(1,min(500,$limit)))->column('id');
        foreach($ids as $id){
            $claim=Db::transaction(function()use($id,$now){
                $hint=AlertStore::table('delivery')->where('id',$id)->find();if(!$hint)return null;
                $r=AlertStore::table('round')->where('id',$hint['round_id'])->find();if(!$r)return null;
                // All workers acquire the account before round/delivery locks, as admission does.
                $where=['tenant_id'=>$r['tenant_id'],'user_id'=>$r['user_id']];
                $stateRow=AlertStore::table('account')->where($where)->lock(true)->find();
                $r=AlertStore::table('round')->where('id',$r['id'])->lock(true)->find();
                $d=AlertStore::table('delivery')->where('id',$id)->lock(true)->find();
                if(!$d||$d['status']!=='pending'||$r['status']!=='pending')return null;
                $s=AlertStore::settings((int)$r['owner_id']);$account=AlertStore::account((int)$r['tenant_id'],(int)$r['user_id']);
                $current=$account?AlertPolicy::decide($s['rules'],[],AlertPolicy::cents($account['balance']),$now):['threshold'=>null];
                $channels=$current['threshold']!==null?($s['rules']['level_channels'][$current['threshold']]??[]):[];
                $contact=AlertStore::contacts((int)$r['tenant_id'],(int)$r['user_id']);
                // Materialize the complete current channel plan before covering a coalesced level.
                // The unique round/channel/recipient key and account lock make this idempotent.
                if($account&&$s['rules']['enabled'])foreach($channels as $channel){
                    $recipients=$channel==='in_app'?['account']:($contact['sms_enabled']?($r['user_id']?array_filter([$account['mobile']]):array_column($contact['contacts'],'mobile')):[]);
                    if(!$recipients)$recipients=[''];
                    foreach(array_unique($recipients) as $recipient){
                        $key=['round_id'=>$r['id'],'channel'=>$channel,'recipient'=>$recipient];
                        if(!AlertStore::table('delivery')->where($key)->find())AlertStore::table('delivery')->insert($key+['status'=>$channel!=='in_app'&&!$recipient?'skipped':'pending','result'=>$channel!=='in_app'&&!$recipient?'未绑定接收地址或已关闭该渠道':'','update_time'=>$now]);
                    }
                }
                $validRecipient=$d['channel']==='in_app'||($contact['sms_enabled']&&($r['user_id']?($d['recipient']===$account['mobile']):in_array($d['recipient'],array_column($contact['contacts'],'mobile'),true)));
                if(!$account||!$s['rules']['enabled']||!in_array($d['channel'],$channels,true)||!$validRecipient){
                    AlertStore::table('delivery')->where('id',$id)->update(['status'=>'cancelled','result'=>'余额已恢复、规则已关闭或接收设置已变化','update_time'=>$now]);return ['cancelled'=>true,'round_id'=>$r['id']];
                }
                // Cover the level actually delivered, including a newer drop while queued.
                $state=AlertStore::json($stateRow['state']??[]);
                if(($state['cycle_no']??0)==$r['cycle_no'] && ($state['cycle_count']??0)==$r['sequence']) {
                    $state['last_threshold']=$current['threshold'];
                    AlertStore::table('account')->where($where)->update(['state'=>AlertStore::encode($state)]);
                }
                $r['balance']=$account['balance'];$r['threshold']=$current['threshold'];
                $r['content']=$account['name'].'，当前可用'.$s['unit'].'为'.$r['balance'].'，已降至'.$r['threshold'].'提醒档位，请及时补充'.$s['unit'].'。';
                AlertStore::table('round')->where('id',$r['id'])->update(['balance'=>$r['balance'],'threshold'=>$r['threshold'],'content'=>$r['content'],'update_time'=>$now]);
                AlertStore::table('delivery')->where('id',$id)->update(['status'=>'sending','attempts'=>1,'lease_at'=>$now,'update_time'=>$now]);
                return ['delivery'=>$d,'round'=>$r,'settings'=>$s,'account'=>$account];
            });
            if(!$claim)continue;
            if(isset($claim['cancelled'])){self::finishRound((int)$claim['round_id']);continue;}
            try{$result=$transport?$transport($claim['delivery'],$claim['round']):AlertDelivery::send($claim['delivery'],$claim['round'],$claim['settings'],$claim['account']);}
            catch(\Throwable $e){$result=['status'=>'unknown','result'=>'发送结果待确认，请检查通道记录'];}
            $status=in_array($result['status']??'',['success','failed','unknown','skipped'],true)?$result['status']:'unknown';
            AlertStore::table('delivery')->where(['id'=>$id,'status'=>'sending'])->update(['status'=>$status,'result'=>mb_substr((string)($result['result']??''),0,255),'update_time'=>time()]);
            self::finishRound((int)$claim['round']['id']);$count++;
        }
        // Also finalize rounds whose sole in-flight channel expired to unknown.
        foreach(AlertStore::table('round')->where('status','pending')->limit($limit)->column('id') as $id)self::finishRound((int)$id);
        return $count;
    }
    private static function finishRound(int $id): void
    {
        $statuses=AlertStore::table('delivery')->where('round_id',$id)->column('status');
        if(array_intersect($statuses,['pending','sending']))return;
        $status=in_array('unknown',$statuses,true)?'unknown':(in_array('success',$statuses,true)?(count(array_unique($statuses))===1?'sent':'partial'):(in_array('failed',$statuses,true)?'failed':'cancelled'));
        AlertStore::table('round')->where('id',$id)->update(['status'=>$status,'update_time'=>time()]);
    }
    /** Keyset scan advances persistently; it never loads all users or scans a large account table per request. */
    public static function scan(int $owner,int $limit=100,bool $enqueue=true): array
    {
        $limit=max(1,min(500,$limit));$cursor=(int)AlertStore::table('cursor')->where('owner_id',$owner)->value('last_id');
        $q=$owner?AlertStore::scoped('user',$owner)->where(function($q){$q->where('login_time','>',0)->whereOr('user_money','>',0)->whereOr('total_recharge_amount','>',0);}):Db::name('tenant')->whereNull('delete_time')->where('disable',0);
        $rows=$q->where('id','>',$cursor)->order('id')->limit($limit)->field($owner?'id,tenant_id,user_money':'id,point_balance')->select()->toArray();
        if($enqueue){foreach($rows as $row)self::touch($owner?:$row['id'],$owner?$row['id']:0);$next=count($rows)<$limit?0:(int)end($rows)['id'];AlertStore::table('cursor')->duplicate(['last_id'=>$next])->insert(['owner_id'=>$owner,'last_id'=>$next]);}
        return ['checked'=>count($rows),'last_id'=>$rows?(int)end($rows)['id']:0];
    }
    public static function preview(int $owner,array $input=[]): array
    {
        $r=$input?AlertPolicy::normalize($input):AlertStore::settings($owner)['rules'];$max=$r['thresholds'][0];
        $q=$owner?AlertStore::scoped('user',$owner)->where(function($q){$q->where('login_time','>',0)->whereOr('user_money','>',0)->whereOr('total_recharge_amount','>',0);}):Db::name('tenant')->whereNull('delete_time')->where('disable',0);
        $field=$owner?'user_money':'point_balance';$count=(clone $q)->where($field,'<=',$max)->count();
        return ['matching_accounts'=>$count,'notice'=>'实际发送会再次检查余额、接收设置、次数和间隔限制；已有预警不会重复发送。'];
    }
    public static function maintain(int $limit=100,?callable $transport=null): array
    {
        foreach(AlertStore::table('setting')->column('owner_id') as $owner)if(AlertStore::settings((int)$owner)['rules']['enabled'])self::scan((int)$owner,$limit);
        $now=time();$processed=0;
        foreach(AlertStore::table('dirty')->where('due_at','<=',$now)->order('due_at')->limit($limit)->select()->toArray() as $row){
            try{
                $d=self::check((int)$row['tenant_id'],(int)$row['user_id'],$now);
                AlertStore::table('dirty')->where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'version'=>$row['version']])->delete();
                $owner=$row['user_id']?$row['tenant_id']:0;$r=AlertStore::settings((int)$owner)['rules'];
                if($r['enabled']&&in_array($d['reason']??'',['interval','pending','daily_limit','cycle_limit','covered','recovered'],true)){
                    // Recovery windows/repeats/day rollover need a future check even with no new spend.
                    $delay=in_array($d['reason'],['daily_limit','cycle_limit','covered'],true)?3600:60;
                    AlertStore::table('dirty')->duplicate(['due_at'=>Db::raw('due_at')])->insert(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'version'=>1,'due_at'=>$now+$delay]);
                }
                $processed++;
            }catch(\Throwable $e){AlertStore::table('dirty')->where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id']])->update(['due_at'=>$now+300]);Log::warning('Point alert check deferred');}
        }
        return ['checked'=>$processed,'delivered'=>self::dispatch($limit,$transport)];
    }
}
