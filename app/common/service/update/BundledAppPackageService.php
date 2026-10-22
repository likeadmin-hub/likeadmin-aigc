<?php
declare(strict_types=1);
namespace app\common\service\update;

use RuntimeException;
use ZipArchive;
use PharData;

/** Snapshot the application already delivered with this site; never rewrite its historic SQL. */
final class BundledAppPackageService
{
    private string $codeRoot;
    public function __construct(private string $root, ?string $codeRoot=null)
    {
        $this->root=rtrim(realpath($root)?:$root,'/');
        $this->codeRoot=rtrim(realpath($codeRoot??$root)?:($codeRoot??$root),'/');
    }

    public static function versionFloor(string $registered, string $disk): string
    {
        return $disk!=='' && ($registered==='' || version_compare($disk,$registered,'>')) ? $disk : $registered;
    }

    public function manifest(string $code): array
    {
        if(!preg_match('/^[a-z][a-z0-9_]*$/D',$code))throw new RuntimeException('应用编码无效');
        $dir=$this->codeRoot.'/app/apps/'.$code;
        if(!is_dir($dir))return [];
        if(is_link($dir)||realpath($dir)!==$dir)throw new RuntimeException('本地应用路径不安全');
        $manifest=json_decode((string)file_get_contents($dir.'/manifest.json'),true);
        if(!is_array($manifest)||($manifest['code']??'')!==$code||!preg_match('/^\d+\.\d+\.\d+$/D',(string)($manifest['version']??'')))throw new RuntimeException('本地应用清单无效');
        return $manifest;
    }

    public function create(string $code): array
    {
        $manifest=$this->manifest($code);if(!$manifest)throw new RuntimeException('本站未包含该应用，请从云端获取兼容的应用包');
        $dir=$this->codeRoot.'/app/apps/'.$code;$files=[];
        foreach(['manifest.json','api_schema.json','menus/platform.json','menus/tenant.json','permissions/tenant.json','migrations','frontend'] as $relative)if(!file_exists($dir.'/'.$relative))throw new RuntimeException('本地应用文件不完整，请先修复系统文件');
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $file){
            if($file->isLink())throw new RuntimeException('本地应用不能包含链接');
            if($file->isFile()){$relative=substr($file->getPathname(),strlen($dir)+1);if($relative!=='signature.json')$files[$relative]=$file->getPathname();}
        }
        foreach((array)($manifest['public_assets']??[]) as $asset){
            $source=(string)($asset['source']??'');$target=(string)($asset['target']??'');
            foreach([$source,$target] as $path)self::safeRelative($path);
            if(!isset($files[$source])){
                $existing=$this->root.'/'.$target;
                if(!is_file($existing)||is_link($existing)||!str_starts_with((string)realpath($existing),$this->root.'/'))throw new RuntimeException('本地应用前端资源缺失，请先修复系统文件');
                $files[$source]=$existing;
            }
        }
        ksort($files);$hashes=[];foreach($files as $relative=>$file)$hashes[$relative]=hash_file('sha256',$file);
        $destination=$this->root.'/runtime/update_uploads';if(!is_dir($destination)&&!mkdir($destination,0755,true))throw new RuntimeException('本地应用暂存目录不可写');
        $format=class_exists(ZipArchive::class)?'zip':'tar';$path=$destination.'/local-'.$code.'-'.bin2hex(random_bytes(8)).'.'.$format;
        if($format==='zip'){
            $archive=new ZipArchive();if($archive->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('无法创建本地应用包');
            $archive->addEmptyDir('migrations');$archive->addEmptyDir('frontend');
            foreach($files as $relative=>$file)$archive->addFile($file,$relative);
            $archive->addFromString('signature.json',json_encode(['sha256'=>$hashes],JSON_THROW_ON_ERROR));
            if(!$archive->close())throw new RuntimeException('无法保存本地应用包');
        }else{
            $archive=new PharData($path);foreach($files as $relative=>$file)$archive->addFile($file,$relative);
            $archive->addFromString('signature.json',json_encode(['sha256'=>$hashes],JSON_THROW_ON_ERROR));unset($archive);
        }
        return ['path'=>$path,'format'=>$format,'sha256'=>hash_file('sha256',$path),'size'=>filesize($path),'manifest'=>$manifest];
    }

    private static function safeRelative(string $path): void
    {
        if($path===''||str_starts_with($path,'/')||str_contains($path,'\\')||str_contains($path,chr(0))||preg_match('/^[A-Za-z]:/',$path)||array_intersect(explode('/',$path),['.','..','']))throw new RuntimeException('应用资源路径不安全');
    }
}
