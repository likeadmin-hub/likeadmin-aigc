<?php
declare(strict_types=1);
namespace app\common\service\brand;
use think\facade\Db;
use think\facade\Log;
final class BrandRefundService
{
    public static function request(int $tenant,int $user,int $id,string $note): void
    {
        Db::transaction(function()use($tenant,$user,$id,$note) {
            $order=BrandWorkflowService::owned($tenant,$user,$id,true);
            if ($order['workflow_state']==='refund_review') return;
            if ((int)$order['flow_version']!==2 || !in_array('request_refund',BrandOrderPolicy::actions($order,'user'),true) || (int)$order['pay_status']!==1 || (int)$order['refund_status']!==0) throw new BrandUserException('当前订单不能申请开通前退款，请联系服务商');
            $note=self::note($note);
            $job=Db::name('tenant_brand_job')->where('order_id',$id)->lock(true)->find();
            $scope=$job?'platform':'tenant';
            if ($job) Db::name('tenant_brand_job')->where('id',$job['id'])->update(['status'=>$job['status']==='running'?'stopping':'paused','update_time'=>time()]);
            Db::name('tenant_brand_order')->where('id',$id)->update(['workflow_state'=>'refund_review','review_note'=>$note,'update_time'=>time()]);
            BrandWorkflowService::event($order,'user',$user,'request_refund','refund_review',$note,['previous_state'=>$order['workflow_state'],'scope'=>$scope]);
        });
    }
    public static function reviewContext(int $id): array
    {
        $payload=Db::name('tenant_brand_event')->where(['order_id'=>$id,'action'=>'request_refund'])->order('id','desc')->value('payload');
        return json_decode((string)$payload,true)?:[];
    }
    public static function review(string $role,int $tenant,int $actor,int $id,string $action,string $note): void
    {
        Db::transaction(function()use($role,$tenant,$actor,$id,$action,$note) {
            $order=$role==='tenant'?BrandWorkflowService::seller($tenant,$id,true):BrandPlatformService::order($id,true);
            if (!in_array($role,['tenant','platform'],true) || !in_array($action,['approve','deny'],true)) throw new BrandUserException('退款操作无效');
            if ($order['workflow_state']!=='refund_review' && !($role==='platform' && $action==='approve' && $order['workflow_state']==='open_failed')) throw new BrandUserException('订单状态已变化，请刷新后处理');
            $context=self::reviewContext($id); $note=self::note($note);
            $job=Db::name('tenant_brand_job')->where('order_id',$id)->lock(true)->find();
            if ($role==='tenant' && (($context['scope']??'tenant')!=='tenant' || $job)) throw new BrandUserException('开通执行结果需要平台核对后处理退款');
            if ($job && ($job['status']==='stopping' || $job['status']==='running') && (int)$job['lease_until']>time()) throw new BrandUserException('请等待开通执行器停止后，再核对退款');
            if ($action==='deny') {
                $state=(string)($context['previous_state']??'tenant_review');
                if ($job) {
                    $state=$state==='open_failed'?'open_failed':'opening';
                    Db::name('tenant_brand_job')->where('id',$job['id'])->update(['status'=>$state==='open_failed'?'failed':'queued','lease_token'=>'','lease_until'=>0,'update_time'=>time()]);
                }
                Db::name('tenant_brand_order')->where('id',$id)->update(['workflow_state'=>$state,'review_note'=>$note,'update_time'=>time()]);
                BrandWorkflowService::event($order,$role,$actor,'deny_refund',$state,$note); return;
            }
            if ($job) {
                $child=(int)$order['child_tenant_id'];
                if ($child>0 && $order['order_kind']==='new') {
                    $target=Db::name('tenant')->where(['id'=>$child,'source_tenant_id'=>$order['tenant_id']])->whereNull('delete_time')->lock(true)->find();
                    if (!$target || (int)$target['disable']!==1) throw new BrandUserException('部分开通租户状态需要平台核对，请勿直接退款');
                }
                if (Db::name('tenant_contract_record')->where('source_order_sn',$order['order_sn'])->count() || Db::name('tenant_app_order')->where('source_sn',$order['order_sn'])->count()) throw new BrandUserException('订单已有交付权益，需要平台人工核对');
                Db::name('tenant_brand_job')->where('id',$job['id'])->update(['status'=>'stopped','lease_token'=>'','lease_until'=>0,'update_time'=>time()]);
            }
            // Platform has fenced and reconciled any partial provision; seller never reaches this path.
            BrandWorkflowService::refundLocked($order,'退款审核通过：'.$note,$role==='platform');
            BrandWorkflowService::event($order,$role,$actor,'approve_refund','refunding',$note);
        });
    }
    public static function process(int $id): void
    {
        $candidate=Db::name('tenant_brand_refund')->where('id',$id)->find(); if (!$candidate) return;
        $claim=Db::transaction(function()use($candidate) {
            $order=Db::name(BrandPaymentService::table($candidate['order_from']))->where('id',$candidate['order_id'])->lock(true)->find();
            $refund=Db::name('tenant_brand_refund')->where('id',$candidate['id'])->lock(true)->find();
            if (!$order || !$refund || in_array($refund['status'],['success','failed'],true) || (int)$refund['lease_until']>time() || (int)$refund['next_check_time']>time()) return null;
            $payment=Db::name('tenant_brand_payment')->where('id',$refund['payment_id'])->find();
            if (!$payment || (int)$payment['amount_cents']!==(int)$refund['amount_cents']) throw new \RuntimeException('Refund payment snapshot mismatch');
            if ($payment['disposition']==='primary' && $candidate['order_from']===TenantBrandService::FROM_ORDER && $order['workflow_state']!=='refunding' && $order['workflow_state']!=='refund_failed') throw new \RuntimeException('Primary refund must be approved before execution');
            $refund['lease_token']=bin2hex(random_bytes(24)); $refund['lease_until']=time()+120;
            Db::name('tenant_brand_refund')->where('id',$refund['id'])->update(['lease_token'=>$refund['lease_token'],'lease_until'=>$refund['lease_until'],'update_time'=>time()]);
            return [$refund,$payment];
        });
        if (!$claim) return;
        [$refund,$payment]=$claim;
        try {
            $gateway=app()->make(BrandChannelGateway::class);
            if (!$gateway instanceof BrandRefundGateway) throw new \RuntimeException('Refund gateway contract missing');
            if ((int)$refund['attempts']>0) {
                $result=$gateway->queryRefund($payment,$refund);
                if ($result!==null) { self::settle($refund,$payment,$result); return; }
            }
            // Persist uncertainty BEFORE crossing the external side effect boundary.
            $updated=Db::name('tenant_brand_refund')->where(['id'=>$id,'lease_token'=>$refund['lease_token']])->update(['status'=>'unknown','attempts'=>(int)$refund['attempts']+1,'next_check_time'=>time()+60,'update_time'=>time()]);
            if (!$updated) return;
            $gateway->refund($payment,$refund);
            self::settle($refund,$payment,['status'=>'pending']);
        } catch (BrandUserException $e) {
            self::settle($refund,$payment,['status'=>'failed','error'=>$e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('OEM refund '.$id.': '.$e->getMessage());
            self::settle($refund,$payment,['status'=>'unknown','error'=>$e->getMessage()]);
        }
    }
    private static function settle(array $lease,array $payment,array $result): void
    {
        Db::transaction(function()use($lease,$payment,$result) {
            $order=Db::name(BrandPaymentService::table($lease['order_from']))->where('id',$lease['order_id'])->lock(true)->find();
            $refund=Db::name('tenant_brand_refund')->where('id',$lease['id'])->lock(true)->find();
            if (!$refund || !hash_equals((string)$refund['lease_token'],(string)$lease['lease_token']) || (int)$refund['lease_until']<=time() || $refund['status']==='success') return;
            $state=$result['status']??'unknown';
            if (in_array($state,['success','failed'],true) && isset($result['refund_sn'])) {
                if (!hash_equals($refund['refund_sn'],(string)$result['refund_sn']) || !hash_equals($payment['transaction_id'],(string)($result['transaction_id']??''))
                    || !hash_equals($payment['merchant_id'],(string)($result['merchant_id']??'')) || ($result['currency']??'')!==$payment['currency']
                    || !is_int($result['amount_cents']??null) || $result['amount_cents']!==(int)$refund['amount_cents']) throw new BrandUserException('退款结果与原交易不一致，请由平台核对');
            } elseif ($state==='success') throw new BrandUserException('退款结果缺少交易凭据，请由平台核对');
            $state=match($state) {'success'=>'success','failed'=>'failed','pending'=>'processing',default=>'unknown'};
            Db::name('tenant_brand_refund')->where('id',$refund['id'])->update(['status'=>$state,'lease_token'=>'','lease_until'=>0,'next_check_time'=>time()+60,'last_error'=>mb_substr((string)($result['error']??''),0,500),
                'channel_refund_id'=>(string)($result['channel_refund_id']??$refund['channel_refund_id']),'confirmed_time'=>$state==='success'?time():0,'update_time'=>time()]);
            if ($lease['order_from']!==TenantBrandService::FROM_ORDER || $payment['disposition']==='duplicate') return;
            if ($state==='success') {
                BrandQuotaService::release($order,'原路退款已确认，释放贴牌额度');
                Db::name('tenant_brand_order')->where('id',$order['id'])->update(['workflow_state'=>'refunded','refund_status'=>1,'update_time'=>time()]);
                BrandWorkflowService::event($order,'system',0,'refund_success','refunded','原路退款已确认');
            } elseif ($state==='failed') {
                Db::name('tenant_brand_order')->where('id',$order['id'])->update(['workflow_state'=>'refund_failed','update_time'=>time()]);
                BrandWorkflowService::event($order,'system',0,'refund_failed','refund_failed','退款需要平台人工核对');
            }
        });
    }
    public static function retry(int $id,int $actor,string $note): void
    {
        Db::transaction(function()use($id,$actor,$note) {
            $order=BrandPlatformService::order($id,true); $note=self::note($note);
            $refunds=Db::name('tenant_brand_refund')->where(['order_from'=>TenantBrandService::FROM_ORDER,'order_id'=>$id,'status'=>'failed'])->lock(true)->select()->toArray();
            if (!$refunds) throw new BrandUserException('当前没有需要重新核对的退款');
            foreach ($refunds as $refund) Db::name('tenant_brand_refund')->where('id',$refund['id'])->update(['status'=>'unknown','next_check_time'=>0,'last_error'=>'','update_time'=>time()]);
            if ($order['workflow_state']==='refund_failed') Db::name('tenant_brand_order')->where('id',$id)->update(['workflow_state'=>'refunding','update_time'=>time()]);
            BrandWorkflowService::event($order,'platform',$actor,'retry_refund',$order['workflow_state'],'使用原退款单号重新核对：'.$note);
        });
    }
    public static function retryTransaction(int $refundId,int $actor,string $note): void
    {
        $candidate=Db::name('tenant_brand_refund')->where('id',$refundId)->find();
        if (!$candidate) throw new BrandUserException('退款记录不存在');
        Db::transaction(function()use($candidate,$actor,$note) {
            $note=self::note($note);
            $order=Db::name(BrandPaymentService::table($candidate['order_from']))->where(['id'=>$candidate['order_id'],'tenant_id'=>$candidate['tenant_id'],'flow_version'=>2])->lock(true)->find();
            $refund=Db::name('tenant_brand_refund')->where('id',$candidate['id'])->lock(true)->find();
            if (!$order || !$refund || $refund['status']!=='failed' || (int)$refund['lease_until']>time()) throw new BrandUserException('当前退款无需重试，请刷新核对结果');
            Db::name('tenant_brand_refund')->where('id',$refund['id'])->update(['status'=>'unknown','next_check_time'=>0,'last_error'=>'','update_time'=>time()]);
            if ($candidate['order_from']===TenantBrandService::FROM_ORDER) {
                if ($order['workflow_state']==='refund_failed') Db::name('tenant_brand_order')->where('id',$order['id'])->update(['workflow_state'=>'refunding','update_time'=>time()]);
                BrandWorkflowService::event($order,'platform',$actor,'retry_refund',$order['workflow_state'],'使用原退款单号重新核对：'.$note);
            }
            Log::info('OEM transaction refund retry: platform_admin='.$actor.' refund_id='.$refund['id'].' note='.$note);
        });
    }
    private static function note(string $note): string {
        $note=trim($note); if($note==='' || mb_strlen($note)>500) throw new BrandUserException('请填写退款处理说明，最多500字'); return $note;
    }
}
