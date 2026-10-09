<?php
namespace app\platformapi\controller\app\aigc_pic_lipsync;
use app\platformapi\controller\BaseAdminController;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncAdminService;
class AdminController extends BaseAdminController
{
    public function stat() { return $this->success('获取成功', AigcPicLipsyncAdminService::stat(max(0, (int)$this->request->get('tenant_id', 0)))); }
    public function tenants() { return $this->success('获取成功', AigcPicLipsyncAdminService::tenantUsage($this->request->get())); }
}
