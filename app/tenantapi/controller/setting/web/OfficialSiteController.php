<?php

namespace app\tenantapi\controller\setting\web;

use app\common\service\OfficialSiteService;
use app\tenantapi\controller\BaseAdminController;

class OfficialSiteController extends BaseAdminController
{
    public function get()
    {
        return $this->data(OfficialSiteService::get());
    }

    public function save()
    {
        return $this->success('保存成功', OfficialSiteService::save($this->request->post()), 1, 1);
    }

    public function preview()
    {
        return $this->data(OfficialSiteService::public());
    }
}
