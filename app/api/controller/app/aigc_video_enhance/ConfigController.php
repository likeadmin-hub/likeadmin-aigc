<?php

declare(strict_types=1);

namespace app\api\controller\app\aigc_video_enhance;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_video_enhance\VideoEnhanceService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('', VideoEnhanceService::config((int)$this->request->tenantId));
    }
}
