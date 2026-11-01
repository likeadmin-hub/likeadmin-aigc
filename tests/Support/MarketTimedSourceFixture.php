<?php

namespace {
    /** Real loopback HTTP with wall-clock URL leases. No provider URL refresh or global DNS changes. */
    final class MarketTimedSourceFixture
    {
        public string $dir;
        public string $url;
        private mixed $process=null;
        private string $base;
        private array $config;
        private static ?array $relay=null;

        public function __construct(MarketIsolatedMysqlFixture $isolated,string $kind,string $mode)
        {
            $media=MarketIsolatedMysqlFixture::media($kind);
            $id=bin2hex(random_bytes(8));$this->dir=$isolated->root.'/source-'.$id;
            mkdir($this->dir,0700);MarketIsolatedMysqlFixture::atomic($this->dir.'/owner',$isolated->owner);
            $this->config=['owner'=>$isolated->owner,'id'=>$id,'ext'=>$media['ext'],'mime'=>$media['mime'],
                'mode'=>$mode,'key'=>bin2hex(random_bytes(32)),'lease_until'=>time()+60,'expires'=>time()+1,
                'truncate_next'=>$mode==='truncate-then-expire-then-renew'];
            file_put_contents($this->dir.'/source.'.$media['ext'],$media['bytes']);
            MarketIsolatedMysqlFixture::atomic($this->dir.'/source.json',json_encode($this->config,JSON_THROW_ON_ERROR));
            file_put_contents($this->dir.'/router.php',self::router());
            $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$reason);
            MarketIsolatedMysqlFixture::check(is_resource($socket),'Cannot reserve a private source port');
            $address=stream_socket_get_name($socket,false);fclose($socket);$this->base='http://'.$address;
            $pipes=[];$this->process=proc_open([PHP_BINARY,'-d','disable_functions=','-S',$address,$this->dir.'/router.php'],
                [0=>['pipe','r'],1=>['file',$this->dir.'/http.log','a'],2=>['file',$this->dir.'/http.log','a']],$pipes,null,array_merge(getenv(),['TMPDIR'=>'/tmp']));
            MarketIsolatedMysqlFixture::check(is_resource($this->process),'Cannot start the owned HTTP source');fclose($pipes[0]);
            $context=stream_context_create(['http'=>['timeout'=>1]]);
            try{MarketWorkerFixture::wait(fn()=>@file_get_contents($this->base.'/'.$id.'/ready',false,$context)==='owned-ready','Owned source did not start',5);}
            catch(Throwable $error){$this->close();throw $error;}
            $path='/'.$id.'/source.'.$media['ext'];
            $query=$mode==='fixed-query-expired-no-refresh'
                ?'expires='.$this->config['expires'].'&sig='.hash_hmac('sha256',$id.':'.$this->config['expires'],$this->config['key'])
                :'token='.hash_hmac('sha256',$id,$this->config['key']);
            $this->url=($kind==='image'?'http://private-media.example.test':$this->base).$path.'?'.$query;
            MarketIsolatedMysqlFixture::atomic($this->dir.'/relay.json',json_encode(['base'=>$this->base,'id'=>$id,'owner'=>$isolated->owner],JSON_THROW_ON_ERROR));
        }

        public function expireNaturally(): void
        {
            $this->config['lease_until']=time()+1;
            MarketIsolatedMysqlFixture::atomic($this->dir.'/source.json',json_encode($this->config,JSON_THROW_ON_ERROR));
            $until=$this->config['mode']==='fixed-query-expired-no-refresh'?$this->config['expires']:$this->config['lease_until'];
            MarketWorkerFixture::wait(fn()=>time()>$until,'Wall-clock source expiry did not occur',5);
        }

        public function renewLease(): void
        {
            MarketIsolatedMysqlFixture::check($this->config['mode']!=='fixed-query-expired-no-refresh','Never renew a fixed Expires URL in place');
            // This renews only our server-side opaque lease. The literal URL and asset/source DB rows do not change.
            $latest=json_decode(file_get_contents($this->dir.'/source.json'),true,16,JSON_THROW_ON_ERROR);
            $this->config=$latest;$this->config['lease_until']=time()+120;$this->config['truncate_next']=false;
            MarketIsolatedMysqlFixture::atomic($this->dir.'/source.json',json_encode($this->config,JSON_THROW_ON_ERROR));
        }

