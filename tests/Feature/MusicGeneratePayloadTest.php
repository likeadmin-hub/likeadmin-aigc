<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\common\service\app\aigc_music\{AigcMusicGenerateRequest, XhadminAigcMusicProvider};

function check($ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$build = new ReflectionMethod(XhadminAigcMusicProvider::class, 'buildPayload');
$provider = new XhadminAigcMusicProvider();
$payload = static function (bool $custom, string $lyrics, bool $instrumental, string $prompt = '轻柔钢琴背景音乐') use ($build, $provider): array {
    $request = new AigcMusicGenerateRequest(
        '钢琴音乐', $prompt, $lyrics, '轻音乐', '宁静', '钢琴', 5, 1,
        ['custom' => $custom, 'instrumental' => $instrumental], [], [], []
    );
    return $build->invoke($provider, $request, ['extra_payload' => []]);
};

$custom = $payload(true, '', true);
check(($custom['prompt'] ?? '') === '轻柔钢琴背景音乐', 'Custom instrumental description was lost');
check($custom['custom'] === true && $custom['instrumental'] === true, 'Custom/instrumental intent changed');
check($custom['style'] === '轻音乐' && $custom['title'] === '钢琴音乐', 'Custom metadata changed');
check(($payload(true, '  ', true)['prompt'] ?? '') === '轻柔钢琴背景音乐', 'Whitespace lyrics suppressed the description');
$song = $payload(true, '春风吹过田野', false, '');
check(($song['lyric'] ?? '') === '春风吹过田野' && !isset($song['prompt']), 'Lyric-only creation changed');
$normal = $payload(false, '', true);
check(($normal['prompt'] ?? '') === '轻柔钢琴背景音乐' && !isset($normal['custom']), 'Ordinary music creation changed');
echo "Music generation payload passed: custom instrumental, whitespace lyrics, lyric-only and ordinary creation; no provider call\n";
