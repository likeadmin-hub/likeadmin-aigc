<?php

namespace app\common\service\app\aigc_video;

interface AigcVideoProviderInterface
{
    public function generate(AigcVideoGenerateRequest $request): AigcVideoGenerateResult;
}

