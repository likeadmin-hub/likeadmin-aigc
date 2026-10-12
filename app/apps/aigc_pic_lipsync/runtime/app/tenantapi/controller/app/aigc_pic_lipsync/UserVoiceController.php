<?php

namespace app\tenantapi\controller\app\aigc_pic_lipsync;

use app\common\service\app\image_human\ImageHumanService;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncAdminService;
use app\tenantapi\controller\BaseAdminController;

class UserVoiceController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcPicLipsyncAdminService::voiceLists((int)$this->tenantId, $this->request->get(), 'mine'));
    }

    public function publish()
    {
        try {
            return $this->success('设置成功', AigcPicLipsyncAdminService::publishUserVoice((int)$this->tenantId, (int)$this->request->post('id', 0)), 1, 1);
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
