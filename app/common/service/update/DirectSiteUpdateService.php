<?php
declare(strict_types=1);
namespace app\common\service\update;

use RuntimeException;
use Throwable;

/** Canonical site deployment. Only replaced paths have temporary rollback copies. */
class DirectSiteUpdateService
{
    private string $root;
    private $writer = null;
    private ?array $legacy = null;

    public function __construct(string $root, private string $php = '', private string $runner = '')
    {
        $this->root = rtrim(realpath($root) ?: $root, '/');
        $this->php = $php ?: PHP_BINARY;
    }

    public static function enabled(string $root): bool
    {
        $file = rtrim($root, '/') . '/runtime/direct-deployment.json';
        $state = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return is_array($state) && ($state['schema'] ?? null) === 1 && ($state['mode'] ?? '') === 'canonical';
    }

    /** Every canonical HTTP reader holds this until exit; writers never wait indefinitely. */
    public static function acquireReader(string $root)
    {
        $directory = rtrim($root, '/') . '/runtime';
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('无法创建站点运行目录');
        $file = $directory . '/site-update.lock';
        if (is_link($file)) throw new RuntimeException('站点更新锁不能是链接');
        $handle = fopen($file, 'c+e');
        if (!$handle || !flock($handle, LOCK_SH | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('本站正在更新，请稍后重试');
        }
        if (is_file($directory . '/direct-update-active.json')) {
            fclose($handle);
            throw new RuntimeException('本站更新恢复尚未完成');
        }
        return $handle;
    }

    /** Release this request's read lease before entering the authenticated writer path. */
    public static function hasPendingOperation(string $root, int $packageId, int $taskId): bool
    {
        $root=rtrim($root,'/');$active=$root.'/runtime/direct-update-active.json';
        if(!is_file($active))return false;
        $pointer=json_decode((string)file_get_contents($active),true);
        $digest=(string)($pointer['digest']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new RuntimeException('更新恢复日志无法核验，保留任务状态');
        $state=json_decode((string)file_get_contents($root.'/runtime/update_workspace/direct-'.$digest.'/state.json'),true);
        return (int)($state['binding']['package_id']??0)===$packageId && (int)($state['binding']['task_id']??0)===$taskId;
    }

    public static function releaseRequestReader(): void
    {
        if (is_resource($GLOBALS['AIGC_DIRECT_READER'] ?? null)) fclose($GLOBALS['AIGC_DIRECT_READER']);
        unset($GLOBALS['AIGC_DIRECT_READER']);
    }

    /** Read-only dependency/CLI probe; never labels a failed process launch as executed SQL. */
    public function assertRuntimeAvailable(): array
    {
        foreach (['proc_open', 'proc_get_status', 'proc_close', 'proc_terminate', 'stream_set_blocking'] as $function) {
            if (!function_exists($function)) throw new RuntimeException('应用安装需要 PHP CLI 子进程支持，请启用 '.$function);
        }
        if (!is_executable($this->php)) throw new RuntimeException('应用安装找不到可执行的 PHP CLI，请核对当前 PHP 版本的命令行程序');
        $runner = $this->runner ?: $this->root.'/scripts/composer-runtime-runner.php';
        if (!is_file($runner) || !is_readable($runner)) throw new RuntimeException('应用安装运行程序缺失或不可读，请先修复系统文件');
        return $this->child(['--probe', $this->root, json_encode(['min_php'=>'8.0.0', 'required_extensions'=>['pdo_mysql','mbstring','openssl'], 'probe_classes'=>['think\\App']], JSON_THROW_ON_ERROR)]);
    }

    /** Same signed digest as apply, including old receipts that predate operation bindings. */
    public function assertPackageRetryable(string $extract, string $version): void
    {
        $digest = hash('sha256', (string)file_get_contents($extract.'/signature.json').'|'.$version);
        if (!is_file($this->receipt($digest))) return;
        $previous = $this->json($this->receipt($digest));
        if (($previous['sql_state'] ?? '') === 'running') {
            $detail = trim((string)($previous['error'] ?? ''));
            throw new RuntimeException('上次 SQL 执行结果未知，禁止自动重放'.($detail !== '' ? '；首次失败原因：'.$detail : '；请查看首次安装失败任务的错误详情并核对数据库'));
        }
    }

    public function apply(string $extract, array $manifest, array $callbacks): array
    {
        self::releaseRequestReader();
        $this->lock();
        try {
            $this->recoverLocked($callbacks['confirm'] ?? null);
            $version = (string)($manifest['version'] ?? '');
            if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) throw new RuntimeException('更新版本无效');
            $signature = $this->json($extract . '/signature.json');
            $digest = hash('sha256', (string)file_get_contents($extract . '/signature.json') . '|' . $version);
            $receipt = $this->receipt($digest);
            if (is_file($receipt)) {
                $previous = $this->json($receipt);
                $this->assertPackageRetryable($extract, $version);
                if (($previous['status'] ?? '') === 'committed' && empty($callbacks['repeatable'])) return ['completion' => $previous['completion'], 'recovered' => true];
            }
            if (isset($manifest['base_version'])) {
                preg_match("/['\"]version['\"]\s*=>\s*['\"]([^'\"]+)['\"]/",(string)file_get_contents($this->root.'/config/project.php'),$currentVersion);
                if (($currentVersion[1]??'')!==$manifest['base_version']) throw new RuntimeException('UPGRADE_CHAIN_UNSUPPORTED');
            }
            $plan = $this->packagePlan($extract, $manifest, $signature, (array)($callbacks['replace_dirs']??[]));
            $state = $this->start($digest, $version, $plan, $callbacks['binding'] ?? [], 'system', (array)($callbacks['watch_paths']??[]));
            if (is_file($receipt) && ($this->json($receipt)['status']??'')!=='committed' && ($this->json($receipt)['sql_state'] ?? '') === 'done') $state['sql_state'] = 'done';
            try {
                if (isset($callbacks['before_sql'])) ($callbacks['before_sql'])();
                $this->install($state);
                if ($state['sql_state'] !== 'done') {
                    if (isset($callbacks['validate_sql'])) ($callbacks['validate_sql'])($this->root);
                    $state['sql_state'] = 'running'; $this->save($state);
                    if (isset($callbacks['sql'])) ($callbacks['sql'])($this->root);
                    $state['sql_state'] = 'done'; $this->save($state);
                }
                $state['phase'] = 'completing'; $this->save($state);
                $completion = isset($callbacks['complete']) ? ($callbacks['complete'])($this->root) : [];
                return $this->commit($state, (array)$completion);
            } catch (Throwable $failure) {
                if ($state['phase']==='committed') throw $failure;
                // Reacquisition proves no inherited updater child is still using files.
                $this->unlock();$this->lock();
                if ($state['phase'] === 'completing' && isset($callbacks['confirm'])) {
                    try { $confirmed = ($callbacks['confirm'])($this->root, $state['binding']); }
                    catch (Throwable $unknown) { throw new RuntimeException('更新完成状态待确认，已保留恢复资料：' . $unknown->getMessage(), 0, $failure); }
                    if (is_array($confirmed)) return $this->commit($state, $confirmed);
                }
                $state['error'] = $failure->getMessage();
                $this->rollback($state);
                throw $failure;
            }
        } finally { $this->unlock(); }
    }

    public function recover(?callable $confirm = null): array
    {
        self::releaseRequestReader(); $this->lock();
        try { return $this->recoverLocked($confirm); } finally { $this->unlock(); }
    }

    private function recoverLocked(?callable $confirm): array
    {
        if (!is_file($this->active())) return ['status' => 'idle'];
        $active = $this->json($this->active());
        $digest = (string)($active['digest'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) throw new RuntimeException('站点更新恢复日志无效');
        $state = $this->json($this->job($digest) . '/state.json');
        if (($state['digest'] ?? '') !== $digest) throw new RuntimeException('站点更新恢复绑定无效');
        if (($state['phase'] ?? '') === 'committed') return $this->commit($state, (array)$state['completion']);
        if (($state['phase'] ?? '') === 'completing') {
            if (!$confirm) throw new RuntimeException('更新完成状态需要独立核验，恢复资料已保留');
            $completion = $confirm($this->root, $state['binding']);
            if (is_array($completion)) return $this->commit($state, $completion);
        }
        $this->rollback($state);
        return ['status' => 'rolled_back', 'sql_state' => $state['sql_state']];
    }

    /** Called only by a signed legacy candidate immediately before its metadata commit. */
    public function beginLegacyMigration(string $candidate, string $version, array $binding): void
    {
        if (self::enabled($this->root)) return;
        $candidate = realpath($candidate) ?: '';
        $base = $this->root . '/.composer-runtime/generations/';
        if (!str_starts_with($candidate, $base) || !preg_match('/^release-[a-f0-9]{24,64}$/D', basename($candidate))) throw new RuntimeException('旧运行时迁移来源无效');
        $pointer = $this->json($this->root . '/.composer-runtime/current.json');
        $metadata = $this->json($candidate . '/generation.json');
        if (($pointer['generation'] ?? '') !== basename($candidate) || ($metadata['version'] ?? '') !== $version || ($metadata['vendor'] ?? '') !== 'generation') throw new RuntimeException('旧运行时迁移版本不一致');
        $this->lock();
        try {
            if (is_file($this->active())) throw new RuntimeException('存在尚未完成的正常目录迁移');
            $plan = [];
            foreach (['app', 'config', 'route', 'extend'] as $directory) {
                if (!is_dir($candidate . '/' . $directory)) continue;
                foreach ($this->files($candidate . '/' . $directory) as $relative => $source) {
                    $path = $directory . '/' . $relative;
                    if ($path === 'config/install.lock') continue;
                    $this->add($plan, $path, $source);
                }
                if (is_dir($this->root . '/' . $directory)) {
                    foreach ($this->files($this->root . '/' . $directory) as $relative => $source) {
                        $path = $directory . '/' . $relative;
                        if ($path !== 'config/install.lock' && !is_file($candidate . '/' . $path)) $this->add($plan, $path, null);
                    }
                }
            }
            // A legacy baseline can still use root/vendor. Do not replace it
            // while such a request holds its actual generation lease.
            if (!$this->same($candidate . '/vendor', $this->root . '/vendor')) {
                $this->assertLegacySiteVendorUnused();
                $this->add($plan, 'vendor', $candidate . '/vendor');
            }
            foreach (['composer.json', 'composer.lock'] as $name) $this->add($plan, $name, $candidate . '/' . $name);
            $digest = hash('sha256', 'canonical-migration|' . $candidate . '|' . $version);
            $state = $this->start($digest, $version, $plan, $binding + ['deployment_generation'=>$candidate], 'legacy');
            $this->legacy = $state;
            $this->install($this->legacy);
            $this->legacy['sql_state'] = 'done';
            $this->legacy['phase'] = 'completing';
            $this->writeMarker($version);
            $this->save($this->legacy);
        } catch (Throwable $e) {
            if ($this->legacy !== null) $this->rollback($this->legacy);
            $this->legacy = null; $this->unlock(); throw $e;
        }
    }

    public function finishLegacyMigration(array $completion): void
    {
        if ($this->legacy === null) return;
        try { $this->commit($this->legacy, $completion); }
        finally { $this->legacy = null; $this->unlock(); }
    }

    public function rollbackLegacyMigration(): void
    {
        if ($this->legacy === null) return;
        try { $this->rollback($this->legacy); }
        finally { $this->legacy = null; $this->unlock(); }
    }

    private function assertLegacySiteVendorUnused(): void
    {
        foreach (glob($this->root . '/.composer-runtime/generations/*/generation.json') ?: [] as $file) {
            if (($this->json($file)['vendor'] ?? '') !== 'site') continue;
            $lease = dirname(dirname($file)) . '/../leases/' . basename(dirname($file)) . '.lock';
            if (!is_file($lease) || is_link($lease)) throw new RuntimeException('旧依赖读者保护不可核验，请排空本站请求后迁移');
            $handle = fopen($lease, 'r');
            if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) { if (is_resource($handle)) fclose($handle); throw new RuntimeException('旧请求仍在使用站点依赖，请稍后迁移'); }
            $this->legacyVendorLeases[] = $handle;
        }
    }
    private array $legacyVendorLeases = [];

    private function packagePlan(string $extract, array $manifest, array $signature, array $appDirs = []): array
    {
        $hashes = (array)($signature['sha256'] ?? []);
        foreach ($signature['files'] ?? [] as $entry) $hashes[$entry['path']] = $entry['sha256'];
        foreach (['files', 'composer-runtime'] as $directory) {
            if (!is_dir($extract . '/' . $directory)) continue;
            foreach ($this->files($extract . '/' . $directory) as $relative => $source) {
                $name = $directory . '/' . $relative;
                if (!isset($hashes[$name]) || !hash_equals((string)$hashes[$name], (string)hash_file('sha256', $source))) throw new RuntimeException('更新文件未签名或校验失败: ' . $name);
            }
        }
        $plan = []; $full = (array)($manifest['full_replace_dirs'] ?? []);
        foreach($appDirs as $dir){if(!preg_match('#^app/apps/[a-z][a-z0-9_]*$#D',$dir))throw new RuntimeException('应用替换目录无效');$full[]=$dir;}
        foreach ($full as $path) {
            if (!in_array($path,$appDirs,true) && !in_array($path, ['public/admin','public/platform','public/pc','public/_nuxt','public/media','public/mobile','public/mp-weixin','public/static'], true)) throw new RuntimeException('覆盖目录不允许');
            if (!is_dir($extract . '/files/' . $path)) throw new RuntimeException('完整覆盖目录缺失');
            $this->add($plan, $path, $extract . '/files/' . $path);
        }
        if (is_dir($extract . '/files')) foreach ($this->files($extract . '/files') as $path => $source) {
            $this->safe($path);
            if (array_filter($full, fn($dir) => str_starts_with($path, $dir . '/'))) continue;
            $this->add($plan, $path, $source);
        }
        foreach ((array)($manifest['delete_files'] ?? []) as $path) {
            $this->safe((string)$path);
            if (!array_filter($full, fn($dir) => str_starts_with($path, $dir . '/'))) $this->add($plan, $path, null);
        }
        if (isset($manifest['composer_runtime'])) {
            foreach (['vendor', 'composer.json', 'composer.lock'] as $name) {
                $source = $extract . '/composer-runtime/' . $name;
                if (!file_exists($source)) throw new RuntimeException('完整依赖载荷缺失');
                $this->add($plan, $name, $source);
            }
        } elseif (is_dir($extract . '/composer-runtime')) throw new RuntimeException('未声明完整依赖载荷');
        return $plan;
    }

    private function add(array &$plan, string $path, ?string $source): void
    {
        $this->safe($path, in_array($path, ['vendor','composer.json','composer.lock'], true));
        if ($source !== null && $this->same($source, $this->root . '/' . $path)) return;
        if ($source === null && !file_exists($this->root . '/' . $path)) return;
        $plan[$path] = ['source' => $source, 'existed' => file_exists($this->root . '/' . $path), 'state' => 'planned', 'original_hash'=>file_exists($this->root.'/'.$path)?$this->fingerprint($this->root.'/'.$path):null];
    }

    private function start(string $digest, string $version, array $plan, array $binding, string $kind, array $watchPaths = []): array
    {
        foreach($watchPaths as $path)$this->safe($path);
        foreach (array_merge(['runtime/direct-deployment.json','upgrade/version.json','public/upgrade/version.json'],$watchPaths) as $path) {
            $covered = false;
            foreach ($plan as $planned => $entry) {
                if (str_starts_with($path, $planned . '/')) { $covered = true; break; }
            }
            if (!$covered && !isset($plan[$path])) $plan[$path] = ['source' => null, 'existed' => file_exists($this->root . '/' . $path), 'state' => 'planned', 'watch' => true, 'original_hash'=>file_exists($this->root.'/'.$path)?$this->fingerprint($this->root.'/'.$path):null];
        }
        $state = ['schema'=>1,'digest'=>$digest,'version'=>$version,'phase'=>'prepared','sql_state'=>'pending','kind'=>$kind,'binding'=>$binding,'paths'=>$plan];
        $this->directory($this->job($digest)); $this->save($state);
        $this->atomicJson($this->active(), ['schema'=>1,'digest'=>$digest]);
        return $state;
    }

    private function install(array &$state): void
    {
        $state['phase'] = 'copying'; $this->save($state);
        foreach ($state['paths'] as $path => &$entry) {
            $target = $this->root . '/' . $path; $backup = $this->job($state['digest']) . '/backup/' . $path;
            $this->assertTarget($path);
            if ($entry['existed']) {
                $this->directory(dirname($backup));
                if (!empty($entry['watch'])) $this->copy($target, $backup);
                elseif (!rename($target, $backup)) throw new RuntimeException('无法暂存被替换文件: ' . $path);
            }
            $entry['state'] = 'backed_up'; $this->save($state);
            if (empty($entry['watch']) && $entry['source'] !== null) $this->copy($entry['source'], $target);
            $entry['state'] = 'installed'; $this->save($state);
        }
        unset($entry);
        $state['phase'] = 'files_done'; $this->save($state);
    }

    private function commit(array &$state, array $completion): array
    {
        $this->writeMarker($state['version']);
        $state['phase'] = 'committed'; $state['completion'] = $completion; $this->save($state);
        $this->atomicJson($this->receipt($state['digest']), ['schema'=>1,'status'=>'committed','version'=>$state['version'],'sql_state'=>$state['sql_state'],'completion'=>$completion]);
        $this->finish($state);
        return ['completion'=>$completion,'deployment'=>'canonical'];
    }

    private function rollback(array &$state): void
    {
        foreach (array_reverse($state['paths'], true) as $path => $entry) {
            $target = $this->root . '/' . $path; $backup = $this->job($state['digest']) . '/backup/' . $path;
            $this->assertTarget($path);
            if ($entry['existed']) {
                if (!file_exists($backup)) {
                    if ($entry['state'] !== 'planned' && (!file_exists($target) || $this->fingerprint($target)!==($entry['original_hash']??null))) throw new RuntimeException('恢复备份缺失: ' . $path);
                    continue;
                }
                $this->remove($target); $this->directory(dirname($target));
                if (!rename($backup, $target)) throw new RuntimeException('恢复文件失败: ' . $path);
            } else $this->remove($target);
        }
        $this->atomicJson($this->receipt($state['digest']), ['schema'=>1,'status'=>'rolled_back','version'=>$state['version'],'sql_state'=>$state['sql_state'],'error'=>$state['error']??'','binding'=>$state['binding']??[]]);
        $this->finish($state);
    }

    private function finish(array $state): void
    {
        if (is_file($this->active()) && ($this->json($this->active())['digest'] ?? '') === $state['digest']) unlink($this->active());
        try{$this->remove($this->job($state['digest']));}catch(Throwable $cleanup){/* Durable terminal receipt prevents a cleanup error from rolling back a committed update. */}
    }
    private function writeMarker(string $version): void { $this->atomicJson($this->root . '/runtime/direct-deployment.json', ['schema'=>1,'mode'=>'canonical','version'=>$version]); }
    private function save(array $state): void { $this->atomicJson($this->job($state['digest']) . '/state.json', $state); }
    private function active(): string { return $this->root . '/runtime/direct-update-active.json'; }
    private function job(string $digest): string { return $this->root . '/runtime/update_workspace/direct-' . $digest; }
    private function receipt(string $digest): string { return $this->root . '/runtime/update_receipts/' . $digest . '.json'; }
    private function lock(): void
    {
        $this->directory($this->root . '/runtime'); $path=$this->root . '/runtime/site-update.lock';
        if (is_link($path)) throw new RuntimeException('站点更新锁不能是链接');
        $this->writer=fopen($path,'c+');
        if (!$this->writer || !flock($this->writer,LOCK_EX|LOCK_NB)) { $this->unlock(); throw new RuntimeException('本站仍有请求或更新正在执行，请稍后重试'); }
    }
    private function unlock(): void
    {
        foreach ($this->legacyVendorLeases as $lease) if (is_resource($lease)) fclose($lease);
        $this->legacyVendorLeases=[];
        if (is_resource($this->writer)) fclose($this->writer); $this->writer=null;
    }
    public function writerLease() { return $this->writer; }

    /** A fresh interpreter inherits the writer lease even when its parent exits. */
    public function child(array $arguments): array
    {
        if (!function_exists('proc_open') || !is_executable($this->php)) throw new RuntimeException('站点更新需要可执行的 PHP CLI');
        $runner=$this->runner ?: $this->root.'/scripts/composer-runtime-runner.php';
        $descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        if(is_resource($this->writer))$descriptors[3]=$this->writer;
        $pipes=[];$process=proc_open(array_merge([$this->php,'-d','opcache.enable_cli=0',$runner,'--site-root',$this->root],$arguments),$descriptors,$pipes,$this->root);
        if(!is_resource($process))throw new RuntimeException('无法启动站点更新子进程');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $stdout='';$stderr='';$started=microtime(true);
        do {
            $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);$status=proc_get_status($process);
            if(!$status['running'])break;
            if(microtime(true)-$started>300 || strlen($stdout)+strlen($stderr)>1048576){proc_terminate($process);throw new RuntimeException('站点更新子进程超时或输出过大');}
            usleep(10000);
        }while(true);
        $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);
        $code=$status['exitcode']>=0?$status['exitcode']:$closed;$result=json_decode($stdout,true);
        if($code!==0 || !is_array($result) || ($result['passed']??false)!==true)throw new RuntimeException('站点更新子进程失败: '.trim($stderr?:$stdout));
        return $result;
    }

    /** Recovery is filesystem-only until an interrupted final metadata commit needs confirmation. */
    public static function bootstrap(string $root): void
    {
        if(is_file(rtrim($root,'/').'/runtime/direct-update-active.json')){
            $service=new self($root,PHP_BINDIR.'/php');
            $service->recover(function(string $site,array $binding)use($service):?array{
                $reply=$service->child(['--direct-confirm',$site,'-',json_encode($binding,JSON_THROW_ON_ERROR)]);
                return !empty($reply['committed'])?(array)$reply['completion']:null;
            });
        }
        $GLOBALS['AIGC_DIRECT_READER']=self::acquireReader($root);
    }

    private function safe(string $path, bool $dependency=false): void
    {
        if ($path==='' || str_starts_with($path,'/') || str_contains($path,'\\') || str_contains($path,"\0") || array_intersect(explode('/',$path),['.','..',''])) throw new RuntimeException('更新路径无效');
        if (preg_match('#^(?:\.env(?:\.|$)|\.composer-runtime(?:/|$)|runtime(?:/|$)|oem(?:/|$)|public/(?:uploads|storage|qrcode|oem-assets)(?:/|$)|config/install\.lock$|(?:public/)?upgrade/version\.json$)#',$path)) throw new RuntimeException('不能替换站点保护路径: '.$path);
        if (!$dependency && ($path==='vendor' || str_starts_with($path,'vendor/') || in_array($path,['composer.json','composer.lock'],true))) throw new RuntimeException('依赖必须整目录安装');
        $this->assertTarget($path);
    }
    private function assertTarget(string $path): void
    {
        $current=$this->root;
        foreach (explode('/',$path) as $part) { $current.='/'.$part; if (is_link($current)) throw new RuntimeException('更新路径不能包含链接: '.$path); }
    }
    private function files(string $directory): array
    {
        if (is_link($directory)) throw new RuntimeException('不能处理链接目录');
        $result=[];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || !$file->isFile()) throw new RuntimeException('更新文件不能是链接或特殊文件');
            $result[substr($file->getPathname(),strlen($directory)+1)]=$file->getPathname();
        }
        ksort($result); return $result;
    }
    private function same(string $source,string $target): bool
    {
        if (is_link($target) || !file_exists($target)) return false;
        if (is_file($source)) return is_file($target) && filesize($source)===filesize($target) && hash_file('sha256',$source)===hash_file('sha256',$target);
        if (!is_dir($target)) return false;
        $a=$this->files($source);$b=$this->files($target);
        if(array_keys($a)!==array_keys($b))return false;
        foreach($a as $path=>$file)if(!$this->same($file,$b[$path]))return false;
        return true;
    }
    private function fingerprint(string $path):string
    {
        if(is_file($path))return hash_file('sha256',$path);
        $hash=[];foreach($this->files($path) as $relative=>$file)$hash[$relative]=hash_file('sha256',$file);
        return hash('sha256',json_encode($hash,JSON_THROW_ON_ERROR));
    }
    private function directory(string $path): void { if (is_link($path) || (!is_dir($path) && !mkdir($path,0755,true))) throw new RuntimeException('更新目录不可写'); }
    private function copy(string $source,string $target): void
    {
        if (is_link($source)) throw new RuntimeException('复制来源不能是链接');
        if (is_dir($source)) { $this->directory($target); foreach($this->files($source) as $relative=>$file)$this->copy($file,$target.'/'.$relative); return; }
        $this->directory(dirname($target));$temporary=dirname($target).'/.'.basename($target).'.update-'.bin2hex(random_bytes(6));
        try { if (!copy($source,$temporary) || !chmod($temporary,fileperms($source)&0777) || !rename($temporary,$target)) throw new RuntimeException('文件替换失败'); }
        finally { if(is_file($temporary))unlink($temporary); }
    }
    private function remove(string $path): void
    {
        if(is_link($path))throw new RuntimeException('不能删除链接路径');
        if(is_dir($path)){foreach(new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS) as $file)$this->remove($file->getPathname());if(!rmdir($path))throw new RuntimeException('无法清理更新目录');}
        elseif(file_exists($path)&&!unlink($path))throw new RuntimeException('无法清理更新文件');
    }
    private function json(string $path): array { if(is_link($path))throw new RuntimeException('元数据不能是链接');$value=json_decode((string)file_get_contents($path),true);if(!is_array($value))throw new RuntimeException('更新元数据无效');return $value; }
    private function atomicJson(string $path,array $value): void
    {
        $this->directory(dirname($path));$temporary=$path.'.tmp-'.bin2hex(random_bytes(6));
        try { if(file_put_contents($temporary,json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($temporary,$path))throw new RuntimeException('无法保存更新状态'); }
        finally {if(is_file($temporary))unlink($temporary);}
    }
}
