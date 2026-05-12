<?php

namespace app\tenantapi\controller\app\aigc_digital_human;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\tenantapi\controller\BaseAdminController;

class UserAvatarController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcDigitalHumanService::userAvatarLists($this->tenantId, $this->request->get()));
    }

    public function delete()
    {
        try {
            AigcDigitalHumanService::deleteUserAvatar($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
