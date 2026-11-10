<?php

namespace app\common\service\app;

use app\common\model\app\AppCase;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanResult;
use app\common\model\app\aigc_video\AigcVideoResult;
use app\common\service\FileService;
use app\common\service\case_gallery\CaseGenerationService;
use Exception;
use app\common\service\app\aigc_product_video\ProductVideoBuiltins;
use app\common\service\app\aigc_product_video\ProductVideoService;

class AppCaseService
{
    public const MEDIA_IMAGE = 'image';
    public const MEDIA_VIDEO = 'video';

    public static function lists(int $tenantId, string $appCode, array $params = [], bool $onlyEnabled = false): array
    {
        AppRegistryService::assertValidCode($appCode);
        $query = AppCase::where([
            'app_code' => $appCode,
            'delete_time' => 0,
        ]);
        self::caseScope($query, $tenantId, [$appCode], $onlyEnabled);
        self::withoutDefaultSeedCases($query);

        $mediaType = (string)($params['media_type'] ?? '');
        if (in_array($mediaType, [self::MEDIA_IMAGE, self::MEDIA_VIDEO], true)) {
            $query->where('media_type', $mediaType);
        }

        if ($onlyEnabled) {
            $query->where('status', 1);
        } elseif (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }

        return self::paginateCaseQuery($query->order(['sort' => 'desc', 'id' => 'desc']), $params);
    }

    public static function listsByAppCodes(int $tenantId, array $appCodes, array $params = [], bool $onlyEnabled = false): array
    {
        $appCodes = self::normalizeAllowedAppCodes($appCodes);
        $query = AppCase::where('delete_time', 0)
            ->whereIn('app_code', $appCodes);
        self::caseScope($query, $tenantId, $appCodes, $onlyEnabled);
        self::withoutDefaultSeedCases($query);

        $appCode = trim((string)($params['app_code'] ?? ''));
        if ($appCode !== '' && in_array($appCode, $appCodes, true)) {
            $query->where('app_code', $appCode);
        }

        $mediaType = (string)($params['media_type'] ?? '');
        if (in_array($mediaType, [self::MEDIA_IMAGE, self::MEDIA_VIDEO], true)) {
            $query->where('media_type', $mediaType);
        }

        if ($onlyEnabled) {
            $query->where('status', 1);
        } elseif (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', (int)$params['status']);
        }

        return self::paginateCaseQuery($query->order(['sort' => 'desc', 'id' => 'desc']), $params);
    }

    /** Only the enabled public gallery may cross into explicitly published platform cases. */
    private static function caseScope($query, int $tenantId, array $codes, bool $public): void
    {
        $ids = [];
        if ($public && in_array('aigc_product_video', $codes, true)
            && AppAccessService::tenantCanUse($tenantId, 'aigc_product_video', false)
            && (int)ProductVideoService::settings($tenantId)['status'] === 1) {
            ProductVideoBuiltins::sync();
            $ids = ProductVideoBuiltins::ids();
        }
        $query->where(static function ($scope) use ($tenantId, $ids) {
            $scope->where('tenant_id', $tenantId);
            if ($ids) $scope->whereOr(static function ($shared) use ($ids) {
                $shared->where('tenant_id', 0)->where('app_code', 'aigc_product_video')->whereIn('id', $ids)->where('status', 1);
            });
        });
    }

    private static function assertWritable(AppCase $row): void
    {
        if ($row['app_code'] === 'aigc_product_video' && !ProductVideoBuiltins::writing() && ProductVideoBuiltins::info((int)$row['id'])) {
            throw new Exception('请在商品视频灵感模板管理中维护平台内置模板');
        }
    }

