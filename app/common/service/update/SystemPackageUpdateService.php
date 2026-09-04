<?php

namespace app\common\service\update;

use app\common\model\update\UpdatePackage;
use app\common\model\update\UpdateTask;
use app\common\service\ConfigService;
use app\common\service\app\AppAccessService;
use app\common\service\app\DefaultAppService;
use app\common\service\app\AppRegistryService;
use app\common\service\billing\PackageProvisionService;
use app\common\service\database\SqlMigrationExecutor;
use app\platformapi\logic\upgrade\UpgradeLogic;
use RuntimeException;
use think\facade\Db;
use Throwable;

class SystemPackageUpdateService
{
    private const UPDATE_EXECUTION_TIMEOUT = 600;
    private const STEP_UPDATE_BRIDGE_VERSION = '1.0.116';
    private const FULL_REPLACE_ALLOWED_DIRS = [
        'public/admin',
        'public/platform',
        'public/pc',
        'public/_nuxt',
        'public/media',
        'public/mobile',
        'public/mp-weixin',
        'public/static',
    ];
    private const DELETE_ALLOWED_PREFIXES = [
        'app/',
        'config/',
        'extend/',
        'public/admin/',
        'public/platform/',
        'public/pc/',
        'public/_nuxt/',
        'public/media/',
        'public/mobile/',
        'public/mp-weixin/',
        'public/static/',
        'route/',
        'upgrade/',
    ];
    private const PROTECTED_PATH_PATTERNS = [
        '#^\.env$#',
        '#(^|/)\.env(\.|$)#',
        '#^config/install\.lock$#',
        '#^runtime(/|$)#',
        '#^public/uploads(/|$)#',
        '#^public/storage(/|$)#',
        '#^public/qrcode(/|$)#',
        '#(^|/)\.DS_Store$#',
        '#\.log$#',
    ];

    public function versions(array $params = []): array
    {
        $params = array_merge([
            'current_version' => UpdateSourceClient::currentCoreVersion(),
            'upgrade_mode' => 'step',
            'updater_capability' => [
                'step_package' => true,
                'base_version_check' => true,
                'bridge_version' => self::STEP_UPDATE_BRIDGE_VERSION,
            ],
        ], $params);
        return (new UpdateSourceClient())->request(UpdateSourceClient::path('system/versions'), $params);
    }

