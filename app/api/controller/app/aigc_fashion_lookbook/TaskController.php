<?php

namespace app\api\controller\app\aigc_fashion_lookbook;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_fashion_lookbook\AigcFashionLookbookService;
use Exception;

class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcFashionLookbookService::taskLists(
            (int)$this->request->tenantId,
            $this->userId,
            $this->request->get()
        ));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcFashionLookbookService::taskDetail(
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
            AigcFashionLookbookService::deleteTask(
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

