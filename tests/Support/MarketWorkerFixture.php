<?php

/** Disposable local SQL/cache/files and owned production result workers. Never submits an AI task. */
class MarketWorkerFixture
{
    public string $prefix;
    public string $dir;
    public int $tenant;
    public int $user;
    private array $tables=[];
    private array $children=[];
    private PDO $pdo;
    private array $original;

    public static function check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
    public static function wait(callable $ready,string $message,int $seconds=30): void
    {
        $until=microtime(true)+$seconds;do{if($ready())return;usleep(20000);}while(microtime(true)<$until);throw new RuntimeException($message);
    }
    public static function activate(array $original,string $prefix,string $dir): void
    {
        self::check(preg_match('/^b56_[a-f0-9]{12}_$/D',$prefix)===1&&is_file($dir.'/owner')&&trim(file_get_contents($dir.'/owner'))===$prefix,'Not an owned fixture');
        $cfg=$original;$cfg['default']='batch56';$cfg['connections']['batch56']=array_replace($original['connections']['mysql'],['prefix'=>$prefix]);$cfg['connections']['mysql']=$cfg['connections']['batch56'];
        app()->config->set($cfg,'database');$config=new think\Config();$config->set($cfg,'database');think\facade\Db::setConfig($config);
        app()->config->set(['default'=>'b56','stores'=>['b56'=>['type'=>'File','path'=>$dir.'/cache/','prefix'=>$prefix]]],'cache');
        $root=new ReflectionProperty(think\App::class,'rootPath');$root->setAccessible(true);$root->setValue(app(),$dir.'/');request()->setHost('localhost');
    }
    public function __construct(array $original,array $tables)
    {
        $this->original=$original;$db=$original['connections']['mysql'];
        self::check(PHP_SAPI==='cli'&&in_array($db['hostname'],['127.0.0.1','localhost'],true)&&in_array($db['database'],['x_cn','z_cn'],true),'Explicit local fixture only');
        $this->prefix='b56_'.bin2hex(random_bytes(6)).'_';$this->dir='/tmp/'.$this->prefix.'fixture';
        mkdir($this->dir,0700);file_put_contents($this->dir.'/owner',$this->prefix);mkdir($this->dir.'/public',0700);mkdir($this->dir.'/tmp',0700);
        $this->pdo=new PDO('mysql:host='.$db['hostname'].';port='.($db['hostport']?:3306).';dbname='.$db['database'].';charset=utf8mb4',$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        try{foreach($tables as$table)$this->addTable($table);self::activate($original,$this->prefix,$this->dir);}
        catch(Throwable $error){$this->cleanup();throw $error;}
        $this->tenant=1800000000+random_int(1,50000000);$this->user=1900000000+random_int(1,50000000);
        think\facade\Db::name('tenant')->insert(['id'=>$this->tenant,'sn'=>'b56','name'=>'Batch56 isolated fixture','point_balance'=>100,'disable'=>0,'allow_custom_storage'=>1,'allow_local_storage'=>1,'create_time'=>time(),'update_time'=>time()]);
        think\facade\Db::name('user')->insert(['id'=>$this->user,'tenant_id'=>$this->tenant,'sn'=>$this->user,'account'=>$this->prefix,'nickname'=>'fixture','user_money'=>100,'create_time'=>time(),'update_time'=>time()]);
    }
    public function addTable(string $name): void
    {
        self::check(preg_match('/^[a-z][a-z0-9_]+$/D',$name)===1,'Invalid fixture table');$table=$this->prefix.$name;
        if(in_array($table,$this->tables,true))return;
        $exists=$this->pdo->prepare('SHOW TABLES LIKE ?');$exists->execute([$this->original['connections']['mysql']['prefix'].$name]);if(!$exists->fetchColumn())return;
        $this->pdo->exec('CREATE TABLE `'.$table.'` LIKE `'.$this->original['connections']['mysql']['prefix'].$name.'`');$this->tables[]=$table;
    }
    public function start(string $script,string $appCode,string $role='worker'): void
    {
        self::check(preg_match('/^[a-z][a-z0-9_]*$/D',$role)===1&&!isset($this->children[$role]),'Invalid or duplicate owned worker');
        $pipes=[];$process=proc_open([PHP_BINARY,'-d','disable_functions=',$script,'--worker',$this->prefix,$this->dir,$appCode],
            [0=>['pipe','r'],1=>['file',$this->dir.'/'.$role.'.log','a'],2=>['file',$this->dir.'/'.$role.'.log','a']],$pipes,null,array_merge(getenv(),['TMPDIR'=>$this->dir.'/tmp']));
        self::check(is_resource($process),'Cannot start owned worker');fclose($pipes[0]);$this->children[$role]=$process;
    }
    public function stop(string $role='worker',bool $kill=false): void
    {
        if(!isset($this->children[$role]))return;$process=$this->children[$role];$state=proc_get_status($process);
        if($state['running'])posix_kill($state['pid'],$kill?SIGKILL:SIGTERM);
        try{self::wait(fn()=>!proc_get_status($process)['running'],'Owned worker did not stop',5);}
        catch(Throwable){posix_kill($state['pid'],SIGKILL);self::wait(fn()=>!proc_get_status($process)['running'],'Owned worker kill failed',5);}
        proc_close($process);unset($this->children[$role]);
    }
    public function cleanup(): void
    {
        foreach(array_keys($this->children)as$role)$this->stop($role);
        if(!is_file($this->dir.'/owner')||trim(file_get_contents($this->dir.'/owner'))!==$this->prefix)return;
        foreach(array_reverse($this->tables)as$table)$this->pdo->exec('DROP TABLE `'.$table.'`');$this->tables=[];
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as$file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($this->dir);
    }
}
