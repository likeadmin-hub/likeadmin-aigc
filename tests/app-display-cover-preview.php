<?php
declare(strict_types=1);

use app\common\enum\FileEnum;
use app\common\service\app\AppDisplayConfigService as Display;
use think\facade\Db;

require dirname(__DIR__).'/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();

function checkPreview(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
}

$tenant = 1;
$app = 'aigc_layer_decomposition';
$insert = static fn($owner, $type, $name, $source = FileEnum::SOURCE_ADMIN) =>
    (int)Db::name('tenant_file')->insertGetId(['tenant_id'=>$owner, 'type'=>$type, 'name'=>$name,
        'source'=>$source, 'uri'=>'uploads/test-covers/'.$name, 'storage_scope'=>'tenant',
        'storage_engine'=>'local', 'storage_domain'=>'', 'create_time'=>time()]);

Db::startTrans();
try {
    foreach (['aigc_sale_video', 'aigc_depth_replication', 'aigc_video_replication'] as $code) {
        $uri = 'resource/image/apps/'.$code.'/cover.webp';
        $source = dirname(__DIR__).'/app/apps/'.$code.'/assets/local/cover.webp';
        $suffix = '/'.$uri.'?v='.substr(hash_file('sha256', $source), 0, 12);
        $stock = $code === 'aigc_video_replication'
            ? 'resource/image/apps/aigc_short_drama/style-covers/cinematic.webp' : $uri;
        $record = Display::save($tenant, $code, ['cover_uri'=>$stock]);
        checkPreview($record['cover_uri'] === $uri && str_ends_with($record['cover_url'], $suffix), $code.' uses the shipped artwork');
        checkPreview(Display::map($tenant, [$code])[$code]['cover_url'] === $record['cover_url'], $code.' serves the same PC and admin URL');
        Display::save($tenant, $code, ['cover_url'=>$record['cover_url']]);
        checkPreview(Db::name('tenant_app_config')->where(['tenant_id'=>$tenant,'app_code'=>$code])->value('cover_uri') === $uri, $code.' stores a stable URI');
        $custom = 'https://example.test/tenant/custom.webp?v=123456789abc';
        $record = Display::save($tenant, $code, ['cover_uri'=>$custom]);
        checkPreview($record['cover_url'] === $custom, $code.' preserves custom artwork');
    }
    $cover = Display::detail($tenant, $app)['cover_uri'];
    foreach (['preview.mp4'=>'video', 'preview.gif'=>'image', 'preview.webp'=>'image'] as $name => $kind) {
        $type = $kind === 'video' ? FileEnum::VIDEO_TYPE : FileEnum::IMAGE_TYPE;
        $id = $insert($tenant, $type, $name);
        Display::saveFromConfigPayload($tenant, $app, ['display_config'=>['cover_preview_id'=>$id]]);
        $record = Display::map($tenant, [$app])[$app];
        checkPreview($record['cover_preview_id'] === $id && $record['cover_preview_type'] === $kind, 'Saved material type');
        checkPreview(str_ends_with($record['cover_preview_url'], '/uploads/test-covers/'.$name), 'Material delivery URL');
        checkPreview($record['cover_uri'] === $cover, 'Existing poster preserved');
    }
    Display::save($tenant, $app, ['extra'=>['cover_preview_id'=>999]]);
    checkPreview(Display::detail($tenant, $app)['cover_preview_id'] === $id, 'Partial writes preserve the chosen material');
    foreach ([$insert(2, 20, 'other.mp4'), $insert($tenant, 20, 'user.mp4', FileEnum::SOURCE_USER),
        $insert($tenant, 10, 'static.png'), $insert($tenant, 30, 'file.mp4')] as $invalid) {
        try {
            Display::save($tenant, $app, ['cover_preview_id'=>$invalid]);
            throw new RuntimeException('Invalid preview was accepted');
        } catch (InvalidArgumentException) {}
    }
    Db::name('tenant_file')->where('id', $id)->update(['delete_time'=>time()]);
    checkPreview(Display::detail($tenant, $app)['cover_preview_url'] === '', 'Deleted preview falls back to poster');
    $record = Display::save($tenant, $app, ['cover_preview_id'=>0]);
    checkPreview(!isset($record['extra']['cover_preview_id']) && $record['cover_uri'] === $cover, 'Clear preview only');
    echo "App display cover preview passed (rolled back)\n";
} finally {
    Db::rollback();
}
