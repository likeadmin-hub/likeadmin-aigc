<?php
namespace app\common\service\app\aigc_short_drama;

use think\facade\Db;
use RuntimeException;

/** Receipts are persisted before parsing. A restart must never replay a received paid response. */
final class ShortDramaPlanningUnit
{
    /** Presentation metadata can differ between HTTP and workers; paid input cannot. */
    public static function requestSignature(array $input): string
    {
        unset($input['_unit_signature']);
        if (is_array($input['model_selection'] ?? null)) {
            foreach (['display_icon', 'name', 'description', 'category_name', 'resource_type_label', 'api_doc', 'developer_doc_slug', 'sort'] as $key) {
                unset($input['model_selection'][$key]);
            }
        }
        $canonical = static function (array $value) use (&$canonical): array {
            if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) ksort($value);
            foreach ($value as &$item) if (is_array($item)) $item = $canonical($item);
            unset($item);
            return $value;
        };
        return hash('sha256', json_encode($canonical($input), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function ready(int $tenant, int $user, string $task): bool
    {
        foreach (Db::name('aigc_short_drama_planning_unit')->where(['tenant_id' => $tenant, 'user_id' => $user, 'task_id' => $task, 'status' => 'waiting'])->select()->toArray() as $row) {
            if (time() < (int)$row['update_time'] + ((int)$row['attempt'] === 1 ? 30 : 120)) return false;
        }
        return true;
    }

    public static function retryableBeforeSubmission(string $error): bool
    {
        // These are connection establishment failures, not ambiguous read timeouts after submission.
        return (bool)preg_match('/SSL_connect:|Could not resolve host|Temporary failure in name resolution|Name or service not known|Network is unreachable|Failed to connect to|Connection refused|cURL error (?:6|7)\b/i', $error);
    }

    public static function call(int $tenant, int $user, string $task, string $key, array $input, callable $generate): array
    {
        if ($task === '') return $generate(); // Pure provider contract tests have no persisted task.
        $scope = ['tenant_id' => $tenant, 'user_id' => $user, 'task_id' => $task, 'unit_key' => $key];
        // Serialize the claim only. Never hold a DB lock across the paid call.
        $claim = Db::transaction(static function () use ($scope, $input, $tenant, $user, $task, $key): array {
            $row = Db::name('aigc_short_drama_planning_unit')->where($scope)->lock(true)->find();
            $signature = self::requestSignature($input);
            $saved = $row ? json_decode((string)$row['request_json'], true) : [];
            // Old receipts retain their original raw signature. Compare the
            // canonical saved request too, without rewriting or resubmitting it.
            if ($row && is_array($saved) && $saved !== []
                && !hash_equals((string)($saved['_unit_signature'] ?? ''), $signature)
                && !hash_equals(self::requestSignature($saved), $signature)) {
                $changed = [];
                foreach (array_unique(array_merge(array_keys($saved), array_keys($input))) as $field) {
                    if ($field === '_unit_signature') continue;
                    if (self::requestSignature([$field => $saved[$field] ?? null]) !== self::requestSignature([$field => $input[$field] ?? null])) $changed[] = $field;
                }
                \think\facade\Log::warning('Short drama receipt context mismatch: ' . json_encode([
                    'tenant_id' => $tenant, 'task_id' => $task, 'unit_key' => $key, 'changed_fields' => $changed,
                ], JSON_UNESCAPED_UNICODE));
                throw new RuntimeException('生成上下文已变化，请新建版本；原有结果已保留', 409);
            }
            if ($row && $row['status'] === 'received') return ['result' => json_decode($row['result_json'], true, 512, JSON_THROW_ON_ERROR)];
            if ($row && $row['status'] === 'running') throw new RuntimeException('上次请求在返回前中断，已保留完成部分；请确认后继续未完成部分');
            if ($row && $row['status'] === 'failed') throw new RuntimeException((string)$row['error']);
            if ($row && $row['status'] === 'waiting' && !self::ready($tenant, $user, $task)) throw new RuntimeException('连接暂时不可用，等待延迟重试', 425);
            if ($row && (int)$row['attempt'] >= 3) throw new RuntimeException('该生成单元已达到重试上限，请调整内容后新建任务');
            $storedInput = $input;
            if (str_starts_with($key, 'v3_')) $storedInput['_unit_signature'] = $signature;
            $data = ['status' => 'running', 'attempt' => (int)($row['attempt'] ?? 0) + 1,
                'request_json' => json_encode($storedInput, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'update_time' => time()];
            if ($row) Db::name('aigc_short_drama_planning_unit')->where($scope)->update($data);
            else Db::name('aigc_short_drama_planning_unit')->insert($scope + $data + ['create_time' => time()]);
            return ['data' => $data];
        });
        if (isset($claim['result'])) return $claim['result'];
        $data = $claim['data'];
        try {
            $result = $generate();
            Db::name('aigc_short_drama_planning_unit')->where($scope)->update(['status' => 'received', 'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'update_time' => time()]);
            return $result;
        } catch (\Throwable $error) {
            $retry = self::retryableBeforeSubmission($error->getMessage()) && $data['attempt'] < 3;
            Db::name('aigc_short_drama_planning_unit')->where($scope)->update(['status' => $retry ? 'waiting' : 'failed', 'error' => $error->getMessage(), 'update_time' => time()]);
            if ($retry) throw new RuntimeException('模型连接暂时不可用，将在 ' . ($data['attempt'] === 1 ? 30 : 120) . ' 秒后重试，已完成部分保留', 425, $error);
            throw $error;
        }
    }
}
