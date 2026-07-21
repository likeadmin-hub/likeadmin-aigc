<?php

namespace app\common\service\ai;

use app\common\model\ai\AiConsumptionLog;
use app\common\service\update\UpdateSourceClient;
use think\Request;

class AiTaskCallbackService
{
    public static function accept(Request $request): array
    {
        $raw = (string)$request->getContent();
        $source = UpdateSourceClient::getSource();
        $secret = trim((string)($source['callback_secret'] ?? ''));
        $signature = trim((string)$request->header('x-ai-callback-signature', ''));
        if ($secret === '' || $signature === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
            throw new \RuntimeException('回调签名无效');
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('回调数据格式错误');
        }
        $root = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $consumeNo = trim((string)($root['consume_no'] ?? $root['idempotency_key'] ?? ''));
        $upstreamTaskId = trim((string)($root['task_id'] ?? $root['upstream_task_id'] ?? $root['id'] ?? ''));
        $query = AiConsumptionLog::where('provider', 'power_market');
        if ($consumeNo !== '') {
            $query->where('consume_no', $consumeNo);
        } elseif ($upstreamTaskId !== '') {
            $query->where('upstream_task_id', $upstreamTaskId);
        } else {
            throw new \RuntimeException('回调缺少任务标识');
        }
        $consumption = $query->findOrEmpty();
        if ($consumption->isEmpty()) {
            throw new \RuntimeException('未找到本地任务');
        }
        AiTaskJobService::enqueueQueryResult((int)$consumption['id'], 100, true);
        return ['consumption_id' => (int)$consumption['id']];
    }
}
