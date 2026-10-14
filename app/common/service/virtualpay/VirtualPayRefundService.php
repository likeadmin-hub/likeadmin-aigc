<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
use think\facade\Db;
use app\common\enum\AdminTerminalEnum;
use app\common\enum\user\AccountLogEnum;
use app\common\model\user\User;
use app\common\model\user\UserAccountLog;
use app\common\model\membership\UserMembership;
use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\logic\AccountLogLogic;
use app\common\service\promotion\PackagePricingService as Price;
use app\common\service\promotion\PackagePromotionPolicy as Money;
final class VirtualPayRefundService
{
    private VirtualPayService $pay;
    public function __construct(?VirtualPayService $pay=null){$this->pay=$pay??new VirtualPayService();}
    public static function owned(int $tenant,int $id,bool $lock=false): array
    {
        $q=Db::name('virtual_pay_intent')->where(['tenant_id'=>$tenant,'id'=>$id]);if($lock)$q->lock(true);$i=$q->find();if(!$i)throw new \RuntimeException('支付记录不存在');return $i;
    }
    private static function scoped(array $i,callable $fn): mixed
    {
        $source=request()->source;$tenant=request()->tenantId;try{request()->source=AdminTerminalEnum::USER;request()->tenantId=(int)$i['tenant_id'];return $fn();}finally{request()->source=$source;request()->tenantId=$tenant;}
    }
    /** Conservative proof; uncertainty returns manual instead of taking rights from an active user. */
    public function eligibility(array $i, bool $lock = true): array
    {
        if($i['delivery_status']!=='granted')return ['safe'=>true,'reason'=>'该交易尚未发放权益'];
        return self::scoped($i,function()use($i,$lock){
            $scope=['tenant_id'=>$i['tenant_id'],'user_id'=>$i['user_id']];
            if(!AiAppTask::where($scope)->whereNotIn('status',['success','partial_success','failed','canceled','cancelled'])->field('id')->findOrEmpty()->isEmpty())return ['safe'=>false,'reason'=>'用户有正在处理的任务，请人工核对，系统不会中断任务'];
            if(!AiConsumptionLog::where($scope)->whereNotIn('billing_status',['none','settled','refunded'])->field('id')->findOrEmpty()->isEmpty())return ['safe'=>false,'reason'=>'用户存在冻结或待结算算力，请人工核对'];
            $s=Price::snapshot((int)$i['tenant_id'],$i['biz_type'],(int)$i['order_id']);$before=json_decode($i['rights_before']??'',true)?:[];$rights=$s?(json_decode($s['rights'],true)?:[]):[];
            if(!$s||!array_key_exists('user_money',$before)||!array_key_exists('log_id',$before))return ['safe'=>false,'reason'=>'权益来源暂无法确认，请人工核对'];
            $user=User::where(['tenant_id'=>$i['tenant_id'],'id'=>$i['user_id']])->lock($lock)->findOrEmpty();if($user->isEmpty())return ['safe'=>false,'reason'=>'用户归属已变化，请人工核对'];
            $other=UserAccountLog::where($scope)->where('id','>',(int)$before['log_id'])->where('source_sn','<>',$s['order_sn'])->field('id')->findOrEmpty();
            if(!$other->isEmpty())return ['safe'=>false,'reason'=>'到账后已有其他算力变动，请人工核对权益来源'];
            $points=Money::cents($rights[$i['biz_type']==='recharge'?'recharge_points':'bonus_points']??'0');
            if(Money::cents($user->user_money)!==Money::cents($before['user_money'])+$points)return ['safe'=>false,'reason'=>'套餐算力已消费或无法归属，请人工核对'];
            if($i['biz_type']==='recharge'&&Money::cents($user->total_recharge_amount)!==Money::cents($before['total_recharge_amount'])+$points)return ['safe'=>false,'reason'=>'累计充值记录有变化，请人工核对'];
            if($i['biz_type']==='membership'){
                $old=$before['membership']??[];$member=UserMembership::where($scope)->lock($lock)->findOrEmpty();
                if($member->isEmpty()||$member['source_order_sn']!==$s['order_sn']||((int)($old['expire_time']??0)>(int)$i['paid_at']))return ['safe'=>false,'reason'=>'会员存在续期叠加或其他授权，请人工核对'];
                if(!AiAppTask::where($scope)->where('create_time','>=',(int)$i['paid_at'])->field('id')->findOrEmpty()->isEmpty())return ['safe'=>false,'reason'=>'会员期间已使用创作功能，请人工核对'];
            }
            return ['safe'=>true,'reason'=>'已核对权益未使用','points_cents'=>$points,'before'=>$before,'snapshot'=>$s];
        });
    }
    public function request(int $tenant,int $actor,int $id): array
    {
        $i=self::owned($tenant,$id);if($i['status']!=='paid')throw new \RuntimeException('请先核对到账状态后再处理退款');
        $o=$this->pay->query($i);if((int)$o['order_type']===7||(int)$i['order_type']===7)throw new \RuntimeException('Apple 支付不支持开发者主动退款，请引导用户在 App Store 申请退款');
        return Db::transaction(function()use($tenant,$actor,$id,$i){
            VirtualPayService::order($tenant,(int)$i['user_id'],$i['biz_type'],(int)$i['order_id'],true);
            Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$tenant,'biz_type'=>$i['biz_type'],'order_id'=>$i['order_id']])->lock(true)->find();
            $i=self::owned($tenant,$id,true);if($i['refund_status']==='success')return $i;
            $proof=$this->eligibility($i);
            if(!$proof['safe']){Db::name('virtual_pay_intent')->where('id',$id)->update(['refund_status'=>'manual','last_error'=>$proof['reason'],'updated_at'=>time()]);self::audit($i,'refund_manual',$actor,$proof['reason']);return self::owned($tenant,$id);}
            $sn=$i['refund_order_id']?:'R'.bin2hex(random_bytes(15));
            Db::name('virtual_pay_intent')->where('id',$id)->update(['refund_source'=>'merchant','refund_order_id'=>$sn,'refund_cents'=>$i['amount_cents'],'refund_status'=>'queued','updated_at'=>time()]);
            Db::name('virtual_pay_work')->extra('IGNORE')->insert(['intent_id'=>$id,'kind'=>'refund','next_at'=>time(),'updated_at'=>time()]);Db::name('virtual_pay_work')->where(['intent_id'=>$id,'kind'=>'refund'])->update(['status'=>'pending','next_at'=>time(),'updated_at'=>time()]);
            self::audit($i,'refund_requested',$actor,'已创建全额原路退款核对任务');return self::owned($tenant,$id);
        });
    }
    public static function audit(array $i,string $event,int $actor,string $message): void
    {
        $payload=json_encode(['actor'=>$actor,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        Db::name('virtual_pay_event')->insert(['tenant_id'=>$i['tenant_id'],'app_id'=>$i['app_id'],'intent_id'=>$i['id'],'event'=>$event,'event_key'=>hash('sha256',$event.'|'.$actor.'|'.bin2hex(random_bytes(16))),'payload'=>$payload,'status'=>'done','message'=>$message,'created_at'=>time(),'updated_at'=>time()]);
    }
    public function process(int $id): bool
    {
        VirtualPayPolicy::assertRuntime();$i=Db::name('virtual_pay_intent')->where('id',$id)->find();if(!$i)throw new \RuntimeException('退款支付记录不存在');if($i['refund_status']==='success')return true;
        if((int)$i['order_type']===7||($i['refund_source']??'none')==='channel'){return $this->checkExternalRefund($i);}
        $db=(string)(Db::query('SELECT DATABASE() AS db')[0]['db']??'');$lock='vp-refund:'.substr(hash('sha256',$db.'|'.$id),0,48);
        if((int)(Db::query('SELECT GET_LOCK(?,0) AS n',[$lock])[0]['n']??0)!==1)return false;
        try{
            $i=self::owned((int)$i['tenant_id'],$id);if(!in_array($i['refund_status'],['queued','required','processing','unknown','submitting'],true))return false;
            if($i['refund_order_id']===''){$i=$this->request((int)$i['tenant_id'],0,$id);if($i['refund_status']==='manual')return false;}
            $v=VirtualPayConfigService::version((int)$i['tenant_id'],(int)$i['config_version_id']);
            if((int)$i['refund_requested_at']>0){
                $r=$this->pay->gateway()->request($v,'/xpay/query_order',['openid'=>$i['openid'],'env'=>(int)$i['env'],'order_id'=>$i['refund_order_id']]);$refund=$r['order']??[];VirtualPayPolicy::assertRuntime();
                if(($refund['order_id']??'')!==$i['refund_order_id']||(int)($refund['env_type']??0)!==(int)$i['env']+1||!in_array((int)($refund['order_type']??-1),[1,8],true)||(int)($refund['refund_fee']??-1)!==(int)$i['refund_cents'])throw new \RuntimeException('原退款请求身份或金额核对失败，暂不能再次退款');
                if((int)$refund['status']===8){$this->complete($i,$refund);return true;}
                if((int)$refund['status']!==7){Db::name('virtual_pay_intent')->where('id',$id)->update(['refund_status'=>'processing','updated_at'=>time()]);return false;}
            }
            $eligibility=$this->eligibility($i);if (!$eligibility['safe']) { Db::name('virtual_pay_intent')->where('id',$id)->update(['refund_status'=>'manual','last_error'=>$eligibility['reason'],'updated_at'=>time()]);return false; }
            $original=$this->pay->query($i);if((int)$original['order_type']===7)throw new \RuntimeException('该交易为 Apple 支付，请等待用户渠道退款');
            if((int)($original['left_fee']??-1)!==(int)$i['refund_cents'])throw new \RuntimeException('剩余可退金额与全额退款不一致，请人工核对');
            VirtualPayPolicy::assertRuntime();
            Db::name('virtual_pay_intent')->where('id',$id)->update(['refund_requested_at'=>time(),'refund_status'=>'submitting','updated_at'=>time()]);
            $this->pay->gateway()->request($v,'/xpay/refund_order',['openid'=>$i['openid'],'order_id'=>$i['out_trade_no'],'refund_order_id'=>$i['refund_order_id'],'left_fee'=>(int)$original['left_fee'],'refund_fee'=>(int)$i['refund_cents'],'biz_meta'=>$i['attach'],'refund_reason'=>'3','req_from'=>'1','env'=>(int)$i['env']]);
            VirtualPayPolicy::assertRuntime();Db::name('virtual_pay_intent')->where('id',$id)->update(['refund_status'=>'processing','updated_at'=>time()]);return false;
        }catch(\Throwable $e){if(!(function_exists('aigc_runtime_generation_changed')&&\aigc_runtime_generation_changed()))Db::name('virtual_pay_intent')->where('id',$id)->update(['refund_status'=>'unknown','last_error'=>VirtualPayPolicy::error($e),'updated_at'=>time()]);throw $e;}
        finally{try{Db::query('SELECT RELEASE_LOCK(?)',[$lock]);}catch(\Throwable){}}
    }
    private function checkExternalRefund(array $i): bool
    {
        $o=$this->pay->query($i);VirtualPayPolicy::assertRuntime();
        if((int)$o['status']===8&&(int)($o['left_fee']??-1)===0){$this->complete($i,$o);return true;}
        if((int)$o['status']===5){Db::name('virtual_pay_intent')->where('id',$i['id'])->update(['refund_status'=>'processing','updated_at'=>time()]);return false;}
        return false;
    }
    public function complete(array $i,array $proof): void
    {
        VirtualPayPolicy::assertRuntime();self::scoped($i,function()use($i,$proof){Db::transaction(function()use($i,$proof){
            $order=VirtualPayService::order((int)$i['tenant_id'],(int)$i['user_id'],$i['biz_type'],(int)$i['order_id'],true);
            $s=Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$i['tenant_id'],'biz_type'=>$i['biz_type'],'order_id'=>$i['order_id']])->lock(true)->find();$i=self::owned((int)$i['tenant_id'],(int)$i['id'],true);
            if($i['refund_status']==='success')return;
            $safe=$this->eligibility($i);$recover='no_grant';
            if($i['delivery_status']==='granted'){
                if(!$safe['safe'])$recover='manual';
                else{
                    $user=User::where(['tenant_id'=>$i['tenant_id'],'id'=>$i['user_id']])->lock(true)->findOrEmpty();$points=(int)$safe['points_cents'];
                    if($points>0){$user->user_money=Money::amount(Money::cents($user->user_money)-$points);if($i['biz_type']==='recharge')$user->total_recharge_amount=Money::amount(Money::cents($user->total_recharge_amount)-$points);$user->save();
                        if(AccountLogLogic::add((int)$i['user_id'],AccountLogEnum::UM_DEC_RECHARGE_REFUND,AccountLogEnum::DEC,(float)Money::amount($points),(string)$s['order_sn'],'虚拟支付全额退款回收未使用权益',['intent_id'=>$i['id']])===false)throw new \RuntimeException('退款权益流水记录失败');}
                    if($i['biz_type']==='membership'){
                        $m=UserMembership::where(['tenant_id'=>$i['tenant_id'],'user_id'=>$i['user_id']])->lock(true)->findOrEmpty();$old=$safe['before']['membership']??[];
                        if($old){unset($old['id']);$m->save($old);}else $m->save(['status'=>0,'expire_time'=>time(),'app_codes'=>[],'features'=>[],'update_time'=>time()]);
                    }
                    $recover='recovered';
                }
            }
            $tx='refund:'.($i['refund_order_id']?:$i['wx_order_id']);Db::name('virtual_pay_receipt')->extra('IGNORE')->insert(['tenant_id'=>$i['tenant_id'],'intent_id'=>$i['id'],'app_id'=>$i['app_id'],'env'=>$i['env'],'transaction_key'=>$tx,'amount_cents'=>$i['amount_cents'],'kind'=>'refund','normalized'=>json_encode($proof,JSON_THROW_ON_ERROR),'created_at'=>time()]);
            Db::name('virtual_pay_intent')->where('id',$i['id'])->update(['refund_status'=>'success','refund_cents'=>$i['amount_cents'],'recovery_status'=>$recover,'last_error'=>$recover==='manual'?$safe['reason']:'','updated_at'=>time()]);
            if($s)Db::name('tenant_package_price_snapshot')->where('id',$s['id'])->update(['status'=>'refunded','closed_at'=>time()]);
            // Never reset a consumed coupon. Only a reservation with no confirmed discounted payment can be released.
            if($s)Db::name('tenant_package_discount_claim')->where(['tenant_id'=>$i['tenant_id'],'user_id'=>$i['user_id'],'biz_type'=>$i['biz_type'],'package_id'=>$s['package_id'],'order_id'=>$i['order_id'],'status'=>'reserved'])->update(['status'=>'available','order_id'=>0,'reserved_until'=>0,'update_time'=>time()]);
            if($i['biz_type']==='recharge')$order->save(['refund_status'=>1,'refund_transaction_id'=>$tx]);
            \app\common\service\distribution\DistributionService::reverseOrder($i['biz_type'],(int)$i['tenant_id'],(string)$s['order_sn']);
            self::audit($i,'refund_confirmed',0,$recover==='manual'?'原路退款已完成，权益需人工核对':'原路退款已完成，未使用权益已核对');
        });});
    }
    public function event(int $tenant,string $app,array $m): array
    {
        $reference=(string)($m['pay_order_id']??$m['MchOrderId']??'');$i=Db::name('virtual_pay_intent')->where(['tenant_id'=>$tenant,'app_id'=>$app])->where(function($q)use($reference){$q->where('out_trade_no',$reference)->whereOr('wx_order_id',$reference);})->find();
        if(($m['Event']??'')==='xpay_subscribe_ios_refund_query_notify'){
            $p=$i?$this->eligibility($i,false):['safe'=>false,'reason'=>'订单尚无法自动归属，需要人工核对'];
            return ['result_code'=>$p['safe']?0:1,'result_info'=>$p['reason'],'evidence'=>json_encode(['intent_id'=>(int)($i['id']??0),'reason'=>$p['reason'],'delivery'=>$i['delivery_status']??'unknown'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
        }
        if(!$i)throw new \RuntimeException('退款通知未关联原支付订单');
        if ($i['refund_status']==='success') return ['ErrCode'=>0,'ErrMsg'=>''];
        if(isset($m['OpenId'])&&$m['OpenId']!==$i['openid'])throw new \RuntimeException('退款通知用户不匹配');
        $channel=$this->pay->query($i);
        if (!in_array((int)$channel['status'],[5,8],true) && ($i['refund_source']??'none')!=='merchant') throw new \RuntimeException('渠道退款状态尚未确认，请稍后核对');
        if(VirtualPayPolicy::integer($m['RefundFee']??null)!==(int)$i['amount_cents']){Db::name('virtual_pay_intent')->where('id',$i['id'])->update(['refund_status'=>'manual','last_error'=>'收到部分退款通知，请人工核对','updated_at'=>time()]);return ['ErrCode'=>0,'ErrMsg'=>''];}
        $sn=(string)($m['MchRefundId']??'');if($i['refund_order_id']!==''&&$sn!==''&&$sn!==$i['refund_order_id'])throw new \RuntimeException('退款通知编号与原请求不一致，请人工核对');
        Db::name('virtual_pay_intent')->where('id',$i['id'])->update(['refund_source'=>($i['refund_source']??'none')==='merchant'?'merchant':'channel','refund_status'=>'processing','refund_order_id'=>$i['refund_order_id']?:$sn,'refund_cents'=>$i['amount_cents'],'updated_at'=>time()]);
        Db::name('virtual_pay_work')->extra('IGNORE')->insert(['intent_id'=>$i['id'],'kind'=>'refund','next_at'=>time(),'updated_at'=>time()]);Db::name('virtual_pay_work')->where(['intent_id'=>$i['id'],'kind'=>'refund'])->update(['status'=>'pending','next_at'=>time(),'updated_at'=>time()]);
        return ['ErrCode'=>0,'ErrMsg'=>''];
    }
}