    public function overview(): array
    {
        $current = UpdateSourceClient::currentCoreVersion();
        $ignored = (string)ConfigService::get('update_service', 'ignored_version', '');
        $environment = PackageExtractService::environment(UpgradeLogic::getProjectPath());
        $versions = [];
        $latest = [];
        $error = '';
        try {
            $data = $this->versions();
            $versions = $this->normalizeVersions($data);
            $latest = $this->selectNextVersion($versions, $current);
            $cloudLatestVersion = $this->versionOf($versions[0] ?? []);
            if (!$latest && $cloudLatestVersion !== '' && version_compare($cloudLatestVersion, $current, '>')) {
                $error = '更新源未返回当前版本可用的下一步升级包，请先同步桥接包或连续步进包';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $latestVersion = (string)($latest['version'] ?? $latest['version_no'] ?? '');
        return [
            'current_version' => $current,
            'latest' => $latest,
            'versions' => $versions,
            'current_version_log' => $this->versionRecord($versions, $current),
            'worker' => $this->workerGuide(),
            'ignored_version' => $ignored,
            'has_update' => $latestVersion !== '' && version_compare($latestVersion, $current, '>'),
            'is_ignored' => $latestVersion !== '' && $latestVersion === $ignored,
            'environment' => $environment,
            'error' => $error,
        ];
    }

    private function workerGuide(): array
    {
        $directory = rtrim(root_path(), DIRECTORY_SEPARATOR);
        $script = $directory . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'start-ai-task-worker.sh';
        if (!is_file($script) && is_file($directory . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'start-ai-task-worker.sh')) {
            $directory .= DIRECTORY_SEPARATOR . 'server';
            $script = $directory . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'start-ai-task-worker.sh';
        }

        return [
            'name' => 'aigc-task-worker',
            'start_user' => 'root',
            'process_count' => '1',
            'priority' => '999',
            'remark' => 'AI 任务守护进程',
            'directory' => $directory,
            'start_command' => '/bin/sh ' . escapeshellarg($script),
            'log_command' => 'tail -f ' . escapeshellarg($directory . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'ai_task_worker.log'),
        ];
    }

    private function versionRecord(array $versions, string $version): array
    {
        foreach ($versions as $item) {
            if ($this->versionOf($item) === $version) {
                return $item;
            }
        }
        return ['version' => $version];
    }

    public function downloadPackage(string $targetVersion, string $currentVersion = ''): array
    {
        $this->extendExecutionTimeout();
        try {
            if ($targetVersion === '') {
                throw new RuntimeException('请选择目标版本');
            }
            (new UpdateLicenseService())->assertSystemUpdateAllowed($targetVersion);
            $data = (new UpdateSourceClient())->request(UpdateSourceClient::path('system/package'), [
                'target_version' => $targetVersion,
                'current_version' => $currentVersion ?: UpdateSourceClient::currentCoreVersion(),
                'upgrade_mode' => 'step',
                'updater_capability' => [
                    'step_package' => true,
                    'base_version_check' => true,
                    'bridge_version' => self::STEP_UPDATE_BRIDGE_VERSION,
                ],
            ]);
            $download = $this->downloadWithFallback($data);
            $row = UpdatePackage::create([
                'package_id' => (string)($data['package_id'] ?? 'system_' . $targetVersion),
                'type' => 'system',
                'source' => 'cloud',
                'app_code' => '',
                'version' => (string)($data['version'] ?? $targetVersion),
                'format' => (string)($download['format'] ?? 'zip'),
                'local_path' => (string)$download['path'],
                'extract_path' => '',
                'sha256' => (string)$download['sha256'],
                'package_size' => (int)$download['size'],
                'manifest_json' => $data['manifest'] ?? [],
                'status' => 'downloaded',
                'error' => '',
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $result = $row->toArray();
            $this->recordTask('download', (string)$result['version'], 'success', (int)$result['id'], [], [
                'package_id' => $result['package_id'],
                'format' => $result['format'],
                'sha256' => $result['sha256'],
                'package_size' => $result['package_size'],
            ]);
            return $result;
        } catch (Throwable $e) {
            $this->recordTask('download', $targetVersion, 'failed', 0, [], [], $e->getMessage());
            throw $e;
        }
    }

    public function ignoreVersion(string $version): array
    {
        ConfigService::set('update_service', 'ignored_version', $version);
        $this->recordTask('ignore', $version, 'success', 0, [], ['ignored_version' => $version]);
        return ['ignored_version' => $version];
    }

    public function logs(array $params = []): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, (int)($params['page_size'] ?? 15));
        $query = UpdateTask::where('type', 'system');
        if (!empty($params['status'])) {
            $query->where('status', (string)$params['status']);
        }
        if (!empty($params['version'])) {
            $query->whereLike('version', '%' . $params['version'] . '%');
        }
        if (!empty($params['start_time']) && !empty($params['end_time'])) {
            $query->whereBetween('create_time', [strtotime((string)$params['start_time']), strtotime((string)$params['end_time'])]);
        }
        $count = (clone $query)->count();
        $lists = $query->order('id', 'desc')
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();
        return [
            'lists' => $lists,
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
            'extend' => [],
        ];
    }

    public function versionLogs(array $params = []): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, (int)($params['page_size'] ?? 15));
        $keyword = trim((string)($params['keyword'] ?? ''));
        $version = trim((string)($params['version'] ?? ''));
        $updateType = trim((string)($params['update_type'] ?? ''));

        $lists = $this->normalizeVersions($this->versions($params));
        $lists = array_values(array_filter($lists, function ($item) use ($keyword, $version, $updateType) {
            $itemVersion = (string)($item['version'] ?? $item['version_no'] ?? '');
            $title = (string)($item['title'] ?? '');
            $summary = (string)($item['summary'] ?? $item['description'] ?? '');
            if ($version !== '' && stripos($itemVersion, $version) === false) {
                return false;
            }
            if ($updateType !== '' && (string)($item['update_type'] ?? '') !== $updateType) {
                return false;
            }
            if ($keyword !== '' && stripos($itemVersion . ' ' . $title . ' ' . $summary, $keyword) === false) {
                return false;
            }
            return true;
        }));

        $count = count($lists);
        $pageLists = array_slice($lists, ($pageNo - 1) * $pageSize, $pageSize);
        return [
            'lists' => $pageLists,
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
            'extend' => [],
        ];
    }

