<?php
namespace app\common\service\app\aigc_short_drama;

use think\facade\Db;
use RuntimeException;

/** Receipts are persisted before parsing. A restart must never replay a received paid response. */
final class ShortDramaPlanningUnit
{
    public const INTERRUPTED_CONFIRMATION = '中断部分需要重新生成，可能产生新的费用；已完成内容和原费用记录保留。请确认后继续。';
    public const CHANGED_CONTEXT_CONFIRMATION = '中断任务的上下文已更新，不能安全复用旧回包；继续会在原项目生成新版本，可能产生新的费用，原剧本和素材保留。请确认后继续。';
    private const INTERRUPTED_SECONDS = 1800;
    /** Presentation metadata can differ between HTTP and workers; paid input cannot. */
    public static function requestSignature(array $input): string
    {
        unset($input['_unit_signature']);
        unset($input['_repair_reason'], $input['_unit_root'], $input['_interrupted_retry_of']);
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
        try { return hash('sha256', json_encode($canonical($input), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
        finally { unset($canonical); }
    }

    public static function ready(int $tenant, int $user, string $task): bool
    {
        foreach (Db::name('aigc_short_drama_planning_unit')->where(['tenant_id' => $tenant, 'user_id' => $user, 'task_id' => $task, 'status' => 'waiting'])->field('attempt,update_time')->select()->toArray() as $row) {
            if (time() < (int)$row['update_time'] + ((int)$row['attempt'] === 1 ? 30 : 120)) return false;
        }
        return true;
    }

    public static function retryableBeforeSubmission(string $error): bool
    {
        // These are connection establishment failures, not ambiguous read timeouts after submission.
        return (bool)preg_match('/SSL_connect:|Could not resolve host|Temporary failure in name resolution|Name or service not known|Network is unreachable|Failed to connect to|Connection refused|cURL error (?:6|7)\b/i', $error);
    }

    /** Reuse received work; uncertain interrupted calls require a separately confirmed successor. */
    public static function prepareExplicitRetry(int $tenant, int $user, string $task, bool $confirmInterrupted = false, bool $checkOnly = false, bool $newContext = false): void
    {
        $lock = '';
        try {
            Db::transaction(static function () use ($tenant, $user, $task, $confirmInterrupted, $checkOnly, $newContext, &$lock): void {
                self::prepareRetry($tenant, $user, $task, $confirmInterrupted, $lock, $checkOnly, $newContext);
            });
        } finally {
            if ($lock !== '') Db::query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private static function prepareRetry(int $tenant, int $user, string $task, bool $confirmInterrupted, string &$lock, bool $checkOnly, bool $newContext): void
    {
        $scope = ['tenant_id' => $tenant, 'user_id' => $user, 'task_id' => $task];
        $rows = Db::name('aigc_short_drama_planning_unit')->where($scope)->lock(true)->select()->toArray();
        $latest = [];
        foreach ($rows as $row) {
            $saved = json_decode((string)$row['request_json'], true) ?: [];
            $root = (string)($saved['_unit_root'] ?? $row['unit_key']);
            if (!isset($latest[$root]) || (int)$row['id'] > (int)$latest[$root]['id']) $latest[$root] = $row;
        }
        $interrupted = [];
        foreach ($latest as $root => $row) {
            if ($row['status'] === 'running') {
                $owner = Db::name('aigc_short_drama_script_task')->where($scope + ['delete_time'=>0])->find();
                if (!$owner || !in_array($owner['status'], ['failed','canceled'], true)
                    || (int)$row['update_time'] <= 0 || time() - (int)$row['update_time'] < self::INTERRUPTED_SECONDS) {
                    throw new RuntimeException('上次模型请求仍在收尾，请稍后再试');
                }
                if ($lock === '') {
                    $episode = Db::name('aigc_short_drama_episode_task')->where(['tenant_id'=>$tenant,'user_id'=>$user,
                        'production_project_id'=>$owner['project_id'],'task_id'=>$task,'delete_time'=>0])->find();
                    $candidate = $episode ? 'short-drama-series:' . $tenant . ':' . $episode['project_id']
                        : substr('sd_plan:' . hash('sha256', $tenant . ':' . $task), 0, 64);
                    if ((int)(Db::query('SELECT GET_LOCK(?, 0) AS acquired', [$candidate])[0]['acquired'] ?? 0) !== 1) {
                        throw new RuntimeException('上次模型请求仍在收尾，请稍后再试');
                    }
                    $lock = $candidate;
                }
                $interrupted[$root] = true;
            }
            if ($checkOnly || !in_array($row['status'], ['failed', 'waiting', 'pending'], true)) continue;
            if ((int)$row['attempt'] >= 3 && !self::retryableBeforeSubmission((string)($row['error'] ?? ''))) {
                throw new RuntimeException('上次模型请求的结果无法确认，不能安全重试；请新建任务并核对已完成内容');
            }
        }
        if ($interrupted && !$confirmInterrupted) throw new RuntimeException($newContext ? self::CHANGED_CONTEXT_CONFIRMATION : self::INTERRUPTED_CONFIRMATION, 409);
        if ($checkOnly) return;
        foreach ($latest as $root => $row) {
            $restartInterrupted = isset($interrupted[$root]);
            if (!$restartInterrupted && ($row['status'] !== 'received' || !str_starts_with((string)($row['error'] ?? ''), 'validation:'))) continue;
            $keys = self::recoveryKeys($root);
            $index = array_search($row['unit_key'], $keys, true);
            if ($index === false || !isset($keys[$index + 1])) throw new RuntimeException('该部分多次修复仍未通过，请调整对应场景后新建版本；已有内容保留', 409);
            $saved = json_decode((string)$row['request_json'], true, 512, JSON_THROW_ON_ERROR);
            $saved['_unit_root'] = $root;
            if ($restartInterrupted) {
                // Keep the uncertain original row and its billing evidence immutable.
                // Only the user-confirmed successor may submit a fresh paid request.
                $saved['_interrupted_retry_of'] = (int)$row['id'];
            } else $saved['_repair_reason'] = substr((string)$row['error'], strlen('validation:'));
            Db::name('aigc_short_drama_planning_unit')->insert($scope + ['unit_key'=>$keys[$index + 1], 'status'=>'pending', 'attempt'=>0,
                'request_json'=>json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'create_time'=>time(),'update_time'=>time()]);
        }
        foreach ($latest as $row) {
            if (!in_array($row['status'], ['failed', 'waiting', 'pending'], true)) continue;
            $update = ['status' => 'pending', 'error' => '', 'update_time' => time()];
            if ((int)$row['attempt'] >= 3) $update['attempt'] = 0;
            Db::name('aigc_short_drama_planning_unit')->where($scope)->where('id', (int)$row['id'])->update($update);
        }
    }

    private static function recoveryKeys(string $key): array
    {
        $keys = [$key];
        for ($i = 1; $i <= 3; $i++) $keys[] = substr($key, 0, 70) . '_retry_' . $i . '_' . substr(hash('sha256', $key), 0, 8);
        return $keys;
    }

    /** Validation failures never erase a paid receipt. Only an explicit retry creates a repair unit. */
    public static function validate(array $receipt, callable $validate): mixed
    {
        try { return $validate(); }
        catch (RuntimeException $error) {
            if (!empty($receipt['_unit']) && in_array($error->getCode(), [409,413,422], true)) {
                Db::name('aigc_short_drama_planning_unit')->where($receipt['_unit'])->where('status','received')
                    ->update(['error'=>'validation:' . mb_substr($error->getMessage(), 0, 2000), 'update_time'=>time()]);
            }
            throw $error;
        }
    }

    public static function supersede(array $receipt): void
    {
        if (empty($receipt['_unit'])) return;
        Db::name('aigc_short_drama_planning_unit')->where($receipt['_unit'])->where('status','received')
            ->whereLike('error','validation:%')->update(['error'=>'superseded:continued with a smaller or repaired unit']);
    }

    public static function call(int $tenant, int $user, string $task, string $key, array $input, callable $generate, bool $trackValidation = false): array
    {
        if ($task === '') return $generate($input); // Pure provider contract tests have no persisted task.
        ShortDramaWorkerMemory::release();
        $root = $key;
        $latestKey = Db::name('aigc_short_drama_planning_unit')->where(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task])
            ->whereIn('unit_key', self::recoveryKeys($key))->order('id','desc')->value('unit_key');
        if ($latestKey) $key = $latestKey;
        $scope = ['tenant_id' => $tenant, 'user_id' => $user, 'task_id' => $task, 'unit_key' => $key];
        // Serialize the claim only. Never hold a DB lock across the paid call.
        $claim = Db::transaction(static function () use ($scope, $input, $tenant, $user, $task, $key, $root): array {
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
            if ($row && (int)$row['attempt'] >= 3) throw new RuntimeException('模型生成单元的请求次数已用完，请新建任务并核对已完成内容');
            $storedInput = $input;
            if ($key !== $root) {
                $storedInput['_unit_root'] = $root;
                $storedInput['_repair_reason'] = (string)($saved['_repair_reason'] ?? '');
                if (!empty($saved['_interrupted_retry_of'])) $storedInput['_interrupted_retry_of'] = (int)$saved['_interrupted_retry_of'];
            }
            if (str_starts_with($key, 'v3_')) $storedInput['_unit_signature'] = $signature;
            $data = ['status' => 'running', 'attempt' => (int)($row['attempt'] ?? 0) + 1,
                'request_json' => json_encode($storedInput, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'update_time' => time()];
            if ($row) Db::name('aigc_short_drama_planning_unit')->where($scope)->update($data);
            else Db::name('aigc_short_drama_planning_unit')->insert($scope + $data + ['create_time' => time()]);
            $effective = $input;
            if (!empty($storedInput['_repair_reason'])) $effective['content'] = (string)($effective['content'] ?? '')
                . "\n这是用户要求继续修复的同一部分。只修复以下校验问题，保留原任务事实和其他已完成部分：" . $storedInput['_repair_reason'];
            return ['data' => $data, 'input' => $effective];
        });
        if (isset($claim['result'])) return $claim['result'] + ($trackValidation ? ['_unit'=>$scope, '_replayed'=>true] : []);
        $data = $claim['data'];
        try {
            $result = $generate($claim['input']);
            Db::name('aigc_short_drama_planning_unit')->where($scope)->update(['status' => 'received', 'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'update_time' => time()]);
            return $result + ($trackValidation ? ['_unit'=>$scope] : []);
        } catch (\Throwable $error) {
            $retry = self::retryableBeforeSubmission($error->getMessage()) && $data['attempt'] < 3;
            Db::name('aigc_short_drama_planning_unit')->where($scope)->update(['status' => $retry ? 'waiting' : 'failed', 'error' => $error->getMessage(), 'update_time' => time()]);
            if ($retry) throw new RuntimeException('模型连接暂时不可用，将在 ' . ($data['attempt'] === 1 ? 30 : 120) . ' 秒后重试，已完成部分保留', 425, $error);
            throw $error;
        }
    }
}
