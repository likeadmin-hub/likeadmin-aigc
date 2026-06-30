<?php

namespace app\common\service\app;

use app\common\model\app\App;
use app\common\model\app\AppApi;
use app\common\model\app\AppFrontendEntry;
use app\common\model\app\AppInstall;
use app\common\model\app\AppCase;
use app\common\model\app\AppVersion;
use app\common\model\app\TenantApp;
use app\common\model\app\aigc_action_transfer\AigcActionTransferConfig;
use app\common\model\app\aigc_action_transfer\AigcActionTransferResult;
use app\common\model\app\aigc_action_transfer\AigcActionTransferTask;
use app\common\model\app\aigc_person_replacement\AigcPersonReplacementConfig;
use app\common\model\app\aigc_person_replacement\AigcPersonReplacementResult;
use app\common\model\app\aigc_person_replacement\AigcPersonReplacementTask;
use app\common\model\app\aigc_image\AigcImageConfig;
use app\common\model\app\aigc_image\AigcImageBilling;
use app\common\model\app\aigc_image\AigcImageChannel;
use app\common\model\app\aigc_image\AigcImageChannelSpec;
use app\common\model\app\aigc_image\AigcImageQuota;
use app\common\model\app\aigc_image\AigcImageResult;
use app\common\model\app\aigc_image\AigcImageSensitiveWord;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\aigc_fitting\AigcFittingConfig;
use app\common\model\app\aigc_fitting\AigcFittingTask;
use app\common\model\app\aigc_hairstyle\AigcHairstyleConfig;
use app\common\model\app\aigc_product_image\AigcProductImageConfig;
use app\common\model\app\aigc_product_image\AigcProductImageResult;
use app\common\model\app\aigc_product_image\AigcProductImageSceneCategory;
use app\common\model\app\aigc_product_image\AigcProductImageSceneTemplate;
use app\common\model\app\aigc_product_image\AigcProductImageTask;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferConfig;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferResult;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferStyleCategory;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferStyleTemplate;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferTask;
use app\common\model\app\aigc_photo_restore\AigcPhotoRestoreConfig;
use app\common\model\app\aigc_photo_restore\AigcPhotoRestoreResult;
use app\common\model\app\aigc_photo_restore\AigcPhotoRestoreTask;
use app\common\model\app\aigc_photo_restore\AigcPhotoRestoreType;
use app\common\model\app\aigc_model_wear\AigcModelWearConfig;
use app\common\model\app\aigc_model_wear\AigcModelWearResult;
use app\common\model\app\aigc_model_wear\AigcModelWearTask;
use app\common\model\app\aigc_background_removal\AigcBackgroundRemovalConfig;
use app\common\model\app\aigc_background_removal\AigcBackgroundRemovalResult;
use app\common\model\app\aigc_background_removal\AigcBackgroundRemovalTask;
use app\common\model\app\aigc_image_translate\AigcImageTranslateConfig;
use app\common\model\app\aigc_image_translate\AigcImageTranslateResult;
use app\common\model\app\aigc_image_translate\AigcImageTranslateTask;
use app\common\model\app\aigc_one_click_cleanup\AigcOneClickCleanupConfig;
use app\common\model\app\aigc_one_click_cleanup\AigcOneClickCleanupOption;
use app\common\model\app\aigc_one_click_cleanup\AigcOneClickCleanupResult;
use app\common\model\app\aigc_one_click_cleanup\AigcOneClickCleanupTask;
use app\common\model\app\aigc_local_redraw\AigcLocalRedrawConfig;
use app\common\model\app\aigc_local_redraw\AigcLocalRedrawResult;
use app\common\model\app\aigc_local_redraw\AigcLocalRedrawTask;
use app\common\model\app\aigc_product_suite\AigcProductSuiteConfig;
use app\common\model\app\aigc_product_suite\AigcProductSuiteModule;
use app\common\model\app\aigc_product_suite\AigcProductSuiteResult;
use app\common\model\app\aigc_product_suite\AigcProductSuiteTask;
use app\common\model\app\aigc_product_multi_angle\AigcProductMultiAngleConfig;
use app\common\model\app\aigc_product_multi_angle\AigcProductMultiAngleResult;
use app\common\model\app\aigc_product_multi_angle\AigcProductMultiAngleTask;
use app\common\model\app\aigc_product_multi_angle\AigcProductMultiAngleView;
use app\common\model\app\aigc_product_promo_video\AigcProductPromoVideoConfig;
use app\common\model\app\aigc_product_promo_video\AigcProductPromoVideoResult;
use app\common\model\app\aigc_product_promo_video\AigcProductPromoVideoTask;
use app\common\model\app\aigc_product_promo_video\AigcProductPromoVideoType;
use app\common\model\app\aigc_video\AigcVideoConfig;
use app\common\model\app\aigc_video\AigcVideoBilling;
use app\common\model\app\aigc_video\AigcVideoChannel;
use app\common\model\app\aigc_video\AigcVideoChannelSpec;
use app\common\model\app\aigc_video\AigcVideoQuota;
use app\common\model\app\aigc_video\AigcVideoResult;
use app\common\model\app\aigc_video\AigcVideoSensitiveWord;
use app\common\model\app\aigc_video\AigcVideoTask;
use app\common\model\app\aigc_llm\AigcLlmChannel;
use app\common\model\app\aigc_llm\AigcLlmConfig;
use app\common\model\app\aigc_llm\AigcLlmMessage;
use app\common\model\app\aigc_llm\AigcLlmModel;
use app\common\model\app\aigc_llm\AigcLlmSensitiveWord;
use app\common\model\app\aigc_llm\AigcLlmSession;
use app\common\model\app\aigc_llm\AigcLlmUsage;
use app\common\model\app\aigc_canvas\AigcCanvasProject;
use app\common\model\app\aigc_canvas\AigcCanvasRun;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanAvatar;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanBilling;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanChannel;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanChannelSpec;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanConfig;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanQuota;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanResult;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanSensitiveWord;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanTask;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanVoice;
use app\common\model\app\image_human\ImageHumanBilling;
use app\common\model\app\image_human\ImageHumanAvatar;
use app\common\model\app\image_human\ImageHumanConfig;
use app\common\model\app\image_human\ImageHumanResult;
use app\common\model\app\image_human\ImageHumanTask;
use app\common\model\auth\SystemMenu;
use app\common\model\auth\TenantSystemMenu;
use app\common\model\tenant\Tenant;
use think\facade\Db;
use RuntimeException;
use Throwable;

