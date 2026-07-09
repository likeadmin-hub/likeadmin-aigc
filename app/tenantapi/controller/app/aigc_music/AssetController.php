<?php

namespace app\tenantapi\controller\app\aigc_music;

use app\common\service\app\aigc_music\AigcMusicService;
use app\tenantapi\controller\BaseAdminController;

class AssetController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcMusicService::assetLists($this->tenantId, $this->request->get()));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcMusicService::saveAsset($this->tenantId, $this->request->post()), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcMusicService::deleteAsset($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
