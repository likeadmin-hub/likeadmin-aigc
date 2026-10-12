<?php
namespace app\common\service\app\aigc_music;

use app\common\service\open_platform\OpenException;

/** Decode locally with no network protocols, bounded threads, wall time and output. */
final class MusicExportDecoder
{
    public static function binary(): string
    {
        if(!function_exists('proc_open'))return '';
        $configured=(string)getenv('OPEN_PLATFORM_MUSIC_FFMPEG');
        $candidates=$configured!==''?[$configured]:['/usr/bin/ffmpeg','/usr/local/bin/ffmpeg','/www/server/ffmpeg/bin/ffmpeg','/opt/homebrew/bin/ffmpeg'];
        foreach($candidates as $path)if(str_starts_with($path,'/')&&is_file($path)&&is_executable($path))return $path;
        return '';
    }
    public static function assertAvailable(): void
    {
        if(self::binary()==='')throw new OpenException('capability_unavailable','视频导出校验服务未配置，暂不能提交',503);
    }
    public static function valid(string $bytes): bool
    {
        self::assertAvailable();$file=tempnam(sys_get_temp_dir(),'music_verify_');if($file===false)return false;$process=null;
        try{
            if(file_put_contents($file,$bytes)!==strlen($bytes))return false;
            // Local MP4 only. No shell, playlists, protocol credentials or external references.
            $args=[self::binary(),'-nostdin','-hide_banner','-v','error','-xerror','-max_alloc','67108864','-protocol_whitelist','file','-format_whitelist','mov','-threads','1','-i',$file,'-map','0:v','-map','0:a?','-threads','1','-f','null','-'];
            $process=proc_open($args,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes,null,[]);if(!is_resource($process))return false;
            stream_set_blocking($pipes[2],false);$deadline=microtime(true)+60;$errors='';$code=-1;
            do{$errors.=stream_get_contents($pipes[2]);if(strlen($errors)>65536||microtime(true)>$deadline){proc_terminate($process,9);break;}$state=proc_get_status($process);if(!$state['running']){$code=$state['exitcode'];break;}usleep(50000);}while(true);
            $errors.=stream_get_contents($pipes[2]);fclose($pipes[2]);proc_close($process);$process=null;
            return $code===0&&trim($errors)==='';
        }finally{if(is_resource($process)){proc_terminate($process,9);proc_close($process);}@unlink($file);}
    }
}
