<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService;
use Exception;
use Throwable;
use think\facade\Log;

class EpisodeController extends BaseApiController
{
    private function dispatchEpisode(string $action)
    {
        try {
            $params = in_array($action, ['list', 'detail', 'context'], true) ? $this->request->get() : $this->request->post();
            $t = (int)$this->request->tenantId;
            $u = (int)$this->userId;
            $result = match ($action) {
                'list' => ShortDramaEpisodeService::lists($t, $u, (int)($params['project_id'] ?? 0)),
                'detail' => ShortDramaEpisodeService::detail($t, $u, (int)($params['episode_id'] ?? 0)),
                'context' => ShortDramaEpisodeService::summary(ShortDramaEpisodeService::context($t, $u, (int)($params['project_id'] ?? 0))),
                'start' => ShortDramaEpisodeService::start($t, $u, $params),
                'retry' => ShortDramaEpisodeService::retry($t, $u, (int)($params['episode_id'] ?? 0)),
                'cancel' => !empty($params['project_id'])
                    ? ShortDramaEpisodeService::cancelAll($t, $u, (int)$params['project_id'])
                    : ShortDramaEpisodeService::cancel($t, $u, (int)($params['episode_id'] ?? 0)),
                'message' => ShortDramaEpisodeService::message($t, $u, $params),
                'export' => ShortDramaEpisodeService::export($t, $u, $params),
            };
            return $this->success('success', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            // Keep an unexpected read failure observable in server logs rather
            // than turning a switch between episodes into an opaque HTTP error.
            Log::error('AI short drama episode ' . $action . ' failed: ' . $e->getMessage());
            return $this->fail('请求异常，请稍后重试');
        }
    }

    public function list() { return $this->dispatchEpisode('list'); }
    public function detail() { return $this->dispatchEpisode('detail'); }
    public function context() { return $this->dispatchEpisode('context'); }
    public function start() { return $this->dispatchEpisode('start'); }
    public function retry() { return $this->dispatchEpisode('retry'); }
    public function cancel() { return $this->dispatchEpisode('cancel'); }
    public function message() { return $this->dispatchEpisode('message'); }
    public function export() { return $this->dispatchEpisode('export'); }
}