class AppRegistryService
{
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_REMOVED = 'removed';

    public static function manifestPath(string $appCode): string
    {
        self::assertValidCode($appCode);
        return root_path() . 'app/apps/' . $appCode . '/manifest.json';
    }

    public static function getManifest(string $appCode): array
    {
        $path = self::manifestPath($appCode);
        if (!is_file($path)) {
            throw new RuntimeException('应用清单不存在: ' . $appCode);
        }
        $manifest = json_decode((string)file_get_contents($path), true);
        if (!is_array($manifest)) {
            throw new RuntimeException('应用清单格式错误: ' . $appCode);
        }
        self::assertValidCode($manifest['code'] ?? '');
        if (($manifest['code'] ?? '') !== $appCode) {
            throw new RuntimeException('应用清单code不匹配: ' . $appCode);
        }
        return $manifest;
    }

    public static function installFromLocal(string $appCode, string $coreVersion = ''): array
    {
        return self::installFromLocalWithResult($appCode, $coreVersion)['manifest'];
    }

    public static function installFromLocalWithResult(string $appCode, string $coreVersion = ''): array
    {
        $manifest = self::getManifest($appCode);
        self::assertCoreCompatible($manifest, $coreVersion);
        $migrations = self::runLocalMigrations($appCode, (string)($manifest['version'] ?? '1.0.0'));
        $time = time();
        $appData = DefaultAppService::normalizeAppData($appCode, [
            'code' => $appCode,
            'name' => $manifest['name'] ?? $appCode,
            'icon' => $manifest['icon'] ?? '',
            'description' => $manifest['description'] ?? '',
            'category' => $manifest['category'] ?? 'common',
            'cover' => $manifest['cover'] ?? '',
            'client_tags' => implode(',', $manifest['frontends'] ?? []),
            'is_builtin' => $manifest['is_builtin'] ?? 0,
            'sort' => $manifest['sort'] ?? 0,
            'current_version' => $manifest['version'] ?? '1.0.0',
            'status' => self::STATUS_INSTALLED,
            'expire_policy' => $manifest['expire_policy'] ?? AppPlanService::EXPIRE_BLOCK,
            'install_time' => $time,
            'update_time' => $time,
        ]);
        self::saveModel(App::class, ['code' => $appCode], $appData);
        self::saveModel(AppVersion::class, ['app_code' => $appCode, 'version' => $manifest['version']], [
            'app_code' => $appCode,
            'version' => $manifest['version'],
            'require_core' => $manifest['require_core'] ?? '',
            'package_path' => 'local',
            'manifest_json' => $manifest,
            'changelog' => $manifest['changelog'] ?? '',
            'status' => 1,
            'create_time' => $time,
        ]);
        self::syncApiSchema($manifest);
        self::syncFrontendEntries($manifest);
        AppMenuService::syncPlatformMenus($appCode);
        if (DefaultAppService::isDefaultApp($appCode)) {
            DefaultAppService::syncAllTenants($appCode);
        }
        self::writeInstallLog($appCode, $manifest['version'] ?? '1.0.0', 'install_success');
        return [
            'manifest' => $manifest,
            'migrations' => $migrations,
        ];
    }

