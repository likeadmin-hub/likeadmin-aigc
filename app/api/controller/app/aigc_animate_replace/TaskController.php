<?php
declare(strict_types=1);

namespace app\api\controller\app\aigc_animate_replace;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_animate_replace\AnimateReplaceTasks;
use InvalidArgumentException;

class TaskController extends BaseApiController
{
    public function lists()
    {
        if ($this->userId <= 0) return $this->fail('请登录后重试');
        return $this->success('获取成功', AnimateReplaceTasks::lists((int)$this->request->tenantId, $this->userId, $this->request->get()));
    }

    public function detail()
    {
        if ($this->userId <= 0) return $this->fail('请登录后重试');
        try {
            return $this->success('获取成功', AnimateReplaceTasks::detail((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id', 0)));
        } catch (InvalidArgumentException $error) {
            return $this->fail($error->getMessage());
        }
    }

    public function delete()
    {
        try {
            AnimateReplaceTasks::delete((int)$this->request->tenantId, $this->userId, (int)$this->request->post('id', 0));
            return $this->success('删除成功');
        } catch (InvalidArgumentException $error) {
            return $this->fail($error->getMessage());
        }
    }
}
