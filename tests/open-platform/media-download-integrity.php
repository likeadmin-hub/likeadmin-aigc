<?php
declare(strict_types=1);

/** Owned HTTP only. The legacy video publication boundary is replaced before autoload; no DB or storage is used. */
namespace {
if (PHP_SAPI !== 'cli') exit(2);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor/topthink/framework/src/helper.php';

final class OwnedMediaBoundaryReached extends RuntimeException {}
final class OwnedMediaStorageBoundary
{
    public static ?array $last = null;
    public static function persist(string $path, int $assetId, int $tenantId, int $userId, array $config, array $metadata): array
    {
        if ($assetId !== 1 || $tenantId !== 199999997 || $userId !== 199999998
            || $config !== ['scope'=>'tenant', 'default'=>'local']) throw new RuntimeException('Unexpected probe boundary');
        self::$last = ['bytes'=>(int)filesize($path), 'sha256'=>hash_file('sha256', $path)];
        throw new OwnedMediaBoundaryReached('Owned pre-storage boundary reached');
    }
}

function mediaCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

/** Selective read-error injection still opens only the exact owned loopback URL. */
final class OwnedMediaReadFault
{
    public static ?string $url=null;
    public static bool $inject=false;
    public static int $stream=0, $opens=0, $closes=0, $errors=0;
    public static function reset(string $url, bool $inject=false): void
    { self::$url=$url; self::$inject=$inject; self::$stream=self::$opens=self::$closes=self::$errors=0; }
    public static function open($url,$mode,$include=false,$context=null)
    {
        mediaCheck(self::$url!==null && $url===self::$url && preg_match('~^http://127\.0\.0\.1:[0-9]+/owned-media-[a-f0-9]{24}/source\.mp4$~D',$url)===1,'Unexpected reader destination');
        $stream=\fopen($url,$mode,$include,$context);
        if(is_resource($stream)){self::$stream=get_resource_id($stream);self::$opens++;}
        return $stream;
    }
    public static function read($stream,$length)
    {
        if(self::$inject && get_resource_id($stream)===self::$stream){self::$errors++;return false;}
        return \fread($stream,$length);
    }
    public static function close($stream): bool
    { if(get_resource_id($stream)===self::$stream)self::$closes++;return \fclose($stream); }
}

/** The inherited listener has no reservation gap. Every accepted path is checked before returning bytes. */
function ownedMediaHttp(string $body, bool|string $mode, callable $read): mixed
{
    $mode=is_bool($mode)?($mode?'short':'complete'):$mode;
    $listener=stream_socket_server('tcp://127.0.0.1:0',$errno,$why);
    mediaCheck(is_resource($listener),'Cannot create owned listener');
    $address=stream_socket_get_name($listener,false);
    mediaCheck(is_string($address)&&preg_match('/^127\.0\.0\.1:[0-9]+$/D',$address)===1,'Unexpected listener identity');
    $path='/owned-media-'.bin2hex(random_bytes(12)).'/source.mp4';
    $pid=pcntl_fork(); mediaCheck($pid>=0,'Cannot fork owned HTTP fixture');
    if($pid===0){
        $redirect=str_starts_with($mode,'redirect');
        for($hop=0;$hop<($redirect?2:1);$hop++){
            $client=@stream_socket_accept($listener,5);if(!is_resource($client))exit(51);
            stream_set_timeout($client,2);$request=fgets($client);$expected=$path.($hop===1?'/final':'');
            if($request!=='GET '.$expected." HTTP/1.1\r\n"&&$request!=='GET '.$expected." HTTP/1.0\r\n")exit(52);
            $length=strlen((string)$request);
            while(($line=fgets($client))!==false&&trim($line)!==''){$length+=strlen($line);if($length>8192)exit(53);}
            if($redirect&&$hop===0){
                $wire="HTTP/1.1 302 Found\r\nLocation: ".$path."/final\r\nContent-Type: text/html\r\nContent-Length: 1073741824\r\nConnection: close\r\n\r\n";
            }else{
                $headers="HTTP/1.1 200 OK\r\nContent-Type: video/mp4\r\nConnection: close\r\n";
                if(str_starts_with($mode,'chunked')){
                    $headers.="Transfer-Encoding: chunked\r\n";
                    if($mode==='chunked-conflict')$headers.='Content-Length: '.strlen($body)."\r\n";
                }
                elseif(!in_array($mode,['no-length','redirect-no-length','body-limit'],true)){
                    $headers.='Content-Length: '.($mode==='invalid-length'?'invalid':strlen($body))."\r\n";
                    if(in_array($mode,['duplicate-length','conflict-length'],true))$headers.='Content-Length: '.(strlen($body)+($mode==='conflict-length'?1:0))."\r\n";
                }
                $payload=match($mode){'short','timeout'=>substr($body,0,32),'early-eof'=>'',
                    'chunked-half-chunk'=>"10\r\n".substr($body,0,8),
                    'chunked-missing-end'=>dechex(strlen($body))."\r\n".$body."\r\n",
                    'chunked','chunked-conflict'=>dechex(7)."\r\n".substr($body,0,7)."\r\n".dechex(strlen($body)-7)."\r\n".substr($body,7)."\r\n0\r\n\r\n",default=>$body};
                $wire=$headers."\r\n".$payload;
            }
            $offset=0;while($offset<strlen($wire)){$n=@fwrite($client,substr($wire,$offset));if($n===false||$n===0)exit(54);$offset+=$n;}
            if($mode==='timeout')usleep(1600000);
            fclose($client);
        }
        fclose($listener);exit(0);
    }
    fclose($listener);
    try{return $read('http://'.$address.$path);}
    finally{
        $until=microtime(true)+2;$status=0;
        do{$done=pcntl_waitpid($pid,$status,WNOHANG);if($done!==0)break;usleep(10000);}while(microtime(true)<$until);
        if($done===0){posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);}
        mediaCheck($done===$pid&&pcntl_wifexited($status)&&pcntl_wexitstatus($status)===0,'Owned HTTP exchange did not complete');
    }
}