    public static function assertCoreCompatible(array $manifest, string $coreVersion = ''): void
    {
        $current = $coreVersion !== '' ? $coreVersion : self::currentCoreVersion();
        $constraints = self::coreConstraints($manifest);
        if (empty($constraints)) {
            throw new RuntimeException('应用清单缺少系统版本兼容声明: require_core/min_core/max_core');
        }
        foreach ($constraints as $constraint) {
            [$operator, $version, $label] = $constraint;
            if (!version_compare($current, $version, $operator)) {
                $appCode = (string)($manifest['code'] ?? '');
                throw new RuntimeException(sprintf(
                    '当前系统版本 %s 不满足应用 %s 的系统版本要求: %s',
                    $current,
                    $appCode ?: '-',
                    $label
                ));
            }
        }
    }

    public static function detail(string $appCode): array
    {
        self::assertValidCode($appCode);
        $app = App::where('code', $appCode)->findOrEmpty();
        if ($app->isEmpty()) {
            return [
                'app' => [],
                'manifest' => is_file(self::manifestPath($appCode)) ? self::getManifest($appCode) : [],
                'versions' => [],
                'apis' => [],
                'frontend_entries' => [],
                'tenant_count' => 0,
            ];
        }
        return [
            'app' => $app->toArray(),
            'manifest' => is_file(self::manifestPath($appCode)) ? self::getManifest($appCode) : [],
            'versions' => AppVersion::where('app_code', $appCode)->order('id', 'desc')->select()->toArray(),
            'apis' => AppApi::where('app_code', $appCode)->order('id', 'asc')->select()->toArray(),
            'frontend_entries' => AppFrontendEntry::where('app_code', $appCode)
                ->order(['terminal' => 'asc', 'sort' => 'desc'])
                ->select()
                ->toArray(),
            'tenant_count' => TenantApp::where('app_code', $appCode)->count(),
        ];
    }

    public static function setStatus(string $appCode, string $status): void
    {
        self::assertValidCode($appCode);
        self::assertNotBuiltin($appCode);
        if (!in_array($status, [self::STATUS_INSTALLED, self::STATUS_DISABLED], true)) {
            throw new RuntimeException('应用状态不支持');
        }
        $app = App::where('code', $appCode)->findOrEmpty();
        if ($app->isEmpty()) {
            throw new RuntimeException('应用不存在');
        }
        $app->status = $status;
        $app->update_time = time();
        $app->save();
        self::writeInstallLog(
            $appCode,
            (string)$app['current_version'],
            $status === self::STATUS_INSTALLED ? 'enable_success' : 'disable_success'
        );
    }

