<?php

namespace app\common\service\update;

use app\common\model\update\UpdatePackage;
use app\common\model\update\UpdateTask;
use app\common\service\ConfigService;
use app\common\service\app\DefaultAppService;
use app\common\service\billing\PackageProvisionService;
use app\platformapi\logic\upgrade\UpgradeLogic;
use RuntimeException;
use think\facade\Db;
use Throwable;

class SystemPackageUpdateService
{
    public function versions(array $params = []): array
    {
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
            $latest = $versions[0] ?? [];
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $latestVersion = (string)($latest['version'] ?? $latest['version_no'] ?? '');
        return [
            'current_version' => $current,
            'latest' => $latest,
            'versions' => $versions,
            'ignored_version' => $ignored,
            'has_update' => $latestVersion !== '' && version_compare($latestVersion, $current, '>'),
            'is_ignored' => $latestVersion !== '' && $latestVersion === $ignored,
            'environment' => $environment,
            'error' => $error,
        ];
    }

    public function downloadPackage(string $targetVersion, string $currentVersion = ''): array
    {
        try {
            if ($targetVersion === '') {
                throw new RuntimeException('请选择目标版本');
            }
            (new UpdateLicenseService())->assertSystemUpdateAllowed($targetVersion);
            $data = (new UpdateSourceClient())->request(UpdateSourceClient::path('system/package'), [
                'target_version' => $targetVersion,
                'current_version' => $currentVersion ?: UpdateSourceClient::currentCoreVersion(),
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
                    'sql/data',
                    'sql/structure',
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
                $result = [
                    'passed' => true,
                    'errors' => [],
                    'extract' => $extract,
                    'manifest' => $manifest,
                    'environment' => $preflight['environment'],
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
            if (!UpgradeLogic::upgradeSql($extractPath . '/sql/data/')) {
                throw new RuntimeException('更新数据库数据失败');
            }
            if (!UpgradeLogic::upgradeFile($extractPath . '/files/', UpgradeLogic::getProjectPath())) {
                throw new RuntimeException('更新文件失败');
            }
            Db::commit();
            if (!UpgradeLogic::upgradeSql($extractPath . '/sql/structure/')) {
                throw new RuntimeException('更新数据库结构失败');
            }
            DefaultAppService::syncAllTenants();
            PackageProvisionService::syncAllTenants();
            $package->save(['status' => 'applied', 'error' => '', 'update_time' => time()]);
            $this->writeLocalVersion((string)$package['version']);
            $result = ['version' => $package['version'], 'extract_path' => $extractPath];
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
