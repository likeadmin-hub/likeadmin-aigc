<?php

namespace app\common\service\app\aigc_canvas;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\service\app\aigc_video\AigcVideoPosterService;
use app\common\service\FileService;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

/** Non-generative frame extraction for a video in the caller's saved canvas. */
class AigcCanvasFrameService
{
    public static function capture(int $tenantId, int $userId, array $params): array
    {
        if ($tenantId <= 0 || $userId <= 0) throw new Exception('请登录后截帧');
        foreach (['url', 'source_url', 'uri', 'video_url', 'storage_domain', 'storage_engine', 'storage_scope'] as $key) {
            if (array_key_exists($key, $params)) throw new Exception('截帧仅接受画布和视频节点');
        }
        $projectId = filter_var($params['project_id'] ?? null, FILTER_VALIDATE_INT);
        $nodeId = is_string($params['node_id'] ?? null) ? trim($params['node_id']) : '';
        $rawTime = $params['time'] ?? null;
        if (!$projectId || $projectId <= 0 || $nodeId === '' || strlen($nodeId) > 255) throw new Exception('缺少画布或视频节点');
        if (!is_scalar($rawTime) || is_bool($rawTime) || !is_numeric($rawTime)) throw new Exception('截帧时间无效');
        $time = (float)$rawTime;
        if (!is_finite($time) || $time < 0 || $time > 28800) throw new Exception('截帧时间无效');
        $document = Db::name('aigc_canvas_project')->where([
            'id' => $projectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->find();
        if (!$document) throw new Exception('画布项目不存在');
        $nodes = $document['nodes_json'] ?? [];
        if (is_string($nodes)) $nodes = json_decode($nodes, true);
        $node = null;
        foreach (is_array($nodes) ? $nodes : [] as $candidate) {
            if (is_array($candidate) && (string)($candidate['id'] ?? '') === $nodeId) {
                $node = $candidate;
                break;
            }
        }
        if (!$node) throw new Exception('视频节点不存在或已被删除');
        if (!in_array($node['type'] ?? '', ['video', 'videoNode'], true)) throw new Exception('只能对视频节点截帧');
        $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : (is_array($node['data'] ?? null) ? $node['data'] : []);
        $source = '';
        foreach (['assetUri', 'uri', 'video', 'video_url', 'url', 'file_url'] as $key) {
            if (is_string($metadata[$key] ?? null) && trim($metadata[$key]) !== '') {
                $source = trim($metadata[$key]);
                break;
            }
        }
        $uri = self::storedVideoUri($source);
        // A canvas document is editable by its owner. Its media metadata is not proof
        // of file ownership, so resolve both ownership and storage from server records.
        $sourceRecord = Db::name('tenant_file')->where([
            'tenant_id' => $tenantId, 'source_id' => $userId, 'source' => FileEnum::SOURCE_USER,
            'type' => FileEnum::VIDEO_TYPE,
        ])->whereIn('uri', [$uri, '/' . $uri])->whereRaw('(delete_time IS NULL OR delete_time = 0)')->find();
        if (!$sourceRecord) {
            $sourceRecord = Db::name('aigc_video_result')->where([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
            ])->whereIn('video_uri', [$uri, '/' . $uri])->find();
        }
        if (!$sourceRecord) throw new Exception('无法验证该视频的文件归属，请重新上传后截帧');
        $config = StorageConfigService::getStoredFileConfig(
            $tenantId, $sourceRecord['storage_scope'] ?? null, $sourceRecord['storage_engine'] ?? null
        );
        $scope = (string)(($sourceRecord['storage_scope'] ?? '') ?: ($config['scope'] ?? 'tenant'));
        $engine = (string)(($sourceRecord['storage_engine'] ?? '') ?: ($config['default'] ?? 'local'));
        $domain = (string)(($sourceRecord['storage_domain'] ?? '') ?: StorageConfigService::getStorageDomain($config));
        if (preg_match('#^https?://#i', $source)) {
            $host = strtolower((string)parse_url($source, PHP_URL_HOST));
            $authorizedUrl = FileService::getFileUrlByStorage($uri, $scope, $engine, $domain);
            $allowedHosts = [strtolower((string)parse_url($domain, PHP_URL_HOST)), strtolower((string)parse_url($authorizedUrl, PHP_URL_HOST))];
            if ($host === '' || !in_array($host, $allowedHosts, true)) throw new Exception('该视频尚未保存到可截帧的存储');
        }
        $stored = AigcVideoPosterService::createFrame($tenantId, $uri, $scope, $engine, $domain, max(0.001, $time), 'frames');
        TenantFile::create([
            'tenant_id' => $tenantId, 'cid' => 0, 'type' => FileEnum::IMAGE_TYPE,
            'name' => basename($stored['uri']), 'uri' => $stored['uri'],
            'storage_scope' => $stored['storage_scope'], 'storage_engine' => $stored['storage_engine'],
            'storage_domain' => $stored['storage_domain'], 'source' => FileEnum::SOURCE_USER,
            'source_id' => $userId, 'create_time' => time(),
        ]);
        return $stored + ['url' => FileService::getFileUrlByStorage(
            $stored['uri'], $stored['storage_scope'], $stored['storage_engine'], $stored['storage_domain']
        )];
    }

    private static function storedVideoUri(string $source): string
    {
        if (preg_match('#^https?://#i', $source)) {
            $source = (string)parse_url($source, PHP_URL_PATH);
        } elseif (str_contains($source, ':') || str_starts_with($source, '//')) {
            throw new Exception('视频存储地址无效');
        }
        $uri = ltrim(rawurldecode($source), '/');
        if (!preg_match('#^(uploads|resource)/.+\.(mp4|webm|mov|m4v)$#i', $uri)
            || str_contains($uri, '..') || str_contains($uri, '\\') || str_contains($uri, '%')
            || preg_match('/[\x00-\x1f\x7f]/', $uri)) {
            throw new Exception('视频存储地址无效');
        }
        return $uri;
    }
}
