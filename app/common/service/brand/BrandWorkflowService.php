<?php
declare(strict_types=1);
namespace app\common\service\brand;
use app\common\model\brand\TenantBrandOrder;
use app\common\model\brand\TenantBrandQuotaOrder;
use app\platformapi\logic\tenant\TenantAdminLogic;
use app\common\service\tenant\TenantUrlService;
use RuntimeException;
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
            }
            $brand=self::brand($params,$prior);
            $account=trim((string)($params['account']??$prior['admin_account']??''));
            $password=(string)($params['password']??'');
            if (!$target && (!preg_match('/^[A-Za-z0-9_@.\-]{3,64}$/D',$account) || strlen($password)<8 || strlen($password)>128)) throw new RuntimeException('管理员账号需为3至64位，密码需为8至128位');
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
            self::event($data,'user',$userId,'create','awaiting_payment','提交贴牌申请');
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

    public static function event(array $order,string $actor,int $actorId,string $action,string $state,string $note=''): void
    {
        Db::name('tenant_brand_event')->insert(['order_id'=>$order['id'],'tenant_id'=>$order['tenant_id'],'actor_type'=>$actor,'actor_id'=>$actorId,'action'=>$action,'state'=>$state,'note'=>mb_substr($note,0,500),'create_time'=>time()]);
    }

    private static function brand(array $params,?array $prior): array
    {
        $old=json_decode((string)($prior['brand_snapshot']??''),true)?:[];
        $name=trim((string)($params['tenant_name']??$old['name']??$prior['child_tenant_name']??''));
        $contact=trim((string)($params['contact']??$old['contact']??''));
        $logo=trim((string)($params['logo']??$old['logo']??''));
        if ($name==='' || mb_strlen($name)>32) throw new RuntimeException('品牌名称应为1至32个字');
        if ($contact==='' || mb_strlen($contact)>80) throw new RuntimeException('请填写有效联系方式');
        if ($logo!=='' && (!preg_match('~^(uploads|oem-assets)/[a-zA-Z0-9/_\-.]+\.(png|jpe?g|webp)$~iD',$logo) || str_contains($logo,'..'))) throw new RuntimeException('请上传持久保存的品牌Logo');
        $domain=TenantUrlService::normalizeHost((string)($params['domain_alias']??$old['domain']??''));
        return ['name'=>$name,'contact'=>$contact,'logo'=>$logo,'domain'=>$domain];
    }
}
