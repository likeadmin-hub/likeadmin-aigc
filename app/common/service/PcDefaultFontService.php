<?php
namespace app\common\service;

final class PcDefaultFontService
{
    public const OPTIONS = ['default', 'system', 'serif', 'mono', 'comfortaa', 'quicksand'];
    public static function normalize($value): string
    {
        return is_string($value) && in_array($value, self::OPTIONS, true) ? $value : 'default';
    }
    public static function read(): string
    {
        return self::normalize(ConfigService::get('website', 'pc_default_font', 'default'));
    }
    public static function validate(array $params): void
    {
        if (array_key_exists('pc_default_font', $params) && (!is_string($params['pc_default_font']) || !in_array($params['pc_default_font'], self::OPTIONS, true))) {
            throw new \InvalidArgumentException('请选择支持的系统默认字体');
        }
    }
    public static function save(array $params): void
    {
        self::validate($params);
        if (array_key_exists('pc_default_font', $params)) ConfigService::set('website', 'pc_default_font', $params['pc_default_font']);
    }
}
