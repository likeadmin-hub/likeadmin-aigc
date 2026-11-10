<?php
namespace app\common\service\power;

use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\FileService;
use InvalidArgumentException;
use think\facade\Db;

/** Measured media facts for source-duration quotes. Browser duration metadata is never authoritative. */
final class MarketGenerationMedia
{
    private static array $storedVideoSeconds = [];

    /** Use measured result metadata only for this owner's resolved video references
     * during this operation. Other URLs retain the normal remote probe. */
    public static function withStoredVideoReferences(int $tenantId, int $userId, array $references, callable $operation): mixed
    {
        $byUri = [];
        foreach ($references as $ref) {
            if (($ref['type'] ?? '') !== 'video' || (int)($ref['asset_id'] ?? 0) <= 0 || empty($ref['uri'])) continue;
            $byUri[(string)$ref['uri']][] = $ref;
        }
        $seconds = [];
        if ($byUri) {
            $rows = Db::name('ai_task_result_asset')->where([
                'tenant_id'=>$tenantId, 'user_id'=>$userId, 'asset_type'=>'video', 'transfer_status'=>'stored',
            ])->whereIn('local_uri', array_keys($byUri))->field('local_uri,storage_scope,storage_engine,storage_domain,storage_meta')->select();
            foreach ($rows as $row) {
                $facts = json_decode((string)$row['storage_meta'], true);
                $duration = (float)($facts['duration'] ?? 0);
                if (!is_numeric($facts['duration'] ?? null) || !is_finite($duration) || $duration <= 0 || (int)($facts['file_size'] ?? 0) <= 0) continue;
                foreach ($byUri[$row['local_uri']] ?? [] as $ref) {
                    if ($row['storage_scope'] !== ($ref['storage_scope'] ?? null)
                        || $row['storage_engine'] !== ($ref['storage_engine'] ?? null)
                        || $row['storage_domain'] !== ($ref['storage_domain'] ?? null)) continue;
                    $url = FileService::getFileUrlByStorage($row['local_uri'], $row['storage_scope'], $row['storage_engine'], $row['storage_domain']);
                    if ($url === ($ref['url'] ?? '')) $seconds[$url] = $duration;
                }
            }
        }
        $previous = self::$storedVideoSeconds;
        self::$storedVideoSeconds = $seconds;
        try {
            return $operation();
        } finally {
            self::$storedVideoSeconds = $previous;
        }
    }

    /** Internal scope for media already measured from authorized bytes by an app service. Never accept browser facts here. */
    public static function withVerifiedVideo(string $url, float $seconds, callable $operation): mixed
    {
        if (!preg_match('~^https?://~i', $url) || !is_finite($seconds) || $seconds <= 0) throw new InvalidArgumentException('实测视频信息无效');
        $previous = self::$storedVideoSeconds;
        self::$storedVideoSeconds[$url] = $seconds;
        try { return $operation(); } finally { self::$storedVideoSeconds = $previous; }
    }

    public static function seconds(array $input, bool $estimateWithoutTimedInput = false, ?callable $probe = null): float
    {
        $totals=['video'=>0.0,'audio'=>0.0];$count=0;
        $media=self::inputs($input);
        foreach(array_keys($totals)as$kind)foreach($media[$kind]as$url){
            if(!preg_match('~^https?://~i',$url))throw new InvalidArgumentException('计费素材缺少可核验的音视频地址');
            $measured=$kind==='video' ? (self::$storedVideoSeconds[$url]??null) : null;
            $duration=$measured??($probe!==null?$probe($url,$kind):self::probe($url,$kind));
            if((!is_int($duration)&&!is_float($duration))||!is_finite((float)$duration)||$duration<=0)throw new InvalidArgumentException('素材实测时长无效');
            $totals[$kind]+=$duration;$count++;
        }
        $seconds=max($totals);
        if($count===0 && $estimateWithoutTimedInput)return 60.0; // Same conservative estimate as the upstream meter; actual receipt is mandatory.
        if($seconds<=0)throw new InvalidArgumentException('无法核验计费素材时长，请上传有效音视频素材');
        return $seconds;
    }

