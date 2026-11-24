<?php
declare(strict_types=1);

namespace app\platformapi\service;

use app\common\cache\AdminAuthCache;
use app\common\service\power\MarketCatalogReadScope;
use app\common\service\power\MarketFileQaTokenPricing;
use app\common\service\power\MarketGenerationMeter;
use app\common\service\power\MarketImageItemBilling;
use app\common\service\power\MarketPriceRuleService;
use app\common\service\power\MarketPriceWriteTransaction;
use app\common\service\power\TenantSupplyMarkup;
use RuntimeException;
use think\facade\Cache;
use think\facade\Db;

final class TenantSupplyMarkupAdmin
{
    private const CACHE_PREFIX = 'tenant_supply_markup_preview:';
    private const STALE = '价格已变化，请重新预览';
    private const EXPIRED = '价格预览已过期或不属于当前管理员和租户，请重新预览';

    public static function allowed(array $admin, bool $edit): bool
    {
        if (empty($admin['admin_id'])) return false;
        if ((int)($admin['root'] ?? 0) === 1) return true;
        $uris = array_map('strtolower', (new AdminAuthCache($admin['admin_id']))->getAdminUri() ?? []);
        return in_array('tenant.tenant/applysupplymarkup', $uris, true)
            || (!$edit && in_array('tenant.tenant/supplymarkup', $uris, true));
    }

    private static function requireTenant(int $tenant, bool $lock = false): void
    {
        if ($tenant <= 0 || !Db::name('tenant')->where('id', $tenant)->whereNull('delete_time')->lock($lock)->field('id')->find()) {
            throw new RuntimeException('租户不存在或已删除，请刷新租户列表');
        }
    }

    public static function read(int $tenant): array
    {
        self::requireTenant($tenant);
        return TenantSupplyMarkup::settings($tenant);
    }

    public static function attachSummaries(array &$tenants): void
    {
        if (!$tenants) return;
        $rows = Db::name('tenant_power_supply_markup')->whereIn('tenant_id', array_column($tenants, 'id'))->select()->toArray();
        $byId = array_column($rows, null, 'tenant_id');
        foreach ($tenants as &$row) {
            $id = (int)$row['id'];
            $setting = $byId[$id] ?? [];
            $row['supply_markup'] = [
                'tenant_id' => $id, 'rate_percent' => (string)($setting['rate_percent'] ?? '0.00'),
                'revision' => (int)($setting['revision'] ?? 0), 'updated_by' => (int)($setting['updated_by'] ?? 0),
                'update_time' => (int)($setting['update_time'] ?? 0),
            ];
        }
        unset($row);
    }

