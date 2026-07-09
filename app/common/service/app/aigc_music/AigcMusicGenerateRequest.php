<?php

namespace app\common\service\app\aigc_music;

class AigcMusicGenerateRequest
{
    public function __construct(
        public string $title,
        public string $prompt,
        public string $lyrics,
        public string $genre,
        public string $mood,
        public string $instruments,
        public int $duration,
        public int $quantity,
        public array $payload,
        public array $spec,
        public array $providerParams,
        public array $channelConfig
    ) {
    }
}
