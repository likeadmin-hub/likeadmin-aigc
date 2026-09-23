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
    public function binding() { try { return $this->success('success', ShortDramaCanvasService::binding((int)$this->request->tenantId, $this->userId, (int)$this->request->get('canvas_id', $this->request->get('id', 0)))); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function writebackSources() { try { return $this->success('success', \app\common\service\app\aigc_short_drama\ShortDramaCanvasWritebackService::sources((int)$this->request->tenantId, $this->userId, (int)$this->request->get('canvas_id', 0))); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function previewStoryWriteback() { try { return $this->success('success', \app\common\service\app\aigc_short_drama\ShortDramaCanvasWritebackService::previewStory((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function applyStoryWriteback() { try { return $this->success('写回成功', \app\common\service\app\aigc_short_drama\ShortDramaCanvasWritebackService::applyStory((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function previewEpisodeWriteback() { try { return $this->success('success', \app\common\service\app\aigc_short_drama\ShortDramaCanvasWritebackService::previewEpisode((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function applyEpisodeWriteback() { try { return $this->success('写回成功', \app\common\service\app\aigc_short_drama\ShortDramaCanvasWritebackService::applyEpisode((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function bind() { try { return $this->success('绑定成功', ShortDramaCanvasService::bindProject((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function quote() { try { return $this->success('报价已生成', ShortDramaCanvasService::quote((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function confirmQuote() { try { return $this->success('报价已确认', ShortDramaCanvasService::confirmQuote((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    /**
     * The public canvas submission boundary is intentionally idempotent.
     *
     * A browser retry can happen after a timeout while a Provider has already
     * accepted a billable task.  The request key therefore reaches the durable
     * generation intent before any Provider I/O; replays return the same run
     * and conflicting payloads are rejected instead of creating another task.
     */
    public function run() { try { return $this->success('任务已提交', ShortDramaCanvasService::submitIdempotent((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
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