    public static function preview(int $tenant, int $admin, array $params): array
    {
        if (array_key_exists('preview_token', $params)) {
            self::requireTenant($tenant);
            $token = self::token($params['preview_token']);
            return self::page(self::saved($tenant, $admin, $token), $token, $params);
        }
        $revision = filter_var($params['expected_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($revision === false) throw new RuntimeException('请重新读取租户当前的价格版本');
        $rate = TenantSupplyMarkup::rate($params['rate_percent'] ?? null);
        $saved = Db::transaction(static function () use ($tenant, $admin, $rate, $revision) {
            $current = self::read($tenant);
            if ($current['revision'] !== $revision) throw new RuntimeException(self::STALE);
            $view = self::impact($tenant, $current, $rate, false);
            return $view + [
                'tenant_id' => $tenant, 'admin_id' => $admin, 'revision' => $revision,
                'before_rate_percent' => $current['rate_percent'], 'after_rate_percent' => $rate,
            ];
        });
        $token = bin2hex(random_bytes(24));
        $saved['expires_at'] = time() + 300;
        Cache::set(self::CACHE_PREFIX.$token, $saved, 300);
        return self::page($saved, $token, $params);
    }

    private static function token(mixed $token): string
    {
        if (!is_string($token) || !preg_match('/^[0-9a-f]{48}$/D', $token)) throw new RuntimeException('请先预览本次价格调整');
        return $token;
    }

    private static function saved(int $tenant, int $admin, string $token): array
    {
        $saved = Cache::get(self::CACHE_PREFIX.$token);
        if (!$saved || $saved['tenant_id'] !== $tenant || $saved['admin_id'] !== $admin || time() >= $saved['expires_at']) {
            throw new RuntimeException(self::EXPIRED);
        }
        return $saved;
    }

    private static function page(array $saved, string $token, array $params): array
    {
        $page = max(1, (int)($params['page_no'] ?? 1));
        $size = min(100, max(1, (int)($params['page_size'] ?? 20)));
        $conflicts = $saved['conflicts'];
        $offset = min($page - 1, count($conflicts)) * $size;
        return [
            'preview_token' => $token, 'expires_at' => $saved['expires_at'],
            'before_rate_percent' => $saved['before_rate_percent'], 'after_rate_percent' => $saved['after_rate_percent'],
            'summary' => $saved['summary'], 'samples' => $saved['samples'], 'application_overrides' => $saved['application_overrides'],
            'conflicts' => ['lists' => array_slice($conflicts, $offset, $size), 'count' => count($conflicts),
                'page_no' => $page, 'page_size' => $size],
        ];
    }

    public static function apply(int $tenant, int $admin, mixed $previewToken): array
    {
        $token = self::token($previewToken);
        $requestId = hash('sha256', $token);
        return MarketPriceWriteTransaction::run(static function () use ($tenant, $admin, $requestId, $token) {
            MarketPriceRuleService::lockOwner($tenant);
            self::requireTenant($tenant, true);
            $receipt = Db::name('tenant_power_supply_markup_log')->where('request_id', $requestId)->lock(true)->find();
            if ($receipt) {
                if ((int)$receipt['tenant_id'] !== $tenant || (int)$receipt['admin_id'] !== $admin) throw new RuntimeException(self::EXPIRED);
                return json_decode($receipt['result_json'], true, 512, JSON_THROW_ON_ERROR);
            }
            $saved = self::saved($tenant, $admin, $token);
            $current = TenantSupplyMarkup::settings($tenant);
            if ($saved['revision'] !== $current['revision']) throw new RuntimeException(self::STALE);
            $rate = $saved['after_rate_percent'];
            $view = self::impact($tenant, $current, $rate, true);
            if ($saved['fingerprint'] !== $view['fingerprint']) throw new RuntimeException(self::STALE);
            if ($view['summary']['blocking_conflicts']) throw new RuntimeException('本次调价将产生或加重售价倒挂，请先调整冲突明细中的租户零售价');
            if (time() >= $saved['expires_at']) throw new RuntimeException(self::EXPIRED);
            $now = time();
            $next = $current;
            if ($rate !== $current['rate_percent']) {
                $values = ['rate_percent' => $rate, 'revision' => $current['revision'] + 1, 'updated_by' => $admin, 'update_time' => $now];
                $query = Db::name('tenant_power_supply_markup');
                if ($current['revision'] === 0) $query->insert(['tenant_id' => $tenant] + $values);
                else $query->where('tenant_id', $tenant)->update($values);
                $next = $values + ['tenant_id' => $tenant];
            }
            $result = ['tenant_id' => $tenant, 'supply_markup' => $next];
            Db::name('tenant_power_supply_markup_log')->insert([
                'tenant_id' => $tenant, 'admin_id' => $admin, 'request_id' => $requestId, 'before_rate_percent' => $current['rate_percent'],
                'after_rate_percent' => $rate, 'before_revision' => $current['revision'], 'after_revision' => $next['revision'],
                'create_time' => $now, 'result_json' => json_encode($result, JSON_THROW_ON_ERROR),
            ]);
            return $result;
        });
    }

    /** Walk the full catalogue in primary-key order without loading all supplier payloads at once. */
    private static function impact(int $tenant, array $current, string $rate, bool $lock): array
    {
        $proposed = array_replace($current, ['rate_percent' => $rate]);
        $platformRules = MarketPriceRuleService::rules(0);
        $tenantRules = MarketPriceRuleService::rules($tenant);
        $summary = ['total_skus' => 0, 'affected_skus' => 0, 'blocking_conflicts' => 0, 'existing_conflicts' => 0, 'off_shelf_skus' => 0];
        $fingerprint = hash_init('sha256');
        $groups = ['blocking' => [], 'existing' => [], 'off_shelf' => []];
        $samples = [];
        $examplesByUnit = [];
        $offShelfSamples = [];
        $cursor = 0;
        do {
            $skus = Db::name('power_market_sku')->alias('s')->join('power_market_product p', 's.product_id=p.id')
                ->field('s.id,s.product_id,s.title,s.usage_unit,s.usage_unit_size,s.upstream_price,s.source_payload,s.status,s.sale_status,s.price_hash,p.name as product_name,p.status as product_status')
                ->where('s.id', '>', $cursor)->order('s.id')->limit(100)->lock($lock)->select()->toArray();
            if (!$skus) break;
            $configs = Db::name('tenant_power_market_sku_price')->where('tenant_id', $tenant)->whereIn('sku_id', array_column($skus, 'id'))
                ->order('sku_id')->lock($lock)->select()->toArray();
            $configs = array_column($configs, null, 'sku_id');
            foreach ($skus as $sku) {
                $cursor = (int)$sku['id'];
                $config = $configs[$cursor] ?? null;
                $onShelf = (bool)$sku['product_status'] && (bool)$sku['status'] && (bool)$sku['sale_status'] && (bool)($config['sale_status'] ?? 1);
                $summary['total_skus']++;
                if (!$onShelf) $summary['off_shelf_skus']++;
                $sku['source_payload'] = json_decode((string)$sku['source_payload'], true) ?: [];
                $items = MarketCatalogReadScope::run(static function () use ($sku, $tenant, $config, $platformRules, $tenantRules, $current, $proposed) {
                    return [
                        self::items($tenant, $sku, $config, $platformRules, $tenantRules, $current),
                        self::items($tenant, $sku, $config, $platformRules, $tenantRules, $proposed),
                    ];
                });
                [$before, $after] = $items;
                $affected = false;
                hash_update($fingerprint, json_encode([$cursor, $sku['product_id'], $sku['product_name'], $sku['title'], $sku['price_hash'], $onShelf, $before, $after], JSON_THROW_ON_ERROR));
                foreach ($after as $key => $item) {
                    $old = $before[$key];
                    $affected = $affected || $old['cost'] !== $item['cost'] || $old['sale'] !== $item['sale'];
                    $conflict = '';
                    $gap = bcsub($item['cost'], $item['sale'], 6);
                    if (bccomp($gap, '0', 6) > 0) {
                        $previousGap = bcsub($old['cost'], $old['sale'], 6);
                        $conflict = !$onShelf ? 'off_shelf' : (bccomp($gap, $previousGap, 6) > 0 ? 'blocking' : 'existing');
                        if ($onShelf) $summary[$conflict.'_conflicts']++;
                    }
                    $row = ['sku_id' => $cursor, 'product_id' => (int)$sku['product_id'], 'product_name' => $sku['product_name'],
                        'sku_title' => $sku['title'], 'item_code' => $key, 'item_label' => $item['label'], 'unit' => $item['unit'],
                        'current_cost' => $old['cost'], 'proposed_cost' => $item['cost'], 'current_sale' => $old['sale'],
                        'proposed_sale' => $item['sale'], 'minimum_sale' => $item['cost'], 'on_shelf' => $onShelf, 'conflict' => $conflict];
                    if ($conflict !== '') $groups[$conflict][] = $row;
                    elseif ($onShelf) {
                        $sampleId = $cursor.':'.$key;
                        $kind = $key.':'.$item['unit'];
                        if (count($examplesByUnit) < 20 && !isset($examplesByUnit[$kind])) $examplesByUnit[$kind] = $row;
                        if (count($samples) < 20) $samples[$sampleId] = $row;
                    } elseif (count($offShelfSamples) < 20) $offShelfSamples[] = $row;
                }
                if ($affected) $summary['affected_skus']++;
            }
        } while (count($skus) === 100);
        $overrides = MarketPriceRuleService::followsApps($tenant) ? [] : MarketPriceRuleService::applicationOverrides($tenant);
        hash_update($fingerprint, json_encode($overrides, JSON_THROW_ON_ERROR));
        $conflicts = array_merge(...array_values($groups));
        $examples = [];
        foreach ($examplesByUnit as $row) $examples[$row['sku_id'].':'.$row['item_code']] = $row;
        $examples = array_merge(array_slice($conflicts, 0, 20), array_values($examples + $samples), $offShelfSamples);
        return ['fingerprint' => hash_final($fingerprint), 'summary' => $summary, 'samples' => array_slice($examples, 0, 20),
            'conflicts' => $conflicts, 'application_overrides' => $overrides];
    }

    private static function items(int $tenant, array $sku, ?array $config, array $platformRules, array $tenantRules, array $markup): array
    {
        $image = MarketImageItemBilling::display(MarketImageItemBilling::prices($sku, $tenant, $platformRules, $tenantRules, $markup), $tenant);
        if ($image !== null) return array_column($image['items'], null, 'code');
        $token = MarketFileQaTokenPricing::display($sku, $tenant, $platformRules, $tenantRules, $markup);
        if ($token !== null) {
            $items = [];
            foreach (['input' => '输入 Token', 'cached_input' => '缓存输入 Token', 'output' => '输出 Token'] as $side => $label) {
                $items[$side] = ['label' => $label, 'unit' => self::unitLabel('Token', $token['usage_unit_size']), 'cost' => $token['cost'][$side], 'sale' => $token['sale'][$side]];
            }
            return $items;
        }
        $price = MarketPriceRuleService::tenant($tenant, $sku, $config, $tenantRules, $platformRules, $markup);
        $unit = match ($sku['usage_unit']) {
            'business_unit' => MarketGenerationMeter::businessUnitLabel(MarketGenerationMeter::priceDisplayFields($sku)),
            'per_call', 'call', 'count' => '次', 'image', 'per_image' => '张', 'token', 'tokens' => 'Token',
            'input_second' => '输入秒', 'output_second' => '输出秒', 'second', 'seconds', 'sec' => '秒',
            'character', 'characters' => '字符', 'pack' => '包', default => (string)$sku['usage_unit'],
        };
        return ['sku' => ['label' => '规格单价', 'unit' => self::unitLabel($unit, $sku['usage_unit_size']),
            'cost' => $price['platform']['price'], 'sale' => $price['tenant']['price']]];
    }

    private static function unitLabel(string $unit, mixed $size): string
    {
        $size = (string)$size;
        if (str_contains($size, '.')) $size = rtrim(rtrim($size, '0'), '.');
        return match ($size) {
            '', '1' => $unit, '1000000' => ($unit === 'Token' ? '百万 Token' : '百万'.$unit), default => $size.' '.$unit,
        };
    }
}
