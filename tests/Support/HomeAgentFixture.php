<?php
declare(strict_types=1);

use app\common\service\app\aigc_llm\agent\AgentCapabilityCatalog;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_video\AigcVideoService;

/** Read available local catalogues without relying on or changing a user's saved preferences. */
final class HomeAgentFixture
{
    public static function textSelection(int $tenant = 1): string
    {
        $models = AgentCapabilityCatalog::models($tenant)['text']['models'] ?? [];
        $selection = (string)($models[0]['code'] ?? '');
        if ($selection === '') throw new RuntimeException('Offline fixture requires an available compatible text model');
        return $selection;
    }

    public static function preferences(int $tenant = 1): array
    {
        $image = AigcImageChannelService::userConfig($tenant)['defaults']['channel'] ?? '';
        $video = AigcVideoService::creationConfig($tenant)['option_config']['defaults']['channel'] ?? '';
        if ($image === '' || $video === '') throw new RuntimeException('Offline fixture requires local image and video catalogue defaults');
        return ['mode'=>'ask', 'models'=>['text'=>self::textSelection($tenant), 'image'=>$image, 'video'=>$video]];
    }
}
