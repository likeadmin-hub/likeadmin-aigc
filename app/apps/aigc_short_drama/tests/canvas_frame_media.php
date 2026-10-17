<?php
// Run on local develop with FFmpeg installed. No database or storage writes.
require dirname(__DIR__, 4) . '/vendor/autoload.php';
(new think\App())->initialize();

use app\common\service\app\aigc_video\AigcVideoPosterService;

$resolve = new ReflectionMethod(AigcVideoPosterService::class, 'resolveFfmpegBinary');
$resolve->setAccessible(true);
$ffmpeg = $resolve->invoke(null);
if (!$ffmpeg) throw new RuntimeException('FFmpeg is required for the media regression test');
$extract = new ReflectionMethod(AigcVideoPosterService::class, 'extractFrame');
$extract->setAccessible(true);
$run = new ReflectionMethod(AigcVideoPosterService::class, 'runCommand');
$run->setAccessible(true);
$dir = sys_get_temp_dir() . '/canvas-frame-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
try {
    // Audio outlasts video: container duration must not cause an empty tail image.
    $source = $dir . '/source.mp4';
    $code = $run->invoke(null, [$ffmpeg, '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=160x90:rate=25:duration=1',
        '-f', 'lavfi', '-i', 'anullsrc', '-t', '1.08', '-c:v', 'libx264', '-c:a', 'aac', '-y', $source], "$dir/generate.log");
    if ($code !== 0) throw new RuntimeException(file_get_contents("$dir/generate.log"));
    foreach (['first' => 0.001, 'current' => 0.48, 'last' => 1.03, 'reference' => 0.96] as $name => $time) {
        $extract->invoke(null, $ffmpeg, $source, "$dir/$name.jpg", $time);
        $size = getimagesize("$dir/$name.jpg");
        if ($size[0] !== 160 || $size[1] !== 90) throw new RuntimeException("Invalid $name frame");
        echo "PASS $name produces a decodable image\n";
    }
    if (hash_file('sha256', "$dir/last.jpg") !== hash_file('sha256', "$dir/reference.jpg")) {
        throw new RuntimeException('Tail fallback must match the actual final video frame');
    }
    echo "PASS tail fallback matches final frame despite longer audio\n";
    if (count(array_unique(array_map(static fn($name) => hash_file('sha256', "$dir/$name.jpg"), ['first', 'current', 'last']))) !== 3) {
        throw new RuntimeException('Different capture modes unexpectedly returned the same frame');
    }
    echo "PASS first/current/last capture distinct images\n";
    $code = $run->invoke(null, [$ffmpeg, '-v', 'error', '-i', $source, '-frames:v', '1', '-q:v', '2', '-y', "$dir/first-reference.jpg"], "$dir/reference.log");
    if ($code !== 0 || hash_file('sha256', "$dir/first.jpg") !== hash_file('sha256', "$dir/first-reference.jpg")) {
        throw new RuntimeException('First capture skipped the opening frame');
    }
    echo "PASS first capture includes the opening frame\n";
    $failed = false;
    try { $extract->invoke(null, $ffmpeg, $source, "$dir/out-of-range.jpg", 5); } catch (Exception $e) { $failed = true; }
    if (!$failed) throw new RuntimeException('Out-of-range capture must not silently return the opening frame');
    echo "PASS out-of-range capture fails instead of returning an unrelated frame\n";
    $code = $run->invoke(null, [PHP_BINARY, '-r', 'sleep(10);'], "$dir/timeout.log", 1);
    if ($code !== 124) throw new RuntimeException('Process deadline must terminate the decoder');
    echo "PASS process timeout terminates the direct child\n";
    if (in_array('--expect-exec-disabled', $argv, true) && function_exists('exec')) {
        throw new RuntimeException('Restricted-process regression must run with exec disabled');
    }
    echo 'PASS extraction works with exec ' . (function_exists('exec') ? 'available' : 'disabled') . "\n";
} finally {
    foreach (glob($dir . '/*') as $file) unlink($file);
    rmdir($dir);
}