    /** Resolve only ambiguous encoded visual slots; ordinary typed extraction remains pure. */
    public static function inputs(array $native, ?callable $visualProbe = null, array $declaredKinds = []): array
    {
        $media=MarketGenerationMediaInputs::extract($native,$declaredKinds);
        foreach(MarketGenerationMediaInputs::visualUrls($native)as$url){
            if(!preg_match('~^https?://~i',$url))throw new InvalidArgumentException('主体素材缺少可核验的图片或视频地址');
            $facts=$visualProbe!==null?$visualProbe($url):self::probeFacts($url,'visual');
            if(!is_array($facts)||!in_array($facts['kind']??'', ['image','video'],true)
                ||($facts['kind']==='video'&&(!is_numeric($facts['duration']??null)||!is_finite((float)$facts['duration'])||(float)$facts['duration']<=0)))
                throw new InvalidArgumentException('主体素材不是有效的图片或视频');
            $media[$facts['kind']][]=$url;
        }
        return $media;
    }

    private static function probe(string $url,string $kind): float
    {
        return (float)self::probeFacts($url,$kind)['duration'];
    }

    private static function probeFacts(string $url,string $kind): array
    {
        static $cache=[];$key=$kind.':'.hash('sha256',$url);
        if(isset($cache[$key]))return $cache[$key];
        $binary='';
        foreach([root_path().'runtime/bin/ffprobe','/usr/bin/ffprobe','/usr/local/bin/ffprobe','/www/server/ffmpeg/bin/ffprobe'] as $candidate)if(@is_executable($candidate)){$binary=$candidate;break;}
        if($binary==='' || !function_exists('proc_open'))throw new InvalidArgumentException('媒体时长校验组件未配置，请联系管理员');
        $path=tempnam(sys_get_temp_dir(),'market-media-');
        if($path===false)throw new InvalidArgumentException('无法准备素材时长校验');
        try {
            $body=OpenSafeHttp::get($url,($kind==='audio'?20:200)*1024*1024,30)['body'];
            if($kind==='visual'&&($image=@getimagesizefromstring($body))!==false&&$image[0]>0&&$image[1]>0)return $cache[$key]=['kind'=>'image','duration'=>0.0];
            if(file_put_contents($path,$body)!==strlen($body))throw new InvalidArgumentException('素材读取失败');
            unset($body);
            $facts=self::localFacts($path,$kind);
            return $cache[$key]=$cache[$facts['kind'].':'.hash('sha256',$url)]=$facts;
        }finally{@unlink($path);}
    }

    /** Probe already downloaded, local bytes with a bounded process and no network protocols. */
    public static function localFacts(string $path,string $kind='video'): array
    {
        if(!in_array($kind,['video','audio','visual'],true)||preg_match('~^[a-z][a-z0-9+.-]*://~i',$path)||!is_file($path)||!is_readable($path)||is_link($path))throw new InvalidArgumentException('媒体本地文件无效');
        $binary='';
        foreach([root_path().'runtime/bin/ffprobe','/usr/bin/ffprobe','/usr/local/bin/ffprobe','/www/server/ffmpeg/bin/ffprobe'] as $candidate)if(@is_executable($candidate)){$binary=$candidate;break;}
        if($binary==='' || !function_exists('proc_open'))throw new InvalidArgumentException('媒体时长校验组件未配置，请联系管理员');
        $process=proc_open([$binary,'-v','error','-protocol_whitelist','file','-format_whitelist','mov,matroska,webm,mp3,wav,ogg,flac,aac,avi','-show_entries','format=duration:stream=codec_type,width,height','-of','json',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new InvalidArgumentException('媒体时长校验启动失败');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$start=microtime(true);$out='';$err='';$code=-1;
        try {
            do { $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
                if(strlen($out)>65536 || strlen($err)>65536 || microtime(true)-$start>15){proc_terminate($process,9);throw new InvalidArgumentException('媒体时长校验超时，请重新上传');}
                $status=proc_get_status($process);if(!$status['running']){$code=$status['exitcode'];$out.=stream_get_contents($pipes[1]);break;}usleep(20000);
            }while(true);
        }finally{fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
        $info=json_decode($out,true);$seconds=(float)($info['format']['duration']??0);
        $expected=$kind==='visual'?'video':$kind;
        if($code!==0 || !is_finite($seconds) || $seconds<=0 || !in_array($expected,array_column($info['streams']??[],'codec_type'),true))throw new InvalidArgumentException('素材损坏或没有有效音视频轨道');
        $video=[];foreach(($info['streams']??[]) as $stream)if(($stream['codec_type']??'')==='video'){$video=$stream;break;}
        return ['kind'=>$expected,'duration'=>$seconds,'width'=>max(0,(int)($video['width']??0)),'height'=>max(0,(int)($video['height']??0)),
            'file_size'=>(int)filesize($path),'mime_type'=>(string)mime_content_type($path)];
    }

    /** Only server-owned output URLs call this; downloads retain the existing safe HTTP bounds. */
    public static function videoFacts(string $url): array
    {
        return self::probeFacts($url,'video');
    }
}
