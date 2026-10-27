<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/common/service/update/BundledAppPackageService.php';
use app\common\service\update\BundledAppPackageService;
$source=dirname(__DIR__,2);
$root=sys_get_temp_dir().'/bundled-real-'.bin2hex(random_bytes(6));
mkdir($root,0755,true);
$checked=[];
try {
    $builder=new BundledAppPackageService($root,$source);
    foreach (glob($source.'/app/apps/*/manifest.json') as $manifestPath) {
        $manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
        if (empty($manifest['is_builtin'])) continue;
        foreach (($manifest['public_assets']??[]) as $asset) {
            $target=$asset['target'];$from=$source.'/'.$target;$to=$root.'/'.$target;
            if (is_file($from)) { if (!is_dir(dirname($to))) mkdir(dirname($to),0755,true); copy($from,$to); }
        }
        $result=$builder->create($manifest['code']);
        if ($result['format']==='zip') {
            $archive=new ZipArchive();if ($archive->open($result['path'])!==true) throw new RuntimeException('Cannot read '.$manifest['code']);
            foreach (['manifest.json','api_schema.json','menus/platform.json','menus/tenant.json','permissions/tenant.json','migrations/','frontend/','signature.json'] as $item) {
                if ($archive->locateName($item)===false) throw new RuntimeException($manifest['code'].' missing '.$item);
            }
            $signature=json_decode($archive->getFromName('signature.json'),true,512,JSON_THROW_ON_ERROR);
            foreach ($signature['sha256'] as $name=>$hash) if (hash('sha256',$archive->getFromName($name))!==$hash) throw new RuntimeException($manifest['code'].' hash mismatch '.$name);
            $archive->close();
        } else {
            $archive=new PharData($result['path']);
            foreach (['manifest.json','api_schema.json','menus/platform.json','menus/tenant.json','permissions/tenant.json','migrations','frontend','signature.json'] as $item) {
                if (!isset($archive[$item])) throw new RuntimeException($manifest['code'].' missing '.$item);
            }
            unset($archive);
        }
        $checked[]=$manifest['code'];
    }
    echo 'Packaged '.count($checked).' bundled applications using '.(class_exists(ZipArchive::class)?'zip':'tar')."; required entries verified\n";
} finally {
    if (is_dir($root)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());
        rmdir($root);
    }
}
