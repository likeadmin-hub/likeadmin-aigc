<?php

namespace app\common\service\app\aigc_video;

class MockAigcVideoProvider implements AigcVideoProviderInterface
{
    public function generate(AigcVideoGenerateRequest $request): AigcVideoGenerateResult
    {
        $videos = [];
        $width = (int)($request->spec['width'] ?? 1024);
        $height = (int)($request->spec['height'] ?? 1024);
        for ($i = 0; $i < max(1, $request->quantity); $i++) {
            $videos[] = [
                'uri' => 'pc/academy/course-preview.mp4',
                'width' => $width,
                'height' => $height,
            ];
        }
        return new AigcVideoGenerateResult(true, $videos);
    }
}
