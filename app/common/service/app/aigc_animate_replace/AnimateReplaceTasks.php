<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_animate_replace;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiTaskResultAsset;
use app\common\service\ai\AiTaskJobService as Queue;
use app\common\service\ai\MarketVideoSubmissionService;
use app\common\service\ai\UpstreamErrorMessageService;
use app\common\service\FileService;
use app\common\service\power\MarketAnimateReplace as Market;
use app\common\service\power\MarketGenerationSchema;
use app\common\service\power\MarketVideoRuntimeService as Video;
use app\common\service\SubmitLockService;
use InvalidArgumentException;
use think\facade\Db;
use think\facade\Log;
use Throwable;

final class AnimateReplaceTasks
{
    public const TABLE = 'aigc_animate_replace_task';
    public const ACTIVE = ['pending', 'running', 'pending_confirmation'];

    public static function create(int $tenant, int $user, array $params): array
    {
        $input = AnimateReplaceService::input($params);
        $key = $params['batch_key'] ?? null;
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $key)) {
            throw new InvalidArgumentException('提交标识无效，请刷新后重试');
        }
        if ($tenant <= 0 || $user <= 0) throw new InvalidArgumentException('请登录后重试');
        $lock = SubmitLockService::acquire('animate_replace_submit_'.$key, $tenant, $user);
        try {
            $owner = ['tenant_id'=>$tenant, 'user_id'=>$user, 'batch_key'=>$key];
            $existing = Db::name(self::TABLE)->where($owner)->find();
            if ($existing) {
                if (MarketGenerationSchema::array($existing['input_json']) !== $input) {
                    throw new InvalidArgumentException('提交标识已用于其他素材或分辨率');
                }
                if ($existing['delete_time'] > 0) throw new InvalidArgumentException('该创作记录已删除，请重新提交');
                return self::views([$existing], $tenant)[0];
            }
            [$assets, $request] = AnimateReplaceService::prepare($tenant, $user, $input);
            return Db::transaction(static function () use ($tenant, $user, $input, $owner, $assets, $request): array {
                $now = time();
                $id = (int)Db::name(self::TABLE)->insertGetId($owner + [
                    'input_json'=>self::encode($input),
                    'request_json'=>self::encode(['assets'=>$assets, 'request'=>$request]),
                    'duration'=>$assets['video']['duration'],
                    'create_time'=>$now,
                    'update_time'=>$now,
                ]);
                $reservation = AnimateReplaceService::withVerifiedVideo($assets, static fn() => Video::reserve(
                    $tenant, $user, Market::APP, 'video', self::TABLE, (string)$id, $request, $request
                ));
                $consumptionId = (int)$reservation['consumption_id'];
                $appTaskId = (int)$reservation['app_task_id'];
                Video::linkBusinessTask($appTaskId, $id);
                Db::name(self::TABLE)->where('id', $id)->update(['consumption_id'=>$consumptionId, 'app_task_id'=>$appTaskId]);
                Queue::enqueue(Queue::TYPE_ANIMATE_REPLACE_SUBMIT, $consumptionId);
                return self::views([self::owned($tenant, $user, $id)], $tenant)[0];
            });
        } finally {
            SubmitLockService::release($lock);
        }
    }

    public static function run(int $consumptionId): bool
    {
        $consumption = AiConsumptionLog::where(['id'=>$consumptionId, 'app_code'=>Market::APP])->find();
        if (!$consumption) return true;
        $tenant = (int)$consumption['tenant_id'];
        $user = (int)$consumption['user_id'];
        $lock = SubmitLockService::tryAcquire('animate_replace_worker_'.$consumptionId, $tenant, $user);
        if (!$lock) return false;
        try {
            $consumption->refresh();
            $row = Db::name(self::TABLE)->where([
                'tenant_id'=>$tenant, 'user_id'=>$user, 'consumption_id'=>$consumptionId,
                'app_task_id'=>$consumption['app_task_id'], 'delete_time'=>0,
            ])->find();
            if (!$row) return true;
            if ($consumption['run_status'] === 'reserved') {
                $saved = MarketGenerationSchema::array($row['request_json']);
                try {
                    $assets = AnimateReplaceService::media($tenant, $user, [
                        'image_uri'=>$saved['assets']['image']['uri'],
                        'video_uri'=>$saved['assets']['video']['uri'],
                    ]);
                    foreach (['image', 'video'] as $kind) {
                        $frozen = $saved['assets'][$kind];
                        if ($assets[$kind]['file_id'] !== $frozen['file_id']) throw new InvalidArgumentException('素材已变化');
                        if ($assets[$kind]['storage_engine'] === 'local' && !preg_match('~^https?://~i', $assets[$kind]['url'])) {
                            if (!preg_match('~^https?://~i', $frozen['url'])) throw new InvalidArgumentException('本地素材地址不可访问');
                            $assets[$kind]['url'] = $frozen['url'];
                        }
                    }
                    $request = AnimateReplaceService::withAssets($saved['request'], $assets);
                } catch (InvalidArgumentException $error) {
                    Video::fail($consumptionId, '提交前素材已失效，请重新上传', 'invalid_material');
                    $consumption->refresh();
                    self::sync($consumption->toArray(), (int)$row['id']);
                    return true;
                }
                try {
                    AnimateReplaceService::withVerifiedVideo($assets, static fn() => Video::submit($consumptionId, $request));
                } catch (Throwable $error) {
                    // The runtime writes a refund or retains the durable start/receipt before returning.
                    $consumption->refresh();
                    if ($consumption['run_status'] === 'reserved') throw $error;
                    Log::warning('Animate replace submit: '.get_class($error));
                }
                $consumption->refresh();
            }
            self::sync($consumption->toArray(), (int)$row['id']);
            if (in_array($consumption['run_status'], ['running', 'success'], true)
                && in_array($consumption['billing_status'], ['reserved', 'pending_usage'], true)) {
                Queue::enqueueQueryResult($consumptionId);
            }
            return true;
        } finally {
            SubmitLockService::release($lock);
        }
    }

    public static function sync(array $consumption, int $id): void
    {
        if (($consumption['app_code'] ?? '') !== Market::APP) return;
        $scope = [
            'id'=>$id, 'tenant_id'=>$consumption['tenant_id'], 'user_id'=>$consumption['user_id'],
            'app_task_id'=>$consumption['app_task_id'], 'consumption_id'=>$consumption['id'], 'delete_time'=>0,
        ];
        $results = [];
        if ($consumption['run_status'] === 'success' && $consumption['billing_status'] === 'settled') {
            $results = self::storedVideos([(int)$consumption['id']], (int)$consumption['tenant_id'])[$consumption['id']] ?? [];
        }
        Db::name(self::TABLE)->where($scope)->whereIn('status', self::ACTIVE)->update([
            'status'=>self::status($consumption, $results),
            'update_time'=>time(),
        ]);
    }

    public static function lists(int $tenant, int $user, array $params): array
    {
        $query = self::query($tenant, $user);
        $count = (clone $query)->count();
        $page = max(1, (int)($params['page_no'] ?? 1));
        $size = max(1, min(40, (int)($params['page_size'] ?? 20)));
        $rows = $query->order('id', 'desc')->page($page, $size)->select()->toArray();
        return ['lists'=>self::views($rows, $tenant), 'count'=>$count];
    }

    public static function detail(int $tenant, int $user, int $id): array
    {
        return self::views([self::owned($tenant, $user, $id)], $tenant)[0];
    }

    public static function delete(int $tenant, int $user, int $id): void
    {
        if ($user <= 0) throw new InvalidArgumentException('请登录后重试');
        Db::transaction(static function () use ($tenant, $user, $id): void {
            $row = self::owned($tenant, $user, $id, true);
            $view = self::views([$row], $tenant)[0];
            if (in_array($view['status'], self::ACTIVE, true)) throw new InvalidArgumentException('任务处理中，请稍后再删除');
            Db::name(self::TABLE)->where('id', $id)->update(['delete_time'=>time(), 'update_time'=>time()]);
        });
    }

    private static function query(int $tenant, int $user)
    {
        if ($tenant <= 0 || $user < 0) throw new InvalidArgumentException('租户或用户身份无效');
        $query = Db::name(self::TABLE)->where(['tenant_id'=>$tenant, 'delete_time'=>0]);
        if ($user > 0) $query->where('user_id', $user);
        return $query;
    }

    private static function owned(int $tenant, int $user, int $id, bool $lock = false): array
    {
        $row = self::query($tenant, $user)->where('id', $id)->lock($lock)->find();
        if (!$row) throw new InvalidArgumentException('创作任务不存在或无权访问');
        return $row;
    }

    private static function status(array $consumption, array $results): string
    {
        if (MarketVideoSubmissionService::pending($consumption)) return 'pending_confirmation';
        $run = $consumption['run_status'] ?? 'reserved';
        $billing = $consumption['billing_status'] ?? '';
        return match (true) {
            $billing === 'refunded' && in_array($run, ['failed', 'canceled'], true) => $run,
            $run === 'success' && $billing === 'settled' && $results !== [] => 'success',
            $run === 'reserved' => 'pending',
            default => 'running',
        };
    }

    private static function storedVideos(array $ids, int $tenant): array
    {
        $groups = [];
        foreach (AiTaskResultAsset::where(['tenant_id'=>$tenant, 'asset_type'=>'video', 'transfer_status'=>'stored'])
            ->whereIn('consumption_id', $ids)->order('id', 'asc')->select()->toArray() as $asset) {
            if (empty($asset['local_uri']) || empty($asset['storage_scope']) || empty($asset['storage_engine'])) continue;
            $groups[$asset['consumption_id']][] = $asset;
        }
        return $groups;
    }

    private static function views(array $rows, int $tenant): array
    {
        if ($rows === []) return [];
        $ids = array_column($rows, 'consumption_id');
        $consumptions = AiConsumptionLog::where(['tenant_id'=>$tenant, 'app_code'=>Market::APP])->whereIn('id', $ids)
            ->field('id,app_code,app_task_id,user_id,run_status,billing_status,error_code,error_message,reserved_user_price,actual_user_price')
            ->select()->toArray();
        $consumptions = array_column($consumptions, null, 'id');
        $results = self::storedVideos($ids, $tenant);
        return array_map(static function (array $row) use ($tenant, $consumptions, $results): array {
            $c = $consumptions[$row['consumption_id']] ?? [];
            if ((int)($c['user_id'] ?? 0) !== (int)$row['user_id'] || (int)($c['app_task_id'] ?? 0) !== (int)$row['app_task_id']) $c = [];
            $assets = array_values(array_filter($results[$row['consumption_id']] ?? [], static fn(array $asset): bool =>
                (int)$asset['user_id'] === (int)$row['user_id'] && (int)$asset['app_task_id'] === (int)$row['app_task_id']
            ));
            $status = self::status($c, $assets);
            $input = MarketGenerationSchema::array($row['input_json']);
            $references = MarketGenerationSchema::array($row['request_json'])['assets'];
            $image = $references['image'];
            $video = $references['video'];
            $assetUrl = static fn(array $file, string $uriKey = 'uri'): string => FileService::getFileUrlByStorage(
                $file[$uriKey], $file['storage_scope'], $file['storage_engine'], $file['storage_domain'], $tenant
            );
            $error = match ($status) {
                'failed' => UpstreamErrorMessageService::normalize((string)($c['error_message'] ?: '动画替换失败')),
                'pending_confirmation' => '模型受理状态待确认，请保留任务',
                default => '',
            };
            $charge = match ($c['billing_status'] ?? '') {
                'settled' => (float)$c['actual_user_price'],
                'reserved' => (float)$c['reserved_user_price'],
                default => 0.,
            };
            return [
                'id'=>(int)$row['id'], 'task_id'=>(int)$row['app_task_id'], 'user_id'=>(int)$row['user_id'],
                'status'=>$status, 'error'=>$error, 'duration'=>(float)$row['duration'],
                'create_time'=>(int)$row['create_time'], 'resolution'=>$input['resolution'], 'user_charge_points'=>$charge,
                'image_uri'=>$image['uri'], 'image_url'=>$assetUrl($image),
                'video_uri'=>$video['uri'], 'source_video_url'=>$assetUrl($video),
                'results'=>$status === 'success' ? array_map(static fn(array $asset): array => [
                    'id'=>(int)$asset['id'], 'type'=>'video', 'url'=>$assetUrl($asset, 'local_uri'),
                ], $assets) : [],
            ];
        }, $rows);
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