$report=['scope'=>'Owned loopback download guards; not provider or accounting certification',
    'before_repro'=>in_array('--before-repro', $argv, true), 'database_connections'=>0, 'real_storage_calls'=>0,
    'provider_calls'=>0, 'managed_boundary_is_test_double'=>true, 'cases'=>[], 'passed'=>false];
try {
    mediaCheck(function_exists('pcntl_fork') && function_exists('posix_kill'), 'Require isolated CLI process support');
    // Construct only; initialize() would read application configuration and is deliberately not called.
    $app = new think\App(dirname(__DIR__, 2) . '/');
    $app->instance('request', new app\Request());
    mediaCheck(!app\common\service\open_platform\OpenCallContext::active(), 'Unexpected open-platform request context');
    $managed='app\\common\\service\\ai\\AiTaskManagedFileService';
    mediaCheck(!class_exists($managed, false), 'Real managed storage was already loaded');
    mediaCheck(class_alias(OwnedMediaStorageBoundary::class, $managed, false), 'Cannot isolate storage boundary');
    $mp4 = pack('N', 32) . 'ftypisom' . pack('N', 512) . 'isomiso2avc1mp41' . str_repeat('x', 128);
    $pcm = str_repeat("\0", 1600);
    $wav = 'RIFF' . pack('V',36+strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv',16,1,1,8000,16000,2,16) . 'data' . pack('V',strlen($pcm)) . $pcm;
    $warnings=0;
    set_error_handler(static function() use (&$warnings): bool { $warnings++; return true; });
    try {
        $raw = ownedMediaHttp($mp4, true, static fn($url)=>file_get_contents($url, false, stream_context_create(['http'=>['timeout'=>2]])));
        mediaCheck(is_string($raw) && strlen($raw) === 32, 'Original PHP short-body behavior was not reproduced');
        $report['php_stream_observation']=['advertised_bytes'=>strlen($mp4), 'returned_bytes'=>strlen($raw), 'short_body_returned_without_exception'=>true];
        foreach (['video'=>$mp4, 'audio'=>$wav] as $kind=>$bytes) {
            $class='app\\common\\service\\app\\' . ($kind==='video'?'aigc_video\\AigcVideoAssetService':'aigc_music\\AigcMusicAssetService');
            $hasReader=method_exists($class,'downloadRemoteContent');
            $method=new ReflectionMethod($class, $hasReader?'downloadRemoteContent':'persistRemoteUrl');
            $case=['kind'=>$kind, 'method'=>$method->getName(), 'advertised_bytes'=>strlen($bytes), 'rejected'=>false];
            OwnedMediaStorageBoundary::$last=null;
            try {
                $result=ownedMediaHttp($bytes, true, static function($url) use ($method,$hasReader) {
                    OwnedMediaReadFault::reset($url);
                    return $hasReader ? $method->invoke(null,$url)
                        : $method->invoke(null,$url,199999997,199999998,['scope'=>'tenant','default'=>'local'],1);
                });
                $case['returned_bytes']=is_array($result) && is_string($result[0]??null)?strlen($result[0]):null;
                $case['short_body_admitted']=$case['returned_bytes']===32;
            } catch (OwnedMediaBoundaryReached) {
                $case['reached_pre_storage_boundary']=true;
                $case['boundary_bytes']=OwnedMediaStorageBoundary::$last['bytes']??null;
                $case['short_body_admitted']=$case['boundary_bytes']===32;
            } catch (Throwable $error) {
                $case['failure_class']=get_class($error);
                $case['failure_fingerprint']=hash('sha256',$error->getMessage());
                // Before a dedicated video reader exists, an unrelated exception is not evidence of a fixed reader.
                $case['rejected']=$hasReader && get_class($error)===Exception::class && str_contains($error->getMessage(),'下载不完整');
                $case['failure_line']=basename($error->getFile()).':'.$error->getLine();
                $case['short_body_admitted']=false;
            }
            $case['passed']=$case['rejected'];
            $report['cases'][]=$case;
        }
        if(!$report['before_repro'])foreach(['video'=>$mp4,'audio'=>$wav]as$kind=>$bytes){
            $class='app\\common\\service\\app\\'.($kind==='video'?'aigc_video\\AigcVideoAssetService':'aigc_music\\AigcMusicAssetService');
            $method=new ReflectionMethod($class,'downloadRemoteContent');
            $modes=['complete'=>null,'no-length'=>null,'chunked'=>null,'redirect'=>null,'redirect-no-length'=>null,
                'duplicate-length'=>null,'chunked-conflict'=>'长度冲突','chunked-half-chunk'=>['下载不完整','下载读取失败'],'chunked-missing-end'=>['下载不完整','下载读取失败'],'conflict-length'=>'长度冲突','invalid-length'=>'长度无效','early-eof'=>'下载不完整',
                'timeout'=>'下载超时','read-error'=>'下载读取失败','header-limit'=>'文件过大','body-limit'=>'文件过大'];
            foreach($modes as$mode=>$expectedError){
                $row=['kind'=>$kind,'mode'=>$mode,'passed'=>false,'expected_failure'=>$expectedError];$error=null;$result=null;
                OwnedMediaStorageBoundary::$last=null;
                try{$result=ownedMediaHttp($bytes,$mode,static function($url)use($method,$mode){
                    OwnedMediaReadFault::reset($url,$mode==='read-error');
                    return $method->invoke(null,$url,$mode==='timeout'?1:2,in_array($mode,['header-limit','body-limit'],true)?64:4096);
                });}catch(Throwable $caught){$error=$caught;}
                if(is_array($result)&&is_string($result[0]??null))$row['observed_returned_bytes']=strlen($result[0]);
                try{
                    if($expectedError===null){
                        mediaCheck($error===null,'Unexpected successful-download exception');
                        mediaCheck(is_array($result)&&($result[0]??null)===$bytes,'Downloaded bytes changed');
                        $statuses=array_values(array_filter($result[1],static fn($line)=>str_starts_with($line,'HTTP/')));
                        mediaCheck(count($statuses)===1&&str_contains($statuses[0],'200'),'Intermediate redirect headers leaked');
                        $row['returned_bytes']=strlen($result[0]);
                    }else{
                        $expectedMessages=is_array($expectedError)?$expectedError:[$expectedError];
                        mediaCheck($error!==null&&get_class($error)===Exception::class&&count(array_filter($expectedMessages,static fn($message)=>str_contains($error->getMessage(),$message)))>0,'Expected exact download failure was not observed');
                        $row['expected_failure']=$expectedError;
                        $row['failure_line']=basename($error->getFile()).':'.$error->getLine();
                    }
                    mediaCheck(OwnedMediaReadFault::$opens===1&&OwnedMediaReadFault::$closes===1,'Reader did not close its owned stream exactly once');
                    if($mode==='read-error')mediaCheck(OwnedMediaReadFault::$errors===1,'Actual fread-false branch was not exercised');
                    mediaCheck(OwnedMediaStorageBoundary::$last===null,'Download-only check reached storage');
                    $row['passed']=true;
                }catch(Throwable $failure){
                    $row['failure_class']=get_class($failure);$row['failure_line']=basename($failure->getFile()).':'.$failure->getLine();
                    $row['failure_fingerprint']=hash('sha256',$failure->getMessage());
                    if($error!==null)$row['reader_failure_fingerprint']=hash('sha256',$error->getMessage());
                }finally{OwnedMediaReadFault::$url=null;OwnedMediaReadFault::$inject=false;}
                $report['cases'][]=$row;
            }
        }
    } finally { restore_error_handler(); }
    $report['suppressed_warning_count']=$warnings;
    $report['defects_reproduced']=count(array_filter($report['cases'],static fn($row)=>!empty($row['short_body_admitted'])));
    $report['case_count']=count($report['cases']);
    $report['failed_cases']=count(array_filter($report['cases'],static fn($row)=>empty($row['passed'])));
    $report['passed']=$report['case_count']===($report['before_repro']?2:34)&&$report['failed_cases']===0;
} catch (Throwable $error) {
    $report['failure_class']=get_class($error);
    $report['failure_fingerprint']=hash('sha256',$error->getMessage());
}
echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . "\n";
exit($report['passed']?0:1);

}
namespace app\common\service\app\aigc_video {
    function fopen($url,$mode,$include=false,$context=null){return \OwnedMediaReadFault::open($url,$mode,$include,$context);}
    function fread($stream,$length){return \OwnedMediaReadFault::read($stream,$length);}
    function fclose($stream){return \OwnedMediaReadFault::close($stream);}
}
namespace app\common\service\app\aigc_music {
    function fopen($url,$mode,$include=false,$context=null){return \OwnedMediaReadFault::open($url,$mode,$include,$context);}
    function fread($stream,$length){return \OwnedMediaReadFault::read($stream,$length);}
    function fclose($stream){return \OwnedMediaReadFault::close($stream);}
}