    public function preflight(int $packageId): array
    {
        $this->extendExecutionTimeout();
        try {
            $package = $this->getPackage($packageId);
            $extractor = new PackageExtractService();
            $preflight = $extractor->preflight((string)$package['local_path'], UpgradeLogic::getProjectPath(), (int)$package['package_size']);
            if (!$preflight['passed']) {
                return $this->finishPreflight($package, $preflight);
            }
            $extract = $extractor->extract((string)$package['local_path'], (string)$package['format']);
            if (($extract['extract_status'] ?? '') !== 'success') {
                return $this->finishPreflight($package, [
                    'passed' => false,
                    'errors' => [$extract['error'] ?? '解压失败'],
                    'extract' => $extract,
                    'environment' => $preflight['environment'],
                ]);
            }
            try {
                PackageExtractService::assertRequiredFiles($extract['path'], [
                    'update.json',
                    'files',
                    'menus',
                    'rollback',
                    'signature.json',
                ]);
                $extractor->verifySignatureManifest($extract['path']);
                $manifest = $this->readUpdateManifest($extract['path']);
                if (!empty($package['version']) && !empty($manifest['version']) && $manifest['version'] !== $package['version']) {
                    throw new RuntimeException('系统包版本与下载接口不一致');
                }
                if (!empty($manifest['product_code']) && $manifest['product_code'] !== UpdateSourceClient::PRODUCT_CODE) {
                    throw new RuntimeException('系统包 product_code 不一致');
                }
                $this->assertCoreRequirement($manifest);
                $this->assertIncrementalManifest($manifest, $extract['path']);
                $this->assertIncrementalComposerRuntime($manifest, $extract['path']);
                $builtinApps = $this->preflightBuiltinApps($extract['path'], $manifest);
                $result = [
                    'passed' => true,
                    'errors' => [],
                    'extract' => $extract,
                    'manifest' => $manifest,
                    'environment' => $preflight['environment'],
                    'builtin_apps' => $builtinApps,
                ];
                $package->save([
                    'version' => (string)($manifest['version'] ?? $package['version']),
                    'manifest_json' => $manifest,
                    'extract_path' => $extract['path'],
                    'status' => 'preflight_success',
                    'error' => '',
                    'update_time' => time(),
                ]);
                $this->recordTask('preflight', (string)($manifest['version'] ?? $package['version']), 'success', (int)$package['id'], $result, [
                    'passed' => true,
                    'extract_path' => $extract['path'] ?? '',
                ]);
                return $result;
            } catch (Throwable $e) {
                return $this->finishPreflight($package, [
                    'passed' => false,
                    'errors' => [$e->getMessage()],
                    'extract' => $extract,
                    'environment' => $preflight['environment'],
                ]);
            }
        } catch (Throwable $e) {
            $this->recordTask('preflight', '', 'failed', $packageId, [], [], $e->getMessage());
            throw $e;
        }
    }