        public function requests(): array
        {
            return array_values(array_filter(array_map(static fn($line)=>json_decode($line,true),
                @file($this->dir.'/requests.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]),'is_array'));
        }

        public function probeExpired(): void
        {
            $parsed=parse_url($this->url);$url=$this->base.$parsed['path'].'?'.$parsed['query'];
            $context=stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>true,'header'=>"X-C03-Probe: 1\r\n"]]);
            $body=@file_get_contents($url,false,$context);$status=0;
            foreach($http_response_header??[]as$header)if(preg_match('~^HTTP/\S+\s+(\d+)~',$header,$match))$status=(int)$match[1];
            MarketIsolatedMysqlFixture::check($body!==false&&$status===403,'The exact original URL is not expired');
        }

        public static function activateRelay(string $dir): void
        {
            MarketIsolatedMysqlFixture::check(preg_match('~^/c03/run/source-[a-f0-9]{16}$~D',$dir)===1 && is_file($dir.'/owner'),'Not an owned source relay');
            $value=json_decode(file_get_contents($dir.'/relay.json'),true,16,JSON_THROW_ON_ERROR);
            MarketIsolatedMysqlFixture::check(($value['owner']??'')===trim(file_get_contents($dir.'/owner'))
                && preg_match('~^http://127\.0\.0\.1:[0-9]+$~D',$value['base']??'')===1,'Invalid loopback relay');
            self::$relay=$value;
        }

        public static function imageUrl(string $url): string
        {
            $parsed=parse_url($url);$relay=self::$relay;
            MarketIsolatedMysqlFixture::check($relay!==null && ($parsed['host']??'')==='private-media.example.test'
                && str_starts_with($parsed['path']??'','/'.$relay['id'].'/source.'),'Unexpected image fixture socket destination');
            return $relay['base'].$parsed['path'].(isset($parsed['query'])?'?'.$parsed['query']:'');
        }

        public function close(): void
        {
            if(is_resource($this->process)){
                $state=proc_get_status($this->process);if($state['running'])proc_terminate($this->process,SIGTERM);
                try{MarketWorkerFixture::wait(fn()=>!proc_get_status($this->process)['running'],'Owned HTTP source did not stop',5);}
                catch(Throwable){proc_terminate($this->process,SIGKILL);MarketWorkerFixture::wait(fn()=>!proc_get_status($this->process)['running'],'Owned HTTP source kill failed',5);}
                proc_close($this->process);$this->process=null;
            }
            MarketIsolatedMysqlFixture::check(is_file($this->dir.'/owner')&&trim(file_get_contents($this->dir.'/owner'))===$this->config['owner'],'Source cleanup owner differs');
            foreach(new DirectoryIterator($this->dir)as$file){if($file->isDot())continue;MarketIsolatedMysqlFixture::check($file->isFile()&&!$file->isLink(),'Unexpected owned source entry');unlink($file->getPathname());}
            rmdir($this->dir);
        }

        private static function router(): string
        {
            return <<<'PHP'
<?php
$dir=__DIR__;$config=json_decode(file_get_contents($dir.'/source.json'),true,16,JSON_THROW_ON_ERROR);
if(trim(file_get_contents($dir.'/owner'))!==$config['owner']){http_response_code(404);return;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$prefix='/'.$config['id'];
if($path===$prefix.'/ready'){echo 'owned-ready';return;}
if($path!==$prefix.'/source.'.$config['ext']){http_response_code(404);return;}
$query=[];parse_str((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_QUERY),$query);
$now=time();$reason='ready';$status=200;
if($config['mode']==='fixed-query-expired-no-refresh'){
    $expires=$query['expires']??'';$signature=$query['sig']??'';
    if(!is_string($expires)||!is_string($signature)||!ctype_digit($expires)||!hash_equals(hash_hmac('sha256',$config['id'].':'.$expires,$config['key']),$signature)){$status=403;$reason='invalid_signature';}
    elseif($now>(int)$expires){$status=403;$reason='query_expired';}
}elseif(!is_string($query['token']??null)||!hash_equals(hash_hmac('sha256',$config['id'],$config['key']),$query['token'])){$status=403;$reason='invalid_token';}
elseif($now>$config['lease_until']){$status=403;$reason='lease_expired';}
$truncate=$status===200&&!empty($config['truncate_next']);
if($truncate){$config['truncate_next']=false;$config['lease_until']=time()+1;file_put_contents($dir.'/source.json',json_encode($config,JSON_THROW_ON_ERROR),LOCK_EX);$reason='partial_then_wall_clock_expiry';}
file_put_contents($dir.'/requests.jsonl',json_encode(['at'=>$now,'status'=>$status,'reason'=>$reason,'truncated'=>$truncate,'probe'=>($_SERVER['HTTP_X_C03_PROBE']??'')==='1'])."\n",FILE_APPEND|LOCK_EX);
http_response_code($status);
if($status!==200){header('Content-Type: text/plain');echo 'Owned synthetic source unavailable';return;}
$file=$dir.'/source.'.$config['ext'];header('Content-Type: '.$config['mime']);header('Content-Length: '.filesize($file));
if($truncate){$fp=fopen($file,'rb');echo fread($fp,32);fclose($fp);flush();while(time()<=$config['lease_until'])usleep(20000);return;}
readfile($file);
PHP;
        }
    }
}

namespace app\common\service\open_platform {
    function dns_get_record($host,$type) {
        if($host!=='private-media.example.test')throw new \RuntimeException('Unexpected fixture DNS destination');
        return $type===DNS_A?[['ip'=>'93.184.216.34']]:[];
    }
    function curl_init($url=null) {
        if(!is_string($url))throw new \RuntimeException('An explicit owned image URL is required');
        return \curl_init(\MarketTimedSourceFixture::imageUrl($url));
    }
    function curl_setopt_array($curl,$options) {unset($options[CURLOPT_RESOLVE]);return \curl_setopt_array($curl,$options);}
}
