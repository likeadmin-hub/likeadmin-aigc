<?php

namespace app\common\service\app\aigc_music_cover;

use app\common\service\app\AppAccessService;
use app\common\service\update\UpdateSourceClient;
use Exception;
use think\facade\Cache;

class AigcMusicSearchService
{
    public static function search(int $tenantId, int $userId, array $params): array
    {
        if (AppAccessService::assertTenantCanUse($tenantId, AigcMusicCoverService::APP_CODE, $userId) !== null) {
            throw new Exception('音乐翻唱应用未开通或当前账号无权限');
        }
        $config = AigcMusicCoverService::config($tenantId);
        if ((int)($config['status'] ?? 0) !== 1) {
            throw new Exception('音乐翻唱应用未启用');
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword === '' || mb_strlen($keyword) > 100) {
            throw new Exception('请输入不超过 100 字的歌曲或歌手名称');
        }
        $page = max(1, min(100, (int)($params['page'] ?? 1)));
        $pageSize = max(1, min(20, (int)($params['page_size'] ?? 10)));
        $cacheKey = 'aigc_music_search:' . md5($tenantId . ':' . $keyword . ':' . $page . ':' . $pageSize);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $source = UpdateSourceClient::getSource();
        $base = trim((string)($source['active_base_url'] ?? $source['base_url'] ?? ''));
        $key = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? ''));
        $parts = parse_url($base);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || $key === '') {
            throw new Exception('音乐搜索服务未配置');
        }
        $origin = (string)($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }
        $payload = json_encode([
            'keyword' => $keyword,
            'page' => $page,
            'page_size' => $pageSize,
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($origin . '/api/v1/apps/music_search/search');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => UpdateSourceClient::sslVerify($source),
            CURLOPT_SSL_VERIFYHOST => UpdateSourceClient::sslVerify($source) ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $failed = curl_errno($ch) !== 0;
        curl_close($ch);
        $response = json_decode((string)$body, true);
        if ($failed || $http >= 400 || !is_array($response) || (int)($response['code'] ?? 0) !== 1) {
            throw new Exception('音乐搜索服务暂不可用，请稍后重试');
        }
        $result = $response['data']['result'] ?? [];
        if (!is_array($result)) {
            throw new Exception('音乐搜索结果格式错误');
        }
        $result = [
            'result' => [
                'items' => array_values(array_filter((array)($result['items'] ?? []), static fn ($item) => is_array($item) && !empty($item['url']))),
                'page' => (int)($result['page'] ?? 1),
                'page_size' => (int)($result['page_size'] ?? 10),
                'has_more' => (bool)($result['has_more'] ?? false),
            ],
        ];
        Cache::set($cacheKey, $result, 30);
        return $result;
    }
}
