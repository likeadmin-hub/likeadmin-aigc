<?php

namespace app\common\service\app\aigc_image;

use app\common\model\app\aigc_image\AigcImageTask;
use InvalidArgumentException;
use think\facade\Db;

/** Persist one task per client submission, scoped to the tenant, user and app. */
class AigcImageSubmission
{
    public static function key(array $params, string $appCode): string
    {
        // API admission owns idempotency and immutable native-task associations.
        // A page batch key must not reuse another request's task or consumption.
        if (\app\common\service\open_platform\OpenCallContext::active()) return '';
        $key = $params['batch_key'] ?? '';
        if ($key === '') {
            return ''; // Older clients retain their existing submission behavior.
        }
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{8,120}$/D', $key)) {
            throw new InvalidArgumentException('图片提交标识无效');
        }
        return hash('sha256', $appCode . ':' . $key);
    }

    public static function find(int $tenantId, int $userId, string $key): ?AigcImageTask
    {
        if ($key === '') {
            return null;
        }
        // Include deleted/failed tasks: retrying an uncertain request never charges again.
        return AigcImageTask::where(['tenant_id' => $tenantId, 'user_id' => $userId])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(provider_params_json), provider_params_json, '{}'), '$._submission_key')) = ?", [$key])
            ->find();
    }

    public static function claim(array $data, string $key): array
    {
        if ($key === '') {
            return ['task' => AigcImageTask::create($data), 'created' => true];
        }
        return Db::transaction(function () use ($data, $key) {
            // Serialize only local lookup/insert, never hold this lock over provider HTTP.
            $owner = Db::name('user')->where('id', (int)$data['user_id'])->lock(true)->find();
            if (!$owner) {
                throw new InvalidArgumentException('请先登录');
            }
            $existing = self::find((int)$data['tenant_id'], (int)$data['user_id'], $key);
            if ($existing) {
                return ['task' => $existing, 'created' => false];
            }
            $data['provider_params_json']['_submission_key'] = $key;
            return ['task' => AigcImageTask::create($data), 'created' => true];
        });
    }
}
