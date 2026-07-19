<?php

namespace app\platformapi\controller\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\platformapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function dependencies()
    {
        return $this->success('Success', AigcShortDramaService::dependencies((int)$this->request->get('tenant_id', 0)));
    }
}
