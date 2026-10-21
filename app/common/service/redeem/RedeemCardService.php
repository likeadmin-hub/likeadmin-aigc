<?php
namespace app\common\service\redeem;

use app\common\model\membership\MembershipPlan;
use app\common\model\membership\MembershipPlanApp;
use app\common\model\user\User;
use app\common\service\membership\MembershipService;
use app\common\service\point\UserPointService;
use think\facade\Db;

/** Single-use, tenant-issued rights. No payment order, tenant deduction or provider call. */
final class RedeemCardService
{
    public static function options(int $tenant): array
    {
        self::assertTenant($tenant);
        return ['plans' => MembershipPlan::where(['tenant_id'=>$tenant,'status'=>1])->whereNull('delete_time')
            ->field('id,name,duration_months,monthly_bonus_points')->order('sort desc,id desc')->select()->toArray(),
            'max_quantity'=>RedeemCardPolicy::MAX_QUANTITY,'max_duration_months'=>120];
    }

    public static function generate(int $tenant, int $admin, array $input): array
    {
        self::assertTenant($tenant);
        foreach(['name','type','remark'] as $key) if(isset($input[$key])&&!is_string($input[$key])) throw new RedeemCardException('invalid','卡密参数格式无效');
        $type=(string)($input['type']??'');$quantity=filter_var($input['quantity']??null,FILTER_VALIDATE_INT);
        $name=trim((string)($input['name']??''));$remark=trim((string)($input['remark']??''));
        if(!in_array($type,['points','membership'],true)||!$quantity||$quantity<1||$quantity>200||$name===''||mb_strlen($name)>60||mb_strlen($remark)>255) throw new RedeemCardException('invalid','批次名称、类型或生成数量无效（每批1至200张）');
        $expiry=filter_var($input['expire_time']??0,FILTER_VALIDATE_INT);
        if($expiry===false||$expiry<0||$expiry>4294967295||($expiry>0&&$expiry<=time())) throw new RedeemCardException('invalid','有效期须为未来时间或0（永久）');
        $points='0.00';$plan=[];$months=0;
        if($type==='points'){
            $raw=(string)($input['points']??'');
            if(!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$raw)||(float)$raw<=0||(float)$raw>10000000) throw new RedeemCardException('invalid','点数须大于0，最多10000000且保留两位小数');
            $points=number_format((float)$raw,2,'.','');
        } else {
            $months=filter_var($input['duration_months']??null,FILTER_VALIDATE_INT);
            if(!$months||$months<1||$months>120) throw new RedeemCardException('invalid','会员期限须为1至120个月');
            $model=MembershipPlan::where(['tenant_id'=>$tenant,'id'=>(int)($input['plan_id']??0),'status'=>1])->whereNull('delete_time')->findOrEmpty();
            if($model->isEmpty()) throw new RedeemCardException('invalid','会员套餐不存在或已停用');
            $p=$model->toArray();
            $plan=['id'=>(int)$p['id'],'name'=>(string)$p['name'],'duration_months'=>$months,
                'monthly_bonus_points'=>(string)($p['monthly_bonus_points']??'0.00'),'features'=>(array)($p['features']??[]),
                'app_codes'=>MembershipPlanApp::where(['tenant_id'=>$tenant,'plan_id'=>(int)$p['id']])->column('app_code')];
        }
        return Db::transaction(function()use($tenant,$admin,$name,$remark,$type,$quantity,$points,$plan,$months,$expiry){
            $now=time();$batch=['tenant_id'=>$tenant,'batch_no'=>'RC'.date('YmdHis').strtoupper(bin2hex(random_bytes(5))),
                'name'=>$name,'type'=>$type,'quantity'=>$quantity,'points'=>$points,'plan_id'=>(int)($plan['id']??0),
                'plan_name'=>(string)($plan['name']??''),'duration_months'=>$months,'reward_snapshot'=>json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'expire_time'=>$expiry,'status'=>'enabled','remark'=>$remark,'admin_id'=>$admin,'create_time'=>$now,'update_time'=>$now];
            $batch['id']=(int)Db::name('redeem_card_batch')->insertGetId($batch);$codes=[];
            for($i=0;$i<$quantity;$i++){
                $code=RedeemCardPolicy::newCode();$mask=RedeemCardPolicy::mask($code);
                $id=Db::name('redeem_card')->insertGetId(['tenant_id'=>$tenant,'batch_id'=>$batch['id'],'code_hash'=>RedeemCardPolicy::hash($code),'code_mask'=>$mask,'status'=>'unused','create_time'=>$now,'update_time'=>$now]);
                $codes[]=['id'=>(int)$id,'code'=>$code,'code_mask'=>$mask];
            }
            unset($batch['reward_snapshot']);return ['batch'=>$batch,'codes'=>$codes];
        });
    }

    public static function redeem(int $tenant, int $user, string $code): array
    {
        if($tenant<=0||$user<=0) throw new RedeemCardException('unauthenticated','请先登录后兑换');
        $normalized=RedeemCardPolicy::normalizeCode($code);
        if(!preg_match('/^[A-F0-9]{32}$/D',$normalized)) throw new RedeemCardException('invalid','卡密无效');
        return Db::transaction(function()use($tenant,$user,$normalized){
            $card=Db::name('redeem_card')->where(['tenant_id'=>$tenant,'code_hash'=>RedeemCardPolicy::hash($normalized)])->lock(true)->find();
            if(!$card) throw new RedeemCardException('invalid','卡密无效');
            // Disabled-batch writes lock only the batch; never acquire a card afterwards.
            $batch=Db::name('redeem_card_batch')->where(['tenant_id'=>$tenant,'id'=>$card['batch_id']])->lock(true)->find();
            if(!$batch) throw new RedeemCardException('invalid','卡密无效');
            $account=User::where(['tenant_id'=>$tenant,'id'=>$user])->lock(true)->findOrEmpty();
            if($account->isEmpty()) throw new RedeemCardException('unauthenticated','当前账号不可兑换');
            if($card['status']==='used'){
                if((int)$card['redeemed_user_id']!==$user) throw new RedeemCardException('used','卡密已被使用');
                $record=Db::name('redeem_card_record')->where(['tenant_id'=>$tenant,'card_id'=>$card['id'],'user_id'=>$user])->find();
                if(!$record) throw new RedeemCardException('unavailable','兑换记录异常，请联系客服');
                return self::formatRecord($record)+['replayed'=>true];
            }
            $state=RedeemCardPolicy::state($card,$batch,time());
            if($state==='disabled') throw new RedeemCardException('disabled','卡密已停用');
            if($state==='expired') throw new RedeemCardException('expired','卡密已过期');
            $source='redeem_card_'.$tenant.'_'.$card['id'];$member=[];
            if($batch['type']==='points'){
                UserPointService::grantRedeemCard($tenant,$user,(float)$batch['points'],$source,['card_id'=>(int)$card['id'],'batch_id'=>(int)$batch['id']]);
            }else{
                $snapshot=json_decode((string)$batch['reward_snapshot'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($snapshot)||empty($snapshot['id'])) throw new RedeemCardException('unavailable','会员卡权益异常，请联系客服');
                $member=MembershipService::assignPlan($tenant,$user,(int)$batch['plan_id'],['snapshot'=>$snapshot,'duration_months'=>(int)$batch['duration_months'],'source_sn'=>$source,'redeem_card'=>true]);
            }
            $now=time();$record=['tenant_id'=>$tenant,'card_id'=>(int)$card['id'],'batch_id'=>(int)$batch['id'],'user_id'=>$user,
                'code_mask'=>$card['code_mask'],'type'=>$batch['type'],'points'=>$batch['points'],'plan_id'=>(int)$batch['plan_id'],'plan_name'=>$batch['plan_name'],
                'duration_months'=>(int)$batch['duration_months'],'member_expire_time'=>(int)($member['expire_time']??0),'source_sn'=>$source,'redeem_time'=>$now];
            $record['id']=(int)Db::name('redeem_card_record')->insertGetId($record);
            Db::name('redeem_card')->where(['tenant_id'=>$tenant,'id'=>$card['id']])->update(['status'=>'used','redeemed_user_id'=>$user,'redeem_time'=>$now,'update_time'=>$now]);
            return self::formatRecord($record)+['replayed'=>false];
        });
    }

    /** Count invalid guesses too. Increment in its own committed transaction before redemption. */
    public static function throttle(int $tenant, int $user, string $ip): void
    {
        if($tenant<=0||$user<=0) throw new RedeemCardException('unauthenticated','请先登录后兑换');
        $limited=false;$now=time();$window=(int)(floor($now/60)*60);
        foreach(['user:'.$tenant.':'.$user=>10,'ip:'.$tenant.':'.$ip=>30] as $subject=>$max){
            $count=Db::transaction(function()use($subject,$now,$window){
                $key=hash('sha256',$subject);
                Db::name('redeem_card_attempt')->duplicate(['subject_hash'=>$key])->insert(['subject_hash'=>$key,'window_start'=>$window,'attempts'=>0,'update_time'=>$now]);
                $row=Db::name('redeem_card_attempt')->where('subject_hash',$key)->lock(true)->find();
                $count=(int)$row['window_start']===$window?(int)$row['attempts']+1:1;
                Db::name('redeem_card_attempt')->where('subject_hash',$key)->update(['window_start'=>$window,'attempts'=>$count,'update_time'=>$now]);return $count;
            });
            if($count>$max)$limited=true;
        }
        if($limited) throw new RedeemCardException('rate_limited','操作过于频繁，请稍后再试');
    }

    public static function disable(int $tenant, array $params): void
    {
        self::assertTenant($tenant);
        Db::transaction(function()use($tenant,$params){
            if((int)($params['batch_id']??0)>0){
                $where=['tenant_id'=>$tenant,'id'=>(int)$params['batch_id']];
                $batch=Db::name('redeem_card_batch')->where($where)->lock(true)->find();
                if(!$batch) throw new RedeemCardException('invalid','批次不存在');
                Db::name('redeem_card_batch')->where($where)->update(['status'=>'disabled','update_time'=>time()]);return;
            }
            $where=['tenant_id'=>$tenant,'id'=>(int)($params['id']??0)];
            $card=Db::name('redeem_card')->where($where)->lock(true)->find();
            if(!$card) throw new RedeemCardException('invalid','卡密不存在');
            if($card['status']==='used') throw new RedeemCardException('used','已兑换卡密不能停用');
            Db::name('redeem_card')->where($where)->update(['status'=>'disabled','update_time'=>time()]);
        });
    }

    public static function listing(int $tenant, string $kind, array $params, int $privateUser = 0): array
    {
        self::assertTenant($tenant);$page=max(1,(int)($params['page_no']??1));$size=max(1,min(100,(int)($params['page_size']??15)));
        if($kind==='batches'){
            $q=Db::name('redeem_card_batch')->alias('b')->where('b.tenant_id',$tenant);
            if(trim((string)($params['name']??''))!=='')$q->whereLike('b.name','%'.trim($params['name']).'%');
            if(in_array($params['status']??'',['enabled','disabled'],true))$q->where('b.status',$params['status']);
            $fields='b.id,b.batch_no,b.name,b.type,b.quantity,b.points,b.plan_id,b.plan_name,b.duration_months,b.expire_time,b.status,b.remark,b.create_time,b.admin_id';
        }elseif($kind==='cards'){
            $q=Db::name('redeem_card')->alias('c')->join('redeem_card_batch b','b.id=c.batch_id AND b.tenant_id=c.tenant_id')->where('c.tenant_id',$tenant);
            if((int)($params['batch_id']??0)>0)$q->where('c.batch_id',(int)$params['batch_id']);
            $status=(string)($params['status']??'');$now=time();
            if($status==='used')$q->where('c.status','used');
            if($status==='disabled')$q->where('c.status','<>','used')->where(function($q){$q->where('c.status','disabled')->whereOr('b.status','disabled');});
            if(in_array($status,['expired','unused'],true)){
                $q->where(['c.status'=>'unused','b.status'=>'enabled']);
                if($status==='expired')$q->where('b.expire_time','>',0)->where('b.expire_time','<=',$now);
                else $q->where(function($q)use($now){$q->where('b.expire_time',0)->whereOr('b.expire_time','>',$now);});
            }
            $fields='c.id,c.batch_id,c.code_mask,c.status,c.redeemed_user_id,c.redeem_time,c.create_time,b.name,b.batch_no,b.type,b.points,b.plan_id,b.plan_name,b.duration_months,b.expire_time,b.status AS batch_status';
        }else{
            $q=Db::name('redeem_card_record')->alias('r')->where('r.tenant_id',$tenant);
            if($privateUser>0)$q->where('r.user_id',$privateUser);
            elseif((int)($params['user_id']??0)>0)$q->where('r.user_id',(int)$params['user_id']);
            if((int)($params['batch_id']??0)>0)$q->where('r.batch_id',(int)$params['batch_id']);
            $fields='r.*';
        }
        if(in_array($params['type']??'',['points','membership'],true))$q->where(($kind==='records'?'r':'b').'.type',$params['type']);
        $count=(clone $q)->count();$rows=$q->field($fields)->order(($kind==='batches'?'b':($kind==='cards'?'c':'r')).'.id','desc')->limit(($page-1)*$size,$size)->select()->toArray();
        foreach($rows as &$row){
            if($kind==='cards') {
                $row['status']=RedeemCardPolicy::state($row,['status'=>$row['batch_status'],'expire_time'=>$row['expire_time']],time());
                $row['user_id']=(int)$row['redeemed_user_id'];
                $row['nickname']=$row['user_id']>0?(string)User::where(['tenant_id'=>$tenant,'id'=>$row['user_id']])->value('nickname',''):'';
            }
            if($kind==='records'){$row=self::formatRecord($row);if(!$privateUser)$row['nickname']=(string)User::where(['tenant_id'=>$tenant,'id'=>$row['user_id']])->value('nickname','');}
        }
        return ['lists'=>$rows,'count'=>$count,'page_no'=>$page,'page_size'=>$size];
    }

    private static function formatRecord(array $row): array
    {
        $row['record_id']=(int)$row['id'];$row['member_expire_time_text']=(int)$row['member_expire_time']>0?date('Y-m-d H:i:s',(int)$row['member_expire_time']):'';return $row;
    }
    private static function assertTenant(int $tenant): void { if($tenant<=0) throw new RedeemCardException('unauthenticated','租户身份无效'); }
}
