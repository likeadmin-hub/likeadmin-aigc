<?php

namespace app\tenantapi\controller\app\aigc_digital_human;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\tenantapi\controller\BaseAdminController;

class UserVoiceController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcDigitalHumanService::userVoiceLists($this->tenantId, $this->request->get()));
    }

    public function publish()
    {
        try {
            return $this->success('设置成功', AigcDigitalHumanService::publishUserVoice($this->tenantId, (int)$this->request->post('id', 0)), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcDigitalHumanService::deleteUserVoice($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
