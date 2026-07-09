<?php

namespace app\tenantapi\controller\app\aigc_music;

use app\common\service\app\aigc_music\AigcMusicService;
use app\tenantapi\controller\BaseAdminController;

class StyleController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcMusicService::styleLists($this->tenantId, $this->request->get()));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcMusicService::saveStyle($this->tenantId, $this->request->post()), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcMusicService::deleteStyle($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
