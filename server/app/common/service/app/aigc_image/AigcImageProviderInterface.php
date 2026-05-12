<?php

namespace app\common\service\app\aigc_image;

interface AigcImageProviderInterface
{
    public function generate(AigcImageGenerateRequest $request): AigcImageGenerateResult;
}

