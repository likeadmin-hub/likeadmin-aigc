<?php
/** Pure run-status and old-canvas recovery checks; no database, provider or billing. */
require dirname(__DIR__, 4) . '/vendor/autoload.php';
use app\common\service\app\aigc_canvas\AigcCanvasService as Canvas;
$checks = 0;
$check = static function ($value, $message) use (&$checks) { $checks++; if (!$value) throw new RuntimeException($message); };
$call = static fn($method, ...$args) => (new ReflectionMethod(Canvas::class, $method))->invoke(null, ...$args);
foreach (['cancelled', 'canceled', 'cancel', ' CANCELED '] as $status) {
    $check($call('normalizeRunStatus', $status, ['results'=>[['url'=>'/stale.jpg']]]) === 'canceled', 'Cancellation promoted by stale results');
}
foreach (['running','pending','queued','processing','failed','error'] as $status) {
    $expected = in_array($status, ['failed','error']) ? 'failed' : 'running';
    $check($call('normalizeRunStatus', $status, ['results'=>[['url'=>'/preview.jpg']]]) === $expected, 'Status lost');
}
$run = ['source_task_id'=>123];
foreach (['running','queued','pending','submitted','processing','loading','failed','error','canceled','cancelled'] as $status) {
    $check(!$call('canRestoreProjectRun', ['status'=>$status], $run), 'Old success replaced current attempt');
}
$check(!$call('canRestoreProjectRun', ['pending'=>true], $run), 'Pending attempt overwritten');
$check(!$call('canRestoreProjectRun', ['taskId'=>124], $run), 'Different task restored');
$check($call('canRestoreProjectRun', ['taskId'=>'123'], $run), 'Matching old task cannot restore');
$check($call('canRestoreProjectRun', ['unknown'=>['keep'=>true]], $run), 'Legacy empty node cannot restore');
echo "Canvas task recovery contract: {$checks} checks passed; no database or provider calls\n";
