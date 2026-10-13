<?php
declare(strict_types=1);
namespace app\common\service\brand;
use app\common\model\auth\TenantAdmin;
use app\common\model\dept\TenantDept;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppMenuService;
use app\common\service\app\DefaultAppService;
use app\common\service\billing\PackageProvisionService;
use app\common\service\tenant\TenantUrlService;
use app\platformapi\logic\tenant\TenantLogic;
use app\platformapi\logic\tenant\TenantSystemMenuLogic;
use app\platformapi\logic\setting\pay\PayConfigLogic;
use app\platformapi\logic\setting\pay\PayWayLogic;
use app\tenantapi\logic\article\ArticleLogic;
use app\tenantapi\logic\notice\NoticeLogic;
use app\tenantapi\logic\decorate\DecorateDataLogic;
use think\facade\Db;

/** Database steps are called with the order lock. External asset work runs in prepare. */
class BrandTenantProvisioner
{
    public function prepare(string $step,array $order): array
    {
        if ($step==='initialize_brand' && $order['order_kind']==='new') {
            $brand=json_decode($order['brand_snapshot'],true,512,JSON_THROW_ON_ERROR);
            if (!empty($brand['logo_asset'])) return ['logo'=>BrandLogoService::persist($order,$brand['logo_asset'])];
        }
        return [];
    }

    public function step(string $step,array $order,array $prepared=[]): array
    {
        return match($step) {
            'create_tenant'=>$this->createTenant($order), 'initialize_admin'=>$this->admin($order),
            'initialize_services'=>$this->services($order), 'initialize_brand'=>$this->brand($order,$prepared),
            'delivery_check'=>$this->check($order), 'deliver'=>$this->deliver($order),
            default=>throw new \RuntimeException('Unknown OEM provision step'),
        };
    }

    private function createTenant(array $order): array
    {
        $target=(int)$order['child_tenant_id'] ?: (int)$order['target_tenant_id'];
        if ($target>0) {
            $tenant=Db::name('tenant')->where(['id'=>$target,'source_tenant_id'=>$order['tenant_id']])->whereNull('delete_time')->find();
            if (!$tenant) throw new BrandUserException('目标租户资料需要核对');
        } else {
            $alias='oem-'.strtolower($order['order_sn']).'.invalid';
            $tenant=TenantLogic::add(['name'=>$order['child_tenant_name'],'avatar'=>'','tel'=>'','domain_alias'=>$alias,
                'domain_aliases'=>[['domain'=>$alias,'is_primary'=>1,'status'=>1]],'disable'=>1,'notes'=>'OEM订单 '.$order['order_sn'],
                'source_tenant_id'=>(int)$order['tenant_id'],'parent_tenant_id'=>(int)$order['tenant_id'],'tactics'=>0]);
            $target=(int)$tenant['id'];
            Db::name('tenant')->where('id',$target)->update(['disable'=>1,'access_mode'=>TenantUrlService::ACCESS_ID,'domain_alias_enable'=>1,
                'contract_start_time'=>0,'contract_expire_time'=>0,'contract_status'=>0,'oem_sales_enabled'=>0,'oem_flow_enabled'=>0]);
        }
        Db::name('tenant_brand_order')->where('id',$order['id'])->update(['child_tenant_id'=>$target,'update_time'=>time()]);
        return ['tenant_id'=>$target];
    }

    private function admin(array $order): array
    {
        $target=(int)$order['child_tenant_id'];
        if ($order['order_kind']==='renew') return ['tenant_id'=>$target,'preserved'=>true];
        $admin=Db::name('tenant_admin')->where(['tenant_id'=>$target,'root'=>1])->whereNull('delete_time')->find();
        if (!$admin) {
            $created=TenantAdmin::create(['tenant_id'=>$target,'account'=>$order['admin_account'],'name'=>'超级管理员','password'=>$order['admin_password_hash'],
                'avatar'=>'','disable'=>0,'root'=>1,'create_time'=>time()]);
            $admin=['id'=>(int)$created['id']];
            TenantDept::initialization($target,$admin['id']);
        }
        return ['admin_id'=>(int)$admin['id']];
    }

