<?php

namespace app\tenantapi\controller\app\image_human;

use app\common\service\app\image_human\ImageHumanService;
use app\tenantapi\controller\BaseAdminController;

class PublicVoiceController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', ImageHumanService::publicVoiceLists((int)$this->tenantId, $this->request->get()));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', ImageHumanService::savePublicVoice((int)$this->tenantId, $this->request->post()), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            ImageHumanService::deletePublicVoice((int)$this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
