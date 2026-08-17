<?php

namespace app\common\service;

use app\common\model\Config;

class TutorialConfigService
{
    private const TYPE = 'tutorial';

    public static function get(): array
    {
        $values = Config::where('type', self::TYPE)->column('value', 'name');
        $icon = trim((string)($values['icon'] ?? ''));

        return [
            'enabled' => (int)($values['enabled'] ?? 0),
            'url' => trim((string)($values['url'] ?? '')),
            'icon' => $icon === '' ? '' : FileService::getFileUrl($icon),
        ];
    }

    public static function save(array $params): void
    {
        self::set('enabled', (int)($params['enabled'] ?? 0));
        self::set('url', trim((string)($params['url'] ?? '')));
        self::set('icon', FileService::setFileUrl((string)($params['icon'] ?? '')));
    }

    private static function set(string $name, $value): void
    {
        $config = Config::where(['type' => self::TYPE, 'name' => $name])->findOrEmpty();
        if ($config->isEmpty()) {
            Config::create([
                'type' => self::TYPE,
                'name' => $name,
                'value' => $value,
            ]);
            return;
        }

        $config->value = $value;
        $config->save();
    }
}
