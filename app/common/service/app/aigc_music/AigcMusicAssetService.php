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

    public static function persistGeneratedAudio(string $url, int $tenantId, int $userId = 0): array
    {
        if (str_starts_with($url, 'data:audio/')) {
            return self::persistDataUri($url, $tenantId, $userId);
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return self::persistRemoteUrl($url, $tenantId, $userId);
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
        $scope = trim((string)($params['storage_scope'] ?? StorageConfigService::currentScope()));
        $engine = trim((string)($params['storage_engine'] ?? StorageConfigService::getEffectiveDefault($tenantId)));
        $domain = trim((string)($params['storage_domain'] ?? StorageConfigService::getEffectiveDomain($tenantId)));
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

    private static function persistRemoteUrl(string $url, int $tenantId, int $userId): array
    {
        [$content, $headers] = self::downloadRemoteContent($url);
        if ($content === '' || !self::isSuccessfulResponse($headers)) {
            throw new Exception('生成音乐下载失败');
        }
        $mime = self::contentTypeFromHeaders($headers);
        if (self::isTextResponse($mime, $content)) {
            throw new Exception('生成音乐下载失败，远程地址返回的不是音频文件');
        }
        return self::persistBinary($content, $tenantId, $userId, self::extensionFromUrl($url, $mime), $mime);
    }

    private static function persistDataUri(string $dataUri, int $tenantId, int $userId): array
    {
        if (!preg_match('/^data:audio\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUri, $matches)) {
            throw new Exception('生成音乐格式错误');
        }
        $content = base64_decode($matches[2], true);
        if ($content === false || $content === '') {
            throw new Exception('生成音乐解析失败');
        }
        return self::persistBinary($content, $tenantId, $userId, strtolower($matches[1]), 'audio/' . strtolower($matches[1]));
    }

    private static function downloadRemoteContent(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 45,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => "User-Agent: LikeAdminAigcMusic/1.0\r\n",
            ],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if (!is_resource($stream)) {
            throw new Exception('生成音乐下载失败');
        }
        $meta = stream_get_meta_data($stream);
        $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
        $contentLength = self::contentLengthFromHeaders($headers);
        if ($contentLength > self::MAX_REMOTE_AUDIO_BYTES) {
            fclose($stream);
            throw new Exception('生成音乐下载失败，音频文件过大');
        }
        $content = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    break;
                }
                $content .= $chunk;
                if (strlen($content) > self::MAX_REMOTE_AUDIO_BYTES) {
                    throw new Exception('生成音乐下载失败，音频文件过大');
                }
            }
        } finally {
            fclose($stream);
        }
        return [$content, $headers];
    }

    private static function persistBinary(string $content, int $tenantId, int $userId, string $ext, string $mime = ''): array
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
            $stored = self::uploadLocalGeneratedFile($tmpPath, $tenantId, $userId);
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

    private static function uploadLocalGeneratedFile(string $filePath, int $tenantId, int $userId): array
    {
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $saveDir = 'uploads/aigc_music/' . date('Ymd');
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($filePath);
        if (!$driver->upload($saveDir)) {
            throw new Exception($driver->getError() ?: '生成音乐保存失败');
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
        $scope = $config['scope'] ?? 'tenant';
        $engine = $config['default'] ?? 'local';
        $domain = StorageConfigService::getEffectiveDomain($tenantId);
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

    private static function contentLengthFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (stripos((string)$header, 'Content-Length:') === 0) {
                return max(0, (int)trim(substr((string)$header, strlen('Content-Length:'))));
            }
        }
        return 0;
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
