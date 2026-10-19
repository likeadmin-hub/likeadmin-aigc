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
        try {
            return $this->data(['lists'=>(new \app\common\service\promotion\PackagePendingService())->lists(
                (int)$this->request->tenantId, $this->userId,
                (string)$this->request->get('from', ''), (int)$this->request->get('package_id', 0)
            )]);
        } catch (\Throwable $e) { return $this->fail(\app\common\service\virtualpay\VirtualPayPolicy::error($e)); }
    }
}
