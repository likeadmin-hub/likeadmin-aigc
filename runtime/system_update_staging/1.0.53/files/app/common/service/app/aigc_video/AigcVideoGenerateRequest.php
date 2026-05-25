<?php

namespace app\common\service\app\aigc_video;

class AigcVideoGenerateRequest
{
    public string $prompt;
    public string $negativePrompt;
    public string $style;
    public string $channel;
    public string $quality;
    public string $ratio;
    public int $quantity;
    public array $referenceImages;
    public array $referenceAssets;
    public array $spec;
    public array $providerParams;
    public array $channelConfig;

    public function __construct(
        string $prompt,
        string $negativePrompt = '',
        string $style = 'general',
        string $channel = 'grok_video_xaiq',
        string $quality = '6',
        string $ratio = '16:9',
        int $quantity = 1,
        array $referenceImages = [],
        array $referenceAssets = [],
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
        $this->referenceAssets = $referenceAssets;
        $this->spec = $spec;
        $this->providerParams = $providerParams;
        $this->channelConfig = $channelConfig;
    }
}
