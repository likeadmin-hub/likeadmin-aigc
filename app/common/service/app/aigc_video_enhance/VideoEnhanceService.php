<?php

declare(strict_types=1);

namespace app\common\service\app\aigc_video_enhance;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiAppTask;
use app\common\model\app\aigc_video_enhance\VideoEnhanceTask;
use app\common\service\ai\StudioMediaInputService;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\FileService;
use app\common\service\open_platform\OpenException;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\point\PointService;
use app\common\service\power\MarketGenerationMedia;
use app\common\service\power\MarketGenerationRuntime;
use app\common\service\power\MarketGenericImageAppRuntimeService as Runtime;
use app\common\service\storage\ManagedStorageReader;
use think\facade\Db;

final class VideoEnhanceService
{
    public const APP = 'aigc_video_enhance';
    private const TABLE = 'aigc_video_enhance_task';
    private const MAX_VIDEO_BYTES = 268435456;

    public static function config(int $tenant): array
    {
        $settings = VideoEnhanceConfig::settings($tenant);
        $option = VideoEnhanceConfig::selected($settings, VideoEnhanceConfig::options($tenant));
        return AppDisplayConfigService::appendToConfig($tenant, self::APP, [
            'status' => $settings['enabled'] && $option && AppAccessService::tenantCanUse($tenant, self::APP) ? 1 : 0,
            'max_seconds' => $settings['max_seconds'],
            'default_bit_rate' => $settings['default_bit_rate'],
            'allow_link' => $settings['allow_link'],
            'channel' => $option ? array_intersect_key($option, ['name' => true, 'health_24h' => true]) : null,
        ]);
    }

    public static function estimate(int $tenant, int $user, array $params): array
    {
        [$source, , $quote] = self::prepare($tenant, $user, $params);
        return [
            'duration' => $source['duration'], 'width' => $source['width'], 'height' => $source['height'],
            'user_charge_points' => $quote['user_charge_points'], 'display_points' => $quote['user_charge_points'],
            'billing_unit' => $quote['billing_unit'], 'market_sku_id' => $quote['market_sku_id'],
            'submit_block_reason' => self::deliveryBlock($source),
        ];
    }

    public static function diagnoseUpload(int $tenant, int $user, string $uri): array
    {
        $facts = self::videoFacts($tenant, $user, $uri, '', VideoEnhanceConfig::MAX_SECONDS);
        return [
            'duration' => (float)$facts['duration'],
            'storage_engine' => $facts['storage_engine'],
            'submit_block_reason' => self::deliveryBlock(['storage_engine' => $facts['storage_engine'], 'source_url' => $facts['url']]),
        ];
    }

    public static function submit(int $tenant, int $user, array $params, ?array $retry = null): array
    {
        [$source, $selection, $quote] = self::prepare($tenant, $user, $params);
        $blocked = self::deliveryBlock($source);
        if ($blocked !== '') throw new \InvalidArgumentException($blocked);
        if ($retry && (!is_numeric($retry['points']) || (float)$retry['points'] !== (float)$quote['user_charge_points'])) {
            throw new \InvalidArgumentException('预计点数已变化，请重新估价后确认');
        }
        $runtime = MarketGenerationRuntime::selection('application', $selection);
        PointService::assertCanConsumeAmounts($tenant, $user, (float)$quote['tenant_cost_points'], (float)$quote['user_charge_points']);
        $request = ['url' => $source['source_url'], 'duration' => $source['duration'], 'bit_rate' => $source['bit_rate']];
        $task = Db::transaction(static function () use ($tenant, $user, $source, $request, $runtime, $retry) {
            if ($retry) {
                $parent = self::owned($tenant, $user)->where('id', $retry['id'])->lock(true)->find();
                if (!$parent || (int)$parent['app_task_id'] !== $retry['parent'] || $retry['parent'] < 1) {
                    throw new \InvalidArgumentException('原任务记录已变化，请重新查看');
                }
                if (AiAppTask::where(['parent_task_id' => $retry['parent'], 'tenant_id' => $tenant, 'app_code' => self::APP])->count()) {
                    throw new \InvalidArgumentException('原任务已有重新提交的记录，请查看创作记录');
                }
            }
            $now = time();
            $task = VideoEnhanceTask::create($source + ['tenant_id' => $tenant, 'user_id' => $user, 'create_time' => $now, 'update_time' => $now]);
            $receipt = $runtime->reserve($tenant, $user, self::APP, 'enhance', self::TABLE, (string)$task['id'], $request, $source['duration']);
            $runtime->bind((int)$receipt['app_task_id'], (int)$task['id'], $tenant, $user);
            $task->save(['app_task_id' => $receipt['app_task_id'], 'consumption_id' => $receipt['consumption_id']]);
            if ($retry) {
                $reserved = AiConsumptionLog::where('id', $receipt['consumption_id'])->value('reserved_user_price');
                if ((float)$reserved !== (float)$retry['points']) throw new \InvalidArgumentException('预计点数已变化，请重新估价后确认');
                AiAppTask::where('id', $receipt['app_task_id'])->update(['parent_task_id' => $retry['parent']]);
                AiConsumptionEvent::create(['consumption_id' => $receipt['consumption_id'], 'event_type' => 'video_enhance_retry',
                    'event_status' => 'created', 'payload_summary' => ['source_task_id' => $retry['id'], 'admin_id' => $retry['admin']], 'create_time' => $now]);
            }
            return $task;
        });
        $consumption = (int)$task['consumption_id'];
        try {
            $runtime->submit($consumption, $request, $tenant, $user);
        } catch (\Throwable $error) {
            // The adapter holds ambiguous upstream receipts. Only an unstarted reservation can be released here.
            $state = AiConsumptionLog::where('id', $consumption)->value('run_status');
            if ($state === 'reserved') Runtime::fail($consumption, '视频处理服务暂不可用，请稍后再试', 'submit_failed');
            elseif ($state !== 'failed') throw $error;
        }
        return self::detail($tenant, $user, (int)$task['id']);
    }

