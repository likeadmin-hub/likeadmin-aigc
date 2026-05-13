<?php

namespace app\common\service\app\aigc_image;

class AigcImageGenerateRequest
{
    public string $prompt;
    public string $negativePrompt;
    public string $style;
    public string $channel;
    public string $quality;
    public string $ratio;
    public int $quantity;
    public array $referenceImages;
    public array $spec;
    public array $providerParams;
    public array $channelConfig;

    public function __construct(
        string $prompt,
        string $negativePrompt = '',
        string $style = 'general',
        string $channel = 'master',
        string $quality = '1k',
        string $ratio = '1:1',
        int $quantity = 1,
        array $referenceImages = [],
        array $spec = [],
        array $providerParams = [],
        array $channelConfig = []
    ) {
        $this->prompt = $prompt;
        $this->negativePrompt = $negativePrompt;
        $this->style = $style;
        $this->channel = $channel;
        $this->quality = $quality;
        $this->ratio = $ratio;
        $this->quantity = $quantity;
        $this->referenceImages = $referenceImages;
        $this->spec = $spec;
        $this->providerParams = $providerParams;
        $this->channelConfig = $channelConfig;
    }
}
