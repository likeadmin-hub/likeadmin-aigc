<?php

namespace app\api\controller\app\aigc_video;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\ai\AiTaskJobService;
use app\common\model\app\aigc_video\AigcVideoTask;

class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcVideoService::taskLists((int)$this->request->tenantId, $this->userId));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', AigcVideoService::taskDetail((int)$this->request->tenantId, $id, $this->userId));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function refresh()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            $task = AigcVideoTask::where(['id' => $id, 'tenant_id' => (int)$this->request->tenantId, 'user_id' => $this->userId])
                ->where('delete_time', 0)->findOrEmpty();
            if ($task->isEmpty()) {
                return $this->fail('任务不存在');
            }
            if ((int)$task['consumption_id'] > 0) {
                AiTaskJobService::enqueueQueryResult((int)$task['consumption_id'], 100, true);
            }
            return $this->success('已交由后台补偿处理');
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
