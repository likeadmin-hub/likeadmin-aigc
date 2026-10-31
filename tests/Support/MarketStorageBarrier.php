<?php

/**
 * Test-only filesystem barrier. All bytes/locks/rename go to owned real local files.
 * The wrapper pauses at a selected open/write; no application upload or DB method is mocked.
 */
class MarketStorageBarrier
{
    public $context;
    private $handle;
    private string $path;
    private bool $output = false;
    private bool $paused = false;
    public static string $root;
    public static string $role;
    public static string $mode;

    public static function install(string $root, string $role, string $mode): void
    {
        if (!is_file($root.'/owner') || !preg_match('/^b55_[a-f0-9]{12}_$/D', trim(file_get_contents($root.'/owner')))) throw new RuntimeException('Not an owned storage fixture');
        self::$root=$root;self::$role=$role;self::$mode=$mode;
        if (!stream_wrapper_register('b55fs', self::class)) throw new RuntimeException('Cannot register fixture filesystem');
        $prop=new ReflectionProperty(think\App::class,'rootPath');$prop->setAccessible(true);$prop->setValue(app(),'b55fs://'.$root.'/');
    }

    private static function physical(string $path): string
    {
        if (!str_starts_with($path,'b55fs://'.self::$root.'/') || str_contains($path,'/../')) throw new RuntimeException('Fixture path escaped its private root');
        return substr($path,8);
    }

    private static function mark(string $name, string $value='1'): void { file_put_contents(self::$root.'/'.self::$role.'-'.$name,$value); }
    private static function wait(string $name): void { while(!is_file(self::$root.'/'.$name))usleep(10000); }

    public function stream_open($path,$mode,$options,&$openedPath): bool
    {
        $this->path=self::physical($path);
        $write=strpbrk($mode,'waxc')!==false;
        $managed=str_contains($this->path,'/public/uploads/ai_result/');
        if($write && $managed && self::$role==='first' && self::$mode==='late-open' && !is_file(self::$root.'/first-before-open')){
            self::mark('before-open');self::wait('release-open');
        }
        $this->handle=fopen($this->path,$mode);
        $this->output=$write && $managed && !str_ends_with($this->path,'.lock');
        return is_resource($this->handle);
    }

    public function stream_write($bytes): int
    {
        if($this->output && self::$role==='first' && !$this->paused){
            $this->paused=true;$written=fwrite($this->handle,substr($bytes,0,32));fflush($this->handle);
            self::mark('partial',json_encode(['path'=>substr($this->path,strlen(self::$root)+1),'bytes'=>filesize($this->path)]));
            self::wait('release-write');return $written;
        }
        return fwrite($this->handle,$bytes);
    }

    public function stream_lock($operation): bool
    {
        if(($operation&~LOCK_NB)===LOCK_EX)self::mark('lock-waiting');
        $ok=flock($this->handle,$operation);
        if($ok && ($operation&~LOCK_NB)===LOCK_EX)self::mark('lock-acquired');
        return $ok;
    }
    public function stream_read($count){return fread($this->handle,$count);}
    public function stream_eof(): bool{return feof($this->handle);}
    public function stream_tell(){return ftell($this->handle);}
    public function stream_seek($offset,$whence=SEEK_SET): bool{return fseek($this->handle,$offset,$whence)===0;}
    public function stream_stat(){return fstat($this->handle);}
    public function stream_flush(): bool{return fflush($this->handle);}
    public function stream_close(): void{fclose($this->handle);}
    public function stream_truncate($size): bool{return ftruncate($this->handle,$size);}
    public function stream_set_option($option,$arg1,$arg2): bool{return false;}
    public function url_stat($path,$flags){$p=self::physical($path);clearstatcache(true,$p);return ($flags&STREAM_URL_STAT_LINK)?@lstat($p):@stat($p);}
    public function mkdir($path,$mode,$options): bool{return mkdir(self::physical($path),$mode,(bool)($options&STREAM_MKDIR_RECURSIVE));}
    public function unlink($path): bool{return unlink(self::physical($path));}
    public function rename($from,$to): bool{return rename(self::physical($from),self::physical($to));}
    public function stream_metadata($path,$option,$value): bool{return $option===STREAM_META_ACCESS && chmod(self::physical($path),$value);}
}

/** Same MySQL connector; retain the first SQL exception before a nested rollback can mask it. */
class MarketStorageTraceMysql extends \think\db\connector\Mysql
{
    public function getPDOStatement(string $sql, array $bind=[], bool $master=false, bool $procedure=false): PDOStatement
    {
        try { return parent::getPDOStatement($sql,$bind,$master,$procedure); }
        catch(Throwable $error){
            file_put_contents(MarketStorageBarrier::$root.'/'.MarketStorageBarrier::$role.'-sql.log',json_encode([
                'class'=>get_class($error),'message'=>$error->getMessage(),'sql_template'=>$sql,
                'trace'=>array_map(static fn($f)=>($f['file']??'').':'.($f['line']??0).' '.($f['function']??''),$error->getTrace()),
            ],JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND);
            throw $error;
        }
    }
}