    public function apply(int $packageId): array
    {
        $this->extendExecutionTimeout();
        $package = $this->getPackage($packageId);
        $task = $this->createTask($package, 'apply');
        $lock = $this->acquireLock('system_update');
        if (!$lock) {
            $this->finishTask($task, 'failed', [], '已有系统更新任务正在执行，请稍后再试');
            throw new RuntimeException('已有系统更新任务正在执行，请稍后再试');
        }
        try {
            if ($package['status'] !== 'preflight_success') {
                $this->preflight($packageId);
                $package = $this->getPackage($packageId);
            }
            if ($package['status'] !== 'preflight_success') {
                throw new RuntimeException($package['error'] ?: '系统包预检未通过');
            }
            $extractPath = rtrim((string)$package['extract_path'], '/');
            Db::startTrans();
            $manifest = $this->readUpdateManifest($extractPath);
            if (!$this->applySqlGroup($extractPath, $manifest, 'data')) {
                throw new RuntimeException('更新数据库数据失败');
            }
            if (!UpgradeLogic::upgradeFile($extractPath . '/files/', UpgradeLogic::getProjectPath())) {
                throw new RuntimeException('更新文件失败');
            }
            $this->applyFullReplaceDirs($extractPath, $manifest);
            $this->applyDeleteFiles($manifest);
            Db::commit();
            if (!$this->applySqlGroup($extractPath, $manifest, 'structure')) {
                throw new RuntimeException('更新数据库结构失败');
            }
            $builtinApps = $this->syncBuiltinApps($extractPath, (string)$package['version']);
            DefaultAppService::syncAllTenants();
            PackageProvisionService::syncAllTenants();
            $package->save(['status' => 'applied', 'error' => '', 'update_time' => time()]);
            $this->writeLocalVersion((string)$package['version']);
            $result = [
                'version' => $package['version'],
                'extract_path' => $extractPath,
                'builtin_apps' => $builtinApps,
            ];
            $this->finishTask($task, 'success', $result);
            return $result;
        } catch (Throwable $e) {
            Db::rollback();
            $package->save(['status' => 'apply_failed', 'error' => $e->getMessage(), 'update_time' => time()]);
            $this->finishTask($task, 'failed', [], $e->getMessage());
            throw $e;
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function downloadWithFallback(array $data): array
    {
        try {
            return UpdateSourceClient::download((string)($data['download_url'] ?? ''), (string)($data['sha256'] ?? ''), (string)($data['format'] ?? 'zip'));
        } catch (Throwable $e) {
            if (empty($data['fallback_url'])) {
                throw $e;
            }
            return UpdateSourceClient::download((string)$data['fallback_url'], (string)($data['fallback_sha256'] ?? ''), (string)($data['fallback_format'] ?? 'tar.gz'));
        }
    }

    private function extendExecutionTimeout(): void
    {
        ini_set('max_execution_time', (string)self::UPDATE_EXECUTION_TIMEOUT);
        set_time_limit(self::UPDATE_EXECUTION_TIMEOUT);
    }

    private function syncBuiltinApps(string $extractPath, string $coreVersion): array
    {
        $this->ensureSqlMigrationExecutorAvailable();
        $result = [];
        foreach ($this->packagedBuiltinAppCodes($extractPath) as $appCode) {
            $manifestPath = AppRegistryService::manifestPath($appCode);
            if (!is_file($manifestPath)) {
                $result[] = [
                    'app_code' => $appCode,
                    'version' => '',
                    'status' => 'skipped',
                    'error' => '应用目录或清单不存在',
                ];
                continue;
            }

            try {
                $sync = $this->installBuiltinAppFromLocal($appCode, $coreVersion);
                $manifest = $sync['manifest'] ?? [];
                $result[] = [
                    'app_code' => $appCode,
                    'version' => (string)($manifest['version'] ?? ''),
                    'status' => 'executed',
                    'migrations' => $sync['migrations'] ?? [],
                    'error' => '',
                ];
            } catch (Throwable $e) {
                $result[] = [
                    'app_code' => $appCode,
                    'version' => '',
                    'status' => 'error',
                    'migrations' => [],
                    'error' => $e->getMessage(),
                ];
                throw new RuntimeException('内置应用同步失败[' . $appCode . ']：' . $e->getMessage(), 0, $e);
            }
        }
        return $result;
    }

    private function ensureSqlMigrationExecutorAvailable(): void
    {
        $class = '\\app\\common\\service\\database\\SqlMigrationExecutor';
        if (class_exists($class)) {
            return;
        }
        $path = root_path() . 'app/common/service/database/SqlMigrationExecutor.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    private function installBuiltinAppFromLocal(string $appCode, string $coreVersion): array
    {
        if (method_exists(AppRegistryService::class, 'installFromLocalWithResult')) {
            return AppRegistryService::installFromLocalWithResult($appCode, $coreVersion);
        }

        $method = new \ReflectionMethod(AppRegistryService::class, 'installFromLocal');
        $manifest = $method->getNumberOfParameters() >= 2
            ? AppRegistryService::installFromLocal($appCode, $coreVersion)
            : AppRegistryService::installFromLocal($appCode);

        return [
            'manifest' => is_array($manifest) ? $manifest : [],
            'migrations' => [],
        ];
    }

    private function preflightBuiltinApps(string $extractPath, array $manifest = []): array
    {
        $result = [];
        $incremental = ($manifest['package_mode'] ?? '') === 'incremental';
        $appCodes = $this->packagedBuiltinAppCodes($extractPath);
        if (!$incremental) {
            $appCodes = array_values(array_unique([
                ...AppAccessService::DEFAULT_AIGC_APP_CODES,
                ...$appCodes,
            ]));
        }
        foreach ($appCodes as $appCode) {
            $appPath = rtrim($extractPath, '/') . '/files/app/apps/' . $appCode;
            $manifestPath = $appPath . '/manifest.json';
            if (!is_file($manifestPath)) {
                if ($incremental) {
                    $result[] = [
                        'app_code' => $appCode,
                        'version' => '',
                        'status' => 'not_in_package',
                        'migrations' => [],
                    ];
                    continue;
                }
                throw new RuntimeException('系统包缺少内置应用清单: ' . $appCode);
            }
            $manifest = json_decode((string)file_get_contents($manifestPath), true);
            if (!is_array($manifest) || ($manifest['code'] ?? '') !== $appCode) {
                throw new RuntimeException('系统包内置应用清单格式错误: ' . $appCode);
            }
            $migrations = [];
            foreach (glob($appPath . '/migrations/*.sql') ?: [] as $file) {
                $migrations[] = basename($file);
            }
            $result[] = [
                'app_code' => $appCode,
                'version' => (string)($manifest['version'] ?? ''),
                'status' => 'present',
                'migrations' => $migrations,
            ];
        }
        return $result;
    }

    /**
     * Only applications shipped by the verified system package are synchronized.
     * This keeps non-built-in app-center packages outside the system-update path.
     */
    private function packagedBuiltinAppCodes(string $extractPath): array
    {
        $appsPath = rtrim($extractPath, '/') . '/files/app/apps';
        if (!is_dir($appsPath)) {
            return [];
        }

        $codes = [];
        foreach (glob($appsPath . '/*', GLOB_ONLYDIR) ?: [] as $appPath) {
            $manifestPath = $appPath . '/manifest.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifest = json_decode((string)file_get_contents($manifestPath), true);
            $appCode = basename($appPath);
            if (!is_array($manifest) || ($manifest['code'] ?? '') !== $appCode) {
                throw new RuntimeException('系统包应用清单格式错误: ' . $appCode);
            }
            if (empty($manifest['is_builtin'])) {
                continue;
            }
            AppRegistryService::assertValidCode($appCode);
            $codes[] = $appCode;
        }

        sort($codes, SORT_STRING);
        return $codes;
    }

    private function normalizeVersions(array $data): array
    {
        $versions = $data;
        if (isset($data['lists']) && is_array($data['lists'])) {
            $versions = $data['lists'];
        } elseif (isset($data['versions']) && is_array($data['versions'])) {
            $versions = $data['versions'];
        }
        usort($versions, function ($a, $b) {
            return version_compare((string)($b['version'] ?? $b['version_no'] ?? ''), (string)($a['version'] ?? $a['version_no'] ?? ''));
        });
        return $versions;
    }

    private function selectNextVersion(array $versions, string $current): array
    {
        $versions = array_values(array_filter($versions, fn (array $item): bool => $this->isVersionEnabled($item)));
        if (!$versions) {
            return [];
        }
        if ($current === '') {
            return $versions[0] ?? [];
        }

        $ascending = $versions;
        usort($ascending, function ($a, $b) {
            return version_compare((string)($this->versionOf($a)), (string)($this->versionOf($b)));
        });

        if (version_compare($current, self::STEP_UPDATE_BRIDGE_VERSION, '<')) {
            foreach ($ascending as $item) {
                if (version_compare($this->versionOf($item), self::STEP_UPDATE_BRIDGE_VERSION, '==')) {
                    return $item;
                }
            }
            return [];
        }

        foreach ($ascending as $item) {
            $version = $this->versionOf($item);
            if ($version === '' || !version_compare($version, $current, '>')) {
                continue;
            }
            $baseVersion = $this->baseVersionOf($item);
            if ($baseVersion !== '' && version_compare($baseVersion, $current, '==')) {
                return $item;
            }
        }

        $hasStepMetadata = false;
        foreach ($versions as $item) {
            if ($this->baseVersionOf($item) !== '') {
                $hasStepMetadata = true;
                break;
            }
        }
        if ($hasStepMetadata) {
            return [];
        }

        foreach ($ascending as $item) {
            if (version_compare($this->versionOf($item), $current, '>')) {
                return $item;
            }
        }
        return [];
    }

    private function isVersionEnabled(array $item): bool
    {
        foreach (['status', 'enabled', 'is_enabled', 'is_published', 'published', 'release_status'] as $field) {
            if (!array_key_exists($field, $item)) {
                continue;
            }
            $value = $item[$field];
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int)$value === 1;
            }
            $value = strtolower(trim((string)$value));
            if (in_array($value, ['0', 'false', 'off', 'disabled', 'down', 'unpublished'], true)) {
                return false;
            }
        }
        return true;
    }

    private function versionOf(array $item): string
    {
        return (string)($item['version'] ?? $item['version_no'] ?? $item['target_version'] ?? ($item['manifest']['version'] ?? ''));
    }

    private function baseVersionOf(array $item): string
    {
        return (string)($item['base_version'] ?? ($item['manifest']['base_version'] ?? ''));
    }

    private function writeLocalVersion(string $version): void
    {
        if ($version === '') {
            return;
        }
        $dir = PackageExtractService::versionDir() . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . 'version.json', json_encode(['version' => $version], JSON_UNESCAPED_UNICODE));
    }