    public static function lists(int $tenant, int $user, array $params): array
    {
        $page = max(1, (int)($params['page_no'] ?? 1));
        $size = min(50, max(1, (int)($params['page_size'] ?? 20)));
        $query = self::owned($tenant, $user);
        $count = (clone $query)->count();
        $tasks = $query->order('id', 'desc')->page($page, $size)->select()->toArray();
        return ['lists' => self::format($tasks, $tenant, $user), 'count' => $count, 'page_no' => $page, 'page_size' => $size];
    }

    public static function detail(int $tenant, int $user, int $id): array
    {
        $task = self::owned($tenant, $user)->where('id', $id)->find();
        if (!$task) throw new \InvalidArgumentException('任务不存在');
        return self::format([$task->toArray()], $tenant, $user)[0];
    }

    public static function delete(int $tenant, int $user, int $id): void
    {
        $task = self::detail($tenant, $user, $id);
        if (!in_array($task['status'], ['success', 'failed', 'canceled'], true)) {
            throw new \InvalidArgumentException('处理中的任务暂不能删除');
        }
        self::owned($tenant, $user)->where('id', $id)->update(['delete_time' => time()]);
    }

    private static function prepare(int $tenant, int $user, array $params): array
    {
        if ($user < 1) throw new \InvalidArgumentException('请先登录');
        $blocked = AppAccessService::assertTenantCanUse($tenant, self::APP, $user);
        if ($blocked) throw new \InvalidArgumentException((string)$blocked->getData()['msg']);
        $settings = VideoEnhanceConfig::settings($tenant);
        if (!$settings['enabled']) throw new \InvalidArgumentException('视频变清晰暂不可用');
        $option = VideoEnhanceConfig::selected($settings, VideoEnhanceConfig::options($tenant));
        if (!$option) throw new \InvalidArgumentException('当前暂无可用的视频处理通道');
        $uri = is_string($params['video_uri'] ?? null) ? trim($params['video_uri']) : '';
        $url = is_string($params['video_url'] ?? null) ? trim($params['video_url']) : '';
        if (($uri === '') === ($url === '')) throw new \InvalidArgumentException('请选择一个视频或提供一个视频直链');
        if ($url !== '' && !$settings['allow_link']) throw new \InvalidArgumentException('当前站点已关闭视频直链，请上传视频');
        $bitRate = filter_var($params['bit_rate'] ?? $settings['default_bit_rate'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        if ($bitRate === false) throw new \InvalidArgumentException('输出码率须为 1 到 20 的整数');
        $maxSeconds = (int)$settings['max_seconds'];
        $facts = self::videoFacts($tenant, $user, $uri, $url, $maxSeconds);
        if ((float)$facts['duration'] > $maxSeconds) throw new \InvalidArgumentException('请选择不超过 '.$maxSeconds.' 秒的视频');
        $duration = round((float)$facts['duration'], 4);
        $selection = [
            'upstream_app_code' => Runtime::ENHANCE_APP,
            'market_product_id' => $option['market_product_id'], 'market_sku_id' => $option['market_sku_id'],
        ];
        $source = [
            'video_uri' => $facts['uri'] ?? '', 'source_url' => $facts['url'] ?? $url, 'duration' => $duration,
            'width' => (int)$facts['width'], 'height' => (int)$facts['height'], 'bit_rate' => $bitRate,
            'storage_scope' => $facts['storage_scope'] ?? '', 'storage_engine' => $facts['storage_engine'] ?? '',
            'storage_domain' => $facts['storage_domain'] ?? '',
        ];
        return [$source, $selection, Runtime::quote($tenant, $selection, $duration)];
    }

    private static function videoFacts(int $tenant, int $user, string $uri, string $url, int $maxSeconds): array
    {
        if ($uri === '') {
            try {
                return MarketGenerationMedia::videoFacts($url);
            } catch (OpenException $error) {
                if ($error->errorType !== 'invalid_url') throw $error;
                throw new \InvalidArgumentException('请输入可从公网直接访问的视频链接', 0, $error);
            }
        }

        return StudioMediaInputService::validateVideo($tenant, $user, $uri, $maxSeconds,
            static function (array $file, array $storage) use ($tenant): string {
                try {
                    return ManagedStorageReader::read(array_replace($file, $storage), $tenant, self::MAX_VIDEO_BYTES, 60);
                } catch (\Exception $error) {
                    throw new \InvalidArgumentException('上传的视频暂时无法读取，请重新选择或联系管理员检查存储配置', 0, $error);
                }
            }
        );
    }

    private static function deliveryBlock(array $source): string
    {
        if ($source['storage_engine'] === 'local') {
            try {
                OpenSafeHttp::resolve($source['source_url'], false);
            } catch (OpenException) {
                return '处理服务无法读取本站的本地视频，请使用公网视频直链或在启用云存储后重新上传';
            }
        }
        return '';
    }

    private static function owned(int $tenant, int $user): \think\db\Query
    {
        if ($tenant < 1 || $user < 1) throw new \InvalidArgumentException('请先登录');
        return VideoEnhanceTask::where(['tenant_id' => $tenant, 'user_id' => $user, 'delete_time' => 0]);
    }

    private static function format(array $tasks, int $tenant, int $user): array
    {
        if (!$tasks) return [];
        $consumptions = AiConsumptionLog::where(['tenant_id' => $tenant, 'user_id' => $user, 'app_code' => self::APP])
            ->whereIn('id', array_column($tasks, 'consumption_id'))->select()->toArray();
        $consumptions = array_column($consumptions, null, 'id');
        foreach ($tasks as &$task) {
            $consumption = $consumptions[(int)$task['consumption_id']] ?? [];
            $status = match ($consumption['run_status'] ?? '') {
                'success' => ($consumption['billing_status'] ?? '') === 'settled' ? 'success' : 'running',
                'failed' => 'failed',
                'canceled', 'cancelled' => 'canceled',
                'submitting', 'pending_confirmation' => 'pending_confirmation',
                default => 'running',
            };
            $results = [];
            foreach ($status === 'success' ? ($consumption['response_summary']['videos'] ?? []) : [] as $video) {
                $url = FileService::getFileUrlByStorage((string)$video['video_uri'], $video['storage_scope'], $video['storage_engine'], $video['storage_domain'], $tenant);
                $results[] = ['type' => 'video', 'video_url' => $url, 'width' => (int)$video['width'], 'height' => (int)$video['height']];
            }
            $task += [
                'task_id' => (int)$task['id'], 'status' => $status, 'title' => '视频变清晰',
                'user_charge_points' => (float)($status === 'success' ? $consumption['actual_user_price'] : ($consumption['reserved_user_price'] ?? 0)),
                'error' => in_array($status, ['failed', 'pending_confirmation'], true) ? (string)($consumption['error_message'] ?? '') : '',
                'results' => $results, 'video_url' => $results[0]['video_url'] ?? '',
            ];
            unset($task['tenant_id'], $task['user_id'], $task['consumption_id'], $task['delete_time'], $task['storage_scope'], $task['storage_engine'], $task['storage_domain']);
        }
        unset($task);
        return $tasks;
    }
}
