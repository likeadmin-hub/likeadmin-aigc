<?php
require __DIR__ . '/cloud-audio-metadata.php';
use think\facade\Db;
use app\common\service\AudioDurationRepairService as Repair;
Db::name('aigc_music_asset')->where('id', $asset['id'])->update(['duration'=>0]);
Db::name('aigc_digital_human_voice')->where('id', $voice['id'])->update(['duration'=>0]);
$preview = Db::name('aigc_digital_human_voice')->where('id', $voice['id'])->find();
unset($preview['id']); $preview['audio_uri']=''; $preview['preview_audio_uri']=$uri;
$previewId = Db::name('aigc_digital_human_voice')->insertGetId($preview);
$scan = Repair::scan(2);
check(count($scan) === 3 && Db::name('aigc_music_asset')->value('duration') == 0, 'repair scan is read-only and includes preview-only voices');
$report = Repair::apply($scan);
check(count($report['updated']) === 3 && is_file($report['backup']), 'repair backs up all records before updating');
check(Db::name('aigc_music_asset')->value('duration') == 2.88 && Db::name('aigc_digital_human_voice')->value('duration') == 3, 'repair preserves fractional audio and whole-second voice schemas');
check(Repair::scan(2) === [], 'repeated repair has no remaining changes');
check(Db::name('aigc_digital_human_voice')->where('id',$previewId)->value('duration') == 3, 'preview-only voice duration is repaired');
Db::name('aigc_music_asset')->where('id', $asset['id'])->update(['duration'=>0]);
$scan = Repair::scan(2);
Db::name('aigc_music_asset')->where('id', $asset['id'])->update(['uri'=>'uploads/replaced.wav']);
$stale = Repair::apply($scan);
check($stale['updated'] === [] && count($stale['skipped']) === 1, 'repair does not overwrite a concurrently replaced source');
foreach ([$report['backup'], $stale['backup']] as $path) { unlink($path); rmdir(dirname($path)); }
