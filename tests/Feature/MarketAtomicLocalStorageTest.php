<?php
namespace Tests\Feature;

use app\common\service\storage\Driver;
use PHPUnit\Framework\TestCase;

/** Real local files only, in a disposable public root; no supplier, database or account writes. */
class MarketAtomicLocalStorageTest extends TestCase
{
    private function local(string $type, callable $check): void
    {
        (new \think\App())->initialize();
        $prop=new \ReflectionProperty(\think\App::class,'rootPath');$prop->setAccessible(true);$old=$prop->getValue(app());
        $dir=sys_get_temp_dir().'/b55-local-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/public',0700);$prop->setValue(app(),$dir.'/');
        try{
            $ext=['image'=>'png','video'=>'mp4','audio'=>'wav'][$type];
            if($type==='image')$bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
            else{$fixture=getenv('AIGC_TRANSFER_MEDIA_DIR').'/synthetic.'.$ext;self::assertFileExists($fixture);$bytes=file_get_contents($fixture);}
            $source=$dir.'/source.'.$ext;file_put_contents($source,$bytes);$hash=hash('sha256',$bytes);$name='asset-1-'.$hash.'.'.$ext;
            $save='uploads/ai_result/199999955/199999955';$output=$dir.'/public/'.$save;mkdir($output,0700,true);
            $driver=new Driver(['scope'=>'tenant','default'=>'local','engine'=>['local'=>[]]]);$driver->setUploadFileByReal($source,$name);
            $check($dir,$driver,$save,$output.'/'.$name,$output.'/.'.$name.'.part',$output.'/.'.$name.'.lock',$bytes,$hash,$source);
        }finally{
            $prop->setValue(app(),$old);$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as$file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($dir);
        }
    }

    /** @dataProvider recovery */
    public function testPartialOrCompleteStagingRecoversAndCompletedFilesRemainImmutable(string $type,string $state): void
    {
        $this->local($type,function($dir,$driver,$save,$target,$part,$lock,$bytes,$hash)use($state){
            if($state==='partial')file_put_contents($part,'partial');
            if($state==='complete'){file_put_contents($part,$bytes);touch($part,time()-60);clearstatcache(true,$part);$before=stat($part);}
            if($state==='published'){file_put_contents($target,$bytes);touch($target,time()-60);clearstatcache(true,$target);$before=stat($target);file_put_contents($part,'leftover');}
            if($state==='old_partial')file_put_contents($target,'old partial direct-copy bytes');
            self::assertTrue($driver->uploadManaged($save,$hash,strlen($bytes)),$driver->getError()?:'Managed write failed');
            self::assertSame($hash,hash_file('sha256',$target));self::assertFileDoesNotExist($part);
            if(isset($before)){clearstatcache(true,$target);$after=stat($target);self::assertSame($before['ino'],$after['ino']);self::assertSame($before['mtime'],$after['mtime']);}
            clearstatcache(true,$target);self::assertSame(0644,fileperms($target)&0777);
            self::assertCount(1,glob(dirname($target).'/asset-*'));self::assertSame(0,filesize($lock));
        });
    }
    public static function recovery(): array{$rows=[];foreach(['image','video','audio']as$type)foreach(['partial','complete','published','old_partial']as$state)$rows[]=[$type,$state];return $rows;}

    /** @dataProvider invalid */
    public function testInvalidSourceOrUnsafeDestinationNeverReplacesBytes(string $type,string $case): void
    {
        $this->local($type,function($dir,$driver,$save,$target,$part,$lock,$bytes,$hash,$source)use($case){
            $sentinel=$dir.'/untouched';file_put_contents($sentinel,'owned sentinel');$size=strlen($bytes);
            if($case==='source'){file_put_contents($target,$bytes);file_put_contents($source,$bytes.'changed');}
            if($case==='size'){$size++;file_put_contents($target,$bytes);}
            if($case==='part_link')symlink($sentinel,$part);
            if($case==='target_link')symlink($sentinel,$target);
            if($case==='lock_link')symlink($sentinel,$lock);
            self::assertFalse($driver->uploadManaged($save,$hash,$size));self::assertNotEmpty($driver->getError());self::assertSame('owned sentinel',file_get_contents($sentinel));
            if(in_array($case,['source','size'],true))self::assertSame($hash,hash_file('sha256',$target));
            else self::assertTrue(is_link(['part_link'=>$part,'target_link'=>$target,'lock_link'=>$lock][$case]));
        });
    }
    public static function invalid(): array{$rows=[];foreach(['image','video','audio']as$type)foreach(['source','size','part_link','target_link','lock_link']as$case)$rows[]=[$type,$case];return $rows;}

    /** @dataProvider types */
    public function testOrdinaryInternalUploadsKeepTheirExistingDriverPath(string $type): void
    {
        $this->local($type,function($dir,$driver,$save,$target,$part,$lock,$bytes,$hash){
            self::assertTrue($driver->upload($save));self::assertSame($hash,hash_file('sha256',$target));self::assertFileDoesNotExist($part);self::assertFileDoesNotExist($lock);
        });
    }
    public static function types(): array{return [['image'],['video'],['audio']];}
}
