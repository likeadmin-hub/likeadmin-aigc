<?php

namespace app\common\service;

class TutorialConfigService
{
    private const TYPE = 'tutorial';

    public static function get(): array
    {
        $icon = trim((string)ConfigService::get(self::TYPE, 'icon', ''));

        return [
            'enabled' => (int)ConfigService::get(self::TYPE, 'enabled', 0),
            'url' => trim((string)ConfigService::get(self::TYPE, 'url', '')),
            'icon' => $icon === '' ? '' : FileService::getFileUrl($icon),
        ];
    }

    public static function save(array $params): void
    {
        ConfigService::set(self::TYPE, 'enabled', (int)($params['enabled'] ?? 0));
        ConfigService::set(self::TYPE, 'url', trim((string)($params['url'] ?? '')));
        ConfigService::set(self::TYPE, 'icon', FileService::setFileUrl((string)($params['icon'] ?? '')));
    }
}
