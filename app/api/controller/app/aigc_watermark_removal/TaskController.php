<?php

namespace app\api\controller\app\aigc_watermark_removal;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_watermark_removal\AigcWatermarkRemovalService;
use Exception;

class TaskController extends BaseApiController
{
    public function lists() { return $this->success('获取成功', AigcWatermarkRemovalService::taskLists((int)$this->request->tenantId, $this->userId, $this->request->get())); }
    public function detail() { try { return $this->success('获取成功', AigcWatermarkRemovalService::taskDetail((int)$this->request->tenantId, (int)$this->request->get('id', 0), $this->userId)); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function delete() { try { AigcWatermarkRemovalService::deleteTask((int)$this->request->tenantId, (int)$this->request->post('id', 0), $this->userId); return $this->success('删除成功', [], 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
}
