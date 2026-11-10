<?php
/** Defaults to dry run. Usage: php scripts/repair-audio-durations.php [--tenant=2] [--limit=100] [--apply] */
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/vendor/autoload.php';
(new think\App(dirname(__DIR__) . '/'))->initialize();
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });
$options = getopt('', ['tenant:', 'limit:', 'apply']);
$rows = \app\common\service\AudioDurationRepairService::scan(max(0, (int)($options['tenant'] ?? 0)), (int)($options['limit'] ?? 100));
$summary = ['mode'=>isset($options['apply'])?'apply':'dry_run', 'candidates'=>count($rows),
    'measurable'=>count(array_filter($rows, static fn($r)=>$r['duration'] !== null)),
    'records'=>array_map(static fn($r)=>['table'=>$r['table'],'tenant_id'=>$r['before']['tenant_id'],'id'=>$r['before']['id'],
        'before'=>$r['before']['duration'],'measured_seconds'=>$r['measured_seconds'],'duration'=>$r['duration']], $rows)];
if (isset($options['apply']) && $rows !== []) $summary['result'] = \app\common\service\AudioDurationRepairService::apply($rows);
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
