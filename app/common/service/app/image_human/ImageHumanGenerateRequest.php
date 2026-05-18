<?php

namespace app\common\service\app\image_human;

class ImageHumanGenerateRequest
{
    public function __construct(
        public string $imageUrl,
        public string $audioUrl,
        public string $prompt,
        public float $duration,
        public string $mode,
        public array $providerParams = [],
        public array $channelConfig = []
    ) {
    }
}
