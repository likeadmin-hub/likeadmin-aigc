<?php

declare(strict_types=1);

namespace app\api\controller\app\aigc_video_enhance;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_video_enhance\VideoEnhanceService;

class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->success('', VideoEnhanceService::lists((int)$this->request->tenantId, $this->userId, $this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('', VideoEnhanceService::detail((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id', 0)));
        } catch (\InvalidArgumentException $error) {
            return $this->fail($error->getMessage());
        }
    }

    public function delete()
    {
        try {
            VideoEnhanceService::delete((int)$this->request->tenantId, $this->userId, (int)$this->request->post('id', 0));
            return $this->success('删除成功');
        } catch (\InvalidArgumentException $error) {
            return $this->fail($error->getMessage());
        }
    }
}
