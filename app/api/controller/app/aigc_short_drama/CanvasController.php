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
    public function delete() { try { return $this->success('删除成功', ShortDramaCanvasService::delete((int)$this->request->tenantId, $this->userId, (int)$this->request->post('id', 0)), 1, 1); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function run() { try { return $this->success('任务已提交', ShortDramaCanvasService::submit((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function patch()
    {
        try {
            $tenant=(int)$this->request->tenantId;
            \app\common\service\app\aigc_short_drama\canvas_agent\FeatureGate::assertEnabled($tenant);
            $params=$this->request->post();
            return $this->success('保存成功', \app\common\service\app\aigc_short_drama\canvas_agent\GraphService::patch($tenant,$this->userId,(int)($params['canvas_id']??0),$params));
        } catch (Exception $e) { return $this->fail($e->getMessage()); }
    }
    public function task() { try { return $this->success('success', ShortDramaCanvasService::runDetail((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id', 0))); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
}
