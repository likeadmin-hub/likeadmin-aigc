<?php
declare(strict_types=1);

namespace app\api\controller\app\aigc_animate_replace;

use app\api\controller\BaseApiController;
use app\common\service\ai\PreSubmissionRejected;
use app\common\service\ai\UpstreamErrorMessageService;
use app\common\service\app\aigc_animate_replace\AnimateReplaceService;
use app\common\service\app\aigc_animate_replace\AnimateReplaceTasks;
use InvalidArgumentException;
use Throwable;
use think\facade\Log;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        return $this->execute(fn(int $tenant, int $user, array $params) => AnimateReplaceService::estimate($tenant, $user, $params));
    }

    public function index()
    {
        return $this->execute(fn(int $tenant, int $user, array $params) => AnimateReplaceTasks::create($tenant, $user, $params));
    }

    private function execute(callable $operation)
    {
        try {
            return $this->success('操作成功', $operation((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (InvalidArgumentException | PreSubmissionRejected $error) {
            return $this->fail($error->getMessage());
        } catch (Throwable $error) {
            Log::warning('Animate replace request: '.get_class($error));
            return $this->fail(UpstreamErrorMessageService::normalize($error->getMessage()));
        }
    }
}
