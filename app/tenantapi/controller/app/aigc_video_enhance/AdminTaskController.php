<?php

declare(strict_types=1);

namespace app\tenantapi\controller\app\aigc_video_enhance;

use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_video_enhance\VideoEnhanceAdminTasks as Tasks;
use app\common\service\ai\GenerationSubmission;
use app\common\service\app\aigc_video_enhance\VideoEnhanceService;

final class AdminTaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->respond(fn() => Tasks::lists($this->scope(), $this->request->get()));
    }

    public function detail()
    {
        return $this->respond(fn() => Tasks::detail($this->scope(), (int)$this->request->get('id', 0)));
    }

    public function media()
    {
        return $this->respond(fn() => Tasks::media($this->scope(), (int)$this->request->get('id', 0)));
    }

    public function diagnose()
    {
        return $this->respond(fn() => Tasks::diagnose($this->scope(), (int)$this->request->get('id', 0)));
    }

    public function operate()
    {
        return $this->respond(fn() => Tasks::operate($this->scope(), $this->adminId, (int)$this->request->post('id', 0),
            (string)$this->request->post('operation', ''), (string)$this->request->post('reason', '')));
    }

    public function retryEstimate()
    {
        return $this->respond(function () {
            [$user, $input] = Tasks::retryInput($this->scope(), (int)$this->request->post('id', 0));
            return VideoEnhanceService::estimate($this->scope(), $user, $input);
        });
    }

    public function retry()
    {
        try {
            $tenant = $this->scope();
            $id = (int)$this->request->post('id', 0);
            [$user, $input, $parent] = Tasks::retryInput($tenant, $id);
            $key = (string)$this->request->post('request_key', '');
            $points = (string)$this->request->post('expected_points', '');
            return GenerationSubmission::run($tenant, $user, 'app.aigc_video_enhance.admin_task/retry', $key,
                ['id' => $id, 'points' => $points, 'input' => $input],
                fn() => $this->respond(fn() => VideoEnhanceService::submit($tenant, $user, $input,
                    ['id' => $id, 'parent' => $parent, 'admin' => $this->adminId, 'points' => $points])));
        } catch (\InvalidArgumentException $error) {
            return $this->fail($error->getMessage());
        }
    }

    private function scope(): int
    {
        if ($this->tenantId < 1) throw new \InvalidArgumentException('租户身份无效');
        return $this->tenantId;
    }

    private function respond(callable $call)
    {
        try {
            return $this->success('', $call());
        } catch (\InvalidArgumentException $error) {
            return $this->fail($error->getMessage());
        } catch (\Throwable $error) {
            \think\facade\Log::warning('Video enhance admin: '.get_class($error));
            return $this->fail('操作暂未完成，请稍后重试');
        }
    }
}
