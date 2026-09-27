<?php
namespace app\tenantapi\controller\app\system_default;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\home\LatestNewsService;
class LatestNewsController extends BaseAdminController
{
    public function lists() { return $this->data(LatestNewsService::lists($this->tenantId)); }
    public function save()
    {
        try {
            $rows = $this->request->post('items');
            if (!is_array($rows)) { return $this->fail('动态列表格式错误'); }
            LatestNewsService::save($this->tenantId, $rows);
            return $this->success('保存成功');
        } catch (\RuntimeException $e) { return $this->fail($e->getMessage()); }
    }
}