    private function services(array $order): array
    {
        $target=(int)$order['child_tenant_id'];
        if ($order['order_kind']==='renew') return ['tenant_id'=>$target,'preserved'=>true];
        // Provisioning must never trigger DDL/autocommit inside a business transaction.
        foreach (['recharge_order'=>['recharge_points','package_id','package_name'],'tenant_system_menu'=>['app_code','source','source_menu_key','is_core']] as $table=>$columns) {
            $full=Db::name($table)->getTable();
            foreach ($columns as $column) {
                if (!(int)(Db::query('SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$full,$column])[0]['n']??0)) throw new BrandUserException('请先完成系统升级数据库迁移，再重试开通');
            }
        }
        if (!Db::name('article')->where('tenant_id',$target)->count()) ArticleLogic::initialization($target);
        if (!Db::name('tenant_system_menu')->where('tenant_id',$target)->count()) TenantSystemMenuLogic::initialization($target);
        if (!Db::name('tenant_pay_config')->where('tenant_id',$target)->count()) PayConfigLogic::initialization($target);
        if (!Db::name('tenant_pay_way')->where('tenant_id',$target)->count()) PayWayLogic::initialization($target);
        // Template payment credentials must not be inherited by a new customer tenant.
        Db::name('tenant_pay_config')->where('tenant_id',$target)->update(['config'=>'{}']);
        Db::name('tenant_pay_way')->where('tenant_id',$target)->update(['status'=>0]);
        if (!Db::name('tenant_notice_setting')->where('tenant_id',$target)->count()) NoticeLogic::initialization($target);
        DecorateDataLogic::initialization($target);
        $tenant=Db::name('tenant')->where('id',$target)->find();
        DefaultAppService::syncTenantDefaults($target,(string)$tenant['sn'],false);
        PackageProvisionService::syncTenant($target,(string)$tenant['sn'],false);
        return ['tenant_id'=>$target];
    }

    private function brand(array $order,array $prepared): array
    {
        $target=(int)$order['child_tenant_id'];
        if ($order['order_kind']==='renew') return ['tenant_id'=>$target,'preserved'=>true];
        $brand=json_decode($order['brand_snapshot'],true,512,JSON_THROW_ON_ERROR);
        $logo=$prepared['logo']??[];
        $values=['tenant'=>['name'=>$brand['name']], 'website'=>['name'=>$brand['name'],'shop_name'=>$brand['name'],'pc_title'=>$brand['name'],'pc_login_title'=>$brand['name']],
            'customer_service'=>['phone'=>$brand['contact']]];
        if ($logo) {
            $values['tenant']+=['web_logo'=>$logo['uri'],'web_favicon'=>$logo['uri']];
            $values['website']+=['shop_logo'=>$logo['uri'],'pc_logo'=>$logo['uri'],'pc_ico'=>$logo['uri'],'h5_favicon'=>$logo['uri']];
            $values['oem_brand']=['logo_meta'=>json_encode($logo,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
        }
        // Copyright is intentionally not set here: the existing license policy remains authoritative.
        foreach ($values as $type=>$entries) foreach ($entries as $name=>$value) {
            $query=['tenant_id'=>$target,'type'=>$type,'name'=>$name];
            if (Db::name('tenant_config')->where($query)->find()) Db::name('tenant_config')->where($query)->update(['value'=>$value]);
            else Db::name('tenant_config')->insert($query+['value'=>$value]);
        }
        if ($logo && !Db::name('tenant_file')->where(['tenant_id'=>$target,'uri'=>$logo['uri']])->count()) {
            Db::name('tenant_file')->insert(['tenant_id'=>$target,'type'=>10,'name'=>'品牌Logo','uri'=>$logo['uri'],'storage_scope'=>$logo['storage_scope'],
                'storage_engine'=>$logo['storage_engine'],'storage_domain'=>$logo['storage_domain'],'source'=>0,'source_id'=>0,'create_time'=>time()]);
        }
        // The PC official site has its own tenant-owned content record; setting website basics alone
        // would leave its header showing the template brand.
        $oldSource=request()->source; $oldTenant=request()->tenantId;
        try {
            request()->source=\app\common\enum\AdminTerminalEnum::USER; request()->tenantId=$target;
            $site=\app\common\service\OfficialSiteService::get();
            $site['basic']['name']=$brand['name']; $site['basic']['title']=$brand['name'];
            $site['basic']['logo']=$logo['uri']??''; $site['basic']['favicon']=$logo['uri']??'';
            \app\common\service\OfficialSiteService::save($site);
        } finally { request()->source=$oldSource; request()->tenantId=$oldTenant; }
        return ['tenant_id'=>$target,'logo_uri'=>$logo['uri']??''];
    }

    private function check(array $order): array
    {
        $target=(int)$order['child_tenant_id'];
        $tenant=Db::name('tenant')->where('id',$target)->whereNull('delete_time')->find();
        if (!$tenant || !Db::name('tenant_admin')->where(['tenant_id'=>$target,'root'=>1,'disable'=>0])->whereNull('delete_time')->count()) throw new BrandUserException('租户或管理员尚未完整初始化');
        if ($order['order_kind']==='renew' && ((int)$tenant['source_tenant_id']!==(int)$order['tenant_id'] || ((int)$tenant['disable']===1 && ((int)$tenant['contract_expire_time']===0 || (int)$tenant['contract_expire_time']>time())))) throw new BrandUserException('续费租户归属或暂停状态需要平台核对');
        if ($order['order_kind']==='new') {
            $admin=Db::name('tenant_admin')->where(['tenant_id'=>$target,'root'=>1])->whereNull('delete_time')->find();
            if (!$admin || !hash_equals((string)$order['admin_account'],(string)$admin['account']) || !hash_equals((string)$order['admin_password_hash'],(string)$admin['password'])) throw new BrandUserException('管理员账号设置与申请资料不一致，请平台核对');
        }
        if ($order['order_kind']==='new' && ((int)$tenant['disable']!==1 || !Db::name('tenant_config')->where(['tenant_id'=>$target,'type'=>'website','name'=>'name'])->value('value'))) throw new BrandUserException('品牌配置或租户激活状态需要核对');
        $snapshot=json_decode($order['package_snapshot'],true,512,JSON_THROW_ON_ERROR);
        if ((int)($snapshot['duration_days']??0)<1 || empty($snapshot['app_plans'])) throw new BrandUserException('成交套餐权益需要核对');
        foreach ($snapshot['app_plans'] as $plan) {
            if ((int)$plan['duration_months']<1 || !Db::name('app')->where(['code'=>$plan['app_code'],'status'=>'installed'])->count()) throw new BrandUserException('成交应用暂不可用，请由平台恢复应用后重试');
        }
        return ['checked'=>true,'tenant_id'=>$target];
    }

    private function deliver(array $order): array
    {
        $this->check($order);
        $target=(int)$order['child_tenant_id']; $now=time();
        $tenant=Db::name('tenant')->where('id',$target)->lock(true)->find();
        $snapshot=json_decode($order['package_snapshot'],true,512,JSON_THROW_ON_ERROR);
        $oldExpire=(int)$tenant['contract_expire_time'];
        $base=$order['order_kind']==='renew'?max($oldExpire,$now):$now;
        $expire=$base+(int)$snapshot['duration_days']*86400;
        foreach ($snapshot['app_plans'] as $plan) $this->grant($order,$plan,$now);
        $record=Db::name('tenant_contract_record')->insertGetId(['tenant_id'=>$target,'package_id'=>$order['package_id'],'package_name'=>$snapshot['name'],
            'duration_days'=>$snapshot['duration_days'],'price'=>$order['order_amount'],'start_time'=>$base,'old_expire_time'=>$oldExpire,'expire_time'=>$expire,
            'operator_id'=>0,'source_order_sn'=>$order['order_sn'],'remark'=>'OEM '.($order['order_kind']==='renew'?'续费':'开通交付'),'create_time'=>$now]);
        BrandQuotaService::consume($order);
        Db::name('tenant')->where('id',$target)->update(['contract_package_id'=>$order['package_id'],'contract_package_name'=>$snapshot['name'],
            'contract_start_time'=>(int)$tenant['contract_start_time']?:$now,'contract_expire_time'=>$expire,'contract_renew_time'=>$now,'contract_status'=>1,'disable'=>0,'update_time'=>$now]);
        Db::name('tenant_brand_order')->where('id',$order['id'])->update(['workflow_state'=>'delivered','open_status'=>1,'open_time'=>$now,'delivered_time'=>$now,'service_expire_time'=>$expire,'open_error'=>'','update_time'=>$now]);
        return ['tenant_id'=>$target,'contract_record_id'=>$record,'expire_time'=>$expire];
    }

    private function grant(array $order,array $plan,int $now): void
    {
        $target=(int)$order['child_tenant_id']; $code=(string)$plan['app_code'];
        if (Db::name('tenant_app_order')->where(['tenant_id'=>$target,'app_code'=>$code,'source_sn'=>$order['order_sn']])->where('pay_status',1)->count()) return;
        $app=Db::name('app')->where(['code'=>$code,'status'=>'installed'])->find();
        if (!$app) throw new BrandUserException('成交应用暂不可用，请平台核对');
        $current=Db::name('tenant_app')->where(['tenant_id'=>$target,'app_code'=>$code])->lock(true)->find();
        $before=(int)($current['expire_time']??0);
        $base=max($before,$now); $after=strtotime('+'.(int)$plan['duration_months'].' months',$base);
        $data=['tenant_id'=>$target,'app_code'=>$code,'version'=>$app['current_version'],'buy_status'=>AppAccessService::BUY_PAID,
            'shelf_status'=>$current['shelf_status']??AppAccessService::SHELF_ON,'enable_status'=>AppAccessService::ENABLED,'expire_time'=>$after,'update_time'=>$now];
        if ($current) Db::name('tenant_app')->where('id',$current['id'])->update($data); else Db::name('tenant_app')->insert($data+['create_time'=>$now]);
        Db::name('tenant_app_order')->insert(['tenant_id'=>$target,'app_code'=>$code,'order_sn'=>'OE'.$order['id'].'-'.(int)$plan['id'],'plan_id'=>$plan['id'],
            'plan_name'=>$plan['name'],'duration_months'=>$plan['duration_months'],'order_type'=>$order['order_kind']==='renew'?'renew':'open','amount'=>'0.00','points_amount'=>'0.00',
            'before_expire_time'=>$before,'after_expire_time'=>$after,'operator_id'=>0,'pay_status'=>1,'pay_time'=>$now,'remark'=>'OEM成交应用权益','source_sn'=>$order['order_sn'],'create_time'=>$now]);
        AppMenuService::syncTenantMenus($target,$code);
    }
}
