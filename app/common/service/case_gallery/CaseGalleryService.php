<?php

namespace app\common\service\case_gallery;

use app\common\model\app\App;
use app\common\model\app\AppCase;
use app\common\model\app\TenantApp;
use app\common\service\app\AppCaseService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_video\AigcVideoService;
use Exception;

class CaseGalleryService
{
    public const APP_CODES = [
        AigcImageService::APP_CODE,
        AigcVideoService::APP_CODE,
        AigcDigitalHumanService::APP_CODE,
        'image_human',
    ];

    public const APP_NAMES = [
        AigcImageService::APP_CODE => 'AIGC生图',
        AigcVideoService::APP_CODE => 'AIGC视频',
        AigcDigitalHumanService::APP_CODE => '数字人',
        'image_human' => '全驱动数字人',
    ];

    public static function appOptions(int $tenantId = 0): array
    {
        $appCodes = $tenantId > 0 ? self::caseAppCodes($tenantId) : self::APP_CODES;
        $names = self::appNameMap($appCodes);
        return array_map(static fn(string $appCode) => [
            'app_code' => $appCode,
            'name' => $names[$appCode] ?? self::APP_NAMES[$appCode] ?? $appCode,
        ], $appCodes);
    }

    public static function lists(int $tenantId, array $params = [], bool $onlyEnabled = false): array
    {
        return AppCaseService::listsByAppCodes($tenantId, self::filterAppCodes($tenantId, $params), $params, $onlyEnabled);
    }

    public static function listsByAppCodes(int $tenantId, array $appCodes, array $params = [], bool $onlyEnabled = false): array
    {
        return AppCaseService::listsByAppCodes($tenantId, self::normalizeAppCodes($appCodes), $params, $onlyEnabled);
    }

    public static function detail(int $tenantId, int $id): array
    {
        return AppCaseService::detailByAppCodes($tenantId, self::caseAppCodes($tenantId), $id);
    }

    public static function detailByAppCodes(int $tenantId, array $appCodes, int $id): array
    {
        return AppCaseService::detailByAppCodes($tenantId, self::normalizeAppCodes($appCodes), $id);
    }

    public static function save(int $tenantId, array $params): array
    {
        $appCode = self::resolveAppCode($tenantId, $params);
        return AppCaseService::save($tenantId, $appCode, $params);
    }

    public static function saveByAppCodes(int $tenantId, array $appCodes, array $params, string $defaultAppCode): array
    {
        $appCode = trim((string)($params['app_code'] ?? '')) ?: $defaultAppCode;
        if (!in_array($appCode, self::normalizeAppCodes($appCodes), true)) {
            throw new Exception('案例所属应用不支持');
        }
        return self::save($tenantId, ['app_code' => $appCode] + $params);
    }

    public static function fromTask(int $tenantId, int $taskId, array $params): array
    {
        $appCode = self::resolveAppCode($tenantId, $params);
        return match ($appCode) {
            AigcImageService::APP_CODE => AigcImageService::saveCaseFromTask($tenantId, $taskId, $params),
            AigcVideoService::APP_CODE => AigcVideoService::saveCaseFromTask($tenantId, $taskId, $params),
            AigcDigitalHumanService::APP_CODE => AigcDigitalHumanService::saveCaseFromTask($tenantId, $taskId, $params),
            default => throw new Exception('该应用暂不支持从任务加入案例'),
        };
    }

    public static function setStatus(int $tenantId, int $id, int $status): void
    {
        AppCaseService::setStatusByAppCodes($tenantId, self::caseAppCodes($tenantId), $id, $status);
    }

    public static function setStatusByAppCodes(int $tenantId, array $appCodes, int $id, int $status): void
    {
        AppCaseService::setStatusByAppCodes($tenantId, self::normalizeAppCodes($appCodes), $id, $status);
    }

    public static function delete(int $tenantId, int $id): void
    {
        AppCaseService::deleteByAppCodes($tenantId, self::caseAppCodes($tenantId), $id);
    }

    public static function deleteByAppCodes(int $tenantId, array $appCodes, int $id): void
    {
        AppCaseService::deleteByAppCodes($tenantId, self::normalizeAppCodes($appCodes), $id);
    }

    private static function filterAppCodes(int $tenantId, array $params): array
    {
        $appCode = trim((string)($params['app_code'] ?? ''));
        if ($appCode === '') {
            return self::caseAppCodes($tenantId);
        }
        return [$appCode];
    }

    private static function resolveAppCode(int $tenantId, array $params): string
    {
        $appCode = trim((string)($params['app_code'] ?? AigcImageService::APP_CODE));
        if (!in_array($appCode, self::caseAppCodes($tenantId), true)) {
            throw new Exception('案例所属应用不支持');
        }
        AppRegistryService::assertValidCode($appCode);
        return $appCode;
    }

    private static function normalizeAppCodes(array $appCodes): array
    {
        $codes = array_values(array_filter(array_map(static fn($appCode) => trim((string)$appCode), $appCodes)));
        if (empty($codes)) {
            throw new Exception('案例应用未配置');
        }
        return $codes;
    }

    private static function caseAppCodes(int $tenantId): array
    {
        $codes = $tenantId > 0
            ? AppCase::where(['tenant_id' => $tenantId, 'delete_time' => 0])
                ->group('app_code')
                ->column('app_code')
            : [];
        if ($tenantId > 0) {
            $codes = array_merge($codes, TenantApp::where([
                'tenant_id' => $tenantId,
                'buy_status' => 'paid',
                'shelf_status' => 'on',
                'enable_status' => 'enabled',
            ])->column('app_code'));
        }
        $codes = array_values(array_unique(array_filter(
            array_map(static fn($appCode) => trim((string)$appCode), array_merge(self::APP_CODES, $codes)),
            static fn(string $appCode) => $appCode !== '' && $appCode !== 'system_default'
        )));
        return $codes;
    }

    private static function appNameMap(array $appCodes): array
    {
        $codes = array_values(array_filter(array_map('strval', $appCodes)));
        if (empty($codes)) {
            return self::APP_NAMES;
        }
        $names = App::whereIn('code', $codes)->column('name', 'code');
        foreach (self::APP_NAMES as $appCode => $name) {
            $names[$appCode] = $names[$appCode] ?? $name;
        }
        return $names;
    }
}
