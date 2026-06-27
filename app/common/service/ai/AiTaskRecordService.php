<?php

namespace app\common\service\ai;

use app\common\service\FileService;
use think\facade\Db;

class AiTaskRecordService
{
    private const APP_NAMES = [
        'aigc_image' => 'AIGC生图',
        'aigc_video' => 'AIGC视频',
        'aigc_digital_human' => '数字人视频',
        'image_human' => '全驱数字人',
        'smart_clip' => 'AI视频剪辑',
        'aigc_product_image' => 'AI商品图',
        'aigc_style_transfer' => '图片换风格',
        'aigc_photo_restore' => '老照片修复',
        'aigc_model_wear' => 'AI模特换装',
        'aigc_background_removal' => '智能去背景',
        'aigc_image_translate' => '图片翻译',
        'aigc_one_click_cleanup' => '一键清理',
        'aigc_product_suite' => 'AI商品套图',
        'aigc_product_multi_angle' => '商品多角度图',
        'aigc_fashion_lookbook' => '服饰 Lookbook',
        'aigc_product_promo_video' => '产品宣传视频',
        'aigc_outpaint' => '无缝扩图',
        'aigc_local_redraw' => '局部重绘',
        'aigc_fitting' => 'AI试衣',
        'aigc_hairstyle' => 'AI换发型',
    ];

    private const IMAGE_SOURCE_TABLES = [
        ['app_code' => 'aigc_product_image', 'table' => 'aigc_product_image_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_style_transfer', 'table' => 'aigc_style_transfer_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_photo_restore', 'table' => 'aigc_photo_restore_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_model_wear', 'table' => 'aigc_model_wear_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_background_removal', 'table' => 'aigc_background_removal_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_image_translate', 'table' => 'aigc_image_translate_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_one_click_cleanup', 'table' => 'aigc_one_click_cleanup_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_product_suite', 'table' => 'aigc_product_suite_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_product_multi_angle', 'table' => 'aigc_product_multi_angle_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_fashion_lookbook', 'table' => 'aigc_fashion_lookbook_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_outpaint', 'table' => 'aigc_outpaint_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_local_redraw', 'table' => 'aigc_local_redraw_task', 'field' => 'image_task_ids'],
        ['app_code' => 'aigc_fitting', 'table' => 'aigc_fitting_task', 'field' => 'image_task_ids'],
    ];

    private const VIDEO_SOURCE_TABLES = [
        ['app_code' => 'aigc_product_promo_video', 'table' => 'aigc_product_promo_video_task', 'field' => 'video_task_id'],
    ];

    private const BASE_TASK_SOURCES = [
        'aigc_image' => [
            'table' => 'aigc_image_task',
            'task_type' => 'image_generate',
            'media_type' => 'image',
            'prompt_fields' => ['prompt'],
        ],
        'aigc_video' => [
            'table' => 'aigc_video_task',
            'task_type' => 'video_generate',
            'media_type' => 'video',
            'prompt_fields' => ['prompt'],
        ],
        'aigc_digital_human' => [
            'table' => 'aigc_digital_human_task',
            'task_type' => 'digital_human_generate',
            'media_type' => 'video',
            'prompt_fields' => ['title', 'script_text', 'prompt'],
        ],
        'image_human' => [
            'table' => 'image_human_task',
            'task_type' => 'image_human_generate',
            'media_type' => 'video',
            'prompt_fields' => ['title', 'script_text', 'prompt'],
        ],
    ];