    private function getPackage(int $packageId): UpdatePackage
    {
        $package = UpdatePackage::where(['id' => $packageId, 'type' => 'system'])->findOrEmpty();
        if ($package->isEmpty()) {
            throw new RuntimeException('系统更新包不存在');
        }
        return $package;
    }

    private function savePreflight(UpdatePackage $package, array $result): array
    {
        $package->save([
            'status' => ($result['passed'] ?? false) ? 'preflight_success' : 'preflight_failed',
            'error' => implode(';', $result['errors'] ?? []),
            'update_time' => time(),
        ]);
        return $result;
    }

    private function finishPreflight(UpdatePackage $package, array $result): array
    {
        $result = $this->savePreflight($package, $result);
        $error = implode(';', $result['errors'] ?? []);
        $this->recordTask(
            'preflight',
            (string)($result['manifest']['version'] ?? $package['version']),
            ($result['passed'] ?? false) ? 'success' : 'failed',
            (int)$package['id'],
            $result,
            ['passed' => (bool)($result['passed'] ?? false)],
            $error
        );
        return $result;
    }

    private function readUpdateManifest(string $extractPath): array
    {
        $manifest = json_decode((string)file_get_contents($extractPath . '/update.json'), true);
        if (!is_array($manifest) || empty($manifest['version'])) {
            throw new RuntimeException('update.json 格式错误');
        }
        return $manifest;
    }

