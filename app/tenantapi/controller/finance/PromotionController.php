<?php
namespace app\tenantapi\controller\finance;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\promotion\PackagePromotionService;
use app\common\service\promotion\PackagePricingService;
class PromotionController extends BaseAdminController
{
    public function detail()
    {
        try {
            $from=(string)$this->request->get('from');$id=(int)$this->request->get('package_id');
            PackagePricingService::catalog($this->tenantId,$from,$id,'package',false);
            return $this->data(PackagePromotionService::detail($this->tenantId,$from,$id));
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }
    public function save()
    {
        try { return $this->data(PackagePromotionService::save($this->tenantId,(string)$this->request->post('from'),(int)$this->request->post('package_id'),$this->request->post())); }
        catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }
}
