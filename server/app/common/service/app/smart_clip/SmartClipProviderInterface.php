<?php

namespace app\common\service\app\smart_clip;

interface SmartClipProviderInterface
{
    public function templateLists(string $scene, array $params = []): array;

    public function templateDetail(string $id): array;

    public function generate(SmartClipGenerateRequest $request): SmartClipGenerateResult;
}
