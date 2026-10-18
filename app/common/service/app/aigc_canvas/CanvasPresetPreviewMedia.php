<?php
namespace app\common\service\app\aigc_canvas;

use app\common\service\storage\SvgSanitizer;
use InvalidArgumentException;

/** Normalize only card previews. Graph originals and tenant uploads are never overwritten. */
final class CanvasPresetPreviewMedia
{
    public static function normalize(string $bytes,string $mime,string $kind): array
    {
        if ($kind==='image') {
            if ($mime==='image/svg+xml' || (in_array($mime,['text/xml','application/xml','text/plain'],true) && preg_match('/<svg\b/i',substr($bytes,0,4096)))) return [SvgSanitizer::sanitize($bytes),'image/svg+xml'];
            if (in_array($mime,['image/vnd.microsoft.icon','image/x-icon'],true)) return [$bytes,'image/x-icon'];
            return [$bytes,$mime];
        }
        if ($kind!=='video' || in_array($mime,['video/mp4','video/webm'],true)) return [$bytes,$mime];
        if (!in_array($mime,['video/quicktime','video/x-msvideo','video/avi','video/msvideo','video/x-ms-asf','video/x-ms-wmv','video/mpeg','video/3gpp','video/3gpp2','video/x-flv','video/x-matroska','application/vnd.rn-realmedia','application/vnd.rn-realmedia-vbr'],true)) throw new InvalidArgumentException('视频展示素材格式无法识别，请使用有效的视频文件');
        $binary='';
        foreach (array_filter([(string)env('ffmpeg_binary',''),(string)env('ffmpeg.binary',''),(string)(getenv('FFMPEG_BINARY') ?: ''),'/usr/bin/ffmpeg','/usr/local/bin/ffmpeg','/www/server/ffmpeg/bin/ffmpeg','/opt/homebrew/bin/ffmpeg']) as $candidate) if (str_starts_with($candidate,'/') && is_file($candidate) && is_executable($candidate)) { $binary=$candidate;break; }
        if ($binary==='' || !function_exists('proc_open')) throw new InvalidArgumentException('此视频格式需要服务器视频转换能力，请配置后重试，或选择 MP4 / WebM');
        $source=tempnam(sys_get_temp_dir(),'canvas_preview_');
        if ($source===false) throw new InvalidArgumentException('无法创建视频预览临时文件');
        $output=$source.'.mp4';$process=null;
        try {
            if (file_put_contents($source,$bytes)!==strlen($bytes)) throw new InvalidArgumentException('视频预览临时保存失败');
            $args=[$binary,'-nostdin','-hide_banner','-v','error','-max_alloc','67108864','-protocol_whitelist','file','-format_whitelist','avi,asf,mpeg,mpegvideo,mpegts,mov,flv,matroska,webm,rm','-threads','2','-i',$source,'-map','0:v:0','-map','0:a?','-vf',"scale=w='min(1920,iw)':h='min(1080,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",'-c:v','libx264','-preset','veryfast','-crf','24','-pix_fmt','yuv420p','-threads','2','-c:a','aac','-b:a','128k','-movflags','+faststart','-y',$output];
            $process=proc_open($args,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes,null,[]);
            if (!is_resource($process)) throw new InvalidArgumentException('视频预览转换无法启动');
            stream_set_blocking($pipes[2],false);$deadline=microtime(true)+60;$errors='';$code=-1;
            do {
                $errors.=stream_get_contents($pipes[2]);clearstatcache(true,$output);
                if (strlen($errors)>65536 || microtime(true)>$deadline || (is_file($output) && filesize($output)>104857600)) { proc_terminate($process,9);break; }
                $state=proc_get_status($process);if (!$state['running']) { $code=$state['exitcode'];break; }usleep(50000);
            } while (true);
            fclose($pipes[2]);proc_close($process);$process=null;
            if ($code!==0 || !is_file($output) || filesize($output)<32 || filesize($output)>104857600) throw new InvalidArgumentException('视频预览转换失败，请检查视频文件或改用 MP4 / WebM');
            return [file_get_contents($output),'video/mp4'];
        } finally { if (is_resource($process)) {proc_terminate($process,9);proc_close($process);} @unlink($source);@unlink($output); }
    }
}
