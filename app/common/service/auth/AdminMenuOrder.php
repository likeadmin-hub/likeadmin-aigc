<?php
declare(strict_types=1);

namespace app\common\service\auth;

final class AdminMenuOrder
{
    /** Keep existing and split-table installations consistent with the seed order. */
    public static function marketFirst(array $menus): array
    {
        foreach ($menus as $index => $menu) {
            if (($menu['paths'] ?? '') !== 'power-mall' || empty($menu['children'])) continue;
            $children = $menu['children'];
            foreach ($children as $childIndex => $child) {
                if (($child['component'] ?? '') !== 'power_mall/market') continue;
                unset($children[$childIndex]);
                $menus[$index]['children'] = array_merge([$child], $children);
                break;
            }
        }
        return $menus;
    }
}
