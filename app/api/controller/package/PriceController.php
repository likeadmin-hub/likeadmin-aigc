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
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }
}
