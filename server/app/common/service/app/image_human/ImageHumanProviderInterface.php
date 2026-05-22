<?php

namespace app\common\service\app\image_human;

interface ImageHumanProviderInterface
{
    public function submit(ImageHumanGenerateRequest $request): ImageHumanGenerateResult;

    public function query(string $taskId, ImageHumanGenerateRequest $request): ImageHumanGenerateResult;
}
