<?php

namespace app\common\service\app\aigc_music;

class AigcMusicGenerateResult
{
    public function __construct(
        public bool $success,
        public array $items = [],
        public string $error = '',
        public string $providerTaskId = '',
        public array $raw = []
    ) {
    }
}
