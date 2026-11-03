<?php

namespace app\common\service\app\aigc_video;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcVideoAssetService
{
    /** Publish an internal generated file through the existing storage driver without base64 copies. */
    public static function persistGeneratedVideoFile(string $path, int $tenantId, int $userId = 0, ?array $storageConfig = null): array
    {
        if ($tenantId <= 0 || $userId < 0 || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)
            || !is_file($path) || !is_readable($path) || is_link($path)) {
            throw new Exception('生成视频本地文件无效');
        }
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > 268435456) {
            throw new Exception('生成视频文件为空或超过存储限制');
        }
        $prefix = file_get_contents($path, false, null, 0, 64);
        if ($prefix === false || self::detectVideoExtension($prefix) === ''
            || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov', 'm4v'], true)) {
            throw new Exception('生成视频文件损坏或格式不支持');
        }
        $config = $storageConfig ?: StorageConfigService::getEffectiveConfig($tenantId);
        $facts = self::measuredFacts($path);
        $uri = self::uploadLocalFile($path, $tenantId, $userId, $config);
        return $facts + [
            'uri' => $uri, 'width' => 0, 'height' => 0, 'stored' => true,
            'storage_scope' => (string)($config['scope'] ?? 'tenant'),
            'storage_engine' => (string)($config['default'] ?? 'local'),
            'storage_domain' => StorageConfigService::getStorageDomain($config),
        ];
    }

    public static function persistGeneratedVideo(string $url, int $tenantId, int $userId = 0, ?array $storageConfig = null, int $resultAssetId = 0): array
    {
        if (str_starts_with($url, 'data:video/')) {
            return self::persistDataUri($url, $tenantId, $userId, $storageConfig, $resultAssetId);
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            try {
                return self::persistRemoteUrl($url, $tenantId, $userId, $storageConfig, $resultAssetId);
            } catch (\Throwable $first) {
                $publicUrl = self::publicObjectUrl($url);
                if ($publicUrl === '') {
                    throw $first;
                }
                return self::persistRemoteUrl($publicUrl, $tenantId, $userId, $storageConfig, $resultAssetId);
            }
        }
        if (!self::isAllowedLocalVideoUri($url)) {
            throw new Exception('供应商返回的视频地址无效');
        }
        return ['uri' => $url, 'width' => 0, 'height' => 0, 'stored' => false];
    }

    /**
     * OSS suppliers may return a signed URL for an object that is also public.
     * A retry is limited to the exact same origin and object path.
     */
    private static function publicObjectUrl(string $url): string
    {
        $parts = parse_url($url);
        $query = [];
        if (is_array($parts) && !empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        if (!is_array($parts) || $query === [] || (!isset($query['Expires']) && !isset($query['x-oss-expires']))
            || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return '';
        }
        return (string)$parts['scheme'] . '://' . (string)$parts['host']
            . (isset($parts['port']) ? ':' . (int)$parts['port'] : '')
            . (string)$parts['path'];
    }

    private static function persistRemoteUrl(string $url, int $tenantId, int $userId, ?array $storageConfig, int $resultAssetId = 0): array
    {
        [$content, $headers] = self::downloadRemoteContent($url);
        if ($content === '') throw new Exception('生成视频下载失败');
        $httpCode = self::lastHttpStatusCode($headers);
        if ($httpCode >= 400) {
            throw new Exception('生成视频下载失败，远程地址返回HTTP ' . $httpCode);
        }
        $contentType = self::lastContentType($headers);
        if (self::isTextResponse($contentType, $content)) {
            throw new Exception('生成视频下载失败，远程地址返回的不是视频文件');
        }
        return self::persistBinary($content, $tenantId, $userId, self::extensionFromUrl($url, $content, $contentType), $storageConfig, $resultAssetId);
    }

    /**
     * Ordinary workers use ext-curl framing validation. 25 seconds is now a total request deadline
     * (including redirects), replacing the old stream per-read timeout. No environment proxy is used.
     */
    private static function downloadRemoteContent(string $url, int $timeout = 25, int $maxBytes = 268435456): array
    {
        if ($timeout < 1 || $timeout > 25 || $maxBytes < 1 || $maxBytes > 268435456) throw new \InvalidArgumentException('下载限制无效');
        if (\app\common\service\open_platform\OpenCallContext::active()) {
            $wire=\app\common\service\open_platform\OpenSafeHttp::get($url,$maxBytes,$timeout);
            $headers=['HTTP/1.1 '.$wire['status']];
            foreach ($wire['headers'] as $name=>$value) $headers[]=$name.': '.$value;
            return [$wire['body'],$headers];
        }
        $curl=curl_init($url);
        if ($curl === false) throw new Exception('生成视频下载失败');
        $headers=[]; $body=''; $status=0; $collectHeaders=false; $expected=null; $oversize=false;
        try {
            curl_setopt_array($curl,[
                CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>20,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROXY=>'', CURLOPT_NOPROXY=>'*', CURLOPT_CONNECTTIMEOUT=>$timeout, CURLOPT_TIMEOUT=>$timeout,
                CURLOPT_USERAGENT=>'LikeAdminAigcVideo/1.0', CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers,&$body,&$status,&$collectHeaders,&$expected,&$oversize,$maxBytes):int {
                    $length=strlen($line);
                    if(preg_match('~^HTTP/\S+\s+(\d{3})~i',$line,$match)){
                        // A final response's trailers cannot open a new header block.
                        if(!$collectHeaders && $status>=200 && !($status>=300&&$status<400)) return $length;
                        $status=(int)$match[1]; $headers=[]; $body=''; $expected=null; $collectHeaders=true;
                    }
                    if(!$collectHeaders)return $length;
                    if(trim($line)===''){
                        $collectHeaders=false;
                        if($status>=200&&$status<300){
                            $expected=self::contentLengthFromHeaders($headers);
                            if($expected!==null&&$expected>$maxBytes){$oversize=true;return 0;}
                        }
                    }else $headers[]=rtrim($line,"\r\n");
                    return $length;
                },
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$status,&$oversize,$maxBytes):int {
                    // Redirect bodies are not part of the final media, even when their declared length is huge.
                    if($status>=300&&$status<400)return strlen($chunk);
                    if(strlen($body)+strlen($chunk)>$maxBytes){$oversize=true;return 0;}
                    $body.=$chunk;return strlen($chunk);
                },
            ]);
            $ok=curl_exec($curl);$errno=curl_errno($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
            if($ok===false){
                $body='';
                if($oversize)throw new Exception('生成视频下载失败，文件过大');
                if($errno===CURLE_OPERATION_TIMEDOUT)throw new Exception('生成视频下载超时');
                if($errno===CURLE_PARTIAL_FILE)throw new Exception('生成视频下载不完整');
                throw new Exception('生成视频下载读取失败');
            }
            if($status!==$http)throw new Exception('生成视频下载读取失败');
            if($expected!==null&&strlen($body)!==$expected)throw new Exception('生成视频下载不完整');
            return [$body,$headers];
        } finally { curl_close($curl); }
    }

    /** Null means HTTP framing does not supply a length, including decoded chunked bodies. */
    private static function contentLengthFromHeaders(array $headers): ?int
    {
        $length=null; $chunked=false;
        foreach ($headers as $header) {
            if (stripos((string)$header,'Transfer-Encoding:')===0 && preg_match('/(?:^|,)\s*chunked\s*(?:,|$)/i',substr((string)$header,18))) $chunked=true;
            if (stripos((string)$header,'Content-Length:')!==0) continue;
            foreach (explode(',',substr((string)$header,15)) as $raw) {
                $raw=trim($raw);
                if (!preg_match('/^[0-9]+$/D',$raw)) throw new Exception('生成视频下载响应长度无效');
                $normalized=ltrim($raw,'0'); if ($normalized==='') $normalized='0';
                $max=(string)PHP_INT_MAX;
                if (strlen($normalized)>strlen($max) || (strlen($normalized)===strlen($max) && strcmp($normalized,$max)>0)) throw new Exception('生成视频下载响应长度无效');
                $value=(int)$normalized;
                if ($length!==null && $length!==$value) throw new Exception('生成视频下载响应长度冲突');
                $length=$value;
            }
        }
        if ($chunked && $length!==null) throw new Exception('生成视频下载响应长度冲突');
        return $chunked?null:$length;
    }

    private static function persistDataUri(string $dataUri, int $tenantId, int $userId, ?array $storageConfig, int $resultAssetId = 0): array
    {
        if (!preg_match('/^data:video\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUri, $matches)) {
            throw new Exception('生成视频格式错误');
        }
        $content = base64_decode($matches[2], true);
        if ($content === false || $content === '') {
            throw new Exception('生成视频解析失败');
        }
        $ext = strtolower($matches[1]);
        return self::persistBinary($content, $tenantId, $userId, $ext === 'jpeg' ? 'jpg' : $ext, $storageConfig, $resultAssetId);
    }

    private static function persistBinary(string $content, int $tenantId, int $userId, string $ext, ?array $storageConfig, int $resultAssetId = 0): array
    {
        $detectedExt = self::detectVideoExtension($content);
        if ($detectedExt === '') {
            throw new Exception('生成视频文件损坏或格式不支持');
        }
        $ext = in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true) ? $ext : $detectedExt;
        $tmp = tempnam(sys_get_temp_dir(), 'aigc_video_');
        if ($tmp === false) {
            throw new Exception('生成视频临时文件创建失败');
        }
        $tmpPath = $tmp . '.' . $ext;
        @rename($tmp, $tmpPath);
        file_put_contents($tmpPath, $content);
        try {
            $config = $storageConfig ?: StorageConfigService::getEffectiveConfig($tenantId);
            $facts = self::measuredFacts($tmpPath);
            if ($resultAssetId > 0) return \app\common\service\ai\AiTaskManagedFileService::persist($tmpPath, $resultAssetId, $tenantId, $userId, $config, $facts);
            $uri = self::uploadLocalFile($tmpPath, $tenantId, $userId, $config);
        } finally {
            @unlink($tmpPath);
        }
        return $facts + [
            'uri' => $uri,
            'width' => 0,
            'height' => 0,
            'storage_scope' => (string)($config['scope'] ?? 'tenant'),
            'storage_engine' => (string)($config['default'] ?? 'local'),
            'storage_domain' => StorageConfigService::getStorageDomain($config),
            'stored' => true,
        ];
    }

    private static function measuredFacts(string $path): array
    {
        try {
            return array_intersect_key(\app\common\service\power\MarketGenerationMedia::localFacts($path), array_flip(['duration','width','height']))
                + ['file_size'=>(int)filesize($path), 'mime_type'=>(string)mime_content_type($path)];
        } catch (\Throwable) {
            // Storage can finish even when probing is temporarily unavailable;
            // actual-duration billing remains pending until a verified measurement exists.
            return ['width'=>0,'height'=>0];
        }
    }

    private static function uploadLocalFile(string $filePath, int $tenantId, int $userId, array $config): string
    {
        $saveDir = 'uploads/aigc_video/' . date('Ymd');
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($filePath);
        if (!$driver->upload($saveDir)) {
            throw new Exception($driver->getError() ?: '生成视频保存失败');
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
        TenantFile::create([
            'tenant_id' => $tenantId,
            'cid' => 0,
            'type' => FileEnum::VIDEO_TYPE,
            'name' => basename($uri),
            'uri' => $uri,
            'storage_scope' => $config['scope'] ?? 'tenant',
            'storage_engine' => $config['default'] ?? 'local',
            'storage_domain' => StorageConfigService::getStorageDomain($config),
            'source' => FileEnum::SOURCE_USER,
            'source_id' => $userId,
            'create_time' => time(),
        ]);
        return $uri;
    }

    private static function extensionFromUrl(string $url, string $content, string $contentType = ''): string
    {
        $pathExt = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($pathExt, ['mp4', 'webm', 'mov', 'm4v'], true)) {
            return $pathExt;
        }
        $mimeExt = match (strtolower(trim(explode(';', $contentType)[0] ?? ''))) {
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'video/x-m4v' => 'm4v',
            'video/mp4', 'application/mp4' => 'mp4',
            default => '',
        };
        return $mimeExt ?: self::detectVideoExtension($content) ?: 'mp4';
    }

    private static function lastHttpStatusCode(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', (string)$header, $matches)) {
                $status = (int)$matches[1];
            }
        }
        return $status;
    }

    private static function lastContentType(array $headers): string
    {
        $contentType = '';
        foreach ($headers as $header) {
            if (stripos((string)$header, 'Content-Type:') === 0) {
                $contentType = trim(substr((string)$header, strlen('Content-Type:')));
            }
        }
        return $contentType;
    }

    private static function isTextResponse(string $contentType, string $content): bool
    {
        $mime = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        if ($mime !== '' && (str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml'], true))) {
            return true;
        }
        $prefix = ltrim(substr($content, 0, 64));
        return str_starts_with($prefix, '<') || str_starts_with($prefix, '{') || str_starts_with($prefix, '[');
    }

    private static function detectVideoExtension(string $content): string
    {
        if (strlen($content) < 16) {
            return '';
        }
        if (str_starts_with($content, "\x1A\x45\xDF\xA3")) {
            return 'webm';
        }
        if (substr($content, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($content, 8, 4));
            return str_starts_with($brand, 'qt') ? 'mov' : 'mp4';
        }
        return '';
    }

    private static function isAllowedLocalVideoUri(string $uri): bool
    {
        $path = ltrim((string)(parse_url($uri, PHP_URL_PATH) ?: $uri), '/');
        if ($path === '' || (!str_starts_with($path, 'uploads/') && !str_starts_with($path, 'resource/'))) {
            return false;
        }
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov', 'm4v'], true);
    }
}
