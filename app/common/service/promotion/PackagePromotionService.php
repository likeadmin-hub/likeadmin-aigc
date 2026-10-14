<?php
declare(strict_types=1);
namespace app\common\service\promotion;
use think\facade\Db;

final class PackagePromotionService
{
    public static function validateConfiguredPrice(int $tenant, string $from, int $package): void
    {
        $p = self::get($tenant, $from, $package);
        if (empty($p['enabled'])) return;
        foreach (json_decode($p['discount_prices'], true, 512, JSON_THROW_ON_ERROR) as $cycle => $discount) {
            $catalog = PackagePricingService::catalog($tenant, $from, $package, $cycle, false);
            PackagePromotionPolicy::validate($catalog['regular_cents'], (int)$discount, (int)$p['hours'], (int)$p['rule_new'], (int)$p['rule_all']);
        }
    }
    public static function get(int $tenant,string $from,int $package): array
    {
        return Db::name('tenant_package_promotion')->where(['tenant_id'=>$tenant,'biz_type'=>$from,'package_id'=>$package])->find()?:[];
    }
    public static function detail(int $tenant,string $from,int $package): array
    {
        $p=self::get($tenant,$from,$package);$prices=json_decode($p['discount_prices']??'{}',true)?:[];
        foreach($prices as &$price)$price=PackagePromotionPolicy::amount((int)$price);unset($price);
        return ['enabled'=>(int)($p['enabled']??0),'rule_new'=>(int)($p['rule_new']??0),'rule_all'=>(int)($p['rule_all']??0),'hours'=>(int)($p['hours']??48),'discount_prices'=>$prices,'all_start'=>(int)($p['all_start']??0),'all_end'=>(int)($p['all_end']??0),'version'=>(int)($p['version']??0)];
    }
    public static function save(int $tenant,string $from,int $package,array $input): array
    {
        return Db::transaction(function()use($tenant,$from,$package,$input){
            $base=PackagePricingService::catalog($tenant,$from,$package,'package',false,true);
            $old=self::get($tenant,$from,$package);if(isset($input['version'])&&(int)$input['version']!==(int)($old['version']??0))throw new \RuntimeException('优惠配置已被修改，请重新加载后再保存');$enabled=!empty($input['enabled'])?1:0;$new=!empty($input['rule_new'])?1:0;$all=!empty($input['rule_all'])?1:0;$hours=(int)($input['hours']??48);
            $prices=[];$raw=(array)($input['discount_prices']??[]);
            foreach($raw as $cycle=>$price){
                if(!in_array($cycle,['package','monthly','yearly'],true))throw new \InvalidArgumentException('优惠购买周期不正确');
                $entry=PackagePricingService::catalog($tenant,$from,$package,$cycle,false);
                if($entry['cycle']!==$cycle)throw new \InvalidArgumentException('请按套餐实际购买周期配置优惠');
                $prices[$cycle]=PackagePromotionPolicy::cents($price);
                if($enabled)PackagePromotionPolicy::validate($entry['regular_cents'],$prices[$cycle],$hours,$new,$all);
            }
            if($enabled&&!$prices)throw new \InvalidArgumentException('请配置优惠价格');
            if($hours<1||$hours>8760)throw new \InvalidArgumentException('优惠时长请填写1到8760小时');
            $window=$all&&$enabled?PackagePromotionPolicy::window($old,time(),$hours,!empty($input['restart'])):['all_start'=>(int)($old['all_start']??0),'all_end'=>(int)($old['all_end']??0)];
            $data=['tenant_id'=>$tenant,'biz_type'=>$from,'package_id'=>$package,'enabled'=>$enabled,'rule_new'=>$new,'rule_all'=>$all,'hours'=>$hours,'discount_prices'=>json_encode($prices,JSON_THROW_ON_ERROR),'version'=>(int)($old['version']??0)+1,'update_time'=>time()]+$window;
            if($old)Db::name('tenant_package_promotion')->where('id',$old['id'])->update($data);else Db::name('tenant_package_promotion')->insert($data+['create_time'=>time()]);
            return self::detail($tenant,$from,$package);
        });
    }
}
