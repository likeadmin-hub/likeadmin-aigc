<?php
/** Platform default preview migration. No tenant edits, generation, or release publishing.
 * php scripts/migrate-default-video-effect-media.php --media-dir=/path/to/media --apply
 * Or --download --apply to fetch the reviewed catalog sources with bounded downloads.
 * Omitting --apply validates local files only. Resume with the same command after failure.
 */
require dirname(__DIR__).'/vendor/autoload.php';
(new \think\App())->initialize();

use app\common\model\file\File;
use app\common\service\app\aigc_video\VideoEffectService;
use app\common\service\app\aigc_video\VideoEffectMediaService;
use app\common\service\storage\StorageConfigService;
use app\common\service\storage\Driver;

$options = getopt('', ['media-dir:', 'download', 'apply']);
$apply = isset($options['apply']);
$download = isset($options['download']);
if ($download && !$apply) throw new RuntimeException('--download requires --apply; dry run never downloads or writes');
$dir = $options['media-dir'] ?? (runtime_path().'video-effect-media');
if (!is_dir($dir) && (!$apply || !mkdir($dir, 0700, true))) throw new RuntimeException('Media directory does not exist');
$lock = fopen(runtime_path().'default-video-effect-media.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another migration is running');
$rows = VideoEffectService::defaults();
$storage = StorageConfigService::getEffectiveConfig(0);
$domain = $storage['default'] === 'local' ? '' : StorageConfigService::getEffectiveDomain(0);
if (($storage['scope'] ?? '') !== 'platform') throw new RuntimeException('Platform storage required');
$registry = VideoEffectMediaService::registry();
$prepared = [];
// Validate all source files before beginning uploads. Source URLs come only from the reviewed catalog.
foreach ($rows as $row) {
    foreach (['poster'=>'webp', 'video'=>'mp4'] as $field=>$ext) {
        $path = rtrim($dir, '/').'/'.$row['id'].'.'.$ext;
        $limit = ($field === 'poster' ? 10 : 40) * 1024 * 1024;
        if (!is_file($path) && $download) {
            $source = $row[$field];
            if (!str_starts_with($source, 'https://imagine.animagic.art/remote-config/assets/video_effects/')) throw new RuntimeException('Unapproved source URL');
            $temp = $path.'.part';
            $handle = fopen($temp, 'wb');
            $bytes = 0;
            $curl = curl_init($source);
            curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>120,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_FAILONERROR=>true, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION=>static function($curl, $chunk) use ($handle, $limit, &$bytes) {
                    $bytes += strlen($chunk);
                    return $bytes > $limit ? 0 : fwrite($handle, $chunk);
                }]);
            $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl); fclose($handle);
            if (!$ok || $status !== 200) { @unlink($temp); throw new RuntimeException('Download failed: '.$row['id'].'/'.$field); }
            rename($temp, $path);
        }
        if (!is_file($path) || filesize($path) < 100 || filesize($path) > $limit) throw new RuntimeException('Missing or oversized media: '.$row['id'].'/'.$field);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime !== ($ext === 'mp4' ? 'video/mp4' : 'image/webp')) throw new RuntimeException('Invalid media MIME: '.$row['id'].'/'.$field);
        $prepared[] = ['id'=>$row['id'], 'field'=>$field, 'ext'=>$ext, 'path'=>$path, 'mime'=>$mime, 'sha256'=>hash_file('sha256', $path), 'size'=>filesize($path)];
    }
}
echo 'Validated '.count($prepared).' platform preview files; storage='.$storage['default']."\n";
if (!$apply) exit;
$created = 0; $reused = 0;
foreach ($prepared as $item) {
    $name = 'default-effect-'.$item['id'].'-'.substr($item['sha256'],0,16).'.'.$item['ext'];
    $file = File::where(['name'=>$name, 'storage_scope'=>'platform', 'storage_engine'=>$storage['default'], 'storage_domain'=>$domain])->findOrEmpty();
    if (!$file->isEmpty() && $storage['default'] === 'local' && !is_file(public_path().$file['uri'])) throw new RuntimeException('Managed file is missing; repair before retry: '.$name);
    if ($file->isEmpty()) {
        $driver = new Driver($storage);
        $driver->setUploadFileByReal($item['path']);
        $saveDir = 'uploads/video-effects/platform-defaults';
        if (!$driver->upload($saveDir)) throw new RuntimeException('Upload failed: '.$item['id'].'/'.$item['field']);
        $file = File::create(['cid'=>0, 'source'=>0, 'source_id'=>0, 'type'=>$item['ext']==='mp4'?20:10,
            'name'=>$name, 'uri'=>$saveDir.'/'.$driver->getFileName(), 'storage_scope'=>'platform',
            'storage_engine'=>$storage['default'], 'storage_domain'=>$domain, 'create_time'=>time()]);
        $created++;
    } else $reused++;
    $registry[$item['id']][$item['field']] = ['file_id'=>(int)$file['id'], 'uri'=>$file['uri'],
        'storage_scope'=>'platform', 'storage_engine'=>$storage['default'], 'storage_domain'=>$domain,
        'mime_type'=>$item['mime'], 'file_size'=>$item['size'], 'checksum'=>$item['sha256']];
    VideoEffectMediaService::saveEntry($item['id'], $registry[$item['id']]);
}
echo "Completed: created=$created reused=$reused; tenant configurations unchanged\n";
