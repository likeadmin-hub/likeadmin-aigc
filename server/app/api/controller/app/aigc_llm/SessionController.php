<?php

namespace app\api\controller\app\aigc_llm;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_llm\AigcLlmService;
use Exception;

class SessionController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcLlmService::sessionLists((int)$this->request->tenantId, $this->userId));
    }

    public function create()
    {
        try {
            return $this->success('创建成功', AigcLlmService::createSession((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcLlmService::sessionDetail((int)$this->request->tenantId, $this->userId, (int)$this->request->get('session_id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function rename()
    {
        try {
            AigcLlmService::renameSession((int)$this->request->tenantId, $this->userId, (int)$this->request->post('session_id', 0), (string)$this->request->post('title', ''));
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcLlmService::deleteSession((int)$this->request->tenantId, $this->userId, (int)$this->request->post('session_id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
