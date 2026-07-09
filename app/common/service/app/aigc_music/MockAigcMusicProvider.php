<?php

namespace app\common\service\app\aigc_music;

class MockAigcMusicProvider implements AigcMusicProviderInterface
{
    public function generate(AigcMusicGenerateRequest $request): AigcMusicGenerateResult
    {
        $providerTaskId = 'mock-aigc-music-' . time();
        $item = [
            'title' => $request->title ?: 'AI Music Demo',
            'audio_uri' => 'resource/audio/aigc-music-preview.mp3',
            'wav_uri' => '',
            'mp4_uri' => '',
            'midi_uri' => '',
            'timing_uri' => '',
            'vox_uri' => '',
            'cover_uri' => '',
            'duration' => $request->duration,
            'lyrics' => $request->lyrics,
            'timing_json' => [],
            'storage_scope' => 'platform',
            'storage_engine' => 'local',
            'storage_domain' => '',
            'mime_type' => 'audio/mpeg',
            'file_size' => 0,
            'provider_task_id' => $providerTaskId,
            'raw' => ['mock' => true, 'model' => 'music_generation'],
        ];
        return new AigcMusicGenerateResult(true, [$item], '', $providerTaskId, ['mock' => true]);
    }

    public function lyrics(array $params): array
    {
        $theme = trim((string)($params['theme'] ?? $params['prompt'] ?? '新的旋律'));
        return [
            'title' => $theme,
            'lyrics' => "Verse:\n" . $theme . " 在节拍里醒来\n\nChorus:\n让声音穿过人群与灯海",
            'provider' => 'mock',
            'raw' => ['mock' => true],
        ];
    }

    public function mashupLyrics(array $params): array
    {
        $a = trim((string)($params['lyrics_a'] ?? $params['source_lyrics'] ?? ''));
        $b = trim((string)($params['lyrics_b'] ?? $params['target_lyrics'] ?? ''));
        return [
            'lyrics' => trim($a . "\n\nBridge:\n" . $b),
            'provider' => 'mock',
            'raw' => ['mock' => true],
        ];
    }

    public function cloneVoice(array $params): array
    {
        return [
            'provider_voice_id' => 'mock-voice-' . time(),
            'status' => 'success',
            'raw' => ['mock' => true],
        ];
    }

    public function export(array $result, string $type, array $params = []): array
    {
        $field = match ($type) {
            'wav' => 'wav_uri',
            'mp4' => 'mp4_uri',
            'midi' => 'midi_uri',
            'timing' => 'timing_uri',
            'vox' => 'vox_uri',
            default => 'audio_uri',
        };
        $uri = (string)($result[$field] ?? '');
        if ($uri === '') {
            $uri = (string)($result['audio_uri'] ?? '');
        }
        return [
            'file_uri' => $uri,
            'status' => 'success',
            'raw' => ['mock' => true, 'type' => $type],
        ];
    }
}
