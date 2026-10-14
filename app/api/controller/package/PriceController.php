<?php
namespace app\api\controller\package;
use app\api\controller\BaseApiController;
use app\common\service\promotion\PackagePricingService;
class PriceController extends BaseApiController
{
    public function quote()
    {
        try {
            return $this->data(PackagePricingService::quote((int)$this->request->tenantId, $this->userId,
                (string)$this->request->get('from'), (int)$this->request->get('package_id'), (string)$this->request->get('cycle', 'package')));
        } catch (\Exception $e) { return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e)); }
    }
    public function cancel()
    {
        try { return $this->data((new \app\common\service\virtualpay\VirtualPayMaintenanceService())->cancel((int)$this->request->tenantId,$this->userId,(string)$this->request->post('from'),(int)$this->request->post('order_id'))); }
        catch (\Throwable $e) { return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e)); }
    }
    public function pending()
    {
        $rows=\think\facade\Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>(int)$this->request->tenantId,'user_id'=>$this->userId,'status'=>'pending'])->field('order_id,biz_type,package_id,cycle,payable_cents,expires_at')->order('id desc')->limit(20)->select()->toArray();
        foreach($rows as &$row){$row['from']=$row['biz_type'];$row['order_amount']=\app\common\service\promotion\PackagePromotionPolicy::amount((int)$row['payable_cents']);unset($row['payable_cents']);}unset($row);
        return $this->data(['lists'=>$rows]);
    }
}
