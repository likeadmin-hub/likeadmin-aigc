<?php

namespace app\tenantapi\controller\setting;

use app\common\service\PcFeedbackService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class PcFeedbackController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', PcFeedbackService::tenantLists(
            $this->tenantId,
            $this->request->get()
        ));
    }

    public function reply()
    {
        try {
            PcFeedbackService::reply(
                $this->tenantId,
                (int)$this->request->post('id', 0),
                $this->request->post()
            );
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
