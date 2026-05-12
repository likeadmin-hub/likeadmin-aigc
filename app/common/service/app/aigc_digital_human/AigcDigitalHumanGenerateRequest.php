<?php

namespace app\common\service\app\aigc_digital_human;

class AigcDigitalHumanGenerateRequest
{
    public string $scriptText;
    public string $prompt;
    public string $channel;
    public string $quality;
    public string $ratio;
    public array $avatar;
    public array $voice;
    public array $spec;
    public array $providerParams;
    public array $channelConfig;

    public function __construct(
        string $scriptText,
        string $prompt,
        string $channel = 'master',
        string $quality = '1k',
        string $ratio = '9:16',
        array $avatar = [],
        array $voice = [],
        array $spec = [],
        array $providerParams = [],
        array $channelConfig = []
    ) {
        $this->scriptText = $scriptText;
        $this->prompt = $prompt;
        $this->channel = $channel;
        $this->quality = $quality;
        $this->ratio = $ratio;
        $this->avatar = $avatar;
        $this->voice = $voice;
        $this->spec = $spec;
        $this->providerParams = $providerParams;
        $this->channelConfig = $channelConfig;
    }
}

