<?php
namespace app\common\service;

/** Replaces storage only; task ownership, transactions and submission receipts stay real. */
final class UploadService
{
    public static array $copies = [];
    public static function file($cid, int $sourceId = 0, int $source = 0, string $saveDir = ''): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sd-retained-upload-');
        copy(request()->file('file')->getRealPath(), $path);
        self::$copies[] = $path;
        $uri = 'uploads/aigc_short_drama/test/' . basename($path);
        return ['url' => $uri, 'uri' => 'https://fixture.invalid/' . $uri];
    }
}

namespace app\common\service\power;

/** An unexpected successful projection must fail locally, never cross the provider boundary. */
final class MarketFileQaAppRuntimeService
{
    public static function reserve(...$arguments): array { throw new \LogicException('Provider dispatch is forbidden in extraction persistence fixtures'); }
    public static function submit(...$arguments): array { throw new \LogicException('Provider dispatch is forbidden in extraction persistence fixtures'); }
}
