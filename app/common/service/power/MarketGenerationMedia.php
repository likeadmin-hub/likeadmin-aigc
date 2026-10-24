<?php
namespace app\common\service\power;

use app\common\service\open_platform\OpenSafeHttp;
use InvalidArgumentException;

/** Measured media facts for source-duration quotes. Browser duration metadata is never authoritative. */
final class MarketGenerationMedia
{
    public static function seconds(array $input): float
    {
        $totals=['video'=>0.0,'audio'=>0.0];
        $walk=static function($value,string $kind='') use (&$walk,&$totals): void {
            if(is_array($value)) { foreach($value as $key=>$child) {
                $next=is_int($key)?$kind:(preg_match('/video|first_clip/i',(string)$key)?'video':(preg_match('/audio|voice/i',(string)$key)?'audio':(preg_match('/image|frame/i',(string)$key)?'':$kind)));
                $walk($child,$next);
            } return; }
            if(isset($totals[$kind]) && is_string($value) && preg_match('~^https?://~i',$value)) $totals[$kind]+=self::probe($value,$kind);
        };
        $walk($input);
        $seconds=max($totals);
        if($seconds<=0)throw new InvalidArgumentException('无法核验计费素材时长，请上传有效音视频素材');
        return $seconds;
    }

    private static function probe(string $url,string $kind): float
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
            if(file_put_contents($path,$body)!==strlen($body))throw new InvalidArgumentException('素材读取失败');
            unset($body);
            $process=proc_open([$binary,'-v','error','-protocol_whitelist','file','-format_whitelist','mov,matroska,webm,mp3,wav,ogg,flac,aac,avi','-show_entries','format=duration:stream=codec_type','-of','json',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new InvalidArgumentException('媒体时长校验启动失败');
            fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$start=microtime(true);$out='';$err='';$code=-1;
            try {
                do { $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
                    if(strlen($out)>65536 || strlen($err)>65536 || microtime(true)-$start>15){proc_terminate($process,9);throw new InvalidArgumentException('媒体时长校验超时，请重新上传');}
                    $status=proc_get_status($process);if(!$status['running']){$code=$status['exitcode'];$out.=stream_get_contents($pipes[1]);break;}usleep(20000);
                }while(true);
            }finally{fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}
            $info=json_decode($out,true);$seconds=(float)($info['format']['duration']??0);
            if($code!==0 || !is_finite($seconds) || $seconds<=0 || !in_array($kind,array_column($info['streams']??[],'codec_type'),true))throw new InvalidArgumentException('素材损坏或没有有效音视频轨道');
            return $cache[$key]=$seconds;
        }finally{@unlink($path);}
    }
}
