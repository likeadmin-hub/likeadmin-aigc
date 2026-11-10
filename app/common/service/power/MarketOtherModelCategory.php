<?php

namespace app\common\service\power;

/** Categories are published upstream document groups, never inferred from model names. */
final class MarketOtherModelCategory
{
    public const UNCLASSIFIED = 'uncategorized';

    public static function index(array $tree): array
    {
        $result = ['available' => false, 'models' => []];
        foreach ($tree as $node) {
            if (!is_array($node) || ($node['slug'] ?? '') !== 'other') continue;
            $result['available'] = true;
            self::collect((array)($node['children'] ?? []), [], $result['models']);
        }
        return $result;
    }

    private static function collect(array $nodes, array $path, array &$models): void
    {
        foreach ($nodes as $position => $node) {
            if (!is_array($node)) continue;
            $id = (int)($node['ai_model_id'] ?? 0);
            if ($id > 0 && empty($node['ai_app_api_id'])) {
                $models[$id] = $path === [] ? [] : [
                    'code' => implode('/', array_column($path, 'code')),
                    'name' => implode(' / ', array_column($path, 'name')),
                    'path' => $path,
                ];
                continue;
            }
            // App/API documents and model leaves are not category headings.
            if (!empty($node['ai_app_api_id'])) continue;
            $code = trim((string)($node['slug'] ?? ''));
            $name = trim((string)($node['title'] ?? ''));
            $next = $path;
            if ($code !== '' && $name !== '') $next[] = ['code' => $code, 'name' => $name, 'order' => $position];
            self::collect((array)($node['children'] ?? []), $next, $models);
        }
    }

    public static function category(array $product): array
    {
        $source = MarketGenerationSchema::array($product['source_payload'] ?? []);
        $category = (array)($source['market_metadata']['other_category'] ?? []);
        return !empty($category['code']) && !empty($category['name']) ? $category : [
            'code' => self::UNCLASSIFIED, 'name' => '未分类', 'path' => [],
        ];
    }

    public static function options(array $products): array
    {
        $categories = [];
        foreach ($products as $product) {
            $category = self::category($product);
            $categories[$category['code']] = $category;
        }
        $categories = array_values($categories);
        usort($categories, static function (array $a, array $b): int {
            if (($a['code'] === self::UNCLASSIFIED) !== ($b['code'] === self::UNCLASSIFIED)) {
                return $a['code'] === self::UNCLASSIFIED ? 1 : -1;
            }
            return array_column($a['path'] ?? [], 'order') <=> array_column($b['path'] ?? [], 'order');
        });
        return $categories;
    }
}