    private function assertIncrementalManifest(array $manifest, string $extractPath): void
    {
        if (($manifest['package_mode'] ?? '') !== 'incremental') {
            return;
        }

        foreach (['base_version', 'target_version', 'included_versions', 'full_replace_dirs', 'delete_files', 'sql_order'] as $field) {
            if (!array_key_exists($field, $manifest)) {
                throw new RuntimeException('增量系统包缺少字段: ' . $field);
            }
        }
        if ((string)$manifest['target_version'] !== (string)$manifest['version']) {
            throw new RuntimeException('增量系统包 target_version 与 version 不一致');
        }
        if (!is_array($manifest['included_versions']) || !$manifest['included_versions']) {
            throw new RuntimeException('增量系统包 included_versions 不能为空');
        }
        if (!in_array((string)$manifest['target_version'], array_map('strval', $manifest['included_versions']), true)) {
            throw new RuntimeException('增量系统包 included_versions 未包含目标版本');
        }
        $this->assertIncrementalVersionRange($manifest);
        foreach (['full_replace_dirs', 'delete_files', 'sql_order'] as $field) {
            if (!is_array($manifest[$field])) {
                throw new RuntimeException('增量系统包字段必须为数组: ' . $field);
            }
        }
        foreach ($manifest['full_replace_dirs'] as $dir) {
            $relative = $this->normalizePackagePath((string)$dir);
            $this->assertSafeUpdatePath($relative, true);
            if (!in_array($relative, self::FULL_REPLACE_ALLOWED_DIRS, true)) {
                throw new RuntimeException('增量系统包不允许全量覆盖目录: ' . $relative);
            }
            if (!is_dir(rtrim($extractPath, '/') . '/files/' . $relative)) {
                throw new RuntimeException('增量系统包声明的全量覆盖目录不存在: ' . $relative);
            }
        }
        foreach ($manifest['delete_files'] as $file) {
            $relative = $this->normalizePackagePath((string)$file);
            $this->assertSafeUpdatePath($relative, false);
            if (!$this->isDeleteAllowed($relative)) {
                throw new RuntimeException('增量系统包不允许删除路径: ' . $relative);
            }
        }
        $declaredSql = [];
        foreach ($manifest['sql_order'] as $file) {
            $relative = $this->normalizePackagePath((string)$file);
            $this->assertSafeUpdatePath($relative, false);
            if (!str_starts_with($relative, 'sql/data/') && !str_starts_with($relative, 'sql/structure/')) {
                throw new RuntimeException('增量系统包 sql_order 只能声明 SQL 文件: ' . $relative);
            }
            if ($this->isReadmeSql($relative)) {
                throw new RuntimeException('增量系统包 sql_order 不允许声明说明文件: ' . $relative);
            }
            if (strtolower(basename($relative)) === 'install.sql') {
                throw new RuntimeException('增量系统包不允许执行全新安装 SQL: ' . $relative);
            }
            if (!is_file(rtrim($extractPath, '/') . '/' . $relative)) {
                throw new RuntimeException('增量系统包 sql_order 声明的文件不存在: ' . $relative);
            }
            $declaredSql[$relative] = true;
        }
        $this->assertIncrementalSqlDirectorySafe($extractPath, $declaredSql);
    }

    /**
     * Incremental packages cannot safely update Composer runtime files. They are
     * copied file by file, so an interrupted or partial vendor update can leave
     * autoload.php and composer/autoload_real.php from different generations.
     */
    private function assertIncrementalComposerRuntime(array $manifest, string $extractPath): void
    {
        if (($manifest['package_mode'] ?? '') !== 'incremental') {
            return;
        }

        $filesRoot = rtrim($extractPath, '/') . '/files';
        $forbidden = [];
        foreach (['composer.json', 'composer.lock', 'vendor'] as $relative) {
            if (file_exists($filesRoot . '/' . $relative)) {
                $forbidden[] = $relative;
            }
        }
        if ($forbidden) {
            throw new RuntimeException('增量系统包不能包含 Composer 运行时文件，请使用完整安装包发布依赖变更: ' . implode(', ', $forbidden));
        }
    }

