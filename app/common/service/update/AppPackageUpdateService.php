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
    /** Read-only catalog: files on disk do not imply installation or tenant entitlement. */
    public function localCatalog(array $registered): array
    {
        $byCode=[];foreach($registered as $row)$byCode[$row['code']]=$row;
        $builder=new BundledAppPackageService(root_path(),dirname(rtrim(base_path(),'/')));
        foreach(glob(base_path().'apps/*/manifest.json')?:[] as $file){
            $code=basename(dirname($file));
            try{$manifest=$builder->manifest($code);AppRegistryService::assertRuntimeMigrationsReady($code);$available=true;}
            catch(Throwable $error){continue;}
            if(!$manifest)continue;
            $row=$byCode[$code]??['code'=>$code,'name'=>$manifest['name']??$code,'description'=>$manifest['description']??'','icon'=>$manifest['icon']??'','category'=>$manifest['category']??'','sort'=>$manifest['sort']??0,'client_tags'=>implode(',',(array)($manifest['frontends']??[])),'status'=>'not_installed','current_version'=>''];
            $row['disk_version']=$manifest['version'];$row['effective_version']=BundledAppPackageService::versionFloor((string)$row['current_version'],(string)$manifest['version']);$row['local_install_available']=$available;
            $byCode[$code]=$row;
        }
        return array_values($byCode);
    }

    public function prepareLocalPackage(string $appCode): array
    {
        AppRegistryService::assertValidCode($appCode);
        AppRegistryService::assertCoreCompatible(AppRegistryService::getManifest($appCode));
        AppRegistryService::assertRuntimeMigrationsReady($appCode);
        $current=(string)(App::where('code',$appCode)->value('current_version')?:'');
        $builder=new BundledAppPackageService(root_path(),dirname(rtrim(base_path(),'/')));
        $manifest=$builder->manifest($appCode);
        if($current!==''&&version_compare((string)$manifest['version'],$current,'<'))throw new RuntimeException('本地应用版本落后于已安装版本，请先同步系统文件');
        $snapshot=$builder->create($appCode);
        return $this->recordPackage(['package_id'=>'local_'.$appCode.'_'.bin2hex(random_bytes(8)),'app_code'=>$appCode,'version'=>$manifest['version'],'manifest'=>$manifest],$snapshot,'local');
    }

    public function installLocal(string $appCode): array
    {
        $package=$this->prepareLocalPackage($appCode);
        $preflight=$this->preflight((int)$package['id'],$appCode);
        if(!$preflight['passed'])throw new RuntimeException(implode('；',$preflight['errors']));
        return $this->apply((int)$package['id']);
    }

    private function assertVersionAllowed(array $manifest, string $source): void
    {
        $code=(string)$manifest['code'];$app=App::where('code',$code)->findOrEmpty();
        $registered=$app->isEmpty()?'':(string)$app['current_version'];
        $disk=is_file(AppRegistryService::manifestPath($code))?AppRegistryService::getManifest($code):[];
        $floor=BundledAppPackageService::versionFloor($registered,(string)($disk['version']??''));
        if($floor!==''&&version_compare((string)$manifest['version'],$floor,'<'))throw new RuntimeException('应用包版本低于本站现有代码，请使用本地安装或同步本地版本');
        if(!$app->isEmpty()&&$app['status']!==AppRegistryService::STATUS_REMOVED&&$registered!==''&&version_compare((string)$manifest['version'],$registered,'<=')&&$source!=='local')throw new RuntimeException('应用包版本必须大于当前版本');
    }

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
        $this->assertVersionAllowed(['code'=>$appCode,'version'=>$targetVersion], 'cloud');
        $registered=(string)(App::where('code',$appCode)->value('current_version')?:'');
        $disk=is_file(AppRegistryService::manifestPath($appCode))?AppRegistryService::getManifest($appCode):[];
        $current=BundledAppPackageService::versionFloor($registered,(string)($disk['version']??''));
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
        $preflight = $extractor->preflight((string)$package['local_path'], base_path() . 'apps', (int)$package['package_size'], (string)$package['format']);
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
            $this->assertVersionAllowed($manifest, (string)$package['source']);
            AppRegistryService::assertCoreCompatible($manifest);
            $direct = new DirectSiteUpdateService(root_path(), PHP_SAPI==='cli' ? PHP_BINARY : PHP_BINDIR.'/php');
            $preflight['environment']['runtime'] = $direct->assertRuntimeAvailable();
            \app\common\service\database\RuntimeMetadataTransactionGate::assertMetadata();
            AppRegistryService::assertRuntimeMigrationsReady((string)$manifest['code'], $extract['path'].'/migrations');
            if (!is_file(root_path().'.composer-runtime/current.json') || DirectSiteUpdateService::enabled(root_path())) {
                $stage=runtime_path().'update_workspace/canonical-preflight-'.bin2hex(random_bytes(8));
                try {
                    $this->stageCanonicalPackage($extract['path'], $manifest, $stage);
                    $direct->assertPackageRetryable($stage, UpdateSourceClient::currentCoreVersion());
                } finally { $this->removeCanonicalStage($stage); }
            }
            // Long-lived workers hold the site reader lease even for a metadata-only local install.
            // Fail preflight before presenting an executable action; never discover this only at the writer lock.
            ProjectUpdateProcessGate::assertDrained(root_path());
            $preflight['environment']['app_worker_gate'] = 'ready';
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
            {
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
            if(is_file(root_path().'.composer-runtime/current.json') && !DirectSiteUpdateService::enabled(root_path()))return $this->applyRuntimePackage($package,$task,$extractPath,$manifest);
            return $this->applyCanonicalPackage($package,$task,$extractPath,$manifest);
        } catch (Throwable $e) {
            if (DirectSiteUpdateService::hasPendingOperation(root_path(),(int)$package['id'],(int)$task['id'])) throw $e;
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
        $tenantStates = Db::name('tenant_app')->where('app_code', $appCode)->select()->toArray();
        $result = AppRegistryService::installFromLocal($appCode);
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
        // Installation may provision new defaults, but must retain existing purchased/disabled grants byte-for-byte.
        foreach($tenantStates as $row){
            $where=['tenant_id'=>$row['tenant_id'],'app_code'=>$appCode];
            $current=Db::name('tenant_app')->where($where)->find();
            if(!$current)throw new RuntimeException('应用安装改变了已有租户授权记录');
            $restore=array_intersect_key($row,array_flip(['version','buy_status','enable_status','shelf_status','expire_time','update_time']));
            $changed=false;foreach($restore as $key=>$value)if((string)$current[$key] !== (string)$value)$changed=true;
            if($changed)Db::name('tenant_app')->where($where)->update($restore);
        }
        AppVersion::where(['app_code' => $appCode, 'version' => $manifest['version']])->update(['package_path' => $package['local_path']]);
        $package->save(['status' => 'applied', 'error' => '', 'update_time' => time()]);
        $this->finishTask($task, 'success', $result);
        return $result;
    }

    private function applyCanonicalPackage(UpdatePackage $package, UpdateTask $task, string $extractPath, array $manifest): array
    {
        if(!hash_equals((string)$package['sha256'],(string)hash_file('sha256',(string)$package['local_path'])))throw new RuntimeException('应用更新包已改变');
        $extractor=new PackageExtractService();$fresh=$extractor->extract((string)$package['local_path'],(string)$package['format']);
        if(($fresh['extract_status']??'')!=='success')throw new RuntimeException('应用更新包解压失败');
        $extractPath=$fresh['path'];$extractor->verifySignatureManifest($extractPath);
        if($this->readManifest($extractPath)!==$manifest)throw new RuntimeException('应用包预检内容与签名档案不同');
        $code=(string)$manifest['code'];
        if(!preg_match('/^[a-z][a-z0-9_]*$/D',$code))throw new RuntimeException('应用编码无效');
        $stage=runtime_path().'update_workspace/canonical-app-'.bin2hex(random_bytes(8));
        try {
            $watch=$this->stageCanonicalPackage($extractPath,$manifest,$stage);
            $version=UpdateSourceClient::currentCoreVersion();
            $next=['version'=>$version,'base_version'=>$version,'package_mode'=>'incremental','full_replace_dirs'=>[],'delete_files'=>[],'sql_order'=>[]];
            $binding=['kind'=>'app','core_version'=>(string)config('project.version'),'package_id'=>(int)$package['id'],'task_id'=>(int)$task['id'],'archive_sha256'=>(string)$package['sha256'],'deployment_generation'=>rtrim(root_path(),'/'),'context'=>array_intersect_key($_SERVER,array_flip(['HTTP_HOST','SERVER_NAME','SERVER_ADDR','SERVER_PORT','HTTPS']))];
            $encoded=json_encode($binding,JSON_THROW_ON_ERROR);
            $direct=new DirectSiteUpdateService(root_path(),PHP_SAPI==='cli'?PHP_BINARY:PHP_BINDIR.'/php');
            $impact=ProjectUpdateProcessGate::inspectUpdate(root_path(),$stage,$next);
            $result=$direct->apply($stage,$next,[
                'binding'=>$binding,'repeatable'=>true,'replace_dirs'=>['app/apps/'.$code],'watch_paths'=>array_values(array_unique($watch)),
                'before_sql'=>function()use($manifest,$package,$impact):void{$this->assertVersionAllowed($manifest,(string)$package['source']);ProjectUpdateProcessGate::assertUpdateAllowed(root_path(),$impact);},
                'validate_sql'=>fn(string $site)=>$direct->child(['--app-runtime-check',$site,$extractPath,$encoded]),
                'sql'=>fn(string $site)=>$direct->child(['--app-runtime-prepare',$site,$extractPath,$encoded]),
                'complete'=>fn(string $site)=>$direct->child(['--app-runtime-complete',$site,$extractPath,$encoded])['completion'],
                'confirm'=>function(string $site,array $context)use($direct):?array{
                    $reply=$direct->child(['--direct-confirm',$site,'-',json_encode($context,JSON_THROW_ON_ERROR)]);
                    return !empty($reply['committed'])?(array)$reply['completion']:null;
                },
            ]);
            return $result['completion'];
        } finally { $this->removeCanonicalStage($stage); }
    }

    private function stageCanonicalPackage(string $extractPath, array $manifest, string $stage): array
    {
        $code=(string)$manifest['code'];
        $this->copyDirectory($extractPath,$stage.'/files/app/apps/'.$code);
        $watch=['public/admin/index.html','public/pc/index.html'];
        foreach((array)($manifest['public_assets']??[]) as $asset){
            $source=(string)($asset['source']??'');$target=(string)($asset['target']??'');
            foreach([$source,$target] as $relative)if($relative===''||str_starts_with($relative,'/')||str_contains($relative,'\\')||str_contains($relative,chr(0))||array_intersect(explode('/',$relative),['.','..','']))throw new RuntimeException('应用资源路径不安全');
            if(!is_file($extractPath.'/'.$source))throw new RuntimeException('应用资源缺失');
            $this->copyDirectoryFile($extractPath.'/'.$source,$stage.'/files/'.$target);
            $watch[]=$target;
        }
        $hashes=[];foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage,\FilesystemIterator::SKIP_DOTS)) as $file)$hashes[substr($file->getPathname(),strlen($stage)+1)]=hash_file('sha256',$file->getPathname());
        file_put_contents($stage.'/signature.json',json_encode(['sha256'=>$hashes],JSON_THROW_ON_ERROR));
        return $watch;
    }

    private function copyDirectoryFile(string $source,string $target):void
    {
        if(!is_dir(dirname($target))&&!mkdir(dirname($target),0755,true))throw new RuntimeException('应用暂存目录不可写');
        if(!copy($source,$target))throw new RuntimeException('应用资源暂存失败');
    }

    private function removeCanonicalStage(string $path):void
    {
        if(is_link($path))throw new RuntimeException('应用暂存路径不能是链接');
        if(is_dir($path)){foreach(new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS) as $file)$this->removeCanonicalStage($file->getPathname());rmdir($path);}
        elseif(is_file($path))unlink($path);
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
            'before_sql'=>fn()=>$this->assertVersionAllowed($manifest,(string)$package['source']),
            'validate_sql'=>fn(string $generation)=>$manager->bootstrapChild(['--app-runtime-check',$generation,$extractPath,$encoded]),
            'sql'=>fn(string $generation)=>$manager->bootstrapChild(['--app-runtime-prepare',$generation,$extractPath,$encoded]),
            'files'=>fn()=>null,'finalize'=>fn()=>[],'marker'=>fn()=>null,
            'complete'=>fn(string $generation)=>$manager->bootstrapChild(['--app-runtime-complete',$generation,$extractPath,$encoded])['completion'],
        ]);
        return $result['completion'];
    }

    /** Read-only checks run in the candidate interpreter before SQL is marked running. */
    public function checkRuntimeAppPackage(string $extractPath, array $binding): array
    {
        if(PHP_SAPI!=='cli'||empty($GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE']))throw new RuntimeException('应用运行时只能由隔离更新进程检查');
        \app\common\service\database\RuntimeMetadataTransactionGate::assertMetadata();
        $manifest=$this->readManifest($extractPath);
        AppRegistryService::assertRuntimeMigrationsReady((string)$manifest['code']);
        return ['checked'=>true];
    }

    public function prepareRuntimeAppPackage(string $extractPath, array $binding): array
    {
        if(PHP_SAPI!=='cli'||empty($GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE']))throw new RuntimeException('应用运行时只能由隔离更新进程准备');
        \app\common\service\database\RuntimeMetadataTransactionGate::assertMetadata();
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
        \app\common\service\database\RuntimeMetadataTransactionGate::assertMetadata();
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
