<?php
namespace app\common\service\power;

use app\common\service\app\aigc_video\AigcVideoReferenceAssetService;

/** Pure material serializations shared by registered model and application protocols. */
final class MarketGenerationMaterialEncoding
{
    public static function imageMessages(string $prompt, array $urls): array
    {
        $content = array_map(static fn($url)=>['image'=>$url], $urls);
        $content[] = ['text'=>$prompt];
        return ['messages'=>[['role'=>'user','content'=>$content]]];
    }

    public static function content(array $request, string $prompt): array
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach (AigcVideoReferenceAssetService::normalize($request) as $asset) {
            $type = (string)($asset['type'] ?? '');
            $url = AigcVideoReferenceAssetService::publicUrl($asset);
            if ($url === '') {
                continue;
            }
            if ($type === 'image') {
                $role = match ((string)($asset['role'] ?? 'reference_image')) {
                    'first_frame_image' => 'first_frame',
                    'last_frame_image' => 'last_frame',
                    default => 'reference_image',
                };
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url], 'role' => $role];
                continue;
            }
            if ($type === 'video') {
                $content[] = ['type' => 'video_url', 'video_url' => ['url' => $url], 'role' => 'reference_video'];
                continue;
            }
            if ($type === 'audio') {
                $content[] = ['type' => 'audio_url', 'audio_url' => ['url' => $url], 'role' => 'reference_audio'];
            }
        }
        return $content;
    }

    public static function seedance(array $request): array
    {
        $content = [['type' => 'text', 'text' => trim((string)($request['prompt'] ?? ''))]];
        foreach (AigcVideoReferenceAssetService::normalize($request) as $asset) {
            $type = (string)($asset['type'] ?? '');
            $url = trim((string)($asset['url'] ?? $asset['uri'] ?? ''));
            if ($url === '') continue;
            if (!str_starts_with($url, 'asset://')) {
                $url = AigcVideoReferenceAssetService::publicUrl($asset);
            }
            if ($type === 'image') {
                $role = match ((string)($asset['role'] ?? '')) {
                    'first_frame_image' => 'first_frame',
                    'last_frame_image' => 'last_frame',
                    default => 'reference_image',
                };
                $content[] = ['type' => 'image_url', 'role' => $role, 'image_url' => ['url' => $url]];
            } elseif ($type === 'video') {
                $content[] = ['type' => 'video_url', 'role' => 'reference_video', 'video_url' => ['url' => $url]];
            } elseif ($type === 'audio') {
                $content[] = ['type' => 'audio_url', 'role' => 'reference_audio', 'audio_url' => ['url' => $url]];
            }
        }
        return $content;
    }

    public static function wanImages(array $request): array
    {
        $images = [];
        foreach (AigcVideoReferenceAssetService::normalize($request) as $asset) {
            if (($asset['type'] ?? '') !== 'image') continue;
            $url = AigcVideoReferenceAssetService::publicUrl($asset);
            if ($url === '') continue;
            $role = match ((string)($asset['role'] ?? '')) {
                'first_frame_image' => 'first_frame',
                'last_frame_image' => 'last_frame',
                default => 'reference_image',
            };
            $images[] = ['url' => $url, 'role' => $role];
        }
        return $images;
    }

    public static function wanMedia(array $request,array $documentTypes=[],?int $maximumMedia=15): array
    {
        $media = [];
        $assets=$maximumMedia===null?AigcVideoReferenceAssetService::normalizeForMarket($request):AigcVideoReferenceAssetService::normalize($request);
        foreach ($assets as $asset) {
            $url = AigcVideoReferenceAssetService::publicUrl($asset);
            if ($url === '') {
                continue;
            }
            $type = (string)($asset['type'] ?? '');
            if ($type === 'image') {
                $media[] = [
                    'type' => match ((string)($asset['role'] ?? 'reference_image')) {
                        'first_frame_image' => 'first_frame',
                        'last_frame_image' => 'last_frame',
                        default => 'reference_image',
                    },
                    'url' => $url,
                ];
                continue;
            }
            if ($type === 'video') {
                $media[] = ['type' => 'reference_video', 'url' => $url];
                continue;
            }
            if ($type === 'audio') {
                $media[] = ['type' => 'reference_audio', 'url' => $url];
            }
        }
        // File/page references are opt-in fields of a complete reviewed mode.
        // The existing video asset normalizer intentionally handles only A/V/image.
        foreach((array)($request['reference_assets']??[])as$asset){
            if(!is_array($asset)||!in_array($asset['type']??'',array_intersect($documentTypes,['file','link']),true))continue;
            $type=$asset['type'];$raw=trim((string)($asset['url']??$asset['uri']??''));
            if($raw==='')throw new \InvalidArgumentException('参考文件或链接地址不能为空');
            if(preg_match('~^[a-z][a-z0-9+.-]*:~i',$raw)&&!preg_match('~^https?://~',$raw))throw new \InvalidArgumentException('参考文件或链接需要有效的公开 HTTP 地址');
            $url=$type==='file'?AigcVideoReferenceAssetService::publicUrl($asset):$raw;
            self::assertDocumentUrl($url);
            $media[]=['type'=>$type,'url'=>$url];
        }
        if($documentTypes!==[]&&$maximumMedia!==null&&count($media)>$maximumMedia)throw new \InvalidArgumentException('参考素材数量超出限制');
        return $media;
    }

    public static function assertDocumentUrl(string $url): void
    {
        $parts=parse_url($url);
        if(!$parts||!in_array($parts['scheme']??'',['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))
            throw new \InvalidArgumentException('参考文件或链接需要有效的公开 HTTP 地址');
    }
}
