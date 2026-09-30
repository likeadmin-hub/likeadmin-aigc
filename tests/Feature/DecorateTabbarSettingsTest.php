<?php

namespace Tests\Feature;

use app\common\service\decorate\DecorateTemplateService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DecorateTabbarSettingsTest extends TestCase
{
    private function merge(array $old, array $patch): array
    {
        $method = new ReflectionMethod(DecorateTemplateService::class, 'mergeSettings');
        $method->setAccessible(true);
        return $method->invoke(null, $old, $patch);
    }

    public function testDeletedNavigationDoesNotReturnAfterSavingAndReloading(): void
    {
        $old = [
            'mobile_tabbar' => [
                'style' => ['default_color' => '#999999', 'selected_color' => '#4173ff'],
                'list' => [
                    ['id' => 'home', 'link' => ['path' => '/pages/index/index']],
                    ['id' => 'tools', 'link' => ['path' => '/pages/diy/diy', 'query' => ['code' => 'tools']]],
                    ['id' => 'service'],
                    ['id' => 'user'],
                ],
            ],
            'mobile_style' => ['themeColor1' => '#00ff00'],
            'future_setting' => ['keep' => true],
        ];
        $remaining = [$old['mobile_tabbar']['list'][0], $old['mobile_tabbar']['list'][3]];
        $remaining[1]['link'] = ['path' => '/pages/user/user'];
        $saved = $this->merge($old, ['mobile_tabbar' => ['list' => $remaining]]);
        $reloaded = json_decode(json_encode($saved), true);
        self::assertSame($remaining, $reloaded['mobile_tabbar']['list']);
        self::assertSame($old['mobile_tabbar']['style'], $reloaded['mobile_tabbar']['style']);
        self::assertSame($old['mobile_style'], $reloaded['mobile_style']);
        self::assertSame($old['future_setting'], $reloaded['future_setting']);
        self::assertSame($saved, $this->merge($saved, ['mobile_tabbar' => ['list' => $remaining]]));
    }

    public function testStyleOnlyPatchKeepsNavigationAndEmptyListReplacesIt(): void
    {
        $old = ['mobile_tabbar' => ['style' => ['default_color' => '#999999'], 'list' => [['id' => 'home'], ['id' => 'user']]]];
        $styled = $this->merge($old, ['mobile_tabbar' => ['style' => ['selected_color' => '#000000']]]);
        self::assertSame($old['mobile_tabbar']['list'], $styled['mobile_tabbar']['list']);
        self::assertSame('#999999', $styled['mobile_tabbar']['style']['default_color']);
        self::assertSame([], $this->merge($styled, ['mobile_tabbar' => ['list' => []]])['mobile_tabbar']['list']);
    }
}
