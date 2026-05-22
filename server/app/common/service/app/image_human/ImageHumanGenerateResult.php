<?php

namespace app\common\service\app\image_human;

class ImageHumanGenerateResult
{
    public function __construct(
        public bool $success,
        public array $videos = [],
        public string $error = '',
        public string $providerTaskId = '',
        public bool $pending = false,
        public array $payload = []
    ) {
    }
}
