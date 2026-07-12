<?php

namespace app\tenantapi\controller\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class StyleController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcShortDramaService::adminStyleLists($this->tenantId, $this->request->get()));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcShortDramaService::saveAdminStyle($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcShortDramaService::setAdminStyleStatus(
                $this->tenantId,
                (int)$this->request->post('id', 0),
                (int)$this->request->post('status', 1)
            );
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcShortDramaService::deleteAdminStyle($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
