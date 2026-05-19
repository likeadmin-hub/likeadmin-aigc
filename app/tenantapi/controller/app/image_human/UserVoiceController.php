<?php

namespace app\tenantapi\controller\app\image_human;

use app\common\service\app\image_human\ImageHumanService;
use app\tenantapi\controller\BaseAdminController;

class UserVoiceController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', ImageHumanService::userVoiceLists((int)$this->tenantId, $this->request->get()));
    }

    public function publish()
    {
        try {
            return $this->success('设置成功', ImageHumanService::publishUserVoice((int)$this->tenantId, (int)$this->request->post('id', 0)), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            ImageHumanService::deleteUserVoice((int)$this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
