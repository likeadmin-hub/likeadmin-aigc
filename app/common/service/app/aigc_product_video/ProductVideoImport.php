<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_product_video;

use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\ai\StudioMediaInputService;
use app\common\service\SubmitLockService;
use InvalidArgumentException;
use think\facade\Cache;

/** Non-billable, owner-scoped import session. Each poll performs one bounded read. */
final class ProductVideoImport
{
    private const TTL = 600;

    public static function capacity(array $model): int
    {
        $maximum = (int)($model['max_reference_images'] ?? 0);
        if ($maximum < 1) throw new InvalidArgumentException('当前视频模型不支持商品图片，请更换模型');
        return min(15, $maximum);
    }

    public static function handle(int $tenant, int $user, array $params): array
    {
        ProductVideoService::available($tenant, $user);
        if ($tenant < 1 || $user < 1) throw new InvalidArgumentException('请登录后导入商品');
        $lock = SubmitLockService::acquire('product_video_import', $tenant, $user);
        try {
            $id = (string)($params['import_id'] ?? '');
            if ($id === '') {
                $target = ProductVideoAmazon::target((string)($params['url'] ?? ''));
                $modelId = ProductVideoPolicy::text($params['model_id'] ?? '', 100);
                if ($modelId === '') throw new InvalidArgumentException('请先选择视频模型');
                $maximum = self::capacity(ProductVideoCatalog::model($tenant, ['video_selection' => ['model_id' => $modelId]]));
                $activeKey = self::key($tenant, $user, 'active');
                $active = Cache::get($activeKey);
                if (is_string($active)) {
                    $previous = Cache::get(self::key($tenant, $user, $active));
                    if (is_array($previous) && in_array($previous['status'], ['pending', 'running'], true)
                        && $previous['source_url'] === $target['url'] && $previous['model_id'] === $modelId && $previous['maximum'] === $maximum) return self::dto($previous, $tenant, $user);
                    if (is_array($previous) && time() - $previous['created_at'] < 5) throw new InvalidArgumentException('请稍候再导入另一件商品');
                }
                $id = bin2hex(random_bytes(16));
                $session = ['import_id' => $id, 'status' => 'pending', 'phase' => 'page', 'source_url' => $target['url'],
                    'model_id' => $modelId, 'maximum' => $maximum, 'images' => [], 'candidates' => [], 'cursor' => 0,
                    'name' => '', 'product_info' => '', 'skipped' => 0, 'created_at' => time(), 'error' => ''];
                Cache::set(self::key($tenant, $user, $id), $session, self::TTL);
                Cache::set($activeKey, $id, self::TTL);
                return self::dto($session, $tenant, $user);
            }
            if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new InvalidArgumentException('商品导入记录无效，请重新导入');
            $key = self::key($tenant, $user, $id); $session = Cache::get($key);
            if (!is_array($session)) throw new InvalidArgumentException('商品导入已过期，请重新导入');
            if (time() - $session['created_at'] > self::TTL) throw new InvalidArgumentException('商品导入已过期，请重新导入');
            if (isset($params['model_id']) && (string)$params['model_id'] !== $session['model_id']) throw new InvalidArgumentException('视频模型已切换，请重新导入');
            if (in_array($session['status'], ['success', 'failed'], true)) return self::dto($session, $tenant, $user);
            $session = self::advance($session, [ProductVideoAmazon::class, 'fetch'], static function (string $bytes, string $mime) use ($tenant, $user): array {
                return AigcImageAssetService::persistGeneratedImage('data:' . $mime . ';base64,' . base64_encode($bytes), $tenant, $user, true, 40000000);
            });
            Cache::set($key, $session, self::TTL);
            return self::dto($session, $tenant, $user);
        } finally { SubmitLockService::release($lock); }
    }

