<?php

namespace app\common\service\app\aigc_digital_human;

interface AigcDigitalHumanProviderInterface
{
    public function generate(AigcDigitalHumanGenerateRequest $request): AigcDigitalHumanGenerateResult;
}

