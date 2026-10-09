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
