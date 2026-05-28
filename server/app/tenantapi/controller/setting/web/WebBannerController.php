<?php

namespace app\tenantapi\controller\setting\web;

use app\common\service\WebsiteBannerService;
use app\tenantapi\controller\BaseAdminController;

class WebBannerController extends BaseAdminController
{
    public function lists()
    {
        return $this->data(WebsiteBannerService::lists(false));
    }

    public function save()
    {
        $data = WebsiteBannerService::save($this->request->post());
        return $this->success('保存成功', $data, 1, 1);
    }

    public function delete()
    {
        WebsiteBannerService::delete((string)$this->request->post('id', ''));
        return $this->success('删除成功', [], 1, 1);
    }

    public function status()
    {
        WebsiteBannerService::status(
            (string)$this->request->post('id', ''),
            (int)$this->request->post('status', 1)
        );
        return $this->success('设置成功', [], 1, 1);
    }
}
