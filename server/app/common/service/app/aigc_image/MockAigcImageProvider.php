<?php

namespace app\common\service\app\aigc_image;

class MockAigcImageProvider implements AigcImageProviderInterface
{
    public function generate(AigcImageGenerateRequest $request): AigcImageGenerateResult
    {
        $images = [];
        $width = (int)($request->spec['width'] ?? 1024);
        $height = (int)($request->spec['height'] ?? 1024);
        for ($i = 0; $i < max(1, $request->quantity); $i++) {
            $images[] = [
                'uri' => 'resource/image/tenantapi/default/banner001.png',
                'width' => $width,
                'height' => $height,
            ];
        }
        return new AigcImageGenerateResult(true, $images);
    }
}
