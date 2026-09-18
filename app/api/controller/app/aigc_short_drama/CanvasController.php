<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use Exception;

class CanvasController extends BaseApiController
{
    public function current() { try { return $this->success('success', ShortDramaCanvasService::current((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id', 0))); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function lists() { try { return $this->success('success', ShortDramaCanvasService::lists((int)$this->request->tenantId, $this->userId, $this->request->get())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function create() { try { return $this->success('创建成功', ShortDramaCanvasService::create((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function save() { try { return $this->success('保存成功', ShortDramaCanvasService::save((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function run() { try { return $this->success('任务已提交', ShortDramaCanvasService::submit((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function task() { try { return $this->success('success', ShortDramaCanvasService::runDetail((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id', 0))); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
}
