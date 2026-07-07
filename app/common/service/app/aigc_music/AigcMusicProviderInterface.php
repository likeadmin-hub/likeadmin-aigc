<?php

namespace app\common\service\app\aigc_music;

interface AigcMusicProviderInterface
{
    public function generate(AigcMusicGenerateRequest $request): AigcMusicGenerateResult;

    public function lyrics(array $params): array;

    public function mashupLyrics(array $params): array;

    public function cloneVoice(array $params): array;

    public function export(array $result, string $type, array $params = []): array;
}
