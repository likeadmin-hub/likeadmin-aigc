<?php
declare(strict_types=1);
namespace app\common\service\brand;
use app\common\model\brand\TenantBrandOrder;
use app\common\model\brand\TenantBrandQuotaOrder;
use app\platformapi\logic\tenant\TenantAdminLogic;
use app\common\service\tenant\TenantUrlService;
use app\common\service\brand\BrandUserException as RuntimeException;
use think\facade\Db;

final class BrandWorkflowService
{
    public static function owned(int $tenantId,int $userId,int $id,bool $lock=false): array
    {
        if ($tenantId<1 || $userId<1 || $id<1) throw new RuntimeException('贴牌订单不存在');
        $row=Db::name('tenant_brand_order')->where(['tenant_id'=>$tenantId,'user_id'=>$userId,'id'=>$id])->whereNull('delete_time')->lock($lock)->find();
        if (!$row) throw new RuntimeException('贴牌订单不存在');
        return $row;
    }

    public static function create(int $tenantId,int $userId,int $terminal,array $params): array
    {
        BrandCatalogService::assertSeller($tenantId);
        if ($userId<1) throw new RuntimeException('请先登录');
        return Db::transaction(function() use($tenantId,$userId,$terminal,$params) {
            $packageId=(int)($params['package_id']??0);
            Db::name('tenant_package')->where('id',$packageId)->lock(true)->find();
            $snapshot=BrandCatalogService::snapshot($packageId);
            $price=Db::name('tenant_brand_package_price')->where(['tenant_id'=>$tenantId,'package_id'=>$packageId,'status'=>1])->lock(true)->find();
            if (!$price || BrandOrderPolicy::cents((string)$price['sale_price'])<=0) throw new RuntimeException('套餐未上架或尚未配置销售价');
            $target=(int)($params['target_tenant_id']??0);
            $prior=null;
            if ($target>0) {
                $prior=Db::name('tenant_brand_order')->where(['tenant_id'=>$tenantId,'user_id'=>$userId,'child_tenant_id'=>$target,'open_status'=>1,'pay_status'=>1])->whereNull('delete_time')->order('id','desc')->find();
                $child=Db::name('tenant')->where(['id'=>$target,'source_tenant_id'=>$tenantId])->whereNull('delete_time')->find();
                if (!$prior || !$child) throw new RuntimeException('只能为您已开通的贴牌租户续费');
                if ((int)$child['disable']===1 && ((int)$child['contract_expire_time']===0 || (int)$child['contract_expire_time']>time())) throw new RuntimeException('该贴牌租户已暂停服务，请先联系平台核对，再办理续费');
            }
            $brand=self::brand($params,$prior,$tenantId,$userId);
            $account=trim((string)($params['account']??$prior['admin_account']??''));
            $password=(string)($params['password']??'');
            if (!$target && (!preg_match('/^[A-Za-z0-9_@.\-]{3,32}$/D',$account) || strlen($password)<8 || strlen($password)>128)) throw new RuntimeException('管理员账号需为3至32位，密码需为8至128位');
            $sn=generate_sn(TenantBrandOrder::class,'order_sn');
            $now=time();
            $data=['tenant_id'=>$tenantId,'user_id'=>$userId,'order_sn'=>$sn,'order_terminal'=>$terminal,'package_id'=>$packageId,'package_name'=>$snapshot['name'],'quantity'=>1,
                'unit_price'=>BrandOrderPolicy::amount(BrandOrderPolicy::cents((string)$price['sale_price'])),'order_amount'=>BrandOrderPolicy::amount(BrandOrderPolicy::cents((string)$price['sale_price'])),
                'target_tenant_id'=>$target,'child_tenant_name'=>$brand['name'],'child_domain_alias'=>$brand['domain'],'admin_account'=>$account,
                'admin_password_hash'=>$target?'':TenantAdminLogic::createPassword($password),'flow_version'=>2,'order_kind'=>$target?'renew':'new','workflow_state'=>'awaiting_payment',
                'package_snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'brand_snapshot'=>json_encode($brand,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'reserve_status'=>0,'reserve_expire_time'=>$now+1800,'create_time'=>$now,'update_time'=>$now];
            $id=Db::name('tenant_brand_order')->insertGetId($data);
            $data['id']=$id;
            BrandQuotaService::reserve($data);
            self::event($data,'user',$userId,'create','awaiting_payment','提交贴牌申请',['brand'=>$brand]);
            return ['order_id'=>$id,'order_sn'=>$sn,'from'=>TenantBrandService::FROM_ORDER,'order_amount'=>$data['order_amount'],'expires_at'=>$now+1800];
        });
    }

    public static function createQuota(int $tenantId,int $adminId,int $terminal,int $packageId,int $quantity,int $brandId=0): array
    {
        if ($brandId===0) BrandCatalogService::assertSeller($tenantId);
        if ($quantity<1 || $quantity>10000) throw new RuntimeException('采购数量应为1至10000');
        return Db::transaction(function() use($tenantId,$adminId,$terminal,$packageId,$quantity,$brandId) {
            if ($brandId>0) {
                $customer=Db::name('tenant_brand_order')->where(['tenant_id'=>$tenantId,'id'=>$brandId,'flow_version'=>2])->whereNull('delete_time')->lock(true)->find();
                if (!$customer || (int)$customer['pay_status']!==1 || !in_array($customer['workflow_state'],['tenant_review','awaiting_quota','platform_info'],true)) throw new RuntimeException('该订单当前无需采购额度');
                if ((int)$customer['reserve_status']===1) throw new RuntimeException('该订单已预占额度，无需重复采购');
                $pending=Db::name('tenant_brand_quota_order')->where(['tenant_id'=>$tenantId,'brand_order_id'=>$brandId,'flow_version'=>2,'pay_status'=>0,'closed_time'=>0])
                    ->where('create_time','>',time()-1800)->whereNull('delete_time')->lock(true)->order('id','desc')->find();
                if ($pending) return ['order_id'=>(int)$pending['id'],'order_sn'=>$pending['order_sn'],'from'=>TenantBrandService::FROM_QUOTA,'order_amount'=>(string)$pending['order_amount'],'expires_at'=>(int)$pending['create_time']+1800];
                $snapshot=json_decode($customer['package_snapshot'],true,512,JSON_THROW_ON_ERROR);
                $packageId=(int)$customer['package_id']; $quantity=1;
            } else $snapshot=BrandCatalogService::snapshot($packageId);
            $unit=BrandOrderPolicy::cents((string)$snapshot['quota_price']);
            $sn=generate_sn(TenantBrandQuotaOrder::class,'order_sn'); $now=time();
            $data=['tenant_id'=>$tenantId,'admin_id'=>$adminId,'order_sn'=>$sn,'order_terminal'=>$terminal,'package_id'=>$packageId,'package_name'=>$snapshot['name'],'quantity'=>$quantity,
                'unit_price'=>BrandOrderPolicy::amount($unit),'order_amount'=>BrandOrderPolicy::amount($unit*$quantity),'flow_version'=>2,'brand_order_id'=>$brandId,
                'package_snapshot'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'create_time'=>$now,'update_time'=>$now];
            $id=Db::name('tenant_brand_quota_order')->insertGetId($data);
            return ['order_id'=>$id,'order_sn'=>$sn,'from'=>TenantBrandService::FROM_QUOTA,'order_amount'=>$data['order_amount'],'expires_at'=>$now+1800];
        });
    }

    public static function cancel(int $tenantId,int $userId,int $id): void
    {
        Db::transaction(function() use($tenantId,$userId,$id) {
            $order=self::owned($tenantId,$userId,$id,true);
            if ((int)$order['pay_status']===1) throw new RuntimeException('已付款订单请申请退款');
            if ((int)$order['flow_version']!==2) throw new RuntimeException('请通过原订单入口取消');
            self::closeLocked($order,'客户取消订单');
        });
    }

    public static function seller(int $tenantId,int $id,bool $lock=false): array
    {
        if ($tenantId<1 || $id<1) throw new RuntimeException('贴牌订单不存在');
        $row=Db::name('tenant_brand_order')->where(['tenant_id'=>$tenantId,'id'=>$id,'flow_version'=>2])->whereNull('delete_time')->lock($lock)->find();
        if (!$row) throw new RuntimeException('贴牌订单不存在或需要通过原流程处理');
        return $row;
    }

    public static function detail(array $order,string $role): array
    {
        $row=TenantBrandService::formatBrandOrder($order,$role);
        $row['events']=Db::name('tenant_brand_event')->where('order_id',$order['id'])->field('id,actor_type,action,state,note,create_time')->order('id')->select()->toArray();
        $refunds=Db::name('tenant_brand_refund')->where(['order_from'=>TenantBrandService::FROM_ORDER,'order_id'=>$order['id']])->field('refund_sn,amount_cents,status,reason,confirmed_time,create_time')->order('id')->select()->toArray();
        foreach ($refunds as &$refund) { $refund['amount']=BrandOrderPolicy::amount((int)$refund['amount_cents']); unset($refund['amount_cents']); }
        unset($refund);
        $row['refunds']=$refunds;
        $domain=Db::name('tenant_brand_domain')->where('order_id',$order['id'])->field('domain,challenge,status,verified_time,last_check_time')->find();
        $row['domain']=$domain?BrandDomainService::present($domain):null;
        if ($order['workflow_state']==='refund_review') {
            $row['refund_review_scope']=BrandRefundService::reviewContext((int)$order['id'])['scope']??'tenant';
            if (($role==='tenant' && $row['refund_review_scope']!=='tenant') || ($role==='platform' && $row['refund_review_scope']!=='platform')) $row['next_actions']=[];
        }
        if ($role==='platform' && Db::name('tenant_brand_refund')->where(['order_from'=>TenantBrandService::FROM_ORDER,'order_id'=>$order['id'],'status'=>'failed'])->count()) $row['next_actions']=array_values(array_unique(array_merge($row['next_actions'],['retry_refund'])));
        $job=Db::name('tenant_brand_job')->where('order_id',$order['id'])->field('status,steps,attempts,finished_time')->find();
        if ($job) $job['steps']=json_decode((string)$job['steps'],true)?:[];
        $row['job']=$job;
        return $row;
    }

    public static function review(int $tenantId,int $adminId,int $id,string $action,string $note): void
    {
        Db::transaction(function()use($tenantId,$adminId,$id,$action,$note) {
            $order=self::seller($tenantId,$id,true);
            self::paid($order);
            if ($action==='approve' && $order['tenant_review']==='approved' && in_array($order['workflow_state'],['awaiting_quota','ready_submit','platform_review'],true)) return;
            if (!in_array($action,BrandOrderPolicy::actions($order,'tenant'),true) || !in_array($action,['approve','needs_info','reject'],true)) throw new RuntimeException('订单状态已变化，请刷新后再审核');
            $note=self::note($note,$action!=='approve');
            if ($action==='reject') {
                self::refundLocked($order,'租户审核拒绝：'.$note);
                Db::name('tenant_brand_order')->where('id',$id)->update(['tenant_review'=>'rejected','review_note'=>$note]);
                self::event($order,'tenant',$adminId,'reject','refunding',$note);
                return;
            }
            if ($action==='needs_info') {
                $state='tenant_info'; $review='needs_info';
            } else {
                $brand=json_decode((string)$order['brand_snapshot'],true)?:[];
                if (empty($brand['name']) || empty($brand['contact']) || ($order['order_kind']==='new' && (empty($order['admin_account']) || empty($order['admin_password_hash'])))) throw new RuntimeException('请先补齐品牌与管理员信息');
                $state=BrandQuotaService::reserve($order)?'ready_submit':'awaiting_quota'; $review='approved';
            }
            Db::name('tenant_brand_order')->where('id',$id)->update(['workflow_state'=>$state,'tenant_review'=>$review,'review_note'=>$note,'update_time'=>time()]);
            self::event($order,'tenant',$adminId,$action,$state,$note);
        });
    }

    public static function supplement(int $tenantId,int $userId,int $id,array $params): void
    {
        Db::transaction(function()use($tenantId,$userId,$id,$params) {
            $order=self::owned($tenantId,$userId,$id,true);
            self::paid($order);
            if (!in_array('supplement',BrandOrderPolicy::actions($order,'user'),true)) throw new RuntimeException('当前订单无需补充资料');
            $brand=self::brand($params,$order,$tenantId,$userId);
            $changes=['brand_snapshot'=>json_encode($brand,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'child_tenant_name'=>$brand['name'],'child_domain_alias'=>$brand['domain'],
                'tenant_review'=>'pending','workflow_state'=>'tenant_review','update_time'=>time()];
            if (!empty($params['account']) || !empty($params['password'])) {
                $account=trim((string)($params['account']??$order['admin_account'])); $password=(string)($params['password']??'');
                if ($order['order_kind']!=='new' || !preg_match('/^[A-Za-z0-9_@.\-]{3,32}$/D',$account) || strlen($password)<8 || strlen($password)>128) throw new RuntimeException('请填写有效管理员账号及8至128位密码');
                $changes['admin_account']=$account; $changes['admin_password_hash']=TenantAdminLogic::createPassword($password);
            }
            Db::name('tenant_brand_order')->where('id',$id)->update($changes);
            self::event($order,'user',$userId,'supplement','tenant_review','客户已补充品牌资料',['before'=>json_decode($order['brand_snapshot'],true),'after'=>$brand]);
        });
    }

    public static function reserve(int $tenantId,int $adminId,int $id): void
    {
        Db::transaction(function()use($tenantId,$adminId,$id) {
            $order=self::seller($tenantId,$id,true); self::paid($order);
            if ((int)$order['reserve_status']===1 && $order['tenant_review']==='approved') return;
            if ($order['workflow_state']!=='awaiting_quota' || $order['tenant_review']!=='approved') throw new RuntimeException('请先审核申请，再预占额度');
            if (!BrandQuotaService::reserve($order)) throw new RuntimeException('开通额度不足，请先采购额度');
            Db::name('tenant_brand_order')->where('id',$id)->update(['workflow_state'=>'ready_submit','update_time'=>time()]);
            self::event($order,'tenant',$adminId,'reserve','ready_submit','已预占开通额度');
        });
    }

    /** The procurement receipt locks this customer before taking its bucket lock. */
    public static function reserveLinked(array $customer): void
    {
        if ((int)$customer['pay_status']!==1 || $customer['tenant_review']!=='approved' || $customer['workflow_state']!=='awaiting_quota' || (int)$customer['refund_status']!==0) return;
        if (BrandQuotaService::reserve($customer)) {
            Db::name('tenant_brand_order')->where('id',$customer['id'])->update(['workflow_state'=>'ready_submit','update_time'=>time()]);
            self::event($customer,'system',0,'reserve','ready_submit','按单采购到账，已预占额度');
        }
    }

    public static function submit(int $tenantId,int $adminId,int $id): void
    {
        Db::transaction(function()use($tenantId,$adminId,$id) {
            $order=self::seller($tenantId,$id,true); self::paid($order);
            if ($order['workflow_state']==='platform_review') return;
            if (!in_array('submit',BrandOrderPolicy::actions($order,'tenant'),true) || $order['tenant_review']!=='approved' || (int)$order['reserve_status']!==1) throw new RuntimeException('请先完成租户审核并预占开通额度，再提交平台');
            Db::name('tenant_brand_order')->where('id',$id)->update(['workflow_state'=>'platform_review','platform_review'=>'pending','review_note'=>'','update_time'=>time()]);
            self::event($order,'tenant',$adminId,'submit','platform_review','已提交平台确认开通');
        });
    }

    public static function refundLocked(array $order,string $reason,bool $platformReconciled=false): void
    {
        if ((int)$order['open_status']===1 || $order['workflow_state']==='delivered') throw new RuntimeException('已交付订单请联系平台处理');
        $job=Db::name('tenant_brand_job')->where('order_id',$order['id'])->lock(true)->find();
        if ($job && ($job['status']==='running' || (int)$job['lease_until']>time() || ((int)$order['child_tenant_id']>0 && !$platformReconciled))) throw new RuntimeException('开通执行结果需要由平台先核对，再处理退款');
        if ($job) Db::name('tenant_brand_job')->where('id',$job['id'])->update(['status'=>'stopped','lease_token'=>'','lease_until'=>0,'update_time'=>time()]);
        $payments=Db::name('tenant_brand_payment')->where(['order_from'=>TenantBrandService::FROM_ORDER,'order_id'=>$order['id']])->select()->toArray();
        if (!$payments) throw new RuntimeException('原付款记录需要核对，请联系平台');
        foreach ($payments as $payment) BrandPaymentService::queueRefund((int)$payment['id'],$payment,$reason);
        Db::name('tenant_brand_order')->where('id',$order['id'])->update(['workflow_state'=>'refunding','refund_status'=>2,'update_time'=>time()]);
    }

    private static function paid(array $order): void
    {
        if ((int)$order['flow_version']!==2 || (int)$order['pay_status']!==1 || (int)$order['closed_time']>0 || (int)$order['refund_status']!==0) throw new RuntimeException('订单尚未付款或正在退款，请刷新订单状态');
    }

    private static function note(string $note,bool $required): string
    {
        $note=trim($note);
        if (($required && $note==='') || mb_strlen($note)>500) throw new RuntimeException('请填写处理说明，最多500字');
        return $note;
    }

    public static function closeLocked(array $order,string $reason): void
    {
        if ((int)$order['closed_time']>0) return;
        BrandQuotaService::release($order,$reason);
        Db::name('tenant_brand_order')->where('id',$order['id'])->update(['closed_time'=>time(),'workflow_state'=>'closed','update_time'=>time()]);
        self::event($order,'system',0,'close','closed',$reason);
    }

    public static function expire(): int
    {
        $count=0;
        $ids=Db::name('tenant_brand_order')->where(['flow_version'=>2,'pay_status'=>0,'closed_time'=>0])->where('reserve_expire_time','<=',time())->limit(100)->column('id');
        foreach ($ids as $id) $count+=Db::transaction(function()use($id) {
            $order=Db::name('tenant_brand_order')->where('id',$id)->lock(true)->find();
            if (!$order || (int)$order['pay_status']!==0 || (int)$order['closed_time']>0 || (int)$order['reserve_expire_time']>time()) return 0;
            self::closeLocked($order,'未支付订单超时关闭'); return 1;
        });
        return $count;
    }

    public static function event(array $order,string $actor,int $actorId,string $action,string $state,string $note='',array $payload=[]): void
    {
        Db::name('tenant_brand_event')->insert(['order_id'=>$order['id'],'tenant_id'=>$order['tenant_id'],'actor_type'=>$actor,'actor_id'=>$actorId,'action'=>$action,'state'=>$state,'note'=>mb_substr($note,0,500),'payload'=>$payload?json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,'create_time'=>time()]);
    }

    private static function brand(array $params,?array $prior,int $tenantId,int $userId): array
    {
        $old=json_decode((string)($prior['brand_snapshot']??''),true)?:[];
        $name=trim((string)($params['tenant_name']??$old['name']??$prior['child_tenant_name']??''));
        $contact=trim((string)($params['contact']??$old['contact']??''));
        $logo=trim((string)($params['logo']??$old['logo']??''));
        if ($name==='' || mb_strlen($name)>32) throw new RuntimeException('品牌名称应为1至32个字');
        if ($contact==='' || mb_strlen($contact)>80) throw new RuntimeException('请填写有效联系方式');
        if ($logo!=='' && (!preg_match('~^(uploads|oem-assets)/[a-zA-Z0-9/_\-.]+\.(png|jpe?g|webp)$~iD',$logo) || str_contains($logo,'..'))) throw new RuntimeException('请上传持久保存的品牌Logo');
        $domain=trim((string)($params['domain_alias']??$old['domain']??''));
        if ($domain!=='') $domain=BrandDomainService::normalize($domain);
        $data=['name'=>$name,'contact'=>$contact,'logo'=>$logo,'domain'=>$domain];
        if ($logo!=='') {
            if (!array_key_exists('logo',$params) && !empty($old['logo_asset'])) $data=array_merge($data,array_intersect_key($old,array_flip(['logo_id','logo_url','logo_asset'])));
            else $data=array_merge($data,BrandLogoService::snapshot($tenantId,$userId,$logo,(int)($params['logo_id']??0)));
        }
        return $data;
    }
}
