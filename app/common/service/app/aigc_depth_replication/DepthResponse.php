<?php
namespace app\common\service\app\aigc_depth_replication;

trait DepthResponse
{
    protected function respond(callable $action)
    {
        try { return $this->success('操作成功',$action()); }
        catch (\InvalidArgumentException $e) { return $this->fail($e->getMessage()); }
        catch (\Throwable $e) {
            \think\facade\Log::error('Depth replication API: '.$e->getMessage());
            return $this->fail('暂时无法处理深度复刻请求，请稍后重试');
        }
    }
}