    private function assertIncrementalSqlDirectorySafe(string $extractPath, array $declaredSql): void
    {
        foreach (['sql/data', 'sql/structure'] as $directory) {
            $root = rtrim($extractPath, '/') . '/' . $directory;
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    throw new RuntimeException('增量系统包 SQL 目录不允许符号链接: ' . $item->getPathname());
                }
                if (!$item->isFile() || strtolower($item->getExtension()) !== 'sql') {
                    continue;
                }
                $relative = $this->normalizePackagePath(substr($item->getPathname(), strlen(rtrim($extractPath, '/')) + 1));
                if ($this->isReadmeSql($relative)) {
                    continue;
                }
                if (strtolower(basename($relative)) === 'install.sql') {
                    throw new RuntimeException('增量系统包不允许包含全新安装 SQL: ' . $relative);
                }
                if (!isset($declaredSql[$relative])) {
                    throw new RuntimeException('增量系统包 SQL 文件未声明执行顺序: ' . $relative);
                }
                $this->assertIncrementalSqlSafe($relative, $item->getPathname());
            }
        }
    }

    private function assertIncrementalSqlSafe(string $relative, string $path): void
    {
        $content = (string)file_get_contents($path);
        $content = preg_replace_callback('/\/\*!\d*\s*([\s\S]*?)\*\//', static fn (array $match) => ' ' . $match[1] . ' ', $content) ?? $content;
        $content = preg_replace('/\/\*(?!\!)[\s\S]*?\*\//', ' ', $content) ?? $content;
        $content = preg_replace('/--[ \t][^\r\n]*/', ' ', $content) ?? $content;
        $content = preg_replace('/#[^\r\n]*/', ' ', $content) ?? $content;
        if (preg_match('/\b(?:DROP|TRUNCATE)\s+(?:TABLE|DATABASE)\b/i', $content)) {
            throw new RuntimeException('增量系统包不允许执行破坏性 SQL: ' . $relative);
        }
    }

    private function assertIncrementalVersionRange(array $manifest): void
    {
        $current = UpdateSourceClient::currentCoreVersion();
        $baseVersion = (string)($manifest['base_version'] ?? '');
        $targetVersion = (string)($manifest['target_version'] ?? $manifest['version'] ?? '');
        $includedVersions = array_map('strval', (array)($manifest['included_versions'] ?? []));

        if ($current === '' || $baseVersion === '' || $targetVersion === '') {
            return;
        }
        if (!version_compare($targetVersion, $current, '>')) {
            throw new RuntimeException('系统包目标版本必须大于当前系统版本');
        }

        $isBridgePackage = version_compare($current, self::STEP_UPDATE_BRIDGE_VERSION, '<')
            && version_compare($targetVersion, self::STEP_UPDATE_BRIDGE_VERSION, '==')
            && in_array($current, $includedVersions, true);
        if ($isBridgePackage) {
            return;
        }

        if (version_compare($current, self::STEP_UPDATE_BRIDGE_VERSION, '<')) {
            throw new RuntimeException('旧版本系统必须先升级到桥接版本 ' . self::STEP_UPDATE_BRIDGE_VERSION);
        }
        if (version_compare($current, $baseVersion, '!=')) {
            throw new RuntimeException('系统包起始版本与当前系统版本不一致，请重新获取更新包');
        }
    }

    private function applyFullReplaceDirs(string $extractPath, array $manifest): void
    {
        $dirs = $manifest['full_replace_dirs'] ?? [];
        if (!is_array($dirs)) {
            throw new RuntimeException('full_replace_dirs 格式错误');
        }
        $replacements = [];
        foreach ($dirs as $dir) {
            $relative = $this->normalizePackagePath((string)$dir);
            $this->assertSafeUpdatePath($relative, true);
            if (!in_array($relative, self::FULL_REPLACE_ALLOWED_DIRS, true)) {
                throw new RuntimeException('不允许全量覆盖目录: ' . $relative);
            }
            $source = rtrim($extractPath, '/') . '/files/' . $relative;
            if (!is_dir($source)) {
                throw new RuntimeException('全量覆盖目录不存在: ' . $relative);
            }
            $target = rtrim(UpgradeLogic::getProjectPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
            $suffix = '.update-' . bin2hex(random_bytes(8));
            $stage = $target . $suffix . '.stage';
            $backup = $target . $suffix . '.backup';
            $this->copyDirectory($source, $stage);
            $replacements[] = compact('relative', 'target', 'stage', 'backup');
        }

        $swapped = [];
        try {
            foreach ($replacements as $replacement) {
                if (file_exists($replacement['target']) && !@rename($replacement['target'], $replacement['backup'])) {
                    throw new RuntimeException('无法备份全量覆盖目录: ' . $replacement['relative']);
                }
                if (!@rename($replacement['stage'], $replacement['target'])) {
                    if (is_dir($replacement['backup'])) {
                        @rename($replacement['backup'], $replacement['target']);
                    }
                    throw new RuntimeException('无法替换全量覆盖目录: ' . $replacement['relative']);
                }
                $swapped[] = $replacement;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($swapped) as $replacement) {
                $this->removeDirectory($replacement['target']);
                if (is_dir($replacement['backup'])) {
                    @rename($replacement['backup'], $replacement['target']);
                }
            }
            throw $e;
        } finally {
            foreach ($replacements as $replacement) {
                $this->removeDirectory($replacement['stage']);
            }
        }

        foreach ($swapped as $replacement) {
            $this->removeDirectory($replacement['backup']);
        }
    }

    private function applyDeleteFiles(array $manifest): void
    {
        $files = $manifest['delete_files'] ?? [];
        if (!is_array($files)) {
            throw new RuntimeException('delete_files 格式错误');
        }
        foreach ($files as $file) {
            $relative = $this->normalizePackagePath((string)$file);
            $this->assertSafeUpdatePath($relative, false);
            if (!$this->isDeleteAllowed($relative)) {
                throw new RuntimeException('不允许删除路径: ' . $relative);
            }
            $target = rtrim(UpgradeLogic::getProjectPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative;
            if (is_file($target)) {
                @unlink($target);
            }
        }
    }

    private function applySqlGroup(string $extractPath, array $manifest, string $group): bool
    {
        if (($manifest['package_mode'] ?? '') !== 'incremental' || !is_array($manifest['sql_order'] ?? null)) {
            return UpgradeLogic::upgradeSql(rtrim($extractPath, '/') . '/sql/' . $group . '/');
        }

        $prefix = 'sql/' . $group . '/';
        $sqlPrefix = config('database.connections.mysql.prefix');
        foreach ($manifest['sql_order'] as $file) {
            $relative = $this->normalizePackagePath((string)$file);
            if (!str_starts_with($relative, $prefix)) {
                continue;
            }
            $this->assertSafeUpdatePath($relative, false);
            if ($this->isReadmeSql($relative)) {
                continue;
            }
            $path = rtrim($extractPath, '/') . '/' . $relative;
            if (!is_file($path)) {
                throw new RuntimeException('增量系统包 SQL 文件不存在: ' . $relative);
            }
            $content = (string)file_get_contents($path);
            if (trim($content) === '' || SqlMigrationExecutor::split($content) === []) {
                continue;
            }
            SqlMigrationExecutor::execute($content, $sqlPrefix);
        }
        return true;
    }

    private function isReadmeSql(string $path): bool
    {
        $filename = strtolower(basename($path));
        return $filename === 'readme.sql' || str_starts_with($filename, 'readme.');
    }

    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            return;
        }
        $base = realpath($dir);
        $project = realpath(UpgradeLogic::getProjectPath());
        if (!$base || !$project || !str_starts_with($base, rtrim($project, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('全量覆盖目录越界');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('全量覆盖目录包含符号链接: ' . $item->getPathname());
            }
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (file_exists($target)) {
            throw new RuntimeException('全量覆盖临时目录已存在: ' . $target);
        }
        if (!@mkdir($target, 0777, true) && !is_dir($target)) {
            throw new RuntimeException('无法创建全量覆盖临时目录: ' . $target);
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    throw new RuntimeException('全量覆盖源目录包含符号链接: ' . $item->getPathname());
                }
                $destination = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
                if ($item->isDir()) {
                    if (!@mkdir($destination, 0777, true) && !is_dir($destination)) {
                        throw new RuntimeException('无法创建全量覆盖子目录: ' . $destination);
                    }
                } elseif ($item->isFile() && !@copy($item->getPathname(), $destination)) {
                    throw new RuntimeException('无法复制全量覆盖文件: ' . $item->getPathname());
                }
            }
        } catch (Throwable $e) {
            $this->removeDirectory($target);
            throw $e;
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $this->clearDirectory($dir);
        if (!@rmdir($dir)) {
            throw new RuntimeException('无法清理更新临时目录: ' . $dir);
        }
    }

    private function normalizePackagePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?: '';
        return trim($path, '/');
    }

    private function assertSafeUpdatePath(string $path, bool $allowDirectory): void
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//', $path) || str_contains('/' . $path . '/', '/../')) {
            throw new RuntimeException('更新包包含非法路径: ' . $path);
        }
        if (!$allowDirectory && str_ends_with($path, '/')) {
            throw new RuntimeException('文件路径格式错误: ' . $path);
        }
        $isWechatArtifact = (bool)preg_match('#^runtime/wechat-artifacts/\d+\.\d+\.\d+(?:/|$)#', $path);
        foreach (self::PROTECTED_PATH_PATTERNS as $pattern) {
            if ($isWechatArtifact && $pattern === '#^runtime(/|$)#') {
                continue;
            }
            if (preg_match($pattern, $path)) {
                throw new RuntimeException('更新包路径命中保护规则: ' . $path);
            }
        }
    }

    private function isDeleteAllowed(string $path): bool
    {
        foreach (self::DELETE_ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function assertCoreRequirement(array $manifest): void
    {
        $require = trim((string)($manifest['require_core'] ?? ''));
        if ($require === '') {
            return;
        }
        $current = UpdateSourceClient::currentCoreVersion();
        preg_match_all('/(>=|<=|==|=|>|<)\s*([0-9][0-9A-Za-z._-]*)/', $require, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $operator = $match[1] === '=' ? '==' : $match[1];
            if (!version_compare($current, $match[2], $operator)) {
                throw new RuntimeException('当前系统版本 ' . $current . ' 不满足系统包升级要求: ' . $require);
            }
        }
    }

    private function createTask(UpdatePackage $package, string $action): UpdateTask
    {
        return UpdateTask::create([
            'type' => 'system',
            'action' => $action,
            'package_id' => (int)$package['id'],
            'app_code' => '',
            'version' => (string)$package['version'],
            'status' => 'running',
            'preflight_json' => [],
            'result_json' => [],
            'error' => '',
            'operator_id' => (int)(request()->adminId ?? 0),
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function recordTask(
        string $action,
        string $version,
        string $status,
        int $packageId = 0,
        array $preflight = [],
        array $result = [],
        string $error = ''
    ): void {
        UpdateTask::create([
            'type' => 'system',
            'action' => $action,
            'package_id' => $packageId,
            'app_code' => '',
            'version' => $version,
            'status' => $status,
            'preflight_json' => $preflight,
            'result_json' => $result,
            'error' => $error,
            'operator_id' => (int)(request()->adminId ?? 0),
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function finishTask(UpdateTask $task, string $status, array $result = [], string $error = ''): void
    {
        $task->save(['status' => $status, 'result_json' => $result, 'error' => $error, 'update_time' => time()]);
    }

    private function acquireLock(string $name)
    {
        $dir = runtime_path() . 'update_locks/';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $handle = fopen($dir . md5($name) . '.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            return false;
        }
        return $handle;
    }

    private function releaseLock($handle): void
    {
        if ($handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
