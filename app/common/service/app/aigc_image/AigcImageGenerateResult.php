<?php

namespace app\common\service\app\aigc_image;

class AigcImageGenerateResult
{
    public bool $success;
    public array $images;
    public string $error;
    public string $providerTaskId;

    public function __construct(
        bool $success,
        array $images = [],
        string $error = '',
        string $providerTaskId = ''
    ) {
        $this->success = $success;
        $this->images = $images;
        $this->error = $error;
        $this->providerTaskId = $providerTaskId;
    }
}
