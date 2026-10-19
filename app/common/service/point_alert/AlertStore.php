<?php
namespace app\common\service\point_alert;

use think\facade\Db;
use RuntimeException;

final class AlertStore
{
    public static function table(string $suffix) { return Db::name('point_alert_'.$suffix); }
    public static function json(mixed $value): array { if(is_array($value))return $value;$a=json_decode((string)$value,true);return is_array($a)?$a:[]; }
    public static function encode(array $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    public static function tenant(int $id): array
    {
        $row=Db::name('tenant')->where('id',$id)->whereNull('delete_time')->find();
        if(!$row)throw new RuntimeException('租户不存在');return $row;
    }
    public static function scoped(string $name,int $tenant)
    {
        $t=self::tenant($tenant);$prefix=(string)config('database.connections.mysql.prefix');
        $table=$prefix.$name;
        if((int)$t['tactics']===1){
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',(string)$t['sn']))throw new RuntimeException('租户分表标识无效');
            $split=$table.'_'.$t['sn'];
            if(Db::connect()->getTableInfo($split))$table=$split;
        }
        return Db::table($table)->where('tenant_id',$tenant);
    }
    public static function account(int $tenant,int $user): ?array
    {
        $t=self::tenant($tenant);if(!empty($t['disable']))return null;
        if(!$user)return ['id'=>$tenant,'name'=>$t['name'],'balance'=>(string)$t['point_balance'],'mobile'=>''];
        $u=self::scoped('user',$tenant)->where('id',$user)->whereNull('delete_time')->find();
        if(!$u || !empty($u['is_disable']))return null;
        return ['id'=>$user,'name'=>$u['nickname']?:$u['account'],'balance'=>(string)$u['user_money'],'mobile'=>$u['mobile']??''];
    }
    public static function config(int $owner,string $type,string $name,mixed $default=null): mixed
    {
        $q=$owner?self::scoped('tenant_config',$owner):Db::name('config');
        $v=$q->where(['type'=>$type,'name'=>$name])->value('value');
        if($v===null)return $default;$decoded=json_decode((string)$v,true);return json_last_error()===JSON_ERROR_NONE?$decoded:$v;
    }
    public static function settings(int $owner): array
    {
        $row=self::table('setting')->where('owner_id',$owner)->find();
        return ['revision'=>(int)($row['revision']??0),'rules'=>AlertPolicy::normalize(self::json($row['config']??[])),
            'unit'=>(string)self::config($owner,'recharge','point_unit','点'),
            'sms_ready'=>self::smsConfig($owner)!==null];
    }
    public static function smsConfig(int $owner): ?array
    {
        $engine=strtoupper((string)self::config($owner,'sms','engine',''));
        if(!in_array($engine,['ALI','TENCENT'],true))return null;
        $c=self::config($owner,'sms',strtolower($engine),[]);
        return is_array($c)&&!empty($c['status'])?['engine'=>$engine,'config'=>$c]:null;
    }
    public static function saveRules(int $owner,array $input,int $expectedRevision): array
    {
        $r=AlertPolicy::normalize($input);
        foreach(array_unique(array_merge($r['channels'],...array_values($r['level_channels']))) as $channel){
            if($r['enabled']&&$channel==='sms'&&(!self::smsConfig($owner)||!$r['sms_template_id']))throw new RuntimeException('请先配置短信通道和提醒模板');
            if($r['enabled']&&$channel==='email')throw new RuntimeException('邮件通道尚未配置，请先使用站内信或短信');
        }
        return Db::transaction(function()use($owner,$r,$expectedRevision){
            self::table('setting')->duplicate(['owner_id'=>$owner])->insert(['owner_id'=>$owner,'revision'=>0,'config'=>'{}','update_time'=>time()]);
            $old=self::table('setting')->where('owner_id',$owner)->lock(true)->find();
            if((int)$old['revision']!==$expectedRevision)throw new RuntimeException('设置已被其他管理员修改，请刷新后重试');
            self::table('setting')->where('owner_id',$owner)->update(['revision'=>$expectedRevision+1,'config'=>self::encode($r),'update_time'=>time()]);
            if(!$r['enabled']){
                $ids=self::table('round')->where(['owner_id'=>$owner,'status'=>'pending'])->column('id');
                if($ids){self::table('delivery')->whereIn('round_id',$ids)->where('status','pending')->update(['status'=>'cancelled','result'=>'预警已关闭']);self::table('round')->whereIn('id',$ids)->update(['status'=>'cancelled','update_time'=>time()]);}
            }
            return self::settings($owner);
        });
    }
    public static function contacts(int $tenant,int $user=0): array
    {
        $row=self::table('contact')->where(['tenant_id'=>$tenant,'user_id'=>$user])->find();
        return array_replace(['sms_enabled'=>true,'contacts'=>[]],self::json($row['config']??[]));
    }
    public static function saveContacts(int $tenant,int $user,array $p): array
    {
        self::tenant($tenant);$contacts=[];
        if(!$user){
            if(count($p['contacts']??[])>5)throw new RuntimeException('最多设置5位接收人');
            foreach($p['contacts']??[] as $c){
                $name=trim((string)($c['name']??''));$mobile=trim((string)($c['mobile']??''));
                if(!$name||mb_strlen($name)>40||!preg_match('/^1\d{10}$/D',$mobile))throw new RuntimeException('请填写接收人姓名和有效手机号');
                $contacts[$mobile]=['name'=>$name,'mobile'=>$mobile];
            }
        }
        $v=['sms_enabled'=>filter_var($p['sms_enabled']??true,FILTER_VALIDATE_BOOLEAN),'contacts'=>array_values($contacts)];
        self::table('contact')->duplicate(['config'=>self::encode($v),'update_time'=>time()])->insert(['tenant_id'=>$tenant,'user_id'=>$user,'config'=>self::encode($v),'update_time'=>time()]);
        return $v;
    }
    public static function records(int $owner,array $p): array
    {
        $q=self::table('round')->where('owner_id',$owner);
        if(!empty($p['keyword'])){
            // Recipient display names are included by DTO; filter stable account ID safely.
            $q->where(function($q)use($p){$q->whereLike('content','%'.mb_substr((string)$p['keyword'],0,100).'%')->whereOr('tenant_id','=',(int)$p['keyword'])->whereOr('user_id','=',(int)$p['keyword']);});
        }
        if(!empty($p['status']))$q->where('status',(string)$p['status']);
        $count=(clone $q)->count();$rows=$q->order('id','desc')->page(max(1,(int)($p['page_no']??1)),min(100,max(1,(int)($p['page_size']??20))))->select()->toArray();
        foreach($rows as &$r){$r['deliveries']=self::table('delivery')->where('round_id',$r['id'])->field('id,channel,status,attempts,result,update_time')->select()->toArray();}
        return ['lists'=>$rows,'count'=>$count];
    }
    public static function detail(int $owner,int $id): array
    {
        $r=self::table('round')->where(['owner_id'=>$owner,'id'=>$id])->find();if(!$r)throw new RuntimeException('预警记录不存在');
        $r['deliveries']=self::table('delivery')->where('round_id',$id)->field('id,channel,status,attempts,result,update_time')->select()->toArray();
        $r['current_balance']=self::account($r['tenant_id'],$r['user_id'])['balance']??null;
        $row=self::table('account')->where(['tenant_id'=>$r['tenant_id'],'user_id'=>$r['user_id']])->find();
        $r['budget']=self::json($row['state']??[]);$r['limits']=self::settings($owner)['rules'];
        return $r;
    }
    public static function inbox(int $tenant,int $user,int $page=1): array
    {
        return self::table('round')->alias('r')->where(['r.tenant_id'=>$tenant,'r.user_id'=>$user])->whereExists(function($q){$q->table((string)config('database.connections.mysql.prefix').'point_alert_delivery')->whereRaw('round_id = r.id')->where(['channel'=>'in_app','status'=>'success']);})->order('r.id','desc')->page(max(1,$page),50)->field('r.id,r.title,r.content,r.balance,r.threshold,r.read_time,r.create_time,r.status')->select()->toArray();
    }
    public static function readInbox(int $tenant,int $user,int $id): bool
    {
        return (bool)self::table('round')->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$id])->update(['read_time'=>time()]);
    }
}
