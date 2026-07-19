<?php

namespace app\platformapi\controller\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('Success', AigcShortDramaService::adminStat((int)$this->request->get('tenant_id', 0)));
    }

    public function lists()
    {
        return $this->success('Success', AigcShortDramaService::adminTenantStatLists($this->request->get()));
    }
}
