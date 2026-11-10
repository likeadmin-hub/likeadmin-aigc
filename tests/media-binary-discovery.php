<?php
/** Synthetic local executables only. Exercises actual duration and media consumers. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new think\App(dirname(__DIR__)))->initialize();
use app\common\service\MediaBinaryService as Binary;
use app\common\service\MediaDurationService as Duration;
use app\common\service\power\MarketGenerationMedia as Market;
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    echo 'PASS ' . $message . "\n";
}
$dir = sys_get_temp_dir() . '/media tools ' . bin2hex(random_bytes(6));
mkdir($dir, 0700); mkdir($dir . '/bin'); mkdir($dir . '/package');
$before = getenv('PATH');
$variables = ['FFMPEG_BINARY', 'FFPROBE_BINARY'];
$environment = array_map('getenv', $variables);
$bt = '/www/server/ffmpeg/ffmpeg-regression-' . bin2hex(random_bytes(6));
try {
    file_put_contents($dir . '/package/ffmpeg', "#!/bin/sh\nexit 0\n");
    file_put_contents($dir . '/package/ffprobe', '#!/bin/sh' . "\n" . 'case "$*" in *json*) printf \'{"format":{"duration":"2.88"},"streams":[{"codec_type":"audio"}]}\';; *) printf 2.88;; esac' . "\n");
    chmod($dir . '/package/ffmpeg', 0700); chmod($dir . '/package/ffprobe', 0700);
    symlink($dir . '/package/ffmpeg', $dir . '/bin/ffmpeg');
    file_put_contents($dir . '/sample.m4a', 'fixture-media');
    putenv('PATH=' . $dir . '/bin');
    foreach ($variables as $var) putenv($var);
    check(Duration::detect($dir . '/sample.m4a') === 2.88, 'ffprobe next to the real default ffmpeg is used');
    check(Binary::resolve('ffprobe') === $dir . '/package/ffprobe', 'symlink target sibling resolves without a ffprobe PATH entry');
    check(Market::localFacts($dir . '/sample.m4a', 'audio')['duration'] === 2.88, 'market validation shares binary discovery');
    putenv('FFPROBE_BINARY=' . $dir . '/package/ffprobe');
    check(Binary::resolve('ffprobe') === $dir . '/package/ffprobe', 'explicit path supports spaces');
    putenv('FFPROBE_BINARY=' . $dir . '/package/ffprobe -unsafe');
    check(Binary::resolve('ffprobe') === $dir . '/package/ffprobe', 'configuration is treated as a file path and never shell code');
    putenv('FFPROBE_BINARY'); putenv('PATH=' . $dir . '/empty');
    @mkdir(dirname($bt), 0755, true); mkdir($bt, 0755);
    copy($dir . '/package/ffprobe', $bt . '/ffprobe'); chmod($bt . '/ffprobe', 0755);
    check(Binary::resolve('ffprobe') === $bt . '/ffprobe', 'versioned Baota directory works with a restricted PHP-FPM PATH');
    unlink($bt . '/ffprobe'); rmdir($bt);
    check(Binary::resolve('ffprobe') === '', 'missing tool fails closed');
    check(Binary::resolve('sh') === '', 'only media program names are accepted');
} finally {
    putenv('PATH=' . $before);
    foreach ($variables as $i => $var) $environment[$i] === false ? putenv($var) : putenv($var . '=' . $environment[$i]);
    foreach ([$bt . '/ffprobe', $dir . '/sample.m4a', $dir . '/bin/ffmpeg', $dir . '/package/ffmpeg', $dir . '/package/ffprobe'] as $file) if (file_exists($file) || is_link($file)) unlink($file);
    foreach ([$bt, $dir . '/bin', $dir . '/package', $dir] as $path) if (is_dir($path)) rmdir($path);
}