    private static function paginateCaseQuery($query, array $params): array
    {
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('title|prompt', '%' . addcslashes($keyword, '\\%_') . '%');
        }
        $usePage = isset($params['page_no']) || isset($params['page_size']);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        if ($usePage) {
            $count = (int)(clone $query)->count();
            $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
            return [
                'lists' => array_map([self::class, 'formatRow'], CaseGenerationService::recoverRows($rows)),
                'count' => $count,
                'page_no' => $pageNo,
                'page_size' => $pageSize,
            ];
        }
        $limit = max(1, min((int)($params['limit'] ?? 50), 100));
        return array_map([self::class, 'formatRow'], CaseGenerationService::recoverRows($query->limit($limit)->select()->toArray()));
    }

    public static function detail(int $tenantId, string $appCode, int $id): array
    {
        $row = self::findRow($tenantId, $appCode, $id);
        return self::formatRow(CaseGenerationService::recoverRows([$row->toArray()])[0]);
    }

    public static function detailByAppCodes(int $tenantId, array $appCodes, int $id): array
    {
        $row = self::findRowByAppCodes($tenantId, $appCodes, $id);
        return self::formatRow(CaseGenerationService::recoverRows([$row->toArray()])[0]);
    }

    public static function save(int $tenantId, string $appCode, array $params): array
    {
        $id = (int)($params['id'] ?? 0);

        if ($id > 0) {
            $row = self::findRow($tenantId, $appCode, $id);
            self::assertWritable($row);
            $data = self::buildSaveData($tenantId, $appCode, array_replace(CaseGenerationService::recoverRows([$row->toArray()])[0], $params));
            $row->save($data);
            return self::formatRow($row->toArray());
        }

        $data = self::buildSaveData($tenantId, $appCode, $params);
        $data['create_time'] = time();
        $data['delete_time'] = 0;
        $row = AppCase::create($data);
        return self::formatRow($row->toArray());
    }

    public static function saveByAppCodes(int $tenantId, array $appCodes, array $params, string $defaultAppCode): array
    {
        $appCode = trim((string)($params['app_code'] ?? '')) ?: $defaultAppCode;
        $appCodes = self::normalizeAllowedAppCodes($appCodes);
        if (!in_array($appCode, $appCodes, true)) {
            throw new Exception('案例所属应用不支持');
        }

        $id = (int)($params['id'] ?? 0);
        if ($id > 0) {
            $row = self::findRowByAppCodes($tenantId, $appCodes, $id);
            self::assertWritable($row);
            $data = self::buildSaveData($tenantId, $appCode, array_replace(CaseGenerationService::recoverRows([$row->toArray()])[0], $params));
            $row->save($data);
            return self::formatRow($row->toArray());
        }

        $data = self::buildSaveData($tenantId, $appCode, $params);
        $data['create_time'] = time();
        $data['delete_time'] = 0;
        $row = AppCase::create($data);
        return self::formatRow($row->toArray());
    }

    public static function setStatus(int $tenantId, string $appCode, int $id, int $status): void
    {
        $row = self::findRow($tenantId, $appCode, $id);
        self::assertWritable($row);
        $row->status = $status ? 1 : 0;
        $row->update_time = time();
        $row->save();
    }

    public static function setStatusByAppCodes(int $tenantId, array $appCodes, int $id, int $status): void
    {
        $row = self::findRowByAppCodes($tenantId, $appCodes, $id);
        self::assertWritable($row);
        $row->status = $status ? 1 : 0;
        $row->update_time = time();
        $row->save();
    }

    public static function delete(int $tenantId, string $appCode, int $id): void
    {
        $row = self::findRow($tenantId, $appCode, $id);
        self::assertWritable($row);
        $row->delete_time = time();
        $row->update_time = time();
        $row->save();
    }

    public static function deleteByAppCodes(int $tenantId, array $appCodes, int $id): void
    {
        $row = self::findRowByAppCodes($tenantId, $appCodes, $id);
        self::assertWritable($row);
        $row->delete_time = time();
        $row->update_time = time();
        $row->save();
    }

    private static function findRow(int $tenantId, string $appCode, int $id): AppCase
    {
        $row = AppCase::where([
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
            'id' => $id,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('案例不存在');
        }
        return $row;
    }

    private static function findRowByAppCodes(int $tenantId, array $appCodes, int $id): AppCase
    {
        $appCodes = self::normalizeAllowedAppCodes($appCodes);
        $query = AppCase::where('id', $id)->where('delete_time', 0)->whereIn('app_code', $appCodes);
        self::caseScope($query, $tenantId, $appCodes, true);
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('案例不存在');
        }
        return $row;
    }

    private static function buildSaveData(int $tenantId, string $appCode, array $params): array
    {
        AppRegistryService::assertValidCode($appCode);
        $title = trim((string)($params['title'] ?? ''));
        if ($title === '') {
            throw new Exception('请输入案例标题');
        }
        $mediaType = (string)($params['media_type'] ?? self::MEDIA_IMAGE);
        if (!in_array($mediaType, [self::MEDIA_IMAGE, self::MEDIA_VIDEO], true)) {
            throw new Exception('案例类型不支持');
        }

        $config = CaseGenerationService::config((array)($params['config_json'] ?? []));
        $request = CaseGenerationService::request(array_replace($config['generation_request'], [
            'reference_images' => $params['reference_images'] ?? $config['generation_request']['reference_images'] ?? [],
        ]));
        $config['generation_request'] = $request;
        $data = [
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
            'title' => $title,
            'prompt' => trim((string)($params['prompt'] ?? '')),
            'media_type' => $mediaType,
            'cover_uri' => self::normalizeUri((string)($params['cover_uri'] ?? $params['cover_url'] ?? '')),
            'media_uri' => self::normalizeUri((string)($params['media_uri'] ?? $params['media_url'] ?? '')),
            'reference_images' => $request['reference_images'],
            'config_json' => $config,
            'source_task_id' => (int)($params['source_task_id'] ?? 0),
            'source_result_id' => (int)($params['source_result_id'] ?? 0),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];

        if ($mediaType === self::MEDIA_IMAGE && $data['cover_uri'] === '' && $data['media_uri'] !== '') {
            $data['cover_uri'] = $data['media_uri'];
        }

        return $data;
    }

    private static function formatRow(array $row): array
    {
        if (($row['app_code']??'') === 'aigc_motion_sync') return \app\common\service\app\aigc_motion_sync\MotionSyncTemplates::galleryRow($row);
        $row = CaseGenerationService::present($row);
        $cover = (string)($row['cover_uri'] ?? '');
        $media = (string)($row['media_uri'] ?? '');
        $config = (array)($row['config_json'] ?? []);
        if ((string)($row['media_type'] ?? '') === self::MEDIA_VIDEO) {
            if ($cover !== '' && ($cover === $media || self::isVideoUri($cover))) {
                $cover = '';
            }
            $media = self::resolveVideoMediaUri($row, $media);
        }
        $row['cover_url'] = $cover !== '' ? FileService::getFileUrl($cover) : '';
        $row['media_url'] = $media !== '' ? FileService::getFileUrl($media) : $row['cover_url'];
        $row['cover_path'] = $cover;
        $row['media_path'] = $media !== '' ? $media : $cover;
        $row['config_fields'] = self::configFields($config, (string)($row['media_type'] ?? self::MEDIA_IMAGE));
        if (($row['app_code'] ?? '') === 'aigc_product_video' && isset($config['generation_request']['advanced_params']['product_video'])) return \app\common\service\app\aigc_product_video\ProductVideoTemplates::galleryRow($row);
        return $row;
    }

    private static function resolveVideoMediaUri(array $row, string $fallback): string
    {
        $tenantId = (int)($row['tenant_id'] ?? 0);
        $taskId = (int)($row['source_task_id'] ?? 0);
        $resultId = (int)($row['source_result_id'] ?? 0);
        $appCode = (string)($row['app_code'] ?? '');
        if ($tenantId <= 0) {
            return $fallback;
        }

        if ($appCode === 'aigc_video') {
            $data = self::findVideoResultRow(AigcVideoResult::class, $tenantId, $taskId, $resultId, $fallback);
            return $data ? self::videoResultUrl($data) ?: $fallback : $fallback;
        }

        if ($appCode === 'aigc_digital_human') {
            $data = self::findVideoResultRow(AigcDigitalHumanResult::class, $tenantId, $taskId, $resultId, $fallback);
            return $data ? self::videoResultUrl($data) ?: $fallback : $fallback;
        }

        return $fallback;
    }

    private static function findVideoResultRow(string $modelClass, int $tenantId, int $taskId, int $resultId, string $fallback): array
    {
        if ($resultId > 0) {
            $result = $modelClass::where(['tenant_id' => $tenantId, 'id' => $resultId])->where('delete_time', 0)->findOrEmpty();
            if (!$result->isEmpty()) {
                $data = $result->toArray();
                if (($taskId <= 0 || (int)($data['task_id'] ?? 0) === $taskId) && ((string)($data['video_uri'] ?? '') === $fallback || $fallback === '')) {
                    return $data;
                }
            }
        }

        if ($taskId <= 0) {
            return [];
        }

        $query = $modelClass::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->where('delete_time', 0);
        if ($fallback !== '') {
            $matched = (clone $query)->where('video_uri', $fallback)->findOrEmpty();
            if (!$matched->isEmpty()) {
                return $matched->toArray();
            }
        }

        $first = $query->order('id', 'asc')->findOrEmpty();
        return $first->isEmpty() ? [] : $first->toArray();
    }

    private static function videoResultUrl(array $data): string
    {
        return FileService::getFileUrlByStorage(
            (string)($data['video_uri'] ?? ''),
            (string)($data['storage_scope'] ?? ''),
            (string)($data['storage_engine'] ?? ''),
            (string)($data['storage_domain'] ?? '')
        );
    }

    private static function isVideoUri(string $uri): bool
    {
        return (bool)preg_match('/\.(mp4|mov|m4v|webm|avi|mkv|flv|3gp)(\?|#|$)/i', $uri);
    }

    private static function withoutDefaultSeedCases($query): void
    {
        $query->whereRaw("(cover_uri = '' OR cover_uri NOT LIKE 'uploads/app_case/%/default/%')")
            ->whereRaw("(media_uri = '' OR media_uri NOT LIKE 'uploads/app_case/%/default/%')");
    }

    private static function configFields(array $config, string $mediaType): array
    {
        if (!empty($config['fields']) && is_array($config['fields'])) {
            return array_values(array_filter(array_map('strval', $config['fields'])));
        }
        if ($mediaType === self::MEDIA_VIDEO) {
            return array_values(array_filter([
                (string)($config['model'] ?? ''),
                (string)($config['ratio'] ?? ''),
                (string)($config['duration'] ?? ''),
                (string)($config['quality'] ?? ''),
            ]));
        }
        return array_values(array_filter([
            (string)($config['model'] ?? $config['channel'] ?? ''),
            isset($config['quantity']) ? ((int)$config['quantity'] . '张') : '',
            (string)($config['ratio'] ?? ''),
            (string)($config['quality'] ?? $config['resolution'] ?? ''),
        ]));
    }

    private static function normalizeUri(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return '';
        }
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return FileService::setFileUrl($uri);
        }
        return ltrim($uri, '/');
    }

    private static function normalizeAllowedAppCodes(array $appCodes): array
    {
        $codes = [];
        foreach ($appCodes as $appCode) {
            $appCode = trim((string)$appCode);
            if ($appCode === '') {
                continue;
            }
            AppRegistryService::assertValidCode($appCode);
            if (!in_array($appCode, $codes, true)) {
                $codes[] = $appCode;
            }
        }
        if (empty($codes)) {
            throw new Exception('案例应用未配置');
        }
        return $codes;
    }
}
