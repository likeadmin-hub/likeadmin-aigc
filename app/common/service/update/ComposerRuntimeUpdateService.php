<?php

declare(strict_types=1);

namespace app\common\service\update;

use RuntimeException;
use Throwable;

/** Offline installation of signed, complete Composer runtimes. Never mutates site/vendor. */
class ComposerRuntimeUpdateService
{
    public const CAPABILITY_VERSION = '1.0.391';
    public const CODE_DIRS = ['app', 'config', 'route', 'extend'];
    private string $root;
    private string $store;
    private string $php;
    private string $runner;
    private $heldLock = null;

    public function __construct(string $root, string $php = '', string $runner = '')
    {
        $this->root = rtrim($root, '/\\');
        $this->store = $this->root . '/.composer-runtime';
        $this->php = $php ?: (PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR . '/php');
        $this->runner=$runner;
    }

    public function preflight(string $extract, array $manifest): array
    {
        if (!array_key_exists('composer_runtime', $manifest)) {
            if (file_exists($extract . '/composer-runtime')) throw new RuntimeException('未声明 Composer 运行时包');
            $this->changedPaths($extract, $manifest);
            return ['passed'=>true, 'uses_existing_runtime'=>true];
        }
        $this->changedPaths($extract, $manifest);
        $spec = $manifest['composer_runtime'] ?? [];
        if (!is_array($spec) || ($spec['schema'] ?? null) !== 1 || ($spec['payload_dir'] ?? '') !== 'composer-runtime') {
            throw new RuntimeException('Composer 运行时包协议不受支持');
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string)($spec['min_updater_version'] ?? ''))
            || version_compare(self::CAPABILITY_VERSION, $spec['min_updater_version'], '<')) {
            throw new RuntimeException('请先安装 Composer 更新器桥接版本 ' . self::CAPABILITY_VERSION);
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', (string)($spec['min_php'] ?? ''))
            || version_compare($spec['min_php'], '8.2.0', '<') || version_compare(PHP_VERSION, $spec['min_php'], '<')) {
            throw new RuntimeException('Composer 自动升级要求 PHP >= ' . ($spec['min_php'] ?? '8.2.0'));
        }
        if (!is_array($spec['required_extensions'] ?? null) || !is_array($spec['probe_classes'] ?? null)) {
            throw new RuntimeException('Composer 运行时包缺少扩展或类验证清单');
        }
        foreach ($spec['required_extensions'] as $extension) {
            if (!is_string($extension) || !preg_match('/^[a-zA-Z0-9_]+$/', $extension) || !extension_loaded($extension)) {
                throw new RuntimeException('当前 PHP 缺少运行时扩展: ' . (string)$extension);
            }
        }
        foreach ($spec['probe_classes'] as $class) {
            if (!is_string($class) || !preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $class)) {
                throw new RuntimeException('Composer 运行时类验证清单格式错误');
            }
        }
        $payload = rtrim($extract, '/') . '/composer-runtime';
        foreach (['composer.json', 'composer.lock', 'vendor/autoload.php', 'vendor/services.php', 'vendor/composer/autoload_real.php', 'vendor/composer/installed.json', 'vendor/composer/installed.php'] as $required) {
            if (!is_file($payload . '/' . $required) || is_link($payload . '/' . $required)) {
                throw new RuntimeException('Composer 运行时包缺少完整文件: ' . $required);
            }
        }
        $files = $this->treeFiles($payload);
        foreach ($files as $relative => $path) {
            if ($relative !== 'composer.json' && $relative !== 'composer.lock' && !str_starts_with($relative, 'vendor/')) {
                throw new RuntimeException('Composer 运行时包包含不允许的文件: ' . $relative);
            }
        }
        $signature = $this->readJson($extract . '/signature.json');
        $hashes = (array)($signature['sha256'] ?? []);
        foreach ((array)($signature['files'] ?? []) as $entry) {
            if (is_array($entry)) $hashes[(string)($entry['path'] ?? '')] = (string)($entry['sha256'] ?? '');
        }
        $bytes = 0;
        foreach (['composer-runtime', 'files'] as $directory) {
            if (!is_dir($extract . '/' . $directory)) continue;
            foreach ($this->treeFiles($extract . '/' . $directory) as $relative => $path) {
                $signedPath = $directory . '/' . $relative;
                if (!isset($hashes[$signedPath]) || !hash_equals((string)$hashes[$signedPath], (string)hash_file('sha256', $path))) {
                    throw new RuntimeException('Composer 更新文件未签名或校验失败: ' . $signedPath);
                }
                $bytes += (int)filesize($path);
            }
        }
        foreach (['composer.json', 'composer.lock', 'vendor'] as $forbidden) {
            if (file_exists($extract . '/files/' . $forbidden)) throw new RuntimeException('Composer 文件只能通过独立运行时包安装');
        }
        $composer = $this->readJson($payload . '/composer.json');
        $lock = $this->readJson($payload . '/composer.lock');
        if (!isset($composer['require']['php']) || !is_array($lock['packages'] ?? null)) throw new RuntimeException('Composer 清单或锁文件不完整');
        $installed = $this->readJson($payload . '/vendor/composer/installed.json');
        $installed = isset($installed['packages']) ? $installed['packages'] : $installed;
        $lockedVersions = []; $installedVersions = [];
        foreach ($lock['packages'] as $package) $lockedVersions[(string)($package['name'] ?? '')] = (string)($package['version'] ?? '');
        foreach ($installed as $package) {
            if (!is_array($package)) throw new RuntimeException('Composer 已安装依赖清单无效');
            $installedVersions[(string)($package['name'] ?? '')] = (string)($package['version'] ?? '');
        }
        ksort($lockedVersions); ksort($installedVersions);
        if ($lockedVersions !== $installedVersions) throw new RuntimeException('Composer vendor 与锁文件生产依赖版本不一致');
        $pattern = '/ComposerAutoloaderInit([A-Za-z0-9_]+)/';
        preg_match($pattern, (string)file_get_contents($payload . '/vendor/autoload.php'), $entryInit);
        preg_match($pattern, (string)file_get_contents($payload . '/vendor/composer/autoload_real.php'), $realInit);
        if (empty($entryInit[1]) || empty($realInit[1]) || $entryInit[1] !== $realInit[1]) throw new RuntimeException('Composer 自动加载文件不属于同一依赖版本');
        foreach (array_merge([$composer], $lock['packages']) as $package) {
            foreach ((array)($package['require'] ?? []) as $requirement => $constraint) {
                if (str_starts_with($requirement, 'ext-') && !extension_loaded(substr($requirement, 4))) {
                    throw new RuntimeException('Composer 依赖缺少 PHP 扩展: ' . $requirement);
                }
            }
        }
        $this->directory($this->store);
        if (!is_writable($this->root) || !is_writable($this->store)) throw new RuntimeException('Composer 运行时安装目录不可写');
        $codeBytes = 0;
        foreach (self::CODE_DIRS as $dir) if (is_dir($this->root . '/' . $dir)) foreach ($this->treeFiles($this->root . '/' . $dir) as $path) $codeBytes += (int)filesize($path);
        $free = disk_free_space($this->root);
        if ($free === false || $free < ($bytes + $codeBytes) * 3 + 1048576) throw new RuntimeException('Composer 运行时安装磁盘空间不足');
        $probe = $this->runChild(['--probe', $payload, json_encode($spec, JSON_UNESCAPED_SLASHES)]);
        return ['passed' => true, 'bytes' => $bytes, 'php_cli' => $this->php, 'probe' => $probe];
    }

    /** Baseline is created by bridge bootstrap before any functional update starts. */
    public function ensureBaseline(): string
    {
        if (is_file($this->store . '/current.json')) return $this->generationFromPointer($this->readJson($this->store . '/current.json'));
        $lock = $this->lock(true);
        try {
            if (is_file($this->store . '/current.json')) return $this->generationFromPointer($this->readJson($this->store . '/current.json'));
            $id = 'baseline-' . bin2hex(random_bytes(12));
            $generation = $this->store . '/generations/' . $id;
            $this->directory($generation);
            foreach (self::CODE_DIRS as $dir) if (is_dir($this->root . '/' . $dir)) $this->copyTree($this->root . '/' . $dir, $generation . '/' . $dir);
            $project=(string)@file_get_contents($this->root.'/config/project.php');
            preg_match("/['\"]version['\"]\s*=>\s*['\"]([^'\"]+)['\"]/",$project,$matched);
            $baselineVersion=$matched[1]??self::CAPABILITY_VERSION;
            $this->atomicJson($generation . '/generation.json', ['schema'=>1, 'vendor'=>'site', 'version'=>$baselineVersion]);
            $this->atomicJson($this->store . '/current.json', ['schema'=>1, 'generation'=>$id, 'version'=>$baselineVersion]);
            return $generation;
        } finally { $this->unlock($lock); }
    }

    /**
     * Callbacks retain business SQL behavior, but code/dependency activation is an
     * independently recoverable filesystem transaction. SQL is never rolled back
     * or blindly replayed after an indeterminate crash.
     */
    public function apply(string $extract, array $manifest, array $callbacks): array
    {
        $this->ensureBaseline();
        if(!empty($callbacks['immutable_code']))$manifest['immutable_code']=true;
        $lock = $this->lock();
        $this->heldLock=$lock;
        try {
            $this->recoverLocked();
            $this->preflight($extract, $manifest);
            $digest = hash_file('sha256', $extract . '/signature.json');
            // Every attempt gets a new immutable path. Even a briefly activated
            // candidate can remain pinned by an in-flight request after rollback.
            $id = 'release-' . substr($digest, 0, 40) . bin2hex(random_bytes(12));
            $journalPath = $this->store . '/journals/' . $digest . '.json';
            $prior = is_file($journalPath) ? $this->readJson($journalPath) : [];
            if (($prior['sql_state'] ?? '') === 'running') throw new RuntimeException('上次 SQL 执行中断，结果未知；为避免重复计费或任务变更，禁止自动重放，请先核对数据库');
            if (($prior['status'] ?? '') === 'committed') return ['generation'=>$this->generationFromPointer(['schema'=>1,'generation'=>$prior['generation']]), 'completion'=>$prior['completion'] ?? [], 'recovered'=>true];
            $generation = $this->store . '/generations/' . $id;
            $stage = $generation . '.stage-' . bin2hex(random_bytes(6));
            $this->directory($stage);
            if (isset($manifest['composer_runtime'])) {
                $this->copyTree($extract . '/composer-runtime', $stage);
            } else {
                $current = $this->generationFromPointer($this->readJson($this->store . '/current.json'));
                $metadata = $this->readJson($current . '/generation.json');
                $vendor = $metadata['vendor'] === 'site' ? $this->root . '/vendor' : $current . '/vendor';
                $this->copyTree($vendor, $stage . '/vendor');
                foreach (['composer.json','composer.lock'] as $file) {
                    $source = is_file($current . '/' . $file) ? $current . '/' . $file : $this->root . '/' . $file;
                    $this->copyPath($source, $stage . '/' . $file);
                }
            }
            $currentCode=$this->generationFromPointer($this->readJson($this->store.'/current.json'));
            foreach (self::CODE_DIRS as $dir) {
                if (is_dir($currentCode . '/' . $dir)) $this->copyTree($currentCode . '/' . $dir, $stage . '/' . $dir);
                if (is_dir($extract . '/files/' . $dir)) $this->copyTree($extract . '/files/' . $dir, $stage . '/' . $dir);
            }
            foreach ((array)($manifest['delete_files'] ?? []) as $relative) {
                $this->safePath((string)$relative);
                if (in_array(explode('/', $relative)[0], self::CODE_DIRS, true)) $this->remove($stage . '/' . $relative);
            }
            if(is_file($extract.'/files/runtime-loader.php'))$this->copyPath($extract.'/files/runtime-loader.php',$stage.'/runtime-loader.php');
            if (is_file($stage . '/config/project.php')) {
                $version = (string)$manifest['version'];
                if (!preg_match('/^\d+(?:\.\d+)+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) throw new RuntimeException('运行时目标版本无效');
                $content = preg_replace("/('version'\\s*=>\\s*)'[^']*'/", "\$1'{$version}'", (string)file_get_contents($stage . '/config/project.php'), 1);
                if ($content === null || file_put_contents($stage . '/config/project.php', $content) === false) throw new RuntimeException('无法写入运行时项目版本');
            }
            $this->atomicJson($stage . '/generation.json', ['schema'=>1,'vendor'=>'generation','version'=>(string)$manifest['version']]);
            if (file_exists($generation)) throw new RuntimeException('不可覆盖已存在的 Composer 运行时版本');
            if (!rename($stage, $generation)) throw new RuntimeException('无法发布完整 Composer 运行时暂存目录');
            // Validate application roots and service discovery in the complete candidate
            // before SQL or ordinary site files can change.
            $this->runChild(['--candidate-probe', $generation]);
            $backup = $this->store . '/backups/' . $digest;
            if (is_dir($backup)) $this->remove($backup);
            $this->directory($backup);
            $paths = $this->changedPaths($extract, $manifest);
            $saved = [];
            foreach ($paths as $relative) {
                $target = $this->root . '/' . $relative;
                $this->assertTargetPath($relative);
                $saved[$relative] = file_exists($target);
                if ($saved[$relative]) $this->copyPath($target, $backup . '/' . $relative);
            }
            $journal = ['schema'=>1, 'digest'=>$digest, 'status'=>'prepared', 'sql_state'=>$prior['sql_state'] ?? 'pending', 'previous_pointer'=>$this->readJson($this->store . '/current.json'), 'generation'=>$id, 'backup'=>$digest, 'paths'=>$saved,'bootstrap_context'=>$callbacks['bootstrap_context']??[],'owner_pid'=>getmypid()];
            $this->atomicJson($journalPath, $journal);
            $this->atomicJson($this->store . '/active.json', ['digest'=>$digest]);
            try {
                if ($journal['sql_state'] !== 'done') {
                    $journal['sql_state']='running'; $this->atomicJson($journalPath,$journal);
                    ($callbacks['sql'])($generation);
                    $journal['sql_state']='done'; $this->atomicJson($journalPath,$journal);
                }
                $journal['status']='copying'; $this->atomicJson($journalPath,$journal);
                ($callbacks['files'])();
                $finalize = ($callbacks['finalize'])($generation);
                $journal['status']='activating'; $this->atomicJson($journalPath,$journal);
                ($callbacks['marker'])();
                $this->atomicJson($this->store . '/current.json', ['schema'=>1,'generation'=>$id,'version'=>(string)$manifest['version']]);
                $completion = isset($callbacks['complete']) ? ($callbacks['complete'])($generation, $finalize) : [];
                $journal['status']='committed'; $journal['completion']=$completion; $this->atomicJson($journalPath,$journal);
                unlink($this->store . '/active.json');
                return ['generation'=>$generation,'finalize'=>$finalize,'completion'=>$completion];
            } catch (Throwable $failure) {
                // Close only our lease, then reacquire. A live inherited candidate
                // must finish before either rollback or committed-state recovery.
                $this->heldLock=null;$this->unlock($lock);$lock=null;
                $lock=$this->lock();$this->heldLock=$lock;
                $confirmed=$this->confirmBootstrapCommit($journal);
                if($confirmed!==null){$journal['status']='committed';$journal['completion']=$confirmed;$this->atomicJson($journalPath,$journal);unlink($this->store.'/active.json');return ['generation'=>$generation,'completion'=>$confirmed,'recovered'=>true];}
                try { $this->restore($journal); $journal['status']='rolled_back'; $this->atomicJson($journalPath,$journal); unlink($this->store . '/active.json'); }
                catch (Throwable $restoreFailure) { throw new RuntimeException($failure->getMessage() . '; 文件回滚失败，恢复日志已保留: ' . $restoreFailure->getMessage(), 0, $failure); }
                throw $failure;
            }
        } finally { $this->heldLock=null; $this->unlock($lock); }
    }

    public function recover(): void
    {
        $lock=$this->lock(); try { $this->recoverLocked(); } finally { $this->unlock($lock); }
    }

    /** Live updates keep ordinary requests pinned to the previously committed generation. */
    public function recoverBeforeBootstrap(): ?string
    {
        if(!is_file($this->store.'/active.json'))return null;
        $handle=fopen($this->store.'/update.lock','c');
        if(!$handle)throw new RuntimeException('无法检查运行时恢复锁');
        if(!flock($handle,LOCK_EX|LOCK_NB)){
            fclose($handle);
            $active=$this->readJson($this->store.'/active.json');
            if(!preg_match('/^[a-f0-9]{64}$/',(string)($active['digest']??'')))throw new RuntimeException('更新日志无效');
            $journal=$this->readJson($this->store.'/journals/'.$active['digest'].'.json');
            return $this->generationFromPointer($journal['previous_pointer']);
        }
        try{$this->recoverLocked();}finally{$this->unlock($handle);}
        return null;
    }

    public function finalize(string $generation, string $extract, string $version): array
    {
        $context = array_intersect_key($_SERVER, array_flip(['HTTP_HOST','SERVER_NAME','SERVER_ADDR','SERVER_PORT','HTTPS']));
        return $this->runChild(['--finalize', $generation, $extract, $version, json_encode($context, JSON_UNESCAPED_SLASHES)]);
    }

    public function generationFromPointer(array $pointer): string
    {
        if (($pointer['schema'] ?? null)!==1 || !preg_match('/^(?:baseline|release)-[a-f0-9]{24,64}$/', (string)($pointer['generation'] ?? ''))) throw new RuntimeException('Composer 运行时指针无效');
        $path=$this->store.'/generations/'.$pointer['generation'];
        if (!is_dir($path) || is_link($path)) throw new RuntimeException('Composer 运行时指向不存在的版本');
        return $path;
    }

    private function recoverLocked(): void
    {
        if (!is_file($this->store.'/active.json')) return;
        $active=$this->readJson($this->store.'/active.json');
        if (!preg_match('/^[a-f0-9]{64}$/',(string)($active['digest']??''))) throw new RuntimeException('Composer 更新恢复日志无效');
        $path=$this->store.'/journals/'.$active['digest'].'.json';
        $journal=$this->readJson($path);
        if (($journal['status']??'')!=='committed') {
            $confirmed=$this->confirmBootstrapCommit($journal);
            if($confirmed!==null){$journal['status']='committed';$journal['completion']=$confirmed;}
            else {$this->restore($journal);$journal['status']='rolled_back';}
            $this->atomicJson($path,$journal);
        }
        unlink($this->store.'/active.json');
    }

    private function confirmBootstrapCommit(array $journal): ?array
    {
        $binding=$journal['bootstrap_context']??[];
        if(!in_array($binding['kind']??'',['system','app'],true) || ($journal['status']??'')!=='activating')return null;
        $generation=$this->generationFromPointer(['schema'=>1,'generation'=>$journal['generation']]);
        $pointer=$this->readJson($this->store.'/current.json');
        if(($pointer['generation']??'')!==$journal['generation'])return null;
        $result=$this->runChild([($binding['kind']==='app'?'--app-runtime-recover':'--bootstrap-recover'),$generation,'-',json_encode($binding,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        return !empty($result['committed'])?(array)$result['completion']:null;
    }

    private function restore(array $journal): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/',(string)($journal['backup']??''))) throw new RuntimeException('Composer 回滚备份路径无效');
        // Restore code first; all existing processes still pin immutable generations.
        foreach ((array)$journal['paths'] as $relative=>$existed) {
            $this->assertTargetPath($relative);
            $target=$this->root.'/'.$relative;
            $this->remove($target);
            if ($existed) $this->copyPath($this->store.'/backups/'.$journal['backup'].'/'.$relative,$target);
        }
        $this->generationFromPointer($journal['previous_pointer']);
        $this->atomicJson($this->store.'/current.json',$journal['previous_pointer']);
    }

    private function changedPaths(string $extract,array $manifest): array
    {
        $paths=array_keys($this->treeFiles($extract.'/files'));
        if(!empty($manifest['immutable_code']))$paths=array_values(array_filter($paths,fn($p)=>!in_array(explode('/',$p)[0],self::CODE_DIRS,true)));
        $paths=array_merge($paths,(array)($manifest['full_replace_dirs']??[]),(array)($manifest['delete_files']??[]),['config/project.php','upgrade/version.json']);
        // Builtin-app synchronization can deploy declared static bridges and edit
        // the legacy HTML shells; include those side effects in the same backup.
        $appRoot = $extract . '/files/app/apps';
        if (is_dir($appRoot)) foreach (new \FilesystemIterator($appRoot, \FilesystemIterator::SKIP_DOTS) as $app) {
            if (!$app->isDir() || $app->isLink()) continue;
            $code = $app->getFilename();
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $code)) throw new RuntimeException('内置应用目录格式错误');
            $manifestPath = $app->getPathname() . '/manifest.json';
            if (!is_file($manifestPath)) $manifestPath = $this->root . '/app/apps/' . $code . '/manifest.json';
            if (!is_file($manifestPath)) continue;
            $appManifest = $this->readJson($manifestPath);
            foreach ((array)($appManifest['public_assets'] ?? []) as $asset) {
                if (!is_array($asset) || empty($asset['target'])) continue;
                $paths[] = (string)$asset['target'];
                $paths[] = 'public/admin/index.html';
                $paths[] = 'public/pc/index.html';
            }
        }
        foreach ($paths as $path) $this->assertTargetPath((string)$path);
        $paths=array_values(array_unique($paths)); sort($paths);
        $result=[];
        foreach($paths as $path) { foreach($result as $parent) if(str_starts_with($path,$parent.'/')) continue 2; $result[]=$path; }
        return $result;
    }

    private function assertTargetPath(string $relative): void
    {
        $this->safePath($relative);
        if(preg_match('#^(?:\.env(?:\.|$)|vendor(?:/|$)|composer\.(?:json|lock)$|\.composer-runtime(?:/|$)|runtime(?:/|$)|oem(?:/|$)|public/(?:uploads|storage|qrcode|oem-assets)(?:/|$)|config/install\.lock$)#',$relative)) throw new RuntimeException('Composer 更新不能更改站点保护路径: '.$relative);
        $path=$this->root;
        foreach(explode('/',$relative) as $segment) { $path.='/'.$segment; if(is_link($path)) throw new RuntimeException('更新目标包含符号链接: '.$relative); }
    }

    private function safePath(string $relative): void
    {
        if($relative==='' || str_contains($relative,'\\') || str_contains($relative,"\0") || preg_match('#(^/|^[A-Za-z]:|(^|/)\.\.?(/|$)|//)#',$relative)) throw new RuntimeException('Composer 更新路径不安全: '.$relative);
    }

    private function treeFiles(string $root): array
    {
        if(!is_dir($root) || is_link($root)) throw new RuntimeException('Composer 更新目录无效: '.$root);
        $result=[]; $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $entry) { if($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) throw new RuntimeException('Composer 更新包不允许链接或特殊文件: '.$entry->getPathname()); if($entry->isFile()) { $relative=substr($entry->getPathname(),strlen($root)+1); $this->safePath($relative); if(!is_readable($entry->getPathname())) throw new RuntimeException('Composer 更新文件不可读'); $result[$relative]=$entry->getPathname(); } }
        ksort($result); return $result;
    }

    private function copyTree(string $source,string $target): void
    {
        $this->directory($target); foreach($this->treeFiles($source) as $relative=>$path) $this->copyPath($path,$target.'/'.$relative);
    }

    private function copyPath(string $source,string $target): void
    {
        if(is_link($source) || is_link($target)) throw new RuntimeException('Composer 复制路径不允许链接');
        if(is_dir($source)) { $this->copyTree($source,$target); return; }
        $this->directory(dirname($target)); if(!is_file($source) || !copy($source,$target)) throw new RuntimeException('Composer 更新文件复制失败: '.$source); chmod($target,0644);
    }

    private function remove(string $path): void
    {
        if(is_link($path)) throw new RuntimeException('Composer 回滚路径不允许链接');
        if(is_dir($path)) { foreach(new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS) as $entry) $this->remove($entry->getPathname()); if(!rmdir($path)) throw new RuntimeException('Composer 回滚无法删除目录'); }
        elseif(file_exists($path) && !unlink($path)) throw new RuntimeException('Composer 回滚无法删除文件');
    }

    private function directory(string $path): void
    {
        for($parent=$path;str_starts_with($parent,$this->root.'/')||$parent===$this->root;$parent=dirname($parent))if(is_link($parent))throw new RuntimeException('Composer 运行时路径不允许链接');
        if(is_link($path) || (!is_dir($path) && !mkdir($path,0755,true)) || !is_writable($path)) throw new RuntimeException('Composer 运行时目录不可写: '.$path);
    }

    private function readJson(string $path): array
    {
        if(is_link($path)) throw new RuntimeException('Composer 状态文件不允许链接');
        $value=json_decode((string)@file_get_contents($path),true); if(!is_array($value)) throw new RuntimeException('Composer JSON 文件无效: '.$path); return $value;
    }

    private function atomicJson(string $path,array $value): void
    {
        $this->directory(dirname($path)); $temp=$path.'.tmp-'.bin2hex(random_bytes(6));
        $handle=fopen($temp,'xb'); if(!$handle) throw new RuntimeException('无法创建 Composer 状态文件');
        try { $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); if(fwrite($handle,$json)!==strlen($json) || !fflush($handle)) throw new RuntimeException('无法写入 Composer 状态文件'); if(function_exists('fsync')) fsync($handle); } finally { fclose($handle); }
        if(!rename($temp,$path)) { @unlink($temp); throw new RuntimeException('无法切换 Composer 状态文件'); }
    }

    private function lock(bool $waitForBaseline = false)
    {
        $this->directory($this->store);
        $handle = fopen($this->store . '/update.lock', 'c');
        if (!$handle) throw new RuntimeException('无法打开 Composer 运行时升级锁');
        $deadline = microtime(true) + ($waitForBaseline ? 30 : 0);
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
            if (!$waitForBaseline || microtime(true) >= $deadline) break;
            usleep(25000);
        } while (true);
        fclose($handle);
        throw new RuntimeException('已有 Composer 运行时升级正在执行');
    }

    private function unlock($lock): void { if(is_resource($lock))fclose($lock); }

    public function confirmHandoffCompletion(int $packageId, int $taskId, string $archiveHash): ?array
    {
        if(is_file($this->store.'/active.json'))$this->recover();
        if(!is_file($this->store.'/current.json'))return null;
        $pointer=$this->readJson($this->store.'/current.json');
        foreach(glob($this->store.'/journals/*.json')?:[] as $path){
            $journal=$this->readJson($path);$binding=$journal['bootstrap_context']??[];
            if(($journal['status']??'')!=='committed'||($binding['package_id']??0)!==$packageId||($binding['task_id']??0)!==$taskId||($binding['archive_sha256']??'')!==$archiveHash||($pointer['generation']??'')!==($journal['generation']??''))continue;
            $generation=$this->generationFromPointer($pointer);
            $verified=$this->runChild(['--bootstrap-recover',$generation,'-',json_encode($binding,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            if(!empty($verified['committed']))return (array)$verified['completion'];
        }
        return null;
    }

    public function bootstrapChild(array $arguments): array { return $this->runChild($arguments); }

    private function runChild(array $arguments): array
    {
        if(!function_exists('proc_open') || !is_file($this->php) || !is_executable($this->php)) throw new RuntimeException('自动依赖升级需要可执行的 PHP CLI 和 proc_open');
        $script=$this->runner?:$this->root.'/scripts/composer-runtime-runner.php';
        if(!is_file($script)) $script=dirname(__DIR__,4).'/scripts/composer-runtime-runner.php';
        $command=array_merge([$this->php,'-d','opcache.enable_cli=0',$script,'--site-root',$this->root],$arguments);
        $descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        // Inherit the same flock file description. A candidate keeps the writer lease
        // alive even if its coordinating interpreter is killed before metadata commit.
        if(is_resource($this->heldLock))$descriptors[3]=$this->heldLock;
        $pipes=[]; $process=proc_open($command,$descriptors,$pipes,$this->root);
        if(!is_resource($process)) throw new RuntimeException('无法启动 PHP CLI 运行时验证');
        fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false); $stdout='';$stderr='';$started=microtime(true);
        do { $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);$status=proc_get_status($process); if(!$status['running']) break; if(microtime(true)-$started>300 || strlen($stdout)+strlen($stderr)>1048576) { proc_terminate($process); fclose($pipes[1]);fclose($pipes[2]);proc_close($process);throw new RuntimeException('PHP CLI 运行时验证超时或输出过大'); } usleep(10000); } while(true);
        $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);$code=$status['exitcode']>=0?$status['exitcode']:$closed;
        $result=json_decode($stdout,true); if($code!==0 || !is_array($result) || ($result['passed']??false)!==true) throw new RuntimeException('PHP CLI 运行时验证失败: '.trim($stderr ?: $stdout)); return $result;
    }
}
