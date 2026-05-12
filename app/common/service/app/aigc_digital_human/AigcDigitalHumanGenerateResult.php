<?php

namespace app\common\service\app\aigc_digital_human;

class AigcDigitalHumanGenerateResult
{
    public bool $success;
    public array $videos;
    public string $error;
    public string $providerTaskId;

    public function __construct(
        bool $success,
        array $videos = [],
        string $error = '',
        string $providerTaskId = ''
    ) {
        $this->success = $success;
        $this->videos = $videos;
        $this->error = $error;
        $this->providerTaskId = $providerTaskId;
    }
}
