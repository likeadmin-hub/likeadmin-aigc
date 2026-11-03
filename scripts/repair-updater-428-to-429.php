<?php
declare(strict_types=1);
/** One-time operator bridge. Installs only updater entry code/UI; never marks an upgrade complete. */
const AIGC_429_REPAIR_ARCHIVE='b23bc0fdad7c75b59136906f74c2e14e84a32d58126e6593bf7c2d3872317ffc';
const AIGC_428_UPDATER_HASHES=[
    'app/common/service/update/ProjectUpdateProcessGate.php'=>'3e88df54daf4b7be507c62af1261fe6e528327f1900946a3a5519d308e716ea1',
    'app/common/service/update/SystemRuntimeBootstrapService.php'=>'2f8f27032d5226ba917596a802595b7010810dd299e161eec6c86c7f2bb1c74a',
    'app/common/service/update/SystemPackageUpdateService.php'=>'de8becd5f29443ea7bb5a578c1787c5ecdb77af2b0128e9a016aa3458647fa68',
    'app/common/service/update/DirectSiteUpdateService.php'=>'3e196442e0c9d44e8df1e81fb32b99239d2b709c53f1620f6123788826bd46a5',
];
function aigcRepair428Updater(string $site,string $archive,bool $apply=false,?callable $checkpoint=null):array
{
    if(PHP_SAPI!=='cli'||PHP_VERSION_ID<80200)throw new RuntimeException('请使用本站 PHP 8.2 CLI 执行更新器接入');
    $root=realpath($site);if($root===false||is_link($site))throw new RuntimeException('站点目录无效');
    foreach(['runtime/direct-update-active.json','.composer-runtime/active.json'] as $path)if(is_file($root.'/'.$path))throw new RuntimeException('存在待恢复更新，需先核对恢复日志');
    $canonical=json_decode((string)@file_get_contents($root.'/runtime/direct-deployment.json'),true);
    if(is_file($root.'/.composer-runtime/current.json')&&($canonical['mode']??'')!=='canonical')throw new RuntimeException('本工具只接入正常站点目录更新器');
    if(!is_file($archive)||!hash_equals(AIGC_429_REPAIR_ARCHIVE,(string)hash_file('sha256',$archive)))throw new RuntimeException('安装包 SHA-256 不符，拒绝接入');
    $zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('安装包无法读取');
    $writer=null;$legacy=null;$stage='';$backup='';$installed=[];$platformMoved=false;$platformInstalled=false;
    try{
        $manifest=json_decode((string)$zip->getFromName('update.json'),true,512,JSON_THROW_ON_ERROR);
        if(($manifest['product_code']??'')!=='likeadmin_aigc_saas'||($manifest['base_version']??'')!=='1.0.428'||($manifest['version']??'')!=='1.0.429'||($manifest['target_version']??'')!=='1.0.429')throw new RuntimeException('更新包版本链不匹配');
        $signature=json_decode((string)$zip->getFromName('signature.json'),true,512,JSON_THROW_ON_ERROR);$hashes=(array)($signature['sha256']??[]);
        foreach($signature['files']??[] as $entry)$hashes[$entry['path']]=$entry['sha256'];
        $safe=static function(string $path):void{if($path===''||str_starts_with($path,'/')||str_contains($path,'\\')||array_intersect(explode('/',$path),['..','.','']))throw new RuntimeException('更新包路径无效');};
        $read=static function(string $relative)use($zip,$hashes):string{$bytes=$zip->getFromName($relative);if($bytes===false||!isset($hashes[$relative])||!hash_equals($hashes[$relative],hash('sha256',$bytes)))throw new RuntimeException('更新器文件签名清单不匹配: '.$relative);return $bytes;};
        $code=[];$new=[];$metadata=[];$oldCount=0;$newCount=0;
        foreach(AIGC_428_UPDATER_HASHES as $path=>$oldHash){
            $safe($path);$target=$root.'/'.$path;if(is_link($target)||!is_file($target)||!str_starts_with((string)realpath(dirname($target)),$root.'/'))throw new RuntimeException('更新器目标不是正常站点文件: '.$path);
            $code[$path]=$read('files/'.$path);token_get_all($code[$path],TOKEN_PARSE);$new[$path]=hash('sha256',$code[$path]);$current=hash_file('sha256',$target);
            $oldCount+=(int)hash_equals($oldHash,$current);$newCount+=(int)hash_equals($new[$path],$current);
            $metadata[$path]=['mode'=>fileperms($target)&0777,'uid'=>fileowner($target),'gid'=>filegroup($target)];
        }
        if($oldCount!==count($code)&&$newCount!==count($code))throw new RuntimeException('更新器指纹与原版不符，拒绝覆盖自定义或混合文件');
        $platform=[];$prefix='files/public/platform/';
        for($i=0;$i<$zip->numFiles;$i++){
            $name=$zip->getNameIndex($i);if(!str_starts_with($name,$prefix)||str_ends_with($name,'/'))continue;
            $relative=substr($name,strlen($prefix));$safe($relative);$read($name);$platform[$relative]=$hashes[$name];
        }
        if(!isset($platform['index.html']))throw new RuntimeException('完整后台界面缺失');
        $version=json_decode((string)@file_get_contents($root.'/upgrade/version.json'),true)['version']??'';
        preg_match('/[\'"]version[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/',(string)file_get_contents($root.'/config/project.php'),$project);
        if(!in_array($version,['1.0.428','1.0.429'],true)||($project[1]??'')!==$version)throw new RuntimeException('站点版本标记不一致，禁止接入');
        $uiReady=true;foreach($platform as $path=>$hash)if(!is_file($root.'/public/platform/'.$path)||is_link($root.'/public/platform/'.$path)||hash_file('sha256',$root.'/public/platform/'.$path)!==$hash){$uiReady=false;break;}
        if($newCount===count($code)&&$uiReady)return ['passed'=>true,'status'=>$version==='1.0.429'?'already_upgraded':'already_installed','version'=>$version,'archive_sha256'=>AIGC_429_REPAIR_ARCHIVE];
        if($version!=='1.0.428'||$oldCount!==count($code))throw new RuntimeException('站点不属于可接入的原版 1.0.428');
        foreach(['runtime','public','public/platform'] as $directory)if(!is_dir($root.'/'.$directory)||is_link($root.'/'.$directory))throw new RuntimeException('站点目录不可安全接入: '.$directory);
        if(stat($root.'/runtime')['dev']!==stat($root.'/public')['dev'])throw new RuntimeException('运行目录与后台不在同一磁盘，需人工安排备份');
        $result=['passed'=>true,'status'=>$apply?'installed':'preview','from_version'=>'1.0.428','target_version'=>'1.0.429','archive_sha256'=>AIGC_429_REPAIR_ARCHIVE,'new_hashes'=>$new,'platform_files'=>count($platform),'version_marker_unchanged'=>true];
        if(!$apply)return $result;
        $open=static function(string $path){if(is_link($path))throw new RuntimeException('更新锁不能是链接');$h=fopen($path,'c+e');if(!$h)throw new RuntimeException('无法打开更新接入锁');return $h;};
        $writer=$open($root.'/runtime/site-update-intent.lock');if(!flock($writer,LOCK_EX|LOCK_NB))throw new RuntimeException('存在其他更新任务');
        $legacy=$open($root.'/runtime/site-update.lock');if(!flock($legacy,LOCK_SH|LOCK_NB))throw new RuntimeException('旧更新器仍在写入，请等待结束');
        foreach(AIGC_428_UPDATER_HASHES as $path=>$hash)if(hash_file('sha256',$root.'/'.$path)!==$hash)throw new RuntimeException('接入前更新器指纹发生变化');
        foreach(['runtime/direct-update-active.json','.composer-runtime/active.json'] as $path)if(is_file($root.'/'.$path))throw new RuntimeException('更新恢复日志发生变化');
        $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));$backup=$root.'/runtime/updater-entry-repair-'.$id;$stage=$root.'/public/.updater-entry-'.$id;
        if(!mkdir($backup,0755,true)||!mkdir($stage,0755))throw new RuntimeException('无法准备接入备份');
        $uiMeta=['uid'=>fileowner($root.'/public/platform'),'gid'=>filegroup($root.'/public/platform'),'mode'=>0755];
        $atomic=static function(string $target,string $bytes,array $meta):void{
            $temp=dirname($target).'/.'.basename($target).'.entry-'.bin2hex(random_bytes(5));
            try{if(file_put_contents($temp,$bytes)!==strlen($bytes)||!chmod($temp,$meta['mode'])||!chown($temp,$meta['uid'])||!chgrp($temp,$meta['gid'])||!rename($temp,$target))throw new RuntimeException('接入文件发布失败: '.$target);}
            finally{if(is_file($temp))unlink($temp);}
        };
        foreach($code as $path=>$bytes){$saved=$backup.'/code/'.$path;if(!is_dir(dirname($saved)))mkdir(dirname($saved),0755,true);if(!copy($root.'/'.$path,$saved))throw new RuntimeException('无法备份原更新器');}
        file_put_contents($backup.'/state.json',json_encode($result+['phase'=>'prepared','original_hashes'=>AIGC_428_UPDATER_HASHES,'metadata'=>$metadata],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        foreach($platform as $path=>$hash){$target=$stage.'/'.$path;if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);$atomic($target,$read($prefix.$path),array_replace($uiMeta,['mode'=>0644]));}
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $file){if(!chmod($file->getPathname(),$file->isDir()?0755:0644)||!chown($file->getPathname(),$uiMeta['uid'])||!chgrp($file->getPathname(),$uiMeta['gid']))throw new RuntimeException('后台文件权限设置失败');}
        if(!chmod($stage,0755)||!chown($stage,$uiMeta['uid'])||!chgrp($stage,$uiMeta['gid']))throw new RuntimeException('后台目录权限设置失败');
        try{
            foreach($code as $path=>$bytes){$atomic($root.'/'.$path,$bytes,$metadata[$path]);$installed[]=$path;if($checkpoint)$checkpoint($path);}
            if(!rename($root.'/public/platform',$backup.'/platform'))throw new RuntimeException('无法备份原后台');$platformMoved=true;
            if(!rename($stage,$root.'/public/platform'))throw new RuntimeException('无法发布新后台');$platformInstalled=true;
            foreach($new as $path=>$hash)if(hash_file('sha256',$root.'/'.$path)!==$hash)throw new RuntimeException('接入后更新器指纹不符');
            $result['backup']=$backup;file_put_contents($backup.'/state.json',json_encode($result+['phase'=>'installed','original_hashes'=>AIGC_428_UPDATER_HASHES,'metadata'=>$metadata],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            return $result;
        }catch(Throwable $failure){
            if($platformInstalled)rename($root.'/public/platform',$stage);
            if($platformMoved&&!rename($backup.'/platform',$root.'/public/platform'))throw new RuntimeException('后台恢复失败，已保留备份',0,$failure);
            foreach(array_reverse($installed) as $path)$atomic($root.'/'.$path,(string)file_get_contents($backup.'/code/'.$path),$metadata[$path]);
            file_put_contents($backup.'/state.json',json_encode(['phase'=>'rolled_back','error'=>$failure->getMessage()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));throw $failure;
        }
    }finally{
        $zip->close();if(is_resource($legacy))fclose($legacy);if(is_resource($writer))fclose($writer);
        if($stage!==''&&is_dir($stage)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($stage);}
    }
}
if(isset($_SERVER['SCRIPT_FILENAME'])&&realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){
    $options=getopt('',['site-root:','archive:','apply']);
    try{echo json_encode(aigcRepair428Updater($options['site-root']??dirname(__DIR__),$options['archive']??'',isset($options['apply'])),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),PHP_EOL;}
    catch(Throwable $error){fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
}
