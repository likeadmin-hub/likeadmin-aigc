<?php
namespace app\tenantapi\controller\app\aigc_pic_lipsync;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncAdminService;
class AudioController extends BaseAdminController
{
    public function lists() { return $this->success('获取成功', AigcPicLipsyncAdminService::audioLists((int)$this->tenantId, $this->request->get())); }
    public function delete() {
        try { AigcPicLipsyncAdminService::deleteAudio((int)$this->tenantId, (int)$this->request->post('id', 0)); return $this->success('删除成功', [], 1, 1); }
        catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }
}