    /** Legacy clients still receive the original final response, using the same parser and capacity. */
    public static function synchronous(int $tenant, int $user, string $url, array $params): array
    {
        $result = self::handle($tenant, $user, $params + ['url' => $url]);
        for ($i = 0; $i < 50 && !in_array($result['status'], ['success', 'failed'], true); $i++) {
            $result = self::handle($tenant, $user, ['import_id' => $result['import_id'], 'model_id' => $result['model_id']]);
        }
        if ($result['status'] !== 'success') throw new InvalidArgumentException($result['error'] ?: '商品导入未完成，请重试');
        return $result;
    }

    /** Transport and persistence are explicit dependencies, allowing failure/partial-result regression tests. */
    public static function advance(array $session, callable $fetch, callable $persist): array
    {
        if (in_array($session['status'], ['success', 'failed'], true)) return $session;
        $session['status'] = 'running';
        try {
            if ($session['phase'] === 'page') {
                $page = $fetch($session['source_url'], false);
                $data = ProductVideoAmazon::metadata($page['body'], $page['url']);
                $session['source_url'] = $data['source_url'];
                $session['name'] = $data['name']; $session['product_info'] = $data['product_info'];
                $session['candidates'] = array_slice($data['images'], 0, min(45, $session['maximum'] * 3 + 3));
                $session['phase'] = 'images';
                return $session;
            }
            $url = $session['candidates'][$session['cursor']] ?? null;
            if ($url !== null) {
                $session['cursor']++;
                try {
                    $download = $fetch($url, true); $bytes = $download['body'];
                    $info = strlen($bytes) <= 10485760 ? @getimagesizefromstring($bytes) : false;
                    if (!$info || !in_array($info['mime'] ?? '', ['image/png','image/jpeg','image/webp'], true)
                        || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) throw new InvalidArgumentException('图片格式或尺寸不支持');
                    $checksum = hash('sha256', $bytes);
                    if (!in_array($checksum, array_column($session['images'], 'import_checksum'), true)) {
                        $stored = $persist($bytes, $info['mime']);
                        if (empty($stored['stored']) || empty($stored['uri'])) throw new InvalidArgumentException('商品图片保存失败');
                        $session['images'][] = $stored + ['import_checksum' => $checksum];
                    }
                } catch (\Throwable $e) { $session['skipped']++; }
            }
            if (count($session['images']) >= $session['maximum'] || $session['cursor'] >= count($session['candidates'])) {
                if (!$session['images']) throw new InvalidArgumentException('商品图片暂时无法保存，请重试或手动上传');
                $session['status'] = 'success'; $session['phase'] = 'done';
            }
        } catch (\Throwable $e) {
            $session['status'] = 'failed';
            $session['error'] = $e instanceof InvalidArgumentException ? $e->getMessage() : '商品读取失败，请重试或手动上传';
        }
        return $session;
    }

    private static function dto(array $session, int $tenant, int $user): array
    {
        $done = $session['status'] === 'success';
        $value = array_intersect_key($session, array_flip(['import_id','status','phase','source_url','model_id','maximum','error']));
        $value['imported_count'] = count($session['images']);
        $value['progress'] = $done ? 100 : ($session['phase'] === 'page' ? 0 : min(95, 20 + (int)(75 * $session['cursor'] / max(1, min($session['maximum'], count($session['candidates']))))));
        if ($done) {
            $model = ProductVideoCatalog::model($tenant, ['video_selection' => ['model_id' => $session['model_id']]]);
            $maximum = self::capacity($model);
            $images = StudioMediaInputService::validateImages($tenant, $user, array_slice($session['images'], 0, $maximum), $maximum);
            $value += ['name' => $session['name'], 'product_info' => $session['product_info'],
                'images' => ProductVideoService::publicBody($images, $tenant), 'notice' => $session['skipped'] ? '部分图片未能读取，已导入可用的商品图片' : ''];
            $value['maximum'] = $maximum;
        }
        return $value;
    }

    private static function key(int $tenant, int $user, string $id): string
    {
        return 'product_video_import_v1:' . $tenant . ':' . $user . ':' . $id;
    }
}
