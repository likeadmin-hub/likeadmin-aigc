<?php
namespace app\common\service\app\aigc_short_drama;

use app\common\model\app\aigc_short_drama\AigcShortDramaScriptTask;
use think\facade\Db;
use RuntimeException;

/** Serializes only local task creation. Never wraps model execution. */
final class ShortDramaSubmission
{
    public static function identity(int $tenant, int $user, string $operation, array $params): array
    {
        $key = $params['submission_key'] ?? '';
        if (!is_string($key) || ($key !== '' && !preg_match('/^[a-zA-Z0-9:_-]{16,128}$/D', $key))) {
            throw new RuntimeException('提交编号无效，请刷新后重试', 422);
        }
        unset($params['submission_key']);
        return ['key' => $key === '' ? '' : hash('sha256', "$tenant|$user|$operation|$key"),
            'hash' => ShortDramaPlanningUnit::requestSignature($params)];
    }

    public static function run(int $tenant, int $user, string $operation, array $params, callable $create): array
    {
        $identity = self::identity($tenant, $user, $operation, $params);
        if ($identity['key'] === '') return $create([]); // Legacy clients remain compatible.
        $lock = 'sd_submit_' . substr($identity['key'], 0, 48);
        if ((int)(Db::query('SELECT GET_LOCK(?, 3) AS acquired', [$lock])[0]['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('正在确认本次提交，请稍后重试；请勿重复新建', 409);
        }
        try {
            $row = AigcShortDramaScriptTask::where(['tenant_id' => $tenant, 'user_id' => $user,
                'idempotency_key' => $identity['key']])->findOrEmpty();
            if (!$row->isEmpty()) {
                $request = json_decode((string)$row['request_json'], true) ?: [];
                if (!hash_equals((string)($request['_submission_hash'] ?? ''), $identity['hash'])) {
                    throw new RuntimeException('本次提交内容已变化，请作为新请求提交', 409);
                }
                if ((int)$row['delete_time'] > 0) throw new RuntimeException('本次提交对应的任务已删除，请新建请求', 409);
                return ['project_id' => (int)$row['project_id'], 'task_id' => (string)$row['task_id'],
                    'status' => (string)$row['status'], 'reused' => true];
            }
            return $create(['_submission_key' => $identity['key'], '_submission_hash' => $identity['hash']]);
        } finally {
            Db::query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
