<?php
declare(strict_types=1);
namespace app\common\service\decorate;

use RuntimeException;
use app\common\service\storage\SvgSanitizer;

/** Byte validation shared by local and remote imports; names/HTTP MIME are not trusted. */
final class DecorationPortableMedia
{
    public static function validate(string $bytes): array
    {
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if(preg_match('/^\s*(?:<\?xml[^>]*>\s*)?<svg\b/i',$bytes)||$mime==='image/svg+xml'){
            $bytes=SvgSanitizer::sanitize($bytes);$mime='image/svg+xml';
        }
        $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/avif'=>'avif','image/svg+xml'=>'svg','image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico','video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov','audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/ogg'=>'ogg','audio/flac'=>'flac','audio/x-flac'=>'flac','audio/mp4'=>'m4a','font/woff'=>'woff','font/woff2'=>'woff2','font/ttf'=>'ttf','font/otf'=>'otf','application/font-sfnt'=>'ttf','application/vnd.ms-fontobject'=>'eot'];
        if(!isset($types[$mime]))throw new RuntimeException('素材文件内容类型不受支持（'.$mime.'）');
        if(str_starts_with($mime,'image/')&&$mime!=='image/svg+xml'){
            $size=@getimagesizefromstring($bytes);
            if(!$size&&$mime!=='image/avif')throw new RuntimeException('图片文件内容无效');
            if($size&&($size[0]>16384||$size[1]>16384||$size[0]*$size[1]>67108864))throw new RuntimeException('图片像素尺寸超出安全上限');
        }
        return [$bytes,$mime,$types[$mime]];
    }

    public static function localPath(string $path,string $publicRoot): string
    {
        $decoded=rawurldecode($path);
        if(preg_match('~[\\\\\x00-\x20]|(?:^|/)\.\.?(/|$)|%~',$decoded)||!preg_match('~^/?(?:resource|static|uploads)/~',$decoded))throw new RuntimeException('素材本地路径无效');
        $root=realpath($publicRoot);$file=$root?realpath($root.'/'.ltrim($decoded,'/')):false;
        if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file))throw new RuntimeException('素材本地文件不存在');
        return $file;
    }
}
