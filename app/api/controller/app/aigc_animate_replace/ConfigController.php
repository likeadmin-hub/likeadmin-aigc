<?php
declare(strict_types=1);

namespace app\api\controller\app\aigc_animate_replace;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_animate_replace\AnimateReplaceService;

class ConfigController extends BaseApiController
{
    public function detail()
    {
        return $this->success('获取成功', AnimateReplaceService::config((int)$this->request->tenantId));
    }
}
