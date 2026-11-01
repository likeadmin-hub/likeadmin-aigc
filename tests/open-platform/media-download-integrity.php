<?php
declare(strict_types=1);

/** Owned HTTP only. The legacy video publication boundary is replaced before autoload; no DB or storage is used. */
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

/** The listener itself is inherited across fork: no port-reservation gap or arbitrary URL input. */
function ownedMediaHttp(string $body, bool $short, callable $read): mixed
{
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $why);
    mediaCheck(is_resource($listener), 'Cannot create owned listener');
    $address = stream_socket_get_name($listener, false);
    mediaCheck(is_string($address) && preg_match('/^127\.0\.0\.1:[0-9]+$/D', $address) === 1, 'Unexpected listener identity');
    $path = '/owned-media-' . bin2hex(random_bytes(12)) . '/source.mp4';
    $pid = pcntl_fork();
    mediaCheck($pid >= 0, 'Cannot fork owned HTTP fixture');
    if ($pid === 0) {
        $client = @stream_socket_accept($listener, 5);
        if (!is_resource($client)) exit(51);
        stream_set_timeout($client, 2);
        $request = fgets($client);
        if ($request !== 'GET ' . $path . " HTTP/1.1\r\n" && $request !== 'GET ' . $path . " HTTP/1.0\r\n") exit(52);
        $length = strlen((string)$request);
        while (($line = fgets($client)) !== false && trim($line) !== '') {
            $length += strlen($line); if ($length > 8192) exit(53);
        }
        $wire = "HTTP/1.1 200 OK\r\nContent-Type: video/mp4\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n" . ($short ? substr($body, 0, 32) : $body);
        $offset=0;
        while ($offset < strlen($wire)) {
            $n=@fwrite($client, substr($wire, $offset));
            if ($n === false || $n === 0) exit(54);
            $offset += $n;
        }
        fclose($client); fclose($listener); exit(0);
    }
    fclose($listener);
    try { return $read('http://' . $address . $path); }
    finally {
        $until=microtime(true)+1; $status=0;
        do { $done=pcntl_waitpid($pid, $status, WNOHANG); if ($done !== 0) break; usleep(10000); } while (microtime(true)<$until);
        if ($done === 0) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
        mediaCheck($done === $pid && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Owned HTTP exchange did not complete');
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
                $case['rejected']=$hasReader && get_class($error)===Exception::class;
                $case['short_body_admitted']=false;
            }
            $report['cases'][]=$case;
        }
    } finally { restore_error_handler(); }
    $report['suppressed_warning_count']=$warnings;
    $report['defects_reproduced']=count(array_filter($report['cases'],static fn($row)=>!empty($row['short_body_admitted'])));
    $report['passed']=count($report['cases'])===2 && count(array_filter($report['cases'],static fn($row)=>$row['rejected']))===2;
} catch (Throwable $error) {
    $report['failure_class']=get_class($error);
    $report['failure_fingerprint']=hash('sha256',$error->getMessage());
}
echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR) . "\n";
exit($report['passed']?0:1);
