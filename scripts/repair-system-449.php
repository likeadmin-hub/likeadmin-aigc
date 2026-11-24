<?php
declare(strict_types=1);

// Run the extracted repair kit as the site owner, outside the served document root.
if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80200) exit(1);

function require449(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function save449(string $path, string $bytes, int $mode): void
{
    $tmp = tempnam(dirname($path), '.repair449-');
    require449($tmp !== false && dirname($tmp) === dirname($path), '无法创建同目录临时文件');
    try {
        require449(file_put_contents($tmp, $bytes) === strlen($bytes) && chmod($tmp, $mode), '写入失败');
        if (is_file($path)) {
            require449(fileowner($tmp) === fileowner($path), '请使用站点文件所有者执行');
            if (filegroup($tmp) !== filegroup($path)) require449(chgrp($tmp, filegroup($path)), '不能保留文件组');
        }
        require449(rename($tmp, $path), '无法原子替换文件');
    } finally { if (is_file($tmp)) unlink($tmp); }
}

try {
    $options = getopt('', ['site:', 'archive:', 'apply']);
    $site = realpath($options['site'] ?? '');
    $zipPath = $options['archive'] ?? '';
    $apply = isset($options['apply']);
    require449(isset($options['site']) && $site && is_file($site.'/config/project.php') && is_file($zipPath),
        '用法: php scripts/repair-system-449.php --site=/站点 --archive=/原始449.zip [--apply]');
    $archiveHash = 'e885d8aec88c81c49e0b6012d37017ae8bd84d88dff32c86dae02cd35ffdec70';
    require449(hash_file('sha256', $zipPath) === $archiveHash, '原始 449 归档哈希不匹配');
    $zip = new ZipArchive();
    require449($zip->open($zipPath) === true, '归档不可读');
    $digest = hash('sha256', $zip->getFromName('signature.json').'|1.0.449');
    $zip->close();
    $runtime = $site.'/runtime';
    require449(realpath($runtime) === $runtime, '运行目录无效');
    $locks = [];
    foreach (['site-update-intent.lock', 'site-update.lock'] as $name) {
        require449(!is_link($runtime.'/'.$name), '锁文件无效');
        $lock = fopen($runtime.'/'.$name, 'c+');
        require449($lock && flock($lock, LOCK_EX | LOCK_NB), '站点仍有请求或更新任务，请在维护窗口重试');
        $locks[] = $lock;
    }
    require449(!file_exists($runtime.'/direct-update-active.json') && !file_exists($site.'/.composer-runtime/active.json'), '站点存在执行中的更新');
    $mode = json_decode((string)@file_get_contents($runtime.'/direct-deployment.json'), true);
    require449(!is_file($site.'/.composer-runtime/current.json') || ($mode['mode'] ?? '') === 'canonical', '此工具只适用于标准站点目录更新');
    $version = json_decode(file_get_contents($site.'/upgrade/version.json'), true, 512, JSON_THROW_ON_ERROR);
    require449(($version['version'] ?? '') === '1.0.448' && preg_match("/['\"]version['\"]\s*=>\s*['\"]1\.0\.448['\"]/",file_get_contents($site.'/config/project.php')) === 1, '仅适用于仍为 1.0.448 的站点');
    $service = 'app/common/service/update/SystemPackageUpdateService.php';
    $before = '9fbce0483e2040624e1fe3bc63ba088975f5747bd8e3eb41a17d545f4c778e58';
    $files = [
        'app/common/service/update/system449/20261124_platform_task_statistics.sql'=>'bb116d59137a37c5f11c9ffec08085cede44b780e94148d304a9ac15125f59f4',
        'app/common/service/update/system449/20261124_power_market_recommendation.sql'=>'5924fae9ffd705e864361d1919f0669c17ab30735b9ae8efba18f9438db25771',
        'app/common/service/update/system449/20261124_tenant_supply_markup.sql'=>'cae43995f595f119134baddd857b3280fad5be429798b9c9129a078c9f275cb9',
        'app/common/service/update/system449/20261124_tenant_task_statistics.sql'=>'474f915f49e9ea5aa930819649423b7aaec45554425f0120b0022fec3c69cc78',
        'app/common/service/update/SystemPackageUpdateService.php'=>'809d3ae722c3f2e3e0a7c9d6ed1eb54d451da4d1044c701134b3f8f91ae9170f',
    ];
    $kit = dirname(__DIR__);
    foreach ($files as $name => $hash) {
        require449(is_file($kit.'/'.$name) && hash_file('sha256', $kit.'/'.$name) === $hash, '修复文件不完整: '.$name);
        $dest = $site.'/'.$name;
        require449(!is_link($dest) && realpath(dirname(dirname($dest))) === dirname(dirname($dest))
            && (!file_exists(dirname($dest)) || realpath(dirname($dest)) === dirname($dest)), '目标路径无效');
        if (file_exists($dest)) require449(is_file($dest) && in_array(hash_file('sha256',$dest),
            $name === $service ? [$before,$hash] : [$hash], true), '站点文件已另行修改: '.$name);
    }
    require449(is_file($site.'/'.$service), '升级服务文件缺失');
    // The stock preparation plans ALL five system scripts before the first SQL.
    // Bind any pending retry to the exact original package, first failure and task.
    foreach ([
        'app/common/service/update/DirectSiteUpdateService.php'=>'70566cd1fc65a9ee72ea771db7d2360de83aa9606bc8d28a66123ce13c21e93e',
        'app/common/service/database/RuntimeMigrationPlan.php'=>'bc2504e18f6c7c8dbde6515a5d091d5a8376fc52f0d19dfa69145a75b9346400',
        'app/common/service/database/SqlMigrationExecutor.php'=>'e1d592a14adb50050977bee09c9ff8b53f9b2c2f02d3a7a4f51c3b03f6d91004',
        'scripts/composer-runtime-runner.php'=>'e6e976410233c9903e4c077876bb6fd38e7893bcde7c2f02057451278b076102',
    ] as $path => $hash) require449(hash_file('sha256',$site.'/'.$path) === $hash, '执行器与 448 基线不一致: '.$path);
    $receipt = $runtime.'/update_receipts/'.$digest.'.json';
    $backup = $runtime.'/repair-system-449-'.$digest;
    require449(!is_link($backup) && !is_link($receipt) && (!file_exists(dirname($receipt)) || realpath(dirname($receipt))===dirname($receipt)), '回执或备份目录无效');
    $original = null;
    if (is_file($receipt)) {
        $original = file_get_contents($receipt);
        $state = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
        $error = '站点更新子进程失败: 新迁移不支持会话变量或动态 SQL，请使用静态迁移';
        require449(($state['status']??'')==='rolled_back' && ($state['version']??'')==='1.0.449'
            && ($state['binding']['kind']??'')==='system' && ($state['binding']['archive_sha256']??'')===$archiveHash
            && (int)($state['binding']['task_id']??0)>0 && (int)($state['binding']['package_id']??0)>0
            && trim($state['error']??'')===$error && in_array($state['sql_state']??'',['running','pending'],true),
            '此回执与已确认的首次故障不符，需要单独核对');
        require $site.'/vendor/autoload.php';
        (new think\App($site))->initialize();
        $task = think\facade\Db::name('update_task')->where('id',$state['binding']['task_id'])->find();
        $package = think\facade\Db::name('update_package')->where('id',$state['binding']['package_id'])->find();
        require449($task && $package && $task['type']==='system' && $task['action']==='apply' && $task['status']==='failed'
            && (int)$task['package_id']===(int)$package['id'] && trim($task['error'])===$error
            && $package['type']==='system' && $package['sha256']===$archiveHash && $package['version']==='1.0.449',
            '首次失败任务或归档记录不匹配');
        if (is_file($backup.'/receipt.json')) {
            $first = json_decode(file_get_contents($backup.'/receipt.json'),true,512,JSON_THROW_ON_ERROR);
            $expected = $state;
            $expected['sql_state'] = $first['sql_state'];
            require449($first === $expected, '已有备份属于不同的尝试，请人工核对');
        } else {
            require449(hash_file('sha256',$site.'/'.$service)===$before, '非原始执行器的失败必须单独核对');
        }
    }
    $receiptBefore = $state['sql_state'] ?? null;
    if ($apply) {
        if (!is_dir($backup)) require449(mkdir($backup,0700), '备份目录创建失败');
        if (!is_file($backup.'/service.php')) {
            require449(hash_file('sha256',$site.'/'.$service)===$before,'找不到原始升级器备份');
            save449($backup.'/service.php',file_get_contents($site.'/'.$service),0600);
        }
        require449(hash_file('sha256',$backup.'/service.php')===$before,'原始升级器备份校验失败');
        if ($original !== null && !is_file($backup.'/receipt.json')) save449($backup.'/receipt.json',$original,0600);
        // Install the static definitions before making the new planner visible.
        foreach ($files as $name=>$hash) {
            $dest = $site.'/'.$name;
            if (is_file($dest) && hash_file('sha256',$dest)===$hash) continue;
            if (!is_dir(dirname($dest))) require449(mkdir(dirname($dest),0755),'迁移目录创建失败');
            save449($dest,file_get_contents($kit.'/'.$name),is_file($dest) ? fileperms($dest)&0777 : 0644);
        }
        if ($original !== null && $state['sql_state']==='running') {
            $state['sql_state']='pending';
            save449($receipt,json_encode($state,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),fileperms($receipt)&0777);
        }
    }
    echo json_encode(['mode'=>$apply?'applied':'preview','installed_version'=>'1.0.448','receipt_before'=>$receiptBefore,
        'original_backup'=>$apply?$backup:null],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
