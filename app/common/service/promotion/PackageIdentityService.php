<?php
declare(strict_types=1);
namespace app\common\service\promotion;
use think\facade\Db;
use app\common\model\user\User;
final class PackageIdentityService
{
    /** Invoked within the existing verified-phone merge transaction, before any identity moves. */
    public static function merge(int $tenant,int $shadow,int $target): void
    {
        $users=User::where('tenant_id',$tenant)->whereIn('id',[$shadow,$target])->order('id')->lock(true)->select();
        if(count($users)!==2)throw new \RuntimeException('账号归属已变化，请重新登录');
        if(Db::name('tenant_package_price_snapshot')->where('tenant_id',$tenant)->whereIn('user_id',[$shadow,$target])->where('status','pending')->lock(true)->find())throw new \RuntimeException('账号有待付款或待核对套餐，请先处理原订单后再绑定手机号');
        if(Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$tenant,'user_id'=>$shadow,'status'=>'paid'])->lock(true)->find())throw new \RuntimeException('当前微信账号已有套餐权益，请联系客服合并，避免影响原有权益');
        $claims=Db::name('tenant_package_discount_claim')->where(['tenant_id'=>$tenant,'user_id'=>$shadow])->lock(true)->select()->toArray();
        foreach($claims as $c){
            if($c['status']==='reserved')throw new \RuntimeException('账号优惠订单尚未核对完成，请稍后再绑定手机号');
            if($c['status']!=='used')continue;$scope=['tenant_id'=>$tenant,'user_id'=>$target,'biz_type'=>$c['biz_type'],'package_id'=>$c['package_id']];
            Db::name('tenant_package_discount_claim')->extra('IGNORE')->insert($scope+['status'=>'used','order_id'=>$c['order_id'],'consumed_at'=>$c['consumed_at'],'create_time'=>time(),'update_time'=>time()]);
            $existing=Db::name('tenant_package_discount_claim')->where($scope)->lock(true)->find();if($existing['status']==='reserved')throw new \RuntimeException('手机号账号优惠订单尚未完成，请先处理原订单');
            if($existing['status']!=='used')Db::name('tenant_package_discount_claim')->where('id',$existing['id'])->update(['status'=>'used','order_id'=>$c['order_id'],'consumed_at'=>$c['consumed_at'],'update_time'=>time()]);
        }
    }
}
