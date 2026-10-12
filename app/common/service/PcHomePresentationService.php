<?php
namespace app\common\service;

/** Tenant-scoped presentation choice; independent of saved shortcut contents. */
class PcHomePresentationService
{
    public static function topMode(): string
    {
        $mode = ConfigService::get('website', 'pc_home_top_mode', 'composer');
        return in_array($mode, ['shortcuts', 'composer'], true) ? $mode : 'composer';
    }

    public static function save(array $params): void
    {
        // Old clients omit the field; do not reset their saved preference.
        if (!array_key_exists('pc_home_top_mode', $params)) return;
        $mode = $params['pc_home_top_mode'];
        if (!in_array($mode, ['shortcuts', 'composer'], true)) {
            throw new \InvalidArgumentException('请选择正确的首页顶部布局');
        }
        ConfigService::set('website', 'pc_home_top_mode', $mode);
    }
}
