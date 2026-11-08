<?php
namespace app\common\service\app\aigc_image;

use app\common\enum\FileEnum;
use app\common\service\FileService;
use think\facade\Db;

/** Read-only display URLs; durable request references remain unchanged for retries. */
final class AigcImageReferenceDisplayService
{
    /** Display slots parallel reference_images, including duplicates and unresolved empty slots. */
    public static function appendRows(array $rows): array
    {
        $owners = []; $uris = [];
        foreach ($rows as $row) {
            $tenant = (int)($row['tenant_id'] ?? 0); $user = (int)($row['user_id'] ?? 0);
            if ($tenant <= 0 || $user <= 0) continue;
            $owners[$tenant.':'.$user] = [$tenant, $user];
            foreach ((array)($row['reference_images'] ?? []) as $reference) {
                $uri = self::uri($reference);
                if ($uri !== '') $uris[$uri] = $uri;
            }
        }
        $files = [];
        if ($owners && $uris) {
            // Both lookups are bounded by the page, never one query per reference or task.
            $uploads = Db::name('tenant_file')->where('source', FileEnum::SOURCE_USER)
                ->where(function ($query) use ($owners) {
                    foreach ($owners as [$tenant, $user]) $query->whereOr(function ($query) use ($tenant, $user) {
                        $query->where(['tenant_id'=>$tenant, 'source_id'=>$user]);
                    });
                })->whereIn('uri', array_values($uris))
                ->where(function ($query) { $query->whereNull('delete_time')->whereOr('delete_time', 0); })
                ->field('tenant_id,source_id,uri,storage_scope,storage_engine,storage_domain')->order('id', 'desc')->select()->toArray();
            foreach ($uploads as $file) $files[$file['tenant_id'].':'.$file['source_id']][$file['uri']] ??= $file;
            $generated = Db::name('aigc_image_result')->alias('r')
                ->join('aigc_image_task t', 't.id=r.task_id AND t.tenant_id=r.tenant_id AND t.user_id=r.user_id AND t.delete_time=0')
                ->where(function ($query) use ($owners) {
                    foreach ($owners as [$tenant, $user]) $query->whereOr(function ($query) use ($tenant, $user) {
                        $query->where(['r.tenant_id'=>$tenant, 'r.user_id'=>$user]);
                    });
                })->where('r.delete_time', 0)->whereIn('r.image_uri', array_values($uris))
                ->field('r.tenant_id,r.user_id,r.image_uri,r.storage_scope,r.storage_engine,r.storage_domain')
                ->order('r.id', 'desc')->select()->toArray();
            foreach ($generated as $file) $files[$file['tenant_id'].':'.$file['user_id']][$file['image_uri']] ??= $file;
        }
        $cache = [];
        foreach ($rows as &$row) {
            $tenant = (int)($row['tenant_id'] ?? 0); $user = (int)($row['user_id'] ?? 0);
            $owner = $tenant.':'.$user;
            $row['reference_image_urls'] = [];
            foreach ((array)($row['reference_images'] ?? []) as $reference) {
                if (!is_string($reference) || $tenant <= 0 || $user <= 0) {
                    $row['reference_image_urls'][] = ''; continue;
                }
                $value = trim($reference);
                $row['reference_image_urls'][] = $cache[$owner][$value] ??= self::resolve($value, $files[$owner] ?? [], $tenant);
            }
        }
        unset($row);
        return $rows;
    }

    private static function resolve(string $value, array $files, int $tenant): string
    {
        $uri = self::uri($value);
        if ($uri === '') return '';
        $remote = preg_match('~^https?://~i', $value) === 1;
        $file = $files[$uri] ?? null;
        // Do not re-sign an unrelated host merely because its path matches an owned key.
        if ($file && (!$remote || parse_url($value, PHP_URL_HOST) === parse_url($file['storage_domain'], PHP_URL_HOST))) {
            return FileService::getFileUrlByStorage($uri, (string)$file['storage_scope'], (string)$file['storage_engine'], (string)$file['storage_domain'], $tenant);
        }
        if ($remote) return $value;
        // Old local references can predate asset records. Never guess a cloud disk for missing metadata.
        return is_file(public_path().$uri) ? FileService::getFileUrlByStorage($uri, 'platform', 'local', '', $tenant) : '';
    }

    private static function uri(mixed $value): string
    {
        if (!is_string($value)) return '';
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '//') || str_contains($value, '\\') || preg_match('/[\x00-\x20]/', $value)) return '';
        $remote = preg_match('~^https?://~i', $value) === 1;
        if (!$remote && preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) return '';
        if ($remote && !parse_url($value, PHP_URL_HOST)) return '';
        $path = parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', rawurldecode($path))) return '';
        // Cloud CDN domains may include a prefix before the durable upload key.
        if ($remote && preg_match('~(?:^|/)((?:uploads|storage)/.+)$~', $path, $match)) return $match[1];
        return ltrim($path, '/');
    }
}
