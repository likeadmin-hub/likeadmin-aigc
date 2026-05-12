<?php

namespace app\common\service\app\aigc_llm;

interface AigcLlmProviderInterface
{
    /**
     * @return \Generator<int, array>
     */
    public function stream(AigcLlmGenerateRequest $request): \Generator;
}
