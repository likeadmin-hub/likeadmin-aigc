<?php

namespace app\platformapi\controller\app\aigc_pic_lipsync;

use app\common\service\app\image_human\ImageHumanService;
use app\platformapi\controller\BaseAdminController;

class PublicAvatarController extends BaseAdminController
{
    private function tenantId(): int
    {
        $id = (int)$this->request->param('tenant_id', 0);
        if ($id <= 0) throw new \Exception('请选择租户');
        return $id;
    }

    public function lists()
    {
        return $this->success('获取成功', ImageHumanService::publicAvatarLists($this->tenantId(), $this->request->get()));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', ImageHumanService::savePublicAvatar($this->tenantId(), $this->request->post()), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            ImageHumanService::deletePublicAvatar($this->tenantId(), (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
