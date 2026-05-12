<?php

namespace app\api\controller\app\aigc_canvas;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use Exception;

class ProjectController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcCanvasService::projectLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
    }

    public function create()
    {
        try {
            return $this->success('创建成功', AigcCanvasService::createProject((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcCanvasService::projectDetail((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcCanvasService::saveProject((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function rename()
    {
        try {
            return $this->success('保存成功', AigcCanvasService::renameProject(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->post('id', 0),
                (string)$this->request->post('name', '')
            ), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function duplicate()
    {
        try {
            return $this->success('复制成功', AigcCanvasService::duplicateProject((int)$this->request->tenantId, $this->userId, (int)$this->request->post('id', 0)), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcCanvasService::deleteProject((int)$this->request->tenantId, $this->userId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
