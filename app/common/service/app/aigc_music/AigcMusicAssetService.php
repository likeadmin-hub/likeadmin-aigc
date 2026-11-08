<?php

namespace app\common\service\app\aigc_music;

use app\common\enum\FileEnum;
use app\common\model\app\aigc_music\AigcMusicAsset;
use app\common\model\file\TenantFile;
use app\common\service\FileService;
use app\common\service\MediaDurationService;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcMusicAssetService
{
    private const ALLOWED_AUDIO_EXT = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus'];
    private const MAX_REMOTE_AUDIO_BYTES = 104857600;

    public static function persistGeneratedAudio(string $url, int $tenantId, int $userId = 0, ?array $storageConfig = null, int $resultAssetId = 0): array
    {
        if (str_starts_with($url, 'data:audio/')) {
            return self::persistDataUri($url, $tenantId, $userId, $storageConfig, $resultAssetId);
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return self::persistRemoteUrl($url, $tenantId, $userId, $storageConfig, $resultAssetId);
        }
        self::assertAudioUri($url);
        return ['uri' => $url, 'duration' => 0, 'stored' => false];
    }

    public static function uploadAudio(int $tenantId, int $userId, array $params): array
    {
        $uri = trim((string)($params['uri'] ?? ''));
        $url = trim((string)($params['url'] ?? ''));
        if ($uri !== '' || $url !== '') {
            return self::registerExisting($tenantId, $userId, $params + ['uri' => $uri ?: $url]);
        }

        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $saveDir = 'uploads/aigc_music/' . date('Ymd');
        $driver = new StorageDriver($config);
        try {
            $driver->setUploadFile('file');
        } catch (\Throwable) {
            $driver->setUploadFile('iFile');
        }
        if (!$driver->upload($saveDir)) {
            throw new Exception($driver->getError() ?: '音频上传失败');
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
        self::assertAudioUri($uri);
        $scope = $config['scope'] ?? 'platform';
        $engine = $config['default'] ?? 'local';
        $domain = StorageConfigService::getEffectiveDomain($tenantId);
        $publicPath = public_path() . ltrim($uri, '/');
        $duration = is_file($publicPath) ? MediaDurationService::detect($publicPath) : 0;
        $fileSize = is_file($publicPath) ? (int)filesize($publicPath) : 0;
        TenantFile::create([
            'tenant_id' => $tenantId,
            'upload_origin' => 'local',
            'cid' => 0,
            'type' => FileEnum::FILE_TYPE,
            'name' => basename($uri),
            'uri' => $uri,
            'storage_scope' => $scope,
            'storage_engine' => $engine,
            'storage_domain' => $domain,
            'source' => FileEnum::SOURCE_USER,
            'source_id' => $userId,
            'create_time' => time(),
        ]);
        return self::createAsset($tenantId, $userId, [
            'asset_type' => $params['asset_type'] ?? 'reference_audio',
            'source_action' => $params['source_action'] ?? 'upload_audio',
            'title' => $params['title'] ?? basename($uri),
            'uri' => $uri,
            'url' => FileService::getFileUrlByStorage($uri, $scope, $engine, $domain),
            'storage_scope' => $scope,
            'storage_engine' => $engine,
            'storage_domain' => $domain,
            'mime_type' => self::mimeByExt(pathinfo($uri, PATHINFO_EXTENSION)),
            'file_size' => $fileSize,
            'duration' => $duration,
            'auth_status' => $params['auth_status'] ?? 'pending',
            'audit_status' => 'pending',
            'audit_json' => self::authorizationPayload($params),
        ]);
    }

    public static function registerExisting(int $tenantId, int $userId, array $params): array
    {
        $uri = trim((string)($params['uri'] ?? $params['url'] ?? ''));
        if ($uri === '') {
            throw new Exception('请上传或选择音频');
        }
        self::assertAudioUri($uri);
        $canonical = FileService::setFileUrl($uri);
        $path = ltrim((string)(parse_url($uri, PHP_URL_PATH) ?: ''), '/');
        if ((str_starts_with($path, 'uploads/') || str_starts_with($path, 'resource/')) && is_file(public_path() . $path)) {
            $canonical = $path;
        }
        $canonical = preg_match('#^https?://#i', $canonical) ? $canonical : ltrim($canonical, '/');
        $file = TenantFile::where(['tenant_id'=>$tenantId,'source_id'=>$userId,'source'=>FileEnum::SOURCE_USER,'uri'=>$canonical])->findOrEmpty();
        if (!$file->isEmpty()) {
            $uri = (string)$file['uri'];
            $scope = (string)$file['storage_scope'];
            $engine = (string)$file['storage_engine'];
            $domain = (string)$file['storage_domain'];
        } elseif (preg_match('#^https?://#i', $canonical)) {
            \app\common\service\open_platform\OpenSafeHttp::resolve($canonical, false);
            $uri = $canonical;
            // External public audio is never signed using client-selected storage.
            $scope = $engine = $domain = '';
        } else {
            throw new \app\common\service\open_platform\OpenException('not_found', '音频文件不存在或不属于当前用户', 404);
        }
        return self::createAsset($tenantId, $userId, [
            'asset_type' => $params['asset_type'] ?? 'reference_audio',
            'source_action' => $params['source_action'] ?? 'register_audio',
            'title' => $params['title'] ?? basename((string)parse_url($uri, PHP_URL_PATH)),
            'uri' => $uri,
            'url' => FileService::getFileUrlByStorage($uri, $scope, $engine, $domain),
            'storage_scope' => $scope,
            'storage_engine' => $engine,
            'storage_domain' => $domain,
            'mime_type' => $params['mime_type'] ?? self::mimeByExt(pathinfo((string)parse_url($uri, PHP_URL_PATH), PATHINFO_EXTENSION)),
            'file_size' => (int)($params['file_size'] ?? 0),
            'duration' => (float)($params['duration'] ?? 0),
            'auth_status' => $params['auth_status'] ?? 'pending',
            'audit_status' => 'pending',
            'audit_json' => self::authorizationPayload($params),
        ]);
    }

    /** Register a picker-owned audio object as driver material; never transfer its bytes. */
    public static function referenceWorkspaceAudio(int $tenantId, int $userId, string $assetId, string $assetType = 'pic_lipsync_audio'): array
    {
        $asset=\app\common\service\WorkspaceMediaPickerService::resolve($tenantId,$userId,$assetId);
        if ($asset['type']!=='audio') throw new \InvalidArgumentException('请选择音频素材');
        self::assertAudioUri($asset['uri']);
        return self::createAsset($tenantId,$userId,[
            'asset_type'=>$assetType,'source_action'=>'reference_workspace_audio','title'=>$asset['name'],
            'uri'=>$asset['uri'],'url'=>$asset['url'],
            'storage_scope'=>$asset['storage_scope'] ?? '', 'storage_engine'=>$asset['storage_engine'] ?? '', 'storage_domain'=>$asset['storage_domain'] ?? '',
            'mime_type'=>($asset['mime'] ?? '') ?: self::mimeByExt(pathinfo((string)(parse_url($asset['uri'],PHP_URL_PATH) ?: $asset['uri']),PATHINFO_EXTENSION)),
            'file_size'=>max(0,(int)($asset['size'] ?? 0)), 'duration'=>max(0,(float)($asset['duration'] ?? 0)),
            'auth_status'=>'pending','audit_status'=>'pending','audit_json'=>self::authorizationPayload(['source'=>'workspace_media']),
        ]);
    }

    public static function assetUrl(array $asset): string
    {
        return FileService::getFileUrlByStorage(
            (string)($asset['uri'] ?? ''),
            (string)($asset['storage_scope'] ?? ''),
            (string)($asset['storage_engine'] ?? ''),
            (string)($asset['storage_domain'] ?? '')
        );
    }

    private static function createAsset(int $tenantId, int $userId, array $data): array
    {
        $row = AigcMusicAsset::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'asset_type' => (string)($data['asset_type'] ?? 'reference_audio'),
            'source_action' => (string)($data['source_action'] ?? ''),
            'title' => trim((string)($data['title'] ?? '')) ?: '未命名音频',
            'uri' => (string)$data['uri'],
            'url' => (string)($data['url'] ?? ''),
            'storage_scope' => (string)($data['storage_scope'] ?? 'platform'),
            'storage_engine' => (string)($data['storage_engine'] ?? 'local'),
            'storage_domain' => (string)($data['storage_domain'] ?? ''),
            'mime_type' => (string)($data['mime_type'] ?? ''),
            'file_size' => (int)($data['file_size'] ?? 0),
            'duration' => (float)($data['duration'] ?? 0),
            'checksum' => (string)($data['checksum'] ?? ''),
            'auth_status' => (string)($data['auth_status'] ?? 'pending'),
            'audit_status' => (string)($data['audit_status'] ?? 'pending'),
            'audit_json' => $data['audit_json'] ?? [],
            'status' => 1,
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
        ]);
        $result = $row->toArray();
        $result['url'] = $result['url'] ?: self::assetUrl($result);
        return $result;
    }

    private static function authorizationPayload(array $params): array
    {
        return [
            'authorization_confirmed' => (int)($params['authorization_confirmed'] ?? 0),
            'authorization_text' => trim((string)($params['authorization_text'] ?? '')),
            'source' => trim((string)($params['source'] ?? '')),
            'created_at' => time(),
        ];
    }

    private static function assertAudioUri(string $uri): void
    {
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: $uri);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_AUDIO_EXT, true)) {
            throw new Exception('仅支持 mp3、wav、ogg、flac、aac、m4a、opus 音频');
        }
    }

    private static function persistRemoteUrl(string $url, int $tenantId, int $userId, ?array $storageConfig, int $resultAssetId = 0): array
    {
        [$content, $headers] = self::downloadRemoteContent($url);
        if ($content === '' || !self::isSuccessfulResponse($headers)) {
            throw new Exception('生成音乐下载失败');
        }
        $mime = self::contentTypeFromHeaders($headers);
        if (self::isTextResponse($mime, $content)) {
            throw new Exception('生成音乐下载失败，远程地址返回的不是音频文件');
        }
        return self::persistBinary($content, $tenantId, $userId, self::extensionFromUrl($url, $mime), $mime, $storageConfig, $resultAssetId);
    }

    private static function persistDataUri(string $dataUri, int $tenantId, int $userId, ?array $storageConfig, int $resultAssetId = 0): array
    {
        if (!preg_match('/^data:audio\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUri, $matches)) {
            throw new Exception('生成音乐格式错误');
        }
        $content = base64_decode($matches[2], true);
        if ($content === false || $content === '') {
            throw new Exception('生成音乐解析失败');
        }
        return self::persistBinary($content, $tenantId, $userId, strtolower($matches[1]), 'audio/' . strtolower($matches[1]), $storageConfig, $resultAssetId);
    }

    /**
     * Ordinary workers use ext-curl framing validation. 45 seconds is now a total request deadline
     * (including redirects), replacing the old stream per-read timeout. No environment proxy is used.
     */
    private static function downloadRemoteContent(string $url, int $timeout = 45, int $maxBytes = 104857600): array
    {
        if ($timeout < 1 || $timeout > 45 || $maxBytes < 1 || $maxBytes > 104857600) throw new \InvalidArgumentException('下载限制无效');
        if (\app\common\service\open_platform\OpenCallContext::active()) {
            $wire=\app\common\service\open_platform\OpenSafeHttp::get($url,$maxBytes,$timeout);
            $headers=['HTTP/1.1 '.$wire['status']];
            foreach ($wire['headers'] as $name=>$value) $headers[]=$name.': '.$value;
            return [$wire['body'],$headers];
        }
        $curl=curl_init($url);
        if ($curl === false) throw new Exception('生成音乐下载失败');
        $headers=[]; $body=''; $status=0; $collectHeaders=false; $expected=null; $oversize=false;
        try {
            curl_setopt_array($curl,[
                CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>20,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROXY=>'', CURLOPT_NOPROXY=>'*', CURLOPT_CONNECTTIMEOUT=>$timeout, CURLOPT_TIMEOUT=>$timeout,
                CURLOPT_USERAGENT=>'LikeAdminAigcMusic/1.0', CURLOPT_RETURNTRANSFER=>false,
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
                if($oversize)throw new Exception('生成音乐下载失败，文件过大');
                if($errno===CURLE_OPERATION_TIMEDOUT)throw new Exception('生成音乐下载超时');
                if($errno===CURLE_PARTIAL_FILE)throw new Exception('生成音乐下载不完整');
                throw new Exception('生成音乐下载读取失败');
            }
            if($status!==$http)throw new Exception('生成音乐下载读取失败');
            if($expected!==null&&strlen($body)!==$expected)throw new Exception('生成音乐下载不完整');
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
                if (!preg_match('/^[0-9]+$/D',$raw)) throw new Exception('生成音乐下载响应长度无效');
                $normalized=ltrim($raw,'0'); if ($normalized==='') $normalized='0';
                $max=(string)PHP_INT_MAX;
                if (strlen($normalized)>strlen($max) || (strlen($normalized)===strlen($max) && strcmp($normalized,$max)>0)) throw new Exception('生成音乐下载响应长度无效');
                $value=(int)$normalized;
                if ($length!==null && $length!==$value) throw new Exception('生成音乐下载响应长度冲突');
                $length=$value;
            }
        }
        if ($chunked && $length!==null) throw new Exception('生成音乐下载响应长度冲突');
        return $chunked?null:$length;
    }

    private static function persistBinary(string $content, int $tenantId, int $userId, string $ext, string $mime = '', ?array $storageConfig = null, int $resultAssetId = 0): array
    {
        $ext = in_array($ext, self::ALLOWED_AUDIO_EXT, true) ? $ext : 'mp3';
        $tmp = tempnam(sys_get_temp_dir(), 'aigc_music_');
        if ($tmp === false) {
            throw new Exception('生成音乐临时文件创建失败');
        }
        $tmpPath = $tmp . '.' . $ext;
        @rename($tmp, $tmpPath);
        file_put_contents($tmpPath, $content);
        $duration = MediaDurationService::detect($tmpPath);
        try {
            if ($resultAssetId > 0) return \app\common\service\ai\AiTaskManagedFileService::persist($tmpPath, $resultAssetId, $tenantId, $userId, $storageConfig ?: StorageConfigService::getEffectiveConfig($tenantId), ['duration' => $duration, 'mime_type' => $mime ?: self::mimeByExt($ext)]);
            $stored = self::uploadLocalGeneratedFile($tmpPath, $tenantId, $userId, $storageConfig ?: StorageConfigService::getEffectiveConfig($tenantId));
        } finally {
            @unlink($tmpPath);
        }
        return [
            'uri' => $stored['uri'],
            'url' => $stored['url'],
            'storage_scope' => $stored['storage_scope'],
            'storage_engine' => $stored['storage_engine'],
            'storage_domain' => $stored['storage_domain'],
            'mime_type' => $mime ?: self::mimeByExt($ext),
            'file_size' => strlen($content),
            'duration' => $duration,
            'stored' => true,
        ];
    }

    private static function uploadLocalGeneratedFile(string $filePath, int $tenantId, int $userId, array $config): array
    {
        $saveDir = 'uploads/aigc_music/' . date('Ymd');
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($filePath);
        if (!$driver->upload($saveDir)) {
            throw new Exception($driver->getError() ?: '生成音乐保存失败');
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
        $scope = $config['scope'] ?? 'tenant';
        $engine = $config['default'] ?? 'local';
        $domain = StorageConfigService::getStorageDomain($config);
        TenantFile::create([
            'tenant_id' => $tenantId,
            'cid' => 0,
            'type' => FileEnum::FILE_TYPE,
            'name' => basename($uri),
            'uri' => $uri,
            'storage_scope' => $scope,
            'storage_engine' => $engine,
            'storage_domain' => $domain,
            'source' => FileEnum::SOURCE_USER,
            'source_id' => $userId,
            'create_time' => time(),
        ]);
        return [
            'uri' => $uri,
            'url' => FileService::getFileUrlByStorage($uri, $scope, $engine, $domain),
            'storage_scope' => $scope,
            'storage_engine' => $engine,
            'storage_domain' => $domain,
        ];
    }

    private static function extensionFromUrl(string $url, string $mime = ''): string
    {
        $pathExt = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($pathExt, self::ALLOWED_AUDIO_EXT, true)) {
            return $pathExt;
        }
        return match (strtolower(trim(explode(';', $mime)[0] ?? ''))) {
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/aac' => 'aac',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/opus' => 'opus',
            default => 'mp3',
        };
    }

    private static function isSuccessfulResponse(array $headers): bool
    {
        $status = 200;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', (string)$header, $matches)) {
                $status = (int)$matches[1];
            }
        }
        return $status >= 200 && $status < 400;
    }

    private static function contentTypeFromHeaders(array $headers): string
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

    private static function mimeByExt(string $ext): string
    {
        return match (strtolower($ext)) {
            'wav' => 'audio/wav',
            'ogg', 'opus' => 'audio/ogg',
            'flac' => 'audio/flac',
            'aac' => 'audio/aac',
            'm4a' => 'audio/mp4',
            default => 'audio/mpeg',
        };
    }
}
