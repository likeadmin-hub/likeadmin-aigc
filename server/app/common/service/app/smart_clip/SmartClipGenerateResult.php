<?php

namespace app\common\service\app\smart_clip;

class SmartClipGenerateResult
{
    public bool $success;
    public array $videos;
    public string $error;
    public string $providerTaskId;
    public array $raw;

    public function __construct(
        bool $success,
        array $videos = [],
        string $error = '',
        string $providerTaskId = '',
        array $raw = []
    ) {
        $this->success = $success;
        $this->videos = $videos;
        $this->error = $error;
        $this->providerTaskId = $providerTaskId;
        $this->raw = $raw;
    }
}
