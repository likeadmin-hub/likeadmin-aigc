<?php
namespace app\common\service\power {
    final class MusicMarketWireFixture {
        public static array $calls = [];
        public static array $options = [];
        public static string $status = 'running';
        public static bool $reject = false;
        public static bool $timeout = false;
        public static int $tracks = 1;
    }
    function curl_init() { return true; }
    function curl_setopt_array($handle, $options) { MusicMarketWireFixture::$options = $options; return true; }
    function curl_setopt($handle, $key, $value) { MusicMarketWireFixture::$options[$key] = $value; return true; }
    function curl_exec($handle) {
        $options = MusicMarketWireFixture::$options;
        MusicMarketWireFixture::$calls[] = $options;
        if (MusicMarketWireFixture::$timeout) return false;
        if (!empty($options[CURLOPT_POST])) return json_encode(MusicMarketWireFixture::$reject
            ? ['code' => 0, 'msg' => 'fixture rejected']
            : ['code' => 1, 'data' => ['task_id' => 'music-market-fixture']]);
        $tracks = [['audio_url' => 'https://fixture.invalid/song.mp3', 'audio_id' => 'song-fixture', 'title' => 'Market song', 'lyrics' => 'fixture lyrics', 'duration' => 42]];
        if (MusicMarketWireFixture::$tracks > 1) $tracks[] = ['audio_url' => 'https://fixture.invalid/second.mp3', 'audio_id' => 'song-two', 'title' => 'Second song', 'duration' => 35];
        return json_encode(['code' => 1, 'data' => ['status' => MusicMarketWireFixture::$status,
            'results' => MusicMarketWireFixture::$status === 'success' ? $tracks : []]]);
    }
    function curl_errno($handle) { return MusicMarketWireFixture::$timeout ? 28 : 0; }
    function curl_error($handle) { return MusicMarketWireFixture::$timeout ? 'Operation timed out' : ''; }
    function curl_getinfo($handle, $key) { return 200; }
    function curl_close($handle) {}
}
namespace app\common\service\app\aigc_music {
    class AigcMusicAssetService {
        public static array $calls = [];
        public static bool $failSecond = false;
        public static function persistGeneratedAudio(string $url, int $tenant, int $user = 0, ?array $config = null): array {
            self::$calls[$url] = (self::$calls[$url] ?? 0) + 1;
            if (self::$failSecond && str_contains($url, 'second')) throw new \RuntimeException('fixture storage unavailable');
            return ['uri' => str_contains($url, 'second') ? 'resource/audio/second-fixture.mp3' : 'resource/audio/aigc-music-preview.mp3', 'url' => $url, 'storage_scope' => 'platform', 'storage_engine' => 'local', 'storage_domain' => '', 'mime_type' => 'audio/mpeg', 'file_size' => 100, 'duration' => 42];
        }
    }
}
