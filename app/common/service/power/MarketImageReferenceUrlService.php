<?php

namespace app\common\service\power;

use app\common\service\FileService;
use think\facade\Db;

/** Resolve stored canvas image references to URLs accepted by market providers. */
final class MarketImageReferenceUrlService
{
    /** @return array<int, string> */
    public static function resolve(array $references, int $tenantId): array
    {
        $urls = [];
        foreach ($references as $reference) {
            $url = trim((string)$reference);
            if ($url === '') {
                continue;
            }
            if (preg_match('/^(https?:\/\/|data:image\/)/i', $url) !== 1) {
                $uri = ltrim($url, '/');
                $file = $tenantId > 0
                    ? Db::name('tenant_file')->where(['tenant_id' => $tenantId, 'uri' => $uri])->order('id', 'desc')->find()
                    : null;
                $url = !empty($file)
                    ? FileService::getFileUrlByStorage($uri, (string)($file['storage_scope'] ?? ''), (string)($file['storage_engine'] ?? ''), (string)($file['storage_domain'] ?? ''))
                    : FileService::getFileUrl($uri);
            }
            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }
        return $urls;
    }
}