    public static function uninstall(string $appCode, bool $clearData = false): void
    {
        self::assertValidCode($appCode);
        self::assertNotBuiltin($appCode);
        $app = App::where('code', $appCode)->findOrEmpty();
        if ($app->isEmpty()) {
            throw new RuntimeException('应用不存在');
        }

        $app->status = self::STATUS_REMOVED;
        $app->update_time = time();
        $app->save();

        TenantApp::where('app_code', $appCode)->update([
            'enable_status' => 'disabled',
            'shelf_status' => 'off',
            'update_time' => time(),
        ]);
        self::deleteTenantMenusForApp($appCode);
        SystemMenu::where(['source' => 'app', 'app_code' => $appCode])->delete();

        if ($clearData) {
            TenantApp::where('app_code', $appCode)->delete();
            AppApi::where('app_code', $appCode)->delete();
            AppFrontendEntry::where('app_code', $appCode)->delete();
            self::clearAppBusinessData($appCode);
        }

        self::writeInstallLog(
            $appCode,
            (string)$app['current_version'],
            $clearData ? 'uninstall_clear_success' : 'uninstall_soft_success'
        );
    }

    public static function isInstalled(string $appCode): bool
    {
        return App::where(['code' => $appCode, 'status' => self::STATUS_INSTALLED])->count() > 0;
    }

    private static function currentCoreVersion(): string
    {
        $local = function_exists('local_version') ? local_version() : [];
        $version = is_array($local) ? (string)($local['version'] ?? '') : '';
        return $version !== '' ? $version : (string)config('project.version');
    }

    private static function coreConstraints(array $manifest): array
    {
        $constraints = [];
        if (!empty($manifest['require_core'])) {
            foreach ((array)$manifest['require_core'] as $require) {
                $constraints = array_merge($constraints, self::parseCoreConstraint((string)$require));
            }
        }
        if (!empty($manifest['min_core'])) {
            $version = ltrim(trim((string)$manifest['min_core']), '>= ');
            $constraints[] = ['>=', $version, '>=' . $version];
        }
        if (!empty($manifest['max_core'])) {
            $raw = trim((string)$manifest['max_core']);
            if (preg_match('/^(<=|<)\s*(.+)$/', $raw, $match)) {
                $constraints[] = [$match[1], trim($match[2]), $match[1] . trim($match[2])];
            } else {
                $constraints[] = ['<=', $raw, '<=' . $raw];
            }
        }
        return array_values(array_filter($constraints, fn($item) => !empty($item[1])));
    }

    private static function parseCoreConstraint(string $require): array
    {
        $require = trim($require);
        if ($require === '') {
            return [];
        }
        preg_match_all('/(>=|<=|==|=|>|<)\s*([0-9][0-9A-Za-z._-]*)/', $require, $matches, PREG_SET_ORDER);
        if (empty($matches) && preg_match('/^[0-9][0-9A-Za-z._-]*$/', $require)) {
            return [['>=', $require, '>=' . $require]];
        }
        return array_map(function ($match) {
            $operator = $match[1] === '=' ? '==' : $match[1];
            return [$operator, $match[2], $match[1] . $match[2]];
        }, $matches);
    }

    public static function frontendEntries(int $tenantId, string $terminal): array
    {
        $apps = AppAccessService::enabledTenantAppCodes($tenantId);
        if (empty($apps)) {
            return [];
        }
        $entries = AppFrontendEntry::whereIn('app_code', $apps)
            ->where('terminal', $terminal)
            ->where('status', 1)
            ->order(['sort' => 'desc', 'id' => 'asc'])
            ->select()
            ->toArray();
        return self::mergeManifestFrontendEntries($entries, $apps, $terminal);
    }

