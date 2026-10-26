<?php
namespace app\common\service\app\aigc_short_drama;

use think\facade\Db;

/** State-only reconciliation. Never calls providers, billing, or rewrites project content. */
final class ShortDramaPlanningState
{
    public const LEGACY_WAIT_SECONDS = 86400;
    public const LEGACY_WAIT_ERROR = '旧版策划任务长时间未启动，已停止等待；原项目保留，请确认后手动重试';
    private const ACTIVE = ['pending', 'queued', 'running'];
    private const TERMINAL = ['success', 'failed', 'canceled'];

    public static function errorCode($error): string
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : (string)$error;
        $code = $error instanceof \Throwable ? (int)$error->getCode() : 0;
        if ($message === self::LEGACY_WAIT_ERROR) return 'legacy_queue_expired';
        if (preg_match('/SQLSTATE|PDOException|database|数据库|Data too long|Duplicate entry/i', $message)) return 'persistence_failed';
        if (preg_match('/undefined method|undefined function|TypeError|ArgumentCountError|must be of type/i', $message)
            || $error instanceof \Error) return 'internal_failed';
        if (preg_match('/请.*配置.*模型|模型.*未配置|文本模型配置|模型配置.*无效|模型不存在/', $message)) return 'model_config_invalid';
        if (preg_match('/SSL_|cURL|Connection refused|Failed to connect|Could not resolve|Network is unreachable|网络请求失败|连接.*超时|请求超时/i', $message)) return 'provider_network_failed';
        if (preg_match('/model_not_found|上游.*模型|上游.*不可用/i', $message)) return 'provider_model_failed';
        if (preg_match('/回复未完整结束|输出达到模型长度上限|finish_reason|模型未返回内容/i', $message)) return 'provider_output_incomplete';
        if (preg_match('/积分不足|余额不足|扣费失败|结算失败|点数不足/', $message)) return 'billing_failed';
        if (preg_match('/请求在返回前中断|长时间未更新|进程.*中断/', $message)) return 'execution_interrupted';
        if (preg_match('/容量不足|预算.*上限|自动处理上限/', $message)) return 'generation_limit_reached';
        if (preg_match('/请输入|上传.*为空|输入.*为空/', $message)) return 'input_invalid';
        if (preg_match('/JSON|解析|格式不正确/i', $message)) return 'parse_failed';
        if (in_array($code, [409, 422], true) || preg_match('/时长|对白|骨架|引用|证据|必须|无效|缺少|不一致|未通过|结果不完整|编号|上下文已变化/', $message)) return 'validation_failed';
        if (preg_match('/上游|供应商|provider|HTTP\s*[45]\d\d/i', $message)) return 'provider_failed';
        return 'internal_failed';
    }

    public static function legacyWaitExpired(array $task, array $request, int $now): bool
    {
        $last = max((int)($task['create_time'] ?? 0), (int)($task['update_time'] ?? 0));
        return in_array($task['status'] ?? '', ['pending', 'queued'], true)
            && (int)($request['_generation_version'] ?? 0) < 3
            && !ShortDramaStoryWorkflow::workerOwned($request)
            && empty($request['multi_episode']) && (int)($request['episode_count'] ?? 1) <= 1 && empty($request['episode_id'])
            && ($request['source'] ?? '') !== 'market_file_qa_parse'
            && empty($task['started_at']) && empty($task['provider_request_id']) && empty($task['provider_task_id'])
            && empty($task['app_task_id']) && !self::hasResult($task['result_json'] ?? '')
            && $last > 0 && $now - $last >= self::LEGACY_WAIT_SECONDS;
    }

    /** Locks the primary before its mirror, the same order used by explicit retry. */
    public static function reconcile(int $tenant, int $user, string $taskId, bool $apply = true, ?int $now = null): array
    {
        return Db::transaction(static function () use ($tenant, $user, $taskId, $apply, $now): array {
            $now ??= time();
            $scope = ['tenant_id'=>$tenant, 'user_id'=>$user, 'task_id'=>$taskId, 'delete_time'=>0];
            $task = Db::name('aigc_short_drama_script_task')->where($scope)->lock($apply)->find();
            if (!$task) return ['actions'=>[], 'reason'=>'not_found'];
            $request = self::json($task['request_json'] ?? '');
            if (($request['source'] ?? '') === 'market_file_qa_parse') return ['actions'=>[], 'reason'=>'file_qa_owned'];
            $generationScope = $scope + ['project_id'=>(int)$task['project_id'], 'task_type'=>'script_plan'];
            $generation = Db::name('aigc_short_drama_generation_task')->where($generationScope)->lock($apply)->find();
            $actions = [];
            $project = Db::name('aigc_short_drama_project')->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$task['project_id'],'delete_time'=>0])->find();
            if ($project && empty($project['multi_episode']) && is_array(json_decode((string)$task['request_json'], true))
                && self::legacyWaitExpired($task, $request, $now)
                && !Db::name('aigc_short_drama_episode_task')->where(['tenant_id'=>$tenant,'user_id'=>$user,'production_project_id'=>$task['project_id'],'delete_time'=>0])->count()
                && !Db::name('aigc_short_drama_planning_unit')->where(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$taskId])->count()
                && !Db::name('aigc_short_drama_asset')->where($scope)->count()
                && (!$generation || (in_array($generation['status'], ['pending','queued'], true)
                    && empty($generation['started_at']) && empty($generation['consumption_id'])
                    && empty($generation['provider_request_id']) && empty($generation['provider_task_id'])
                    && in_array($generation['billing_status'], ['', 'none'], true)
                    && (float)$generation['tenant_cost_points'] == 0 && (float)$generation['user_charge_points'] == 0
                    && !self::hasResult($generation['result_json']) && !self::hasResult($generation['output_asset_ids'])))) {
                $update = ['status'=>'failed','progress'=>0,'current_step'=>'等待已结束，请手动重试',
                    'error'=>self::LEGACY_WAIT_ERROR,'finished_at'=>$now,'update_time'=>$now];
                if ($apply) Db::name('aigc_short_drama_script_task')->where($scope)->where('status',$task['status'])->update($update);
                $task = array_replace($task, $update);
                $actions[] = 'expire_unstarted_legacy_wait';
            }
            if ($generation && in_array($task['status'], self::TERMINAL, true)) {
                $update = [];
                if (in_array($generation['status'], self::ACTIVE, true)) {
                    $update = ['status'=>$task['status'], 'progress'=>$task['status']==='success' ? 100 : 0,
                        'error_msg'=>$task['status']==='success' ? '' : (string)$task['error'],
                        'error_code'=>$task['status']==='failed' ? self::errorCode($task['error']) : ($task['status']==='canceled' ? 'canceled' : ''),
                        'finished_at'=>(int)($task['finished_at'] ?: $now)];
                    $actions[] = 'sync_terminal_status';
                } elseif ($task['status']==='failed' && $generation['status']==='failed'
                    && in_array($generation['error_code'], ['', 'provider_failed'], true)) {
                    $code = self::errorCode($task['error']);
                    if ($code !== $generation['error_code']) { $update['error_code']=$code; $actions[]='classify_failure'; }
                }
                if ($update && $apply) Db::name('aigc_short_drama_generation_task')->where($generationScope)
                    ->where('status',$generation['status'])->update($update + ['update_time'=>$now]);
            }
            return ['task_id'=>$taskId,'status'=>$task['status'],'actions'=>$actions,'applied'=>$apply];
        });
    }

    /** Primary and mirror fail atomically; completed/canceled or newer attempts are fenced. */
    public static function failExecution(int $tenant, int $user, string $taskId, \Throwable $error, string $message, ?int $attempt = null, string $providerRequestId = ''): bool
    {
        return Db::transaction(static function () use ($tenant, $user, $taskId, $error, $message, $attempt, $providerRequestId): bool {
            $scope=['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$taskId,'delete_time'=>0];
            $task=Db::name('aigc_short_drama_script_task')->where($scope)->lock(true)->find();
            if (!$task || !in_array($task['status'],self::ACTIVE,true)
                || ($attempt !== null && (int)$task['retry_count'] !== $attempt)) return false;
            Db::name('aigc_short_drama_script_task')->where($scope)->update(['status'=>'failed','progress'=>0,
                'current_step'=>'剧本策划失败','error'=>$message,'finished_at'=>time(),'update_time'=>time()]);
            Db::name('aigc_short_drama_generation_task')->where($scope + ['project_id'=>(int)$task['project_id'],'task_type'=>'script_plan'])
                ->whereIn('status',self::ACTIVE)->update(['status'=>'failed','progress'=>0,'error_code'=>self::errorCode($error),
                    'error_msg'=>$message,'finished_at'=>time(),'update_time'=>time()]
                    + ($providerRequestId !== '' ? ['provider_request_id'=>$providerRequestId] : []));
            return true;
        });
    }

    public static function failSetup(int $tenant, int $user, string $taskId, \Throwable $error): void
    {
        self::failExecution($tenant,$user,$taskId,$error,'准备生成失败，请检查文本模型配置及数据库升级；已完成部分保留');
    }

    /** Only an explicit user retry can reactivate a terminal mirror. Billing stays untouched. */
    public static function explicitRetry(int $tenant, int $user, string $taskId, int $projectId): void
    {
        Db::name('aigc_short_drama_generation_task')->where(['tenant_id'=>$tenant,'user_id'=>$user,
            'project_id'=>$projectId,'task_id'=>$taskId,'task_type'=>'script_plan','delete_time'=>0])
            ->whereIn('status',['failed','canceled'])->update(['status'=>'pending','progress'=>0,
                'error_code'=>'','error_msg'=>'','finished_at'=>0,'update_time'=>time()]);
    }

    private static function hasResult($value): bool
    {
        return !in_array(trim((string)$value), ['', '{}', '[]', 'null'], true);
    }

    private static function json($value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
