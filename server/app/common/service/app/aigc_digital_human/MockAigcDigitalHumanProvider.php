<?php

namespace app\common\service\app\aigc_digital_human;

class MockAigcDigitalHumanProvider implements AigcDigitalHumanProviderInterface
{
    public function generate(AigcDigitalHumanGenerateRequest $request): AigcDigitalHumanGenerateResult
    {
        $width = (int)($request->spec['width'] ?? 1024);
        $height = (int)($request->spec['height'] ?? 1024);
        return new AigcDigitalHumanGenerateResult(true, [
            [
                'uri' => 'pc/academy/course-preview.mp4',
                'cover_uri' => $request->avatar['cover_uri'] ?? $request->avatar['media_uri'] ?? '',
                'width' => $width,
                'height' => $height,
                'duration' => max(6, (int)ceil(mb_strlen($request->scriptText) / 5)),
            ],
        ]);
    }
}
