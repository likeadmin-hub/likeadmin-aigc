<?php

namespace app\common\service\update;

use app\common\model\app\App;
use app\common\model\app\AppMigration;
use app\common\model\app\AppVersion;
use app\common\model\app\TenantApp;
use app\common\model\update\UpdatePackage;
use app\common\model\update\UpdateTask;
use app\common\service\app\AppRegistryService;
use app\common\service\app\DefaultAppService;
use app\common\service\database\SqlMigrationExecutor;
use RuntimeException;
use think\facade\Db;
use Throwable;

class AppPackageUpdateService
{
    public function cloudLists(array $params = []): array
    {
        return (new UpdateSourceClient())->request(UpdateSourceClient::path('apps/lists'), $params);
    }

    public function cloudDetail(string $appCode): array
    {
        AppRegistryService::assertValidCode($appCode);
        return (new UpdateSourceClient())->request(UpdateSourceClient::path('apps/detail'), ['app_code' => $appCode]);
    }

    public function downloadCloudPackage(string $appCode, string $targetVersion, string $action): array
    {
        AppRegistryService::assertValidCode($appCode);
        if ($targetVersion === '') {
            throw new RuntimeException('请选择目标版本');
        }
        if (!in_array($action, ['install', 'update'], true)) {
            throw new RuntimeException('应用包操作类型不支持');
        }
        $current = App::where('code', $appCode)->value('current_version') ?: '';
        $data = (new UpdateSourceClient())->request(UpdateSourceClient::path('apps/package'), [
            'app_code' => $appCode,
            'target_version' => $targetVersion,
            'current_version' => $current,
            'action' => $action,
        ]);
        $download = $this->downloadWithFallback($data);
        return $this->recordPackage($data, $download, 'cloud');
    }

