<?php
declare(strict_types=1);
namespace app\common\service\brand;
use think\facade\Db;
use think\facade\Config;
use think\facade\Log;
use app\common\service\tenant\TenantUrlService;
use app\common\service\tenant\TenantDomainAliasService;
final class BrandDomainService
{
    public static function normalize(string $domain): string {
        $domain=strtolower(trim($domain));
        if (strlen($domain)>253 || filter_var($domain,FILTER_VALIDATE_IP) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$domain)
            || preg_match('/\.(?:invalid|localhost|local|test|internal)$/D',$domain) || TenantUrlService::isPlatformHost($domain)) throw new BrandUserException('请填写独立公网域名，不含协议、端口或路径');
        return $domain;
    }
    public static function target(): string {
        $hosts=TenantUrlService::platformHosts(); return (string)($hosts[0]??'');
    }
    public static function prepare(int $tenant,int $user,int $id,string $domain): array {
        $domain=self::normalize($domain);
        return Db::transaction(function()use($tenant,$user,$id,$domain) {
            $order=BrandWorkflowService::owned($tenant,$user,$id,true); self::delivered($order);
            $row=Db::name('tenant_brand_domain')->where('order_id',$id)->lock(true)->find();
            if ($row && $row['domain']===$domain) return self::present($row);
            if ($row && $row['status']==='verified') throw new BrandUserException('已验证域名如需更换，请联系平台处理');
            TenantDomainAliasService::validateAliases([['domain'=>$domain,'status'=>0,'is_primary'=>0]],(int)$order['child_tenant_id']);
            $data=['order_id'=>$id,'tenant_id'=>$tenant,'domain'=>$domain,'challenge'=>bin2hex(random_bytes(32)),'status'=>'pending','last_check_time'=>0,'last_error'=>'','create_time'=>time(),'update_time'=>time()];
            if ($row) { Db::name('tenant_brand_domain')->where('id',$row['id'])->update($data); $data['id']=$row['id']; }
            else $data['id']=Db::name('tenant_brand_domain')->insertGetId($data);
            BrandWorkflowService::event($order,'user',$user,'domain_prepare','delivered','已提交域名验证'); return self::present($data);
        });
    }
    public static function verify(int $tenant,int $user,int $id): array {
        $snapshot=Db::transaction(function()use($tenant,$user,$id) {
            $order=BrandWorkflowService::owned($tenant,$user,$id,true); self::delivered($order);
            $row=Db::name('tenant_brand_domain')->where('order_id',$id)->lock(true)->find();
            if (!$row) throw new BrandUserException('请先提交需要验证的域名');
            if ($row['status']==='verified') return $row;
            if ((int)$row['last_check_time']>time()-30) throw new BrandUserException('域名刚刚检查过，请稍等30秒后重试');
            Db::name('tenant_brand_domain')->where('id',$row['id'])->update(['last_check_time'=>time(),'update_time'=>time()]); return $row;
        });
        if ($snapshot['status']==='verified') return self::present($snapshot);
        $target=self::target();
        if ($target==='') throw new BrandUserException('本站域名配置尚未完成，请联系平台协助验证');
        try { app()->make(BrandDomainProbe::class)->verify($snapshot['domain'],$snapshot['challenge'],$target); }
        catch (\Throwable $e) {
            Log::error('OEM domain '.$id.': '.$e->getMessage());
            Db::name('tenant_brand_domain')->where(['id'=>$snapshot['id'],'challenge'=>$snapshot['challenge']])->update(['last_error'=>mb_substr($e->getMessage(),0,500),'update_time'=>time()]);
            throw $e instanceof BrandUserException?$e:new BrandUserException('域名检查暂未完成，请确认解析及 HTTPS 后重试');
        }
        return Db::transaction(function()use($tenant,$user,$id,$snapshot) {
            $order=BrandWorkflowService::owned($tenant,$user,$id,true); self::delivered($order);
            $row=Db::name('tenant_brand_domain')->where('id',$snapshot['id'])->lock(true)->find();
            if (!$row || !hash_equals($row['challenge'],$snapshot['challenge']) || $row['domain']!==$snapshot['domain']) throw new BrandUserException('域名资料已变更，请重新验证');
            $child=(int)$order['child_tenant_id'];
            $target=Db::name('tenant')->where(['id'=>$child,'source_tenant_id'=>$tenant])->whereNull('delete_time')->lock(true)->find();
            if (!$target) throw new BrandUserException('交付租户需要平台核对');
            $aliases=TenantDomainAliasService::getAliasesByTenantId($child);
            $aliases=array_values(array_filter($aliases,fn($a)=>$a['domain']!==$row['domain'] && !str_ends_with($a['domain'],'.invalid')));
            foreach($aliases as &$a) $a['is_primary']=0; unset($a);
            $aliases[]=['domain'=>$row['domain'],'status'=>1,'is_primary'=>1];
            TenantDomainAliasService::syncTenantAliases($child,$aliases,'',true);
            Db::name('tenant')->where('id',$child)->update(['domain_alias'=>$row['domain'],'domain_alias_enable'=>0,'access_mode'=>'alias','update_time'=>time()]);
            Db::name('tenant_brand_domain')->where('id',$row['id'])->update(['status'=>'verified','verified_time'=>time(),'last_error'=>'','update_time'=>time()]);
            $row['status']='verified'; $row['verified_time']=time();
            BrandWorkflowService::event($order,'system',0,'domain_verified','delivered','域名归属、解析和 HTTPS 验证通过'); return self::present($row);
        });
    }
    public static function present(array $row): array {
        return ['domain'=>$row['domain'],'challenge'=>$row['challenge'],'status'=>$row['status'],'verified_time'=>(int)($row['verified_time']??0),
            'txt_name'=>'_oem-brand.'.$row['domain'],'txt_value'=>'oem='.$row['challenge'],'dns_target'=>self::target(),'last_check_time'=>(int)($row['last_check_time']??0)];
    }
    private static function delivered(array $order): void {
        if ((int)$order['flow_version']!==2 || $order['workflow_state']!=='delivered' || (int)$order['child_tenant_id']<1) throw new BrandUserException('请等待贴牌租户交付后再验证域名');
    }
}
