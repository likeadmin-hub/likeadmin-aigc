<?php
namespace app\common\service;

/** Tenant-editable API presentation. Does not grant API access or define billing. */
class OfficialSiteApiTemplate
{
    public static function modules(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/official-site-api.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
