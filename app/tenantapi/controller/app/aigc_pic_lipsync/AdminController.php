<?php
namespace app\tenantapi\controller\app\aigc_pic_lipsync;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncAdminService;
class AdminController extends BaseAdminController
{
    public function stat() { return $this->success('获取成功', AigcPicLipsyncAdminService::stat((int)$this->tenantId)); }
}
