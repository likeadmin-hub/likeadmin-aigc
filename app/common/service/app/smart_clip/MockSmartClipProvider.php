<?php

namespace app\common\service\app\smart_clip;

class MockSmartClipProvider implements SmartClipProviderInterface
{
    public function templateLists(string $scene, array $params = []): array
    {
        $items = [
            [
                'id' => 'mock-' . $scene . '-001',
                'styleId' => 'mock-' . $scene . '-001',
                'name' => '默认剪辑模板',
                'coverUrl' => '',
                'previewUrl' => '',
                'scene' => $scene,
            ],
        ];
        return [
            'lists' => $items,
            'items' => $items,
            'count' => count($items),
            'sid' => '',
        ];
    }

    public function templateDetail(string $id): array
    {
        return [
            'id' => $id,
            'styleId' => $id,
            'name' => '默认剪辑模板',
            'coverUrl' => '',
            'previewUrl' => '',
            'canvas' => [],
            'structLayers' => [],
        ];
    }

    public function generate(SmartClipGenerateRequest $request): SmartClipGenerateResult
    {
        $videos = [];
        $videos[] = [
            'uri' => 'pc/media/aigc-preview.mp4',
            'width' => (int)($request->spec['width'] ?? 0),
            'height' => (int)($request->spec['height'] ?? 0),
            'duration' => (float)($request->payload['duration'] ?? 5),
            'provider_task_id' => 'mock-smart-clip-' . time(),
            'raw' => ['mock' => true],
        ];
        return new SmartClipGenerateResult(true, $videos, '', $videos[0]['provider_task_id'], ['mock' => true]);
    }
}
