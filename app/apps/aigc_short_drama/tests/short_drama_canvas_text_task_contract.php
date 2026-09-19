<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$canvas = (string)file_get_contents($root . '/app/common/service/app/aigc_short_drama/ShortDramaCanvasService.php');
$service = (string)file_get_contents($root . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php');
$menu = (string)file_get_contents($root . '/app/apps/aigc_short_drama/menus/tenant.json');
$backfill = (string)file_get_contents($root . '/app/apps/aigc_short_drama/migrations/zz_20260919_canvas_text_task_backfill.sql');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(str_contains($canvas, '$status = $type === \'text\'') && str_contains($canvas, "? 'success'"), 'canvas text result is not completed synchronously');
$assert(str_contains($canvas, 'MarketTextModelRuntimeService::bindBusinessTask'), 'canvas text app task is not bound to its run');
$assert(str_contains($canvas, 'textResultProjection($result)'), 'canvas text billing projection is missing');
$assert(str_contains($service, "'result_content' => self::generationTaskResultContent($row)"), 'tenant task API does not expose text output');
$assert(str_contains($service, "($row['task_type'] ?? '') !== 'canvas_text'"), 'text output is not limited to canvas text tasks');
$assert(str_contains($menu, 'aigc_short_drama_creation_task'), 'creation-task tenant menu is missing');
$assert(!str_contains($menu, 'aigc_short_drama_image_task'), 'legacy image task menu remains in the app menu');
$assert(str_contains($backfill, "`node_type` = 'text'") && str_contains($backfill, "`status` = 'success'"), 'completed legacy canvas text runs are not repaired during upgrade');
$assert(str_contains($backfill, "task.`task_type` = 'canvas_text'"), 'legacy canvas text history is not repaired during upgrade');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "short drama canvas text task contract passed\n";
