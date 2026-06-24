<?php

namespace app\api\controller\app\aigc_model_wear;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_model_wear\AigcModelWearService;
use Exception;

class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcModelWearService::taskLists(
            (int)$this->request->tenantId,
            $this->userId,
            $this->request->get()
        ));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcModelWearService::taskDetail(
                (int)$this->request->tenantId,
                (int)$this->request->get('id', 0),
                $this->userId
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcModelWearService::deleteTask(
                (int)$this->request->tenantId,
                (int)$this->request->post('id', 0),
                $this->userId
            );
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

