<?php

declare(strict_types=1);

namespace app\api\controller\app\aigc_video_enhance;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_video_enhance\VideoEnhanceService;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            return $this->success('', VideoEnhanceService::estimate((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (\Exception $error) {
            return $this->fail($error->getMessage());
        }
    }

    public function index()
    {
        try {
            return $this->success('', VideoEnhanceService::submit((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (\Exception $error) {
            return $this->fail($error->getMessage());
        }
    }
}
