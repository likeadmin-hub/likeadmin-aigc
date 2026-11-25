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