    public static function assertValidCode(string $appCode): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $appCode)) {
            throw new RuntimeException('应用标识必须为小写snake_case: ' . $appCode);
        }
        if (in_array($appCode, ['core', 'platform', 'tenant', 'api', 'user'], true)) {
            throw new RuntimeException('应用标识为保留词: ' . $appCode);
        }
    }

    private static function syncFrontendEntries(array $manifest): void
    {
        $entries = $manifest['frontend_entries'] ?? [];
        foreach ($entries as $entry) {
            self::saveModel(AppFrontendEntry::class, [
                'app_code' => $manifest['code'],
                'terminal' => $entry['terminal'],
                'entry_key' => $entry['entry_key'],
            ], [
                'app_code' => $manifest['code'],
                'terminal' => $entry['terminal'],
                'entry_key' => $entry['entry_key'],
                'name' => $entry['name'] ?? $manifest['name'],
                'path' => $entry['path'] ?? '',
                'icon' => $entry['icon'] ?? '',
                'sort' => $entry['sort'] ?? 0,
                'status' => $entry['status'] ?? 1,
                'meta' => $entry['meta'] ?? [],
                'update_time' => time(),
            ]);
        }
    }

    private static function mergeManifestFrontendEntries(array $entries, array $appCodes, string $terminal): array
    {
        $existing = [];
        foreach ($entries as $entry) {
            $key = (string)($entry['app_code'] ?? '') . ':' . (string)($entry['entry_key'] ?? '');
            if ($key !== ':') {
                $existing[$key] = true;
            }
        }

        foreach ($appCodes as $appCode) {
            if (!is_file(self::manifestPath($appCode))) {
                continue;
            }
            try {
                $manifest = self::getManifest($appCode);
            } catch (Throwable) {
                continue;
            }
            foreach (($manifest['frontend_entries'] ?? []) as $entry) {
                if (($entry['terminal'] ?? '') !== $terminal || (int)($entry['status'] ?? 1) !== 1) {
                    continue;
                }
                $entryKey = (string)($entry['entry_key'] ?? '');
                $key = $appCode . ':' . $entryKey;
                if ($entryKey === '' || isset($existing[$key])) {
                    continue;
                }
                $entries[] = [
                    'id' => 0,
                    'app_code' => $appCode,
                    'terminal' => $terminal,
                    'entry_key' => $entryKey,
                    'name' => $entry['name'] ?? ($manifest['name'] ?? $appCode),
                    'path' => $entry['path'] ?? '',
                    'icon' => $entry['icon'] ?? '',
                    'sort' => (int)($entry['sort'] ?? 0),
                    'status' => 1,
                    'meta' => $entry['meta'] ?? [],
                    'create_time' => 0,
                    'update_time' => 0,
                ];
                $existing[$key] = true;
            }
        }

        usort($entries, function ($left, $right) {
            $sortCompare = (int)($right['sort'] ?? 0) <=> (int)($left['sort'] ?? 0);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }
            return (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
        });
        return array_values($entries);
    }

    private static function runLocalMigrations(string $appCode, string $version): array
    {
        $dir = root_path() . 'app/apps/' . $appCode . '/migrations';
        if (!is_dir($dir)) {
            return [];
        }
        $result = [];
        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            $migrationKey = basename($file);
            $where = [
                'scope' => 'platform',
                'app_code' => $appCode,
                'tenant_id' => 0,
                'migration_key' => $migrationKey,
            ];
            $migration = \app\common\model\app\AppMigration::where($where)->findOrEmpty();
            if (!$migration->isEmpty() && $migration['status'] === 'success') {
                $result[] = [
                    'migration_key' => $migrationKey,
                    'status' => 'skipped',
                    'error' => '',
                ];
                continue;
            }
            $data = [
                'version' => $version,
                'batch' => date('YmdHis'),
                'status' => 'running',
                'error' => '',
                'update_time' => time(),
            ];
            if ($migration->isEmpty()) {
                $migration = \app\common\model\app\AppMigration::create($where + $data + [
                    'create_time' => time(),
                ]);
            } else {
                $migration->save($data);
            }
            $content = trim((string)file_get_contents($file));
            if ($content === '') {
                $migration->save(['status' => 'success', 'update_time' => time()]);
                $result[] = [
                    'migration_key' => $migrationKey,
                    'status' => 'executed',
                    'error' => '',
                ];
                continue;
            }
            try {
                $sqlPrefix = config('database.connections.mysql.prefix');
                self::executeMigrationSql($content, $sqlPrefix);
                $migration->save(['status' => 'success', 'error' => '', 'update_time' => time()]);
                $result[] = [
                    'migration_key' => $migrationKey,
                    'status' => 'executed',
                    'error' => '',
                ];
            } catch (Throwable $e) {
                $migration->save(['status' => 'failed', 'error' => $e->getMessage(), 'update_time' => time()]);
                throw $e;
            }
        }
        return $result;
    }

    private static function executeMigrationSql(string $content, string $prefix): void
    {
        $executor = '\\app\\common\\service\\database\\SqlMigrationExecutor';
        if (!class_exists($executor)) {
            $executorPath = root_path() . 'app/common/service/database/SqlMigrationExecutor.php';
            if (is_file($executorPath)) {
                require_once $executorPath;
            }
        }
        if (class_exists($executor)) {
            $executor::execute($content, $prefix);
            return;
        }

        foreach (self::splitMigrationSql($content) as $sql) {
            $statement = str_replace('`la_', '`' . $prefix, $sql) . ';';
            try {
                Db::execute($statement);
            } catch (Throwable $e) {
                if (self::isDuplicateAddColumn($statement, $e)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    private static function splitMigrationSql(string $content): array
    {
        $statements = [];
        $statement = '';
        $length = strlen($content);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            $next = $content[$i + 1] ?? '';

            if ($quote !== null) {
                $statement .= $char;
                if ($char === '\\' && ($quote === '\'' || $quote === '"') && $next !== '') {
                    $statement .= $next;
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) {
                        $statement .= $next;
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
                $statement .= $char;
                continue;
            }
            if ($char === '-' && $next === '-' && self::isSqlCommentBoundary($content[$i + 2] ?? '')) {
                $i = self::skipSqlLineComment($content, $i + 2);
                $statement .= "\n";
                continue;
            }
            if ($char === '#') {
                $i = self::skipSqlLineComment($content, $i + 1);
                $statement .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $i = self::skipSqlBlockComment($content, $i + 2);
                $statement .= ' ';
                continue;
            }
            if ($char === ';') {
                $sql = trim($statement);
                if ($sql !== '') {
                    $statements[] = $sql;
                }
                $statement = '';
                continue;
            }
            $statement .= $char;
        }

        $sql = trim($statement);
        if ($sql !== '') {
            $statements[] = $sql;
        }
        return $statements;
    }

    private static function isSqlCommentBoundary(string $char): bool
    {
        return $char === '' || ctype_space($char);
    }

    private static function skipSqlLineComment(string $content, int $offset): int
    {
        $newline = strpos($content, "\n", $offset);
        return $newline === false ? strlen($content) : $newline;
    }

    private static function skipSqlBlockComment(string $content, int $offset): int
    {
        $end = strpos($content, '*/', $offset);
        return $end === false ? strlen($content) : $end + 1;
    }

    private static function isDuplicateAddColumn(string $statement, Throwable $e): bool
    {
        if (substr_count(strtoupper($statement), 'ADD COLUMN') !== 1) {
            return false;
        }
        return strpos($e->getMessage(), '1060') !== false
            || stripos($e->getMessage(), 'Duplicate column') !== false
            || stripos($e->getMessage(), '42S21') !== false;
    }

    private static function assertNotBuiltin(string $appCode): void
    {
        if ($appCode === 'system_default' || DefaultAppService::isDefaultApp($appCode)) {
            throw new RuntimeException('系统应用不允许禁用或卸载');
        }
    }

    private static function syncApiSchema(array $manifest): void
    {
        $path = root_path() . 'app/apps/' . $manifest['code'] . '/api_schema.json';
        if (!is_file($path)) {
            return;
        }
        $schema = json_decode((string)file_get_contents($path), true);
        if (!is_array($schema)) {
            return;
        }
        foreach (($schema['apis'] ?? []) as $api) {
            self::saveModel(AppApi::class, [
                'app_code' => $manifest['code'],
                'api_path' => $api['api_path'],
                'api_method' => strtoupper($api['api_method'] ?? 'GET'),
                'scene' => $api['scene'] ?? 'tenant_admin',
            ], [
                'app_code' => $manifest['code'],
                'api_path' => $api['api_path'],
                'api_method' => strtoupper($api['api_method'] ?? 'GET'),
                'permission_key' => $api['permission_key'] ?? '',
                'scene' => $api['scene'] ?? 'tenant_admin',
                'need_login' => $api['need_login'] ?? 1,
                'need_role_permission' => $api['need_role_permission'] ?? 1,
                'status' => $api['status'] ?? 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function clearAppBusinessData(string $appCode): void
    {
        if ($appCode === 'aigc_image') {
            AigcImageConfig::where('id', '>', 0)->delete();
            AigcImageTask::where('id', '>', 0)->delete();
            AigcImageResult::where('id', '>', 0)->delete();
            AigcImageQuota::where('id', '>', 0)->delete();
            AigcImageSensitiveWord::where('id', '>', 0)->delete();
            AigcImageChannel::where('id', '>', 0)->delete();
            AigcImageChannelSpec::where('id', '>', 0)->delete();
            AigcImageBilling::where('id', '>', 0)->delete();
            AppCase::where('app_code', $appCode)->delete();
            return;
        }
        if ($appCode === 'aigc_video') {
            AigcVideoConfig::where('id', '>', 0)->delete();
            AigcVideoTask::where('id', '>', 0)->delete();
            AigcVideoResult::where('id', '>', 0)->delete();
            AigcVideoQuota::where('id', '>', 0)->delete();
            AigcVideoSensitiveWord::where('id', '>', 0)->delete();
            AigcVideoChannel::where('id', '>', 0)->delete();
            AigcVideoChannelSpec::where('id', '>', 0)->delete();
            AigcVideoBilling::where('id', '>', 0)->delete();
            AppCase::where('app_code', $appCode)->delete();
        }
        if ($appCode === 'aigc_llm') {
            AigcLlmConfig::where('id', '>', 0)->delete();
            AigcLlmChannel::where('id', '>', 0)->delete();
            AigcLlmModel::where('id', '>', 0)->delete();
            AigcLlmSensitiveWord::where('id', '>', 0)->delete();
            AigcLlmSession::where('id', '>', 0)->delete();
            AigcLlmMessage::where('id', '>', 0)->delete();
            AigcLlmUsage::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_canvas') {
            AigcCanvasProject::where('id', '>', 0)->delete();
            AigcCanvasRun::where('id', '>', 0)->delete();
            AppCase::where('app_code', $appCode)->delete();
        }
        if ($appCode === 'aigc_digital_human') {
            AigcDigitalHumanConfig::where('id', '>', 0)->delete();
            AigcDigitalHumanTask::where('id', '>', 0)->delete();
            AigcDigitalHumanResult::where('id', '>', 0)->delete();
            AigcDigitalHumanAvatar::where('id', '>', 0)->delete();
            AigcDigitalHumanVoice::where('id', '>', 0)->delete();
            AigcDigitalHumanQuota::where('id', '>', 0)->delete();
            AigcDigitalHumanSensitiveWord::where('id', '>', 0)->delete();
            AigcDigitalHumanChannel::where('id', '>', 0)->delete();
            AigcDigitalHumanChannelSpec::where('id', '>', 0)->delete();
            AigcDigitalHumanBilling::where('id', '>', 0)->delete();
            AppCase::where('app_code', $appCode)->delete();
        }
        if ($appCode === 'image_human') {
            ImageHumanConfig::where('id', '>', 0)->delete();
            ImageHumanTask::where('id', '>', 0)->delete();
            ImageHumanResult::where('id', '>', 0)->delete();
            ImageHumanAvatar::where('id', '>', 0)->delete();
            ImageHumanBilling::where('id', '>', 0)->delete();
            AppCase::where('app_code', $appCode)->delete();
        }
        if ($appCode === 'aigc_hairstyle') {
            AigcHairstyleConfig::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_fitting') {
            AigcFittingConfig::where('id', '>', 0)->delete();
            AigcFittingTask::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_product_image') {
            AigcProductImageConfig::where('id', '>', 0)->delete();
            AigcProductImageSceneCategory::where('id', '>', 0)->delete();
            AigcProductImageSceneTemplate::where('id', '>', 0)->delete();
            AigcProductImageTask::where('id', '>', 0)->delete();
            AigcProductImageResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_style_transfer') {
            AigcStyleTransferConfig::where('id', '>', 0)->delete();
            AigcStyleTransferStyleCategory::where('id', '>', 0)->delete();
            AigcStyleTransferStyleTemplate::where('id', '>', 0)->delete();
            AigcStyleTransferTask::where('id', '>', 0)->delete();
            AigcStyleTransferResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_photo_restore') {
            AigcPhotoRestoreConfig::where('id', '>', 0)->delete();
            AigcPhotoRestoreType::where('id', '>', 0)->delete();
            AigcPhotoRestoreTask::where('id', '>', 0)->delete();
            AigcPhotoRestoreResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_model_wear') {
            AigcModelWearConfig::where('id', '>', 0)->delete();
            AigcModelWearTask::where('id', '>', 0)->delete();
            AigcModelWearResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_background_removal') {
            AigcBackgroundRemovalConfig::where('id', '>', 0)->delete();
            AigcBackgroundRemovalTask::where('id', '>', 0)->delete();
            AigcBackgroundRemovalResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_image_translate') {
            AigcImageTranslateConfig::where('id', '>', 0)->delete();
            AigcImageTranslateTask::where('id', '>', 0)->delete();
            AigcImageTranslateResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_one_click_cleanup') {
            AigcOneClickCleanupConfig::where('id', '>', 0)->delete();
            AigcOneClickCleanupOption::where('id', '>', 0)->delete();
            AigcOneClickCleanupTask::where('id', '>', 0)->delete();
            AigcOneClickCleanupResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_product_suite') {
            AigcProductSuiteConfig::where('id', '>', 0)->delete();
            AigcProductSuiteModule::where('id', '>', 0)->delete();
            AigcProductSuiteTask::where('id', '>', 0)->delete();
            AigcProductSuiteResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_product_multi_angle') {
            AigcProductMultiAngleConfig::where('id', '>', 0)->delete();
            AigcProductMultiAngleView::where('id', '>', 0)->delete();
            AigcProductMultiAngleTask::where('id', '>', 0)->delete();
            AigcProductMultiAngleResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_product_promo_video') {
            AigcProductPromoVideoConfig::where('id', '>', 0)->delete();
            AigcProductPromoVideoType::where('id', '>', 0)->delete();
            AigcProductPromoVideoTask::where('id', '>', 0)->delete();
            AigcProductPromoVideoResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_action_transfer') {
            AigcActionTransferConfig::where('id', '>', 0)->delete();
            AigcActionTransferTask::where('id', '>', 0)->delete();
            AigcActionTransferResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_person_replacement') {
            AigcPersonReplacementConfig::where('id', '>', 0)->delete();
            AigcPersonReplacementTask::where('id', '>', 0)->delete();
            AigcPersonReplacementResult::where('id', '>', 0)->delete();
        }
        if ($appCode === 'aigc_local_redraw') {
            AigcLocalRedrawConfig::where('id', '>', 0)->delete();
            AigcLocalRedrawTask::where('id', '>', 0)->delete();
            AigcLocalRedrawResult::where('id', '>', 0)->delete();
        }
    }

    private static function deleteTenantMenusForApp(string $appCode): void
    {
        TenantSystemMenu::where(['source' => 'app', 'app_code' => $appCode])->delete();
        $tenants = Tenant::field('id,sn,tactics')->select()->toArray();
        foreach ($tenants as $tenant) {
            if ((int)($tenant['tactics'] ?? 0) !== 1 || empty($tenant['sn'])) {
                Db::name('tenant_system_menu')
                    ->where(['tenant_id' => (int)$tenant['id'], 'source' => 'app', 'app_code' => $appCode])
                    ->delete();
                continue;
            }
            $table = env('database.prefix', 'la_') . 'tenant_system_menu_' . $tenant['sn'];
            try {
                Db::table($table)->where(['source' => 'app', 'app_code' => $appCode])->delete();
            } catch (Throwable) {
            }
        }
    }

    private static function writeInstallLog(string $appCode, string $version, string $status, string $error = ''): void
    {
        AppInstall::create([
            'app_code' => $appCode,
            'version' => $version,
            'status' => $status,
            'error' => $error,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private static function saveModel(string $modelClass, array $where, array $data): void
    {
        $model = new $modelClass();
        $row = $model->where($where)->findOrEmpty();
        if ($row->isEmpty()) {
            $model->create($data);
            return;
        }
        $row->save($data);
    }
}