    public static function lists(array $params, int $tenantId = 0): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, (int)($params['page_size'] ?? 15));
        $fetchLimit = $pageNo * $pageSize;
        $count = 0;
        $rows = [];

        foreach (self::BASE_TASK_SOURCES as $baseAppCode => $sourceConfig) {
            if (!self::tableExists((string)$sourceConfig['table'])) {
                continue;
            }
            $query = self::baseQuery($params, $tenantId, $baseAppCode);
            $count += (int)(clone $query)->count();
            $sourceRows = $query
                ->field(self::taskListFields($baseAppCode))
                ->order('t.create_time', 'desc')
                ->order('t.id', 'desc')
                ->limit($fetchLimit)
                ->select()
                ->toArray();
            foreach ($sourceRows as $row) {
                $rows[] = self::formatTaskRow($row, $baseAppCode, $tenantId);
            }
        }

        usort($rows, static function (array $left, array $right): int {
            return ((int)($right['create_time'] ?? 0) <=> (int)($left['create_time'] ?? 0))
                ?: ((int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0));
        });
        $rows = array_slice($rows, ($pageNo - 1) * $pageSize, $pageSize);

        return [
            'lists' => $rows,
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
            'extend' => [],
        ];
    }

    public static function detail(int $id, int $tenantId = 0, string $baseAppCode = 'aigc_image'): array
    {
        $baseAppCode = self::normalizeBaseAppCode($baseAppCode);
        if (!self::tableExists(self::baseTaskTable($baseAppCode))) {
            return [];
        }
        $query = self::baseQuery(['id' => $id], $tenantId, $baseAppCode);
        $row = $query
            ->field(self::taskDetailFields($baseAppCode))
            ->find();
        if (empty($row)) {
            return [];
        }
        $data = is_array($row) ? $row : $row->toArray();
        self::appendComputedFields($data);
        self::normalizePromptFields($data, $baseAppCode);
        $taskTenantId = (int)($data['tenant_id'] ?? $tenantId);
        $source = self::resolveSourceApp($taskTenantId, $baseAppCode, (int)$data['id']);
        $data['source_app_code'] = $source['app_code'];
        $data['source_app_name'] = self::appName($source['app_code']);
        $data['source_task_id'] = $source['task_id'];
        $data['source_task_sn'] = $source['task_sn'];
        $data['base_app_code'] = $baseAppCode;
        $data['base_app_name'] = self::appName($baseAppCode);
        $data['app_code'] = $data['source_app_code'];
        $data['app_name'] = $data['source_app_name'];
        $data['task_type'] = self::BASE_TASK_SOURCES[$baseAppCode]['task_type'] ?? '';
        $data['task_sn'] = $baseAppCode . '_' . $data['id'];
        $data['record_key'] = $baseAppCode . ':' . $data['id'];
        $data['initiator_name'] = $data['user_nickname'] ?: ($data['user_account'] ?: ('用户#' . $data['user_id']));
        $data['request_params'] = [
            'title' => $data['title'] ?? '',
            'prompt' => $data['prompt'] ?? '',
            'script_text' => $data['script_text'] ?? '',
            'negative_prompt' => $data['negative_prompt'] ?? '',
            'style' => $data['style'] ?? '',
            'channel' => $data['channel'] ?? '',
            'quality' => $data['quality'] ?? '',
            'ratio' => $data['ratio'] ?? '',
            'duration' => $data['duration'] ?? 0,
            'quantity' => $data['quantity'] ?? 1,
            'reference_images' => self::decodeJsonValue($data['reference_images'] ?? []),
            'reference_assets' => self::decodeJsonValue($data['reference_assets'] ?? []),
            'provider_params' => self::decodeJsonValue($data['provider_params_json'] ?? []),
        ];
        $data['response_info'] = [
            'status' => $data['status'] ?? '',
            'error' => $data['error'] ?? '',
            'provider' => $data['provider'] ?? '',
            'model' => $data['model'] ?? '',
            'provider_task_id' => $data['provider_task_id'] ?? '',
            'results' => self::rawResults($baseAppCode, $taskTenantId, (int)$data['id']),
        ];
        $data['media_results'] = self::mediaResults($baseAppCode, $taskTenantId, (int)$data['id']);
        $data['result_count'] = count($data['media_results']);
        $data['user_info'] = [
            'user_id' => (int)($data['user_id'] ?? 0),
            'nickname' => $data['user_nickname'] ?? '',
            'account' => $data['user_account'] ?? '',
            'mobile' => $data['user_mobile'] ?? '',
            'display_name' => $data['initiator_name'],
        ];
        $data['upstream_tasks'] = self::upstreamTasks($taskTenantId, $baseAppCode, (int)$data['id'], $source);
        return $data;
    }

    private static function formatTaskRow(array $row, string $baseAppCode, int $tenantId = 0): array
    {
        self::appendComputedFields($row);
        self::normalizePromptFields($row, $baseAppCode);
        $taskTenantId = (int)($row['tenant_id'] ?? $tenantId);
        $taskId = (int)$row['id'];
        $row['media_results'] = self::mediaResults($baseAppCode, $taskTenantId, $taskId);
        $row['result_count'] = count($row['media_results']);
        $source = self::resolveSourceApp($taskTenantId, $baseAppCode, $taskId);
        $row['source_app_code'] = $source['app_code'];
        $row['source_app_name'] = self::appName($source['app_code']);
        $row['source_task_id'] = $source['task_id'];
        $row['source_task_sn'] = $source['task_sn'];
        $row['base_app_code'] = $baseAppCode;
        $row['base_app_name'] = self::appName($baseAppCode);
        $row['app_code'] = $row['source_app_code'];
        $row['app_name'] = $row['source_app_name'];
        $row['task_type'] = self::BASE_TASK_SOURCES[$baseAppCode]['task_type'] ?? '';
        $row['task_sn'] = $baseAppCode . '_' . $taskId;
        $row['record_key'] = $baseAppCode . ':' . $taskId;
        $row['initiator_name'] = $row['user_nickname'] ?: ($row['user_account'] ?: ('用户#' . $row['user_id']));
        return $row;
    }

    private static function appendComputedFields(array &$row): void
    {
        $row['point_estimated'] = (float)($row['user_charge_points'] ?? $row['quantity'] ?? 0);
        $row['point_actual'] = in_array((string)($row['status'] ?? ''), ['success', 'partial_failed'], true)
            ? (float)($row['user_charge_points'] ?? $row['quantity'] ?? 0)
            : 0;
        $row['create_time_text'] = self::formatTime((int)($row['create_time'] ?? 0));
        $row['finish_time_text'] = self::formatTime((int)($row['finish_time'] ?? 0));
    }

    private static function resolveSourceApp(int $tenantId, string $baseAppCode, int $taskId): array
    {
        $source = self::findMappedSource($tenantId, $taskId, $baseAppCode === 'aigc_video' ? self::VIDEO_SOURCE_TABLES : self::IMAGE_SOURCE_TABLES);
        if ($source) {
            return $source;
        }
        return [
            'app_code' => $baseAppCode,
            'task_id' => $taskId,
            'task_sn' => $baseAppCode . '_' . $taskId,
        ];
    }

    private static function findMappedSource(int $tenantId, int $taskId, array $tables): array
    {
        foreach ($tables as $item) {
            $table = (string)$item['table'];
            if (!self::tableExists($table)) {
                continue;
            }
            $query = Db::name($table)
                ->where('tenant_id', $tenantId)
                ->where('delete_time', 0);
            if ((string)$item['field'] === 'image_task_ids') {
                $row = self::findImageSourceRow($query, $table, $taskId);
            } else {
                $field = (string)$item['field'];
                if (!self::columnExists($table, $field)) {
                    continue;
                }
                $row = $query->where($field, $taskId)->field('id')->order('id', 'desc')->find();
            }
            if ($row) {
                $sourceTaskId = (int)(is_array($row) ? $row['id'] : $row->id);
                return [
                    'app_code' => (string)$item['app_code'],
                    'task_id' => $sourceTaskId,
                    'task_sn' => (string)$item['app_code'] . '_' . $sourceTaskId,
                ];
            }
        }
        return [];
    }

    private static function findImageSourceRow($query, string $table, int $taskId)
    {
        if (self::columnExists($table, 'image_task_id')) {
            $row = (clone $query)->where('image_task_id', $taskId)->field('id')->order('id', 'desc')->find();
            if ($row) {
                return $row;
            }
        }
        if (!self::columnExists($table, 'image_task_ids')) {
            return null;
        }

        $rows = (clone $query)
            ->whereRaw("`image_task_ids` REGEXP '(^|[^0-9])" . $taskId . "([^0-9]|$)'")
            ->field('id,image_task_ids')
            ->order('id', 'desc')
            ->limit(20)
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            if (self::containsTaskId($row['image_task_ids'] ?? [], $taskId)) {
                return $row;
            }
        }
        return null;
    }

    private static function containsTaskId($value, int $taskId): bool
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : preg_split('/\D+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) {
            return false;
        }
        return in_array($taskId, array_map('intval', $value), true);
    }

    private static function upstreamTasks(int $tenantId, string $baseAppCode, int $taskId, array $source): array
    {
        $rows = [[
            'app_code' => $baseAppCode,
            'app_name' => self::appName($baseAppCode),
            'task_id' => $taskId,
            'task_sn' => $baseAppCode . '_' . $taskId,
            'relation' => '实际执行任务',
        ]];
        if (($source['app_code'] ?? $baseAppCode) !== $baseAppCode) {
            array_unshift($rows, [
                'app_code' => (string)$source['app_code'],
                'app_name' => self::appName((string)$source['app_code']),
                'task_id' => (int)$source['task_id'],
                'task_sn' => (string)$source['task_sn'],
                'relation' => '来源应用任务',
            ]);
        }
        return $rows;
    }

    private static function imageResults(int $tenantId, int $taskId): array
    {
        if (!self::tableExists('aigc_image_result')) {
            return [];
        }
        $rows = Db::name('aigc_image_result')
            ->where(['tenant_id' => $tenantId, 'task_id' => $taskId])
            ->where('delete_time', 0)
            ->field('id,image_uri,storage_scope,storage_engine,storage_domain,width,height,provider_task_id,create_time')
            ->select()
            ->toArray();
        foreach ($rows as &$row) {
            $row['image_url'] = FileService::getFileUrlByStorage(
                (string)($row['image_uri'] ?? ''),
                (string)($row['storage_scope'] ?? ''),
                (string)($row['storage_engine'] ?? ''),
                (string)($row['storage_domain'] ?? '')
            );
            $row['create_time_text'] = self::formatTime((int)($row['create_time'] ?? 0));
        }
        return $rows;
    }

    private static function imageMediaResults(int $tenantId, int $taskId): array
    {
        return array_values(array_filter(array_map(static function (array $row): array {
            $url = (string)($row['image_url'] ?? '');
            if ($url === '') {
                return [];
            }
            return [
                'id' => (int)($row['id'] ?? 0),
                'type' => 'image',
                'url' => $url,
                'thumb_url' => $url,
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
                'provider_task_id' => (string)($row['provider_task_id'] ?? ''),
            ];
        }, self::imageResults($tenantId, $taskId))));
    }

    private static function videoResults(string $baseAppCode, int $tenantId, int $taskId): array
    {
        $table = self::videoResultTable($baseAppCode);
        if ($table === '' || !self::tableExists($table)) {
            return [];
        }
        $fields = ['id', 'video_uri', 'storage_scope', 'storage_engine', 'storage_domain', 'width', 'height', 'provider_task_id', 'create_time'];
        if (self::columnExists($table, 'cover_uri')) {
            $fields[] = 'cover_uri';
        }
        if (self::columnExists($table, 'duration')) {
            $fields[] = 'duration';
        }
        $rows = Db::name($table)
            ->where(['tenant_id' => $tenantId, 'task_id' => $taskId])
            ->where('delete_time', 0)
            ->field(implode(',', $fields))
            ->select()
            ->toArray();
        foreach ($rows as &$row) {
            $row['video_url'] = FileService::getFileUrlByStorage(
                (string)($row['video_uri'] ?? ''),
                (string)($row['storage_scope'] ?? ''),
                (string)($row['storage_engine'] ?? ''),
                (string)($row['storage_domain'] ?? '')
            );
            $row['cover_url'] = FileService::getFileUrlByStorage(
                (string)($row['cover_uri'] ?? ''),
                (string)($row['storage_scope'] ?? ''),
                (string)($row['storage_engine'] ?? ''),
                (string)($row['storage_domain'] ?? '')
            );
            $row['create_time_text'] = self::formatTime((int)($row['create_time'] ?? 0));
        }
        return $rows;
    }

    private static function videoMediaResults(string $baseAppCode, int $tenantId, int $taskId): array
    {
        return array_values(array_filter(array_map(static function (array $row): array {
            $url = (string)($row['video_url'] ?? '');
            if ($url === '') {
                return [];
            }
            return [
                'id' => (int)($row['id'] ?? 0),
                'type' => 'video',
                'url' => $url,
                'thumb_url' => (string)($row['cover_url'] ?? ''),
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
                'duration' => (float)($row['duration'] ?? 0),
                'provider_task_id' => (string)($row['provider_task_id'] ?? ''),
            ];
        }, self::videoResults($baseAppCode, $tenantId, $taskId))));
    }

    private static function mediaResults(string $baseAppCode, int $tenantId, int $taskId): array
    {
        return self::baseMediaType($baseAppCode) === 'video'
            ? self::videoMediaResults($baseAppCode, $tenantId, $taskId)
            : self::imageMediaResults($tenantId, $taskId);
    }

    private static function rawResults(string $baseAppCode, int $tenantId, int $taskId): array
    {
        return self::baseMediaType($baseAppCode) === 'video'
            ? self::videoResults($baseAppCode, $tenantId, $taskId)
            : self::imageResults($tenantId, $taskId);
    }

    private static function decodeJsonValue($value)
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private static function appName(string $appCode): string
    {
        return self::APP_NAMES[$appCode] ?? $appCode;
    }

    private static function tableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        try {
            $cache[$table] = Db::name($table)->whereRaw('1=0')->count() >= 0;
        } catch (\Throwable) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }

    private static function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $cache[$key] = in_array($column, array_column(Db::query('SHOW COLUMNS FROM `la_' . $table . '`'), 'Field'), true);
        } catch (\Throwable) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }

    private static function taskListFields(string $baseAppCode): string
    {
        $table = self::baseTaskTable($baseAppCode);
        $candidateFields = [
            'id', 'tenant_id', 'user_id', 'title', 'prompt', 'script_text', 'negative_prompt', 'style',
            'channel', 'quality', 'ratio', 'duration', 'quantity', 'tenant_cost_points', 'user_charge_points',
            'provider', 'model', 'provider_task_id', 'status', 'error', 'create_time', 'update_time', 'finish_time',
        ];
        $fields = [];
        foreach ($candidateFields as $field) {
            if (self::columnExists($table, $field)) {
                $fields[] = $field;
            }
        }
        return implode(',', array_map(static fn($field) => 't.' . $field, $fields))
            . ',te.name tenant_name,te.sn tenant_sn,u.nickname user_nickname,u.account user_account,u.mobile user_mobile';
    }

    private static function taskDetailFields(string $baseAppCode): string
    {
        return self::taskListFields($baseAppCode)
            . self::optionalDetailField($baseAppCode, 'reference_images')
            . self::optionalDetailField($baseAppCode, 'reference_assets')
            . self::optionalDetailField($baseAppCode, 'provider_params_json')
            . self::optionalDetailField($baseAppCode, 'provider_payload_json');
    }

    private static function optionalDetailField(string $baseAppCode, string $field): string
    {
        $table = self::baseTaskTable($baseAppCode);
        return self::columnExists($table, $field) ? ',t.' . $field : '';
    }

    private static function baseQuery(array $params, int $tenantId = 0, string $baseAppCode = 'aigc_image')
    {
        $baseAppCode = self::normalizeBaseAppCode($baseAppCode);
        $table = self::baseTaskTable($baseAppCode);
        $query = Db::name($table)
            ->alias('t')
            ->leftJoin('tenant te', 'te.id = t.tenant_id')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->where('t.delete_time', 0);

        if ($tenantId > 0) {
            $query->where('t.tenant_id', $tenantId);
        }
        if (!empty($params['id'])) {
            $query->where('t.id', (int)$params['id']);
        }
        if (!empty($params['tenant_id']) && $tenantId <= 0) {
            $query->where('t.tenant_id', (int)$params['tenant_id']);
        }
        if (!empty($params['user_id'])) {
            $query->where('t.user_id', (int)$params['user_id']);
        }
        if (!empty($params['status'])) {
            $query->where('t.status', (string)$params['status']);
        }
        if (!empty($params['keyword'])) {
            $keyword = trim((string)$params['keyword']);
            $promptFields = array_values(array_filter(
                self::BASE_TASK_SOURCES[$baseAppCode]['prompt_fields'] ?? ['prompt'],
                static fn($field) => self::columnExists($table, $field)
            ));
            $query->where(function ($query) use ($keyword, $promptFields) {
                $hasCondition = false;
                foreach ($promptFields as $field) {
                    if ($hasCondition) {
                        $query->whereOrLike('t.' . $field, '%' . $keyword . '%');
                    } else {
                        $query->whereLike('t.' . $field, '%' . $keyword . '%');
                        $hasCondition = true;
                    }
                }
                if ($hasCondition) {
                    $query->whereOr('t.id', (int)$keyword);
                } else {
                    $query->where('t.id', (int)$keyword);
                }
                $query->whereOrLike('te.name', '%' . $keyword . '%')
                    ->whereOrLike('te.sn', '%' . $keyword . '%')
                    ->whereOrLike('u.nickname', '%' . $keyword . '%')
                    ->whereOrLike('u.account', '%' . $keyword . '%')
                    ->whereOrLike('u.mobile', '%' . $keyword . '%');
            });
        }
        if (!empty($params['create_time_start'])) {
            $query->where('t.create_time', '>=', strtotime((string)$params['create_time_start']));
        }
        if (!empty($params['create_time_end'])) {
            $query->where('t.create_time', '<=', strtotime((string)$params['create_time_end'] . ' 23:59:59'));
        }

        return $query;
    }

    private static function normalizePromptFields(array &$row, string $baseAppCode): void
    {
        if (!isset($row['prompt']) || (string)$row['prompt'] === '') {
            $row['prompt'] = (string)($row['script_text'] ?? $row['title'] ?? '');
        }
        $row['quantity'] = (int)($row['quantity'] ?? 1);
        $row['ratio'] = (string)($row['ratio'] ?? '');
    }

    private static function normalizeBaseAppCode(string $baseAppCode): string
    {
        return isset(self::BASE_TASK_SOURCES[$baseAppCode]) ? $baseAppCode : 'aigc_image';
    }

    private static function baseTaskTable(string $baseAppCode): string
    {
        return (string)(self::BASE_TASK_SOURCES[self::normalizeBaseAppCode($baseAppCode)]['table'] ?? 'aigc_image_task');
    }

    private static function baseMediaType(string $baseAppCode): string
    {
        return (string)(self::BASE_TASK_SOURCES[self::normalizeBaseAppCode($baseAppCode)]['media_type'] ?? 'image');
    }

    private static function videoResultTable(string $baseAppCode): string
    {
        return match (self::normalizeBaseAppCode($baseAppCode)) {
            'aigc_video' => 'aigc_video_result',
            'aigc_digital_human' => 'aigc_digital_human_result',
            'image_human' => 'image_human_result',
            default => '',
        };
    }

    private static function formatTime(int $time): string
    {
        return $time > 0 ? date('Y-m-d H:i:s', $time) : '';
    }
}
