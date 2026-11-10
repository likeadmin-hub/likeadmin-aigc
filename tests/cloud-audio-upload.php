<?php
namespace app\common\service\storage {
    /** Replace only the cloud upload boundary; metadata, duration parser and database remain real. */
    final class Driver {
        public static string $path;
        public static int $uploads = 0;
        public function __construct(array $config) {}
        public function setUploadFile(string $name): void {}
        public function getFileInfo(): array { return ['realPath'=>self::$path, 'size'=>filesize(self::$path)]; }
        public function upload(string $directory): bool { self::$uploads++; return true; }
        public function getFileName(): string { return 'fixture.wav'; }
    }
}
namespace {
    require __DIR__ . '/cloud-audio-metadata.php';
    $temp = tempnam(sys_get_temp_dir(), 'anonymous-upload-');
    file_put_contents($temp, $wav);
    \app\common\service\storage\Driver::$path = $temp;
    try {
        $uploaded = \app\common\service\app\aigc_music\AigcMusicAssetService::uploadAudio(2, 7, []);
        check(abs($uploaded['duration'] - 2.88) < 0.000001, 'cloud upload measures the anonymous temporary audio before upload');
        check($uploaded['file_size'] === strlen($wav), 'cloud upload retains original file size');
        check(!is_file(public_path() . $uploaded['uri']), 'cloud upload needs no local public copy');
        file_put_contents($temp, 'invalid audio');
        try { \app\common\service\app\aigc_music\AigcMusicAssetService::uploadAudio(2, 7, []); throw new \RuntimeException('Invalid upload accepted'); }
        catch (\Exception) { check(\app\common\service\storage\Driver::$uploads === 1, 'invalid audio is rejected before a cloud upload'); }
    } finally { unlink($temp); }
}
