<?php
namespace app\common\service;

final class PcDefaultLocaleService
{
    public const OPTIONS = ['zh-CN', 'zh-TW', 'en'];
    public static function read(): string
    {
        $value = ConfigService::get('website', 'pc_default_locale', 'zh-CN');
        return in_array($value, self::OPTIONS, true) ? $value : 'zh-CN';
    }
    public static function validate(array $params): void
    {
        if (array_key_exists('pc_default_locale', $params) && !in_array($params['pc_default_locale'], self::OPTIONS, true)) {
            throw new \InvalidArgumentException('请选择支持的默认语言');
        }
    }
    public static function save(array $params): void
    {
        self::validate($params);
        if (array_key_exists('pc_default_locale', $params)) ConfigService::set('website', 'pc_default_locale', $params['pc_default_locale']);
    }
}