    public function saveUploadedPackage(string $filePath, string $format = 'zip'): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('上传包文件不存在');
        }
        return $this->recordPackage([
            'package_id' => 'upload_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)),
            'app_code' => '',
            'version' => '',
        ], [
            'path' => $filePath,
            'sha256' => hash_file('sha256', $filePath),
            'size' => filesize($filePath),
            'format' => $format,
        ], 'upload');
    }

    public function preflight(int $packageId, string $expectedAppCode = ''): array
    {
        $package = $this->getPackage($packageId);
        $extractor = new PackageExtractService();
        $preflight = $extractor->preflight((string)$package['local_path'], base_path() . 'apps', (int)$package['package_size']);
        if (!$preflight['passed']) {
            return $this->savePreflight($package, $preflight);
        }
        $extract = $extractor->extract((string)$package['local_path'], (string)$package['format']);
        if (($extract['extract_status'] ?? '') !== 'success') {
            return $this->savePreflight($package, [
                'passed' => false,
                'errors' => [$extract['error'] ?? '解压失败'],
                'extract' => $extract,
                'environment' => $preflight['environment'],
            ]);
        }
        try {
            PackageExtractService::assertRequiredFiles($extract['path'], [
                'manifest.json',
                'api_schema.json',
                'menus/platform.json',
                'menus/tenant.json',
                'permissions/tenant.json',
                'migrations',
                'frontend',
                'signature.json',
            ]);
            $extractor->verifySignatureManifest($extract['path']);
            $manifest = $this->readManifest($extract['path']);
            AppRegistryService::assertValidCode((string)$manifest['code']);
            if ($expectedAppCode !== '' && $manifest['code'] !== $expectedAppCode) {
                throw new RuntimeException('应用包 app_code 与预期不一致');
            }
            if (!empty($package['app_code']) && $manifest['code'] !== $package['app_code']) {
                throw new RuntimeException('应用包 app_code 与下载接口不一致');
            }
            if (!empty($package['version']) && $manifest['version'] !== $package['version']) {
                throw new RuntimeException('应用包版本与下载接口不一致');
            }
            $installedApp = App::where('code', (string)$manifest['code'])->findOrEmpty();
            $current = !$installedApp->isEmpty() ? (string)($installedApp['current_version'] ?? '') : '';
            $status = !$installedApp->isEmpty() ? (string)($installedApp['status'] ?? '') : '';
            if ($status !== AppRegistryService::STATUS_REMOVED && $current !== '' && version_compare((string)$manifest['version'], (string)$current, '<=')) {
                throw new RuntimeException('应用包版本必须大于当前版本');
            }
            AppRegistryService::assertCoreCompatible($manifest);
            $result = [
                'passed' => true,
                'errors' => [],
                'extract' => $extract,
                'manifest' => $manifest,
                'environment' => $preflight['environment'],
            ];
            $package->save([
                'app_code' => $manifest['code'],
                'version' => $manifest['version'],
                'manifest_json' => $manifest,
                'extract_path' => $extract['path'],
                'status' => 'preflight_success',
                'error' => '',
                'update_time' => time(),
            ]);
            return $result;
        } catch (\Throwable $e) {
            return $this->savePreflight($package, [
                'passed' => false,
                'errors' => [$e->getMessage()],
                'extract' => $extract,
                'environment' => $preflight['environment'],
            ]);
        }
    }

    public function apply(int $packageId): array
    {
        $package = $this->getPackage($packageId);
        $task = $this->createTask($package, 'apply');
        $lock = $this->acquireLock('app_update_' . $packageId);
        if (!$lock) {
            $this->finishTask($task, 'failed', [], '已有应用安装/更新任务正在执行，请稍后再试');
            throw new RuntimeException('已有应用安装/更新任务正在执行，请稍后再试');
        }
        try {
            if ($package['status'] !== 'preflight_success') {
                $this->preflight($packageId, (string)$package['app_code']);
                $package = $this->getPackage($packageId);
            }
            if ($package['status'] !== 'preflight_success') {
                throw new RuntimeException($package['error'] ?: '应用包预检未通过');
            }
            $extractPath = (string)$package['extract_path'];
            $manifest = $this->readManifest($extractPath);
            AppRegistryService::assertCoreCompatible($manifest);
            $appCode = (string)$manifest['code'];
            if(is_file(root_path().'.composer-runtime/current.json'))return $this->applyRuntimePackage($package,$task,$extractPath,$manifest);
            return $this->installVerifiedApp($package,$task,$extractPath,$manifest,false);
        } catch (Throwable $e) {
            $package->save([
                'status' => 'apply_failed',
                'error' => $e->getMessage(),
                'update_time' => time(),
            ]);
            $this->finishTask($task, 'failed', [], $e->getMessage());
            throw $e;
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function installVerifiedApp(UpdatePackage $package, UpdateTask $task, string $extractPath, array $manifest, bool $runtime): array
    {
        $appCode = (string)$manifest['code'];
        AppRegistryService::installPublicAssets($manifest, $extractPath);
        $target = base_path() . 'apps/' . $appCode;
        if (!$runtime) {
            $this->copyDirectory($extractPath, $target);
            $this->runSqlMigrations($target . '/migrations', $appCode, (string)$manifest['version']);
        }
        $installed = App::where('code', $appCode)->findOrEmpty();
        $tenantStates = TenantApp::where('app_code', $appCode)->select()->toArray();
        $result = AppRegistryService::installFromLocal($appCode);
        foreach ($tenantStates as $row) {
            if (DefaultAppService::isDefaultApp($appCode)) continue;
            TenantApp::where(['tenant_id' => $row['tenant_id'], 'app_code' => $appCode])->update([
                'buy_status' => $row['buy_status'],
                'enable_status' => $row['enable_status'],
                'shelf_status' => $row['shelf_status'],
                'expire_time' => $row['expire_time'],
                'update_time' => time(),
            ]);
        }
        if (!$installed->isEmpty()) {
            if (DefaultAppService::isDefaultApp($appCode)) {
                App::where('code', $appCode)->update([
                    'is_builtin' => 1, 'expire_policy' => 'allow',
                    'status' => AppRegistryService::STATUS_INSTALLED, 'update_time' => time(),
                ]);
            } else {
                $previousStatus = (string)($installed['status'] ?? '');
                App::where('code', $appCode)->update([
                    'status' => $previousStatus !== AppRegistryService::STATUS_REMOVED && $previousStatus !== ''
                        ? $previousStatus : AppRegistryService::STATUS_INSTALLED,
                    'update_time' => time(),
                ]);
            }
        }
        if (DefaultAppService::isDefaultApp($appCode)) DefaultAppService::syncAllTenants($appCode);
        AppVersion::where(['app_code' => $appCode, 'version' => $manifest['version']])->update(['package_path' => $package['local_path']]);
        $package->save(['status' => 'applied', 'error' => '', 'update_time' => time()]);
        $this->finishTask($task, 'success', $result);
        return $result;
    }

    private function applyRuntimePackage(UpdatePackage $package, UpdateTask $task, string $extractPath, array $manifest): array
    {
        // Reauthorize from the original archive. A stale preflight workspace is not a signed source.
        if(!hash_equals((string)$package['sha256'],(string)hash_file('sha256',(string)$package['local_path'])))throw new RuntimeException('应用更新包已改变');
        $extractor=new PackageExtractService();$fresh=$extractor->extract((string)$package['local_path'],(string)$package['format']);
        if(($fresh['extract_status']??'')!=='success')throw new RuntimeException('应用更新包解压失败');
        $extractPath=$fresh['path'];$extractor->verifySignatureManifest($extractPath);
        $verified=$this->readManifest($extractPath);
        if($verified!==$manifest)throw new RuntimeException('应用包预检内容与签名档案不同');
        $stage=runtime_path().'update_workspace/app-generation-'.bin2hex(random_bytes(8));
        $code=(string)$manifest['code'];$this->copyDirectory($extractPath,$stage.'/files/app/apps/'.$code);
        $version=UpdateSourceClient::currentCoreVersion();
        $next=['version'=>$version,'package_mode'=>'incremental','base_version'=>$version,'full_replace_dirs'=>[],'delete_files'=>[]];
        $hashes=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage,\FilesystemIterator::SKIP_DOTS)) as $file)$hashes[substr($file->getPathname(),strlen($stage)+1)]=hash_file('sha256',$file->getPathname());
        $operationPath=runtime_path().'update_workspace/app-operation-'.(int)$package['id'].'.json';
        $operation=is_file($operationPath)?json_decode((string)file_get_contents($operationPath),true):null;
        if(!is_array($operation)){
            $operation=['package_id'=>(int)$package['id'],'first_task_id'=>(int)$task['id'],'archive_sha256'=>(string)$package['sha256'],'base_pointer'=>json_decode((string)file_get_contents(root_path().'.composer-runtime/current.json'),true)];
            if(file_put_contents($operationPath,json_encode($operation,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('无法记录应用更新操作');
        }
        if(($operation['archive_sha256']??'')!==$package['sha256'])throw new RuntimeException('应用更新操作绑定已改变');
        $signature=['sha256'=>$hashes,'runtime_app_operation'=>$operation];
        file_put_contents($stage.'/signature.json',json_encode($signature,JSON_THROW_ON_ERROR));
        $binding=['kind'=>'app','package_id'=>(int)$package['id'],'task_id'=>(int)$task['id'],'context'=>array_intersect_key($_SERVER,array_flip(['HTTP_HOST','SERVER_NAME','SERVER_ADDR','SERVER_PORT','HTTPS']))];
        $encoded=json_encode($binding,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $manager=new ComposerRuntimeUpdateService(rtrim(root_path(),'/'));
        $result=$manager->apply($stage,$next,[
            'immutable_code'=>true,'bootstrap_context'=>$binding,
            'sql'=>fn(string $generation)=>$manager->bootstrapChild(['--app-runtime-prepare',$generation,$extractPath,$encoded]),
            'files'=>fn()=>null,'finalize'=>fn()=>[],'marker'=>fn()=>null,
            'complete'=>fn(string $generation)=>$manager->bootstrapChild(['--app-runtime-complete',$generation,$extractPath,$encoded])['completion'],
        ]);
        return $result['completion'];
    }

    public function prepareRuntimeAppPackage(string $extractPath, array $binding): array
    {
        if(PHP_SAPI!=='cli'||empty($GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE']))throw new RuntimeException('应用运行时只能由隔离更新进程准备');
        $manifest=$this->readManifest($extractPath);$code=(string)$manifest['code'];
        AppRegistryService::prepareRuntimeMigrations($code,(string)$manifest['version']);
        \app\common\service\billing\PackageProvisionService::prepareRuntimeSchema();
        return ['prepared'=>true];
    }

    public function completeRuntimeAppPackage(string $extractPath, array $binding): array
    {
        if(PHP_SAPI!=='cli'||empty($GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE']))throw new RuntimeException('应用运行时只能由隔离更新进程完成');
        $package=$this->getPackage((int)$binding['package_id']);
        $task=UpdateTask::where(['id'=>(int)$binding['task_id'],'package_id'=>(int)$package['id'],'status'=>'running'])->findOrEmpty();
        if($task->isEmpty())throw new RuntimeException('应用运行时任务绑定无效');
        $manifest=$this->readManifest($extractPath);Db::startTrans();
        try {
            $result=$this->installVerifiedApp($package,$task,$extractPath,$manifest,true);
            $result['composer_runtime']=['generation'=>$GLOBALS['AIGC_RUNTIME_GENERATION']];
            $this->finishTask($task,'success',$result);
            if(!Db::connect()->getPdo()->inTransaction())throw new RuntimeException('应用元数据事务被隐式提交');
            Db::commit();return $result;
        }catch(Throwable $e){Db::rollback();throw $e;}
    }

    private function downloadWithFallback(array $data): array
    {
        try {
            return UpdateSourceClient::download((string)($data['download_url'] ?? ''), (string)($data['sha256'] ?? ''), (string)($data['format'] ?? 'zip'));
        } catch (\Throwable $e) {
            if (empty($data['fallback_url'])) {
                throw $e;
            }
            return UpdateSourceClient::download((string)$data['fallback_url'], (string)($data['fallback_sha256'] ?? ''), (string)($data['fallback_format'] ?? 'tar.gz'));
        }
    }

    private function recordPackage(array $remote, array $download, string $source): array
    {
        $row = UpdatePackage::create([
            'package_id' => (string)($remote['package_id'] ?? ''),
            'type' => 'app',
            'source' => $source,
            'app_code' => (string)($remote['app_code'] ?? ''),
            'version' => (string)($remote['version'] ?? ''),
            'format' => (string)($download['format'] ?? 'zip'),
            'local_path' => (string)$download['path'],
            'extract_path' => '',
            'sha256' => (string)$download['sha256'],
            'package_size' => (int)$download['size'],
            'manifest_json' => $remote['manifest'] ?? [],
            'status' => 'downloaded',
            'error' => '',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        return $row->toArray();
    }

    private function getPackage(int $packageId): UpdatePackage
    {
        $package = UpdatePackage::where(['id' => $packageId, 'type' => 'app'])->findOrEmpty();
        if ($package->isEmpty()) {
            throw new RuntimeException('应用更新包不存在');
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

    private function readManifest(string $extractPath): array
    {
        $manifest = json_decode((string)file_get_contents($extractPath . '/manifest.json'), true);
        if (!is_array($manifest) || empty($manifest['code']) || empty($manifest['version'])) {
            throw new RuntimeException('manifest.json 格式错误');
        }
        return $manifest;
    }

    private function copyDirectory(string $source, string $target, array $excludeTop = []): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $top = explode(DIRECTORY_SEPARATOR, $relative)[0];
            if (in_array($top, $excludeTop, true)) {
                continue;
            }
            $dest = $target . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0777, true);
                }
                continue;
            }
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0777, true);
            }
            copy($item->getPathname(), $dest);
        }
    }

    private function runSqlMigrations(string $dir, string $appCode, string $version): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            $migrationKey = basename($file);
            $exists = AppMigration::where([
                'scope' => 'platform',
                'app_code' => $appCode,
                'tenant_id' => 0,
                'migration_key' => $migrationKey,
                'status' => 'success',
            ])->findOrEmpty();
            if (!$exists->isEmpty()) {
                continue;
            }
            $migration = AppMigration::create([
                'scope' => 'platform',
                'app_code' => $appCode,
                'tenant_id' => 0,
                'version' => $version,
                'migration_key' => $migrationKey,
                'batch' => date('YmdHis'),
                'status' => 'running',
                'error' => '',
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $content = trim((string)file_get_contents($file));
            if ($content === '') {
                $migration->save(['status' => 'success', 'update_time' => time()]);
                continue;
            }
            try {
                $sqlPrefix = config('database.connections.mysql.prefix');
                $this->ensureSqlMigrationExecutor();
                SqlMigrationExecutor::execute($content, $sqlPrefix);
                $migration->save(['status' => 'success', 'error' => '', 'update_time' => time()]);
            } catch (Throwable $e) {
                $migration->save(['status' => 'failed', 'error' => $e->getMessage(), 'update_time' => time()]);
                throw $e;
            }
        }
    }

    private function ensureSqlMigrationExecutor(): void
    {
        if (class_exists(SqlMigrationExecutor::class)) {
            return;
        }
        $path = root_path() . 'app/common/service/database/SqlMigrationExecutor.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    private function createTask(UpdatePackage $package, string $action): UpdateTask
    {
        return UpdateTask::create([
            'type' => 'app',
            'action' => $action,
            'package_id' => (int)$package['id'],
            'app_code' => (string)$package['app_code'],
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

    private function finishTask(UpdateTask $task, string $status, array $result = [], string $error = ''): void
    {
        $task->save([
            'status' => $status,
            'result_json' => $result,
            'error' => $error,
            'update_time' => time(),
        ]);
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
