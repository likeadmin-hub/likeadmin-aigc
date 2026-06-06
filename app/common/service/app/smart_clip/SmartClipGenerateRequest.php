<?php

namespace app\common\service\app\smart_clip;

class SmartClipGenerateRequest
{
    public string $api;
    public string $scene;
    public string $styleId;
    public string $title;
    public string $videoUrl;
    public string $audioUrl;
    public string $language;
    public array $materials;
    public array $introduceCard;
    public array $packRules;
    public array $processRules;
    public array $structLayers;
    public array $subtitle;
    public string $callbackUrl;
    public array $source;
    public array $payload;
    public array $spec;
    public array $providerParams;
    public array $channelConfig;

    public function __construct(
        string $api,
        string $scene,
        string $styleId,
        string $title = '',
        string $videoUrl = '',
        string $audioUrl = '',
        string $language = '',
        array $materials = [],
        array $introduceCard = [],
        array $packRules = [],
        array $processRules = [],
        array $structLayers = [],
        array $subtitle = [],
        string $callbackUrl = '',
        array $source = [],
        array $payload = [],
        array $spec = [],
        array $providerParams = [],
        array $channelConfig = []
    ) {
        $this->api = $api;
        $this->scene = $scene;
        $this->styleId = $styleId;
        $this->title = $title;
        $this->videoUrl = $videoUrl;
        $this->audioUrl = $audioUrl;
        $this->language = $language;
        $this->materials = $materials;
        $this->introduceCard = $introduceCard;
        $this->packRules = $packRules;
        $this->processRules = $processRules;
        $this->structLayers = $structLayers;
        $this->subtitle = $subtitle;
        $this->callbackUrl = $callbackUrl;
        $this->source = $source;
        $this->payload = $payload;
        $this->spec = $spec;
        $this->providerParams = $providerParams;
        $this->channelConfig = $channelConfig;
    }
}
