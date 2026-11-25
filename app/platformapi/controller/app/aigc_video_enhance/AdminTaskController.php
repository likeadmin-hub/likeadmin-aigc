<?php

declare(strict_types=1);

namespace app\platformapi\controller\app\aigc_video_enhance;

use app\platformapi\controller\BaseAdminController;
use app\common\service\app\aigc_video_enhance\VideoEnhanceAdminTasks as Tasks;

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

    private function scope(): int
    {
        return 0;
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
