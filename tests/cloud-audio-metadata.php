<?php
/** Reuses the isolated SQLite and byte transport fixture, never a real provider. */
require __DIR__ . '/image-human-cloud-audio.php';
use think\facade\Db;
use app\common\service\app\aigc_music\AigcMusicAssetService as Music;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncAdminService as Admin;
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    echo 'PASS ' . $message . "\n";
}
Db::name('tenant_file')->insert($row + ['source'=>\app\common\enum\FileEnum::SOURCE_USER, 'source_id'=>7]);
Db::execute('CREATE TABLE la_aigc_music_asset (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, asset_type TEXT, source_action TEXT, title TEXT, uri TEXT, url TEXT, storage_scope TEXT, storage_engine TEXT, storage_domain TEXT, mime_type TEXT, file_size INTEGER, duration REAL, checksum TEXT, auth_status TEXT, audit_status TEXT, audit_json TEXT, status INTEGER, create_time INTEGER, update_time INTEGER, delete_time INTEGER)');
$asset = Music::registerExisting(2, 7, ['uri'=>$uri, 'duration'=>999]);
check(abs($asset['duration'] - 2.88) < 0.000001, 'existing cloud audio is measured rather than trusting client duration');
Db::execute('CREATE TABLE la_aigc_digital_human_voice (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, source TEXT, name TEXT, audio_uri TEXT, preview_audio_uri TEXT DEFAULT "", cover_uri TEXT, gender TEXT, age_group TEXT, storage_scope TEXT, storage_engine TEXT, storage_domain TEXT, duration INTEGER, provider_asset_id TEXT, provider TEXT, status TEXT, sort INTEGER, delete_time INTEGER, create_time INTEGER, update_time INTEGER)');
$voice = Admin::savePublicVoice(2, ['audio_uri'=>$uri, 'name'=>'Fixture']);
check($voice['duration'] === 3, 'public cloud reference voice retains the existing whole-second rule');
try { Admin::savePublicVoice(2, ['audio_uri'=>'uploads/missing.wav']); throw new RuntimeException('Unmeasurable voice was accepted'); }
catch (Exception $e) { check(Db::name('aigc_digital_human_voice')->count() === 1, 'unmeasurable voice is not saved as ready'); }
check(Db::name('aigc_music_asset')->value('duration') == 2.88, 'measured duration is persisted');
$shadow = public_path() . $uri;
if (is_file($shadow)) throw new RuntimeException('Fixture local path already exists');
@mkdir(dirname($shadow), 0700, true);
$short = str_repeat("\0", 16000);
file_put_contents($shadow, 'RIFF'.pack('V',36+strlen($short)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($short)).$short);
try {
    check(\app\common\service\AudioDurationService::detect($uri,2,$row) === 2.88, 'explicit cloud metadata wins over a stale same-key local file');
    check(\app\common\service\AudioDurationService::detect($uri,2) === 2.88, 'stored cloud metadata wins over a stale same-key local file');
} finally { unlink($shadow); }
