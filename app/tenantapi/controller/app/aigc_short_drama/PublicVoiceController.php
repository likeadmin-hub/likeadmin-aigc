<?php

namespace app\tenantapi\controller\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class PublicVoiceController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcShortDramaService::adminPublicVoiceLists((int)$this->tenantId, $this->request->get()));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcShortDramaService::saveAdminPublicVoice((int)$this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcShortDramaService::deleteAdminPublicVoice((int)$this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
