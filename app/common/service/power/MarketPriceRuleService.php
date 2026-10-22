<?php
namespace app\common\service\power;

use RuntimeException;
use think\facade\Db;
use think\facade\Cache;

/** Explicit owner 0 = platform; positive owner = tenant. Never uses request tenant context. */
final class MarketPriceRuleService
{
    public static function rules(int $owner): array
    {
        return Db::name('power_market_price_rule')->where('owner_id', $owner)->order('id')->select()->toArray();
    }

    public static function platform(array $sku, ?array $rules = null): array
    {
        return MarketPricePolicy::resolve($sku['upstream_price'] ?? 0, $sku['upstream_price'] ?? 0,
            $rules ?? self::rules(0), (int)$sku['product_id'], (int)$sku['id']);
    }

    public static function tenant(int $owner, array $sku, ?array $configured = null, ?array $rules = null, ?array $platformRules = null): array
    {
        $platform = self::platform($sku, $platformRules);
        $tenant = MarketPricePolicy::resolve($platform['price'], $platform['price'],
            $rules ?? self::rules($owner), (int)$sku['product_id'], (int)$sku['id']);
        return ['platform' => $platform, 'tenant' => $tenant, 'valid' => $platform['valid'] && $tenant['valid']];
    }

    public static function tenantPrice(int $owner, mixed $sku, mixed $configured = null): float
    {
        if (!is_array($sku)) $sku = $sku->toArray();
        if ($configured !== null && !is_array($configured)) $configured = $configured->isEmpty() ? null : $configured->toArray();
        $quote = self::tenant($owner, $sku, $configured);
        if (!$quote['valid']) throw new RuntimeException('当前手动售价低于进货价，请先调整价格或恢复价格');
        return (float)$quote['tenant']['price'];
    }

    public static function followsApps(int $owner): bool
    {
        if ($owner <= 0) return false;
        return (bool)Db::name('power_market_price_rule')->where(['owner_id' => $owner, 'scope_type' => 'global', 'scope_id' => 0])->value('follow_apps');
    }

    /** Capture provenance once, before a new consumption can cross the supplier boundary. */
    public static function captureSnapshot(int $owner, array $snapshot, array $context = []): array
    {
        foreach ($snapshot as $key => $value) if (is_array($value) && !in_array($key, ['pricing_rules', 'contract', 'market_metadata', 'pricing_attributes'], true)) $snapshot[$key] = self::captureSnapshot($owner, $value, $context);
        $id = (int)($snapshot['sku_id'] ?? $snapshot['market_sku_id'] ?? 0);
        if ($id <= 0) return $snapshot;
        $sku = Db::name('power_market_sku')->where('id', $id)->find();
        if (!$sku) throw new RuntimeException('计费规格不存在');
        $configured = Db::name('tenant_power_market_sku_price')->where(['tenant_id' => $owner, 'sku_id' => $id])->find();
        $quote = self::tenant($owner, $sku, $configured ?: null);
        if (!$quote['valid']) throw new RuntimeException('当前手动售价低于进货价，请先调整价格或恢复价格');
        foreach (['platform_price' => 'platform', 'tenant_price' => 'tenant'] as $field => $level) {
            if (!array_key_exists($field, $snapshot)) continue;
            if ($level === 'tenant' && array_key_exists('user_id', $context) && $context['user_id'] === 0) continue;
            $actual = $level === 'tenant' ? ($snapshot['market_tenant_price'] ?? $snapshot[$field]) : $snapshot[$field];
            if (MarketPricePolicy::amount($actual) !== $quote[$level]['price']) throw new RuntimeException('价格已更新，请刷新报价后重新提交');
        }
        foreach (['tenant' => 'platform', 'user' => 'tenant'] as $field => $level) {
            if (isset($snapshot['prices'][$field]) && MarketPricePolicy::amount($snapshot['prices'][$field]) !== $quote[$level]['price']) throw new RuntimeException('价格已更新，请刷新报价后重新提交');
        }
        $snapshot['pricing_rules'] = ['platform' => $quote['platform'], 'tenant' => $quote['tenant']];
        return $snapshot;
    }

    public static function settings(int $owner): array
    {
        return ['rules' => self::rules($owner), 'follow_apps' => self::followsApps($owner)];
    }

    private static function lockOwner(int $owner): void
    {
        foreach (array_values(array_unique([0, $owner])) as $id) {
            $table = Db::name('power_market_price_rule')->getTable();
            Db::execute("INSERT IGNORE INTO `$table` (owner_id,scope_type,scope_id,mode,value,revision,follow_apps,create_time,update_time) VALUES (?, 'global',0,'inherit',0,0,0,?,?)", [$id, time(), time()]);
            Db::name('power_market_price_rule')->where(['owner_id' => $id, 'scope_type' => 'global', 'scope_id' => 0])->lock(true)->find();
        }
    }

    public static function manual(int $owner, int $productId, int $skuId, float $price): void
    {
        self::lockOwner($owner);
        self::put($owner, 'sku', $skuId, 'manual', MarketPricePolicy::amount($price));
        self::bump($owner);
    }

    private static function put(int $owner, string $scope, int $id, string $mode, string $value): void
    {
        $where = ['owner_id' => $owner, 'scope_type' => $scope, 'scope_id' => $id];
        $data = ['mode' => $mode, 'value' => $value, 'update_time' => time()];
        $row = Db::name('power_market_price_rule')->where($where)->find();
        if ($row) Db::name('power_market_price_rule')->where('id', $row['id'])->update($data);
        else Db::name('power_market_price_rule')->insert($where + $data + ['revision' => 0, 'follow_apps' => 0, 'create_time' => time()]);
    }

    private static function bump(int $owner): void
    {
        Db::name('power_market_price_rule')->where(['owner_id' => $owner, 'scope_type' => 'global', 'scope_id' => 0])->inc('revision')->update(['update_time' => time()]);
    }

    private static function request(array $params): array
    {
        $action = (string)($params['action'] ?? 'markup');
        $scope = (string)($params['scope'] ?? 'global');
        if (!in_array($action, ['markup', 'restore'], true) || !in_array($scope, ['global', 'product', 'sku'], true)) throw new RuntimeException('价格操作或范围无效');
        $ids = array_values(array_unique(array_map('intval', (array)($params['ids'] ?? []))));
        if ($scope === 'global') $ids = [0];
        if (!$ids || ($scope !== 'global' && min($ids) <= 0) || count($ids) > 1000) throw new RuntimeException('请选择有效的价格范围（最多 1000 项）');
        $rule = MarketPricePolicy::normalize($action === 'restore' ? 'add' : (string)($params['mode'] ?? 'percent'), $action === 'restore' ? 0 : ($params['value'] ?? 0));
        if (!in_array($rule['mode'], ['add', 'percent'], true)) throw new RuntimeException('请选择加点或百分比溢价');
        return ['action' => $action, 'scope' => $scope, 'ids' => $ids, 'mode' => $rule['mode'], 'value' => $rule['value'],
            'replace_descendants' => $action === 'restore' || !empty($params['replace_descendants']),
            'follow_apps' => !empty($params['follow_apps'])];
    }

    private static function catalogue(int $owner, array $params, bool $lock = false): array
    {
        $query = Db::name('power_market_sku')->alias('s')->join('power_market_product p', 'p.id=s.product_id')
            ->field('s.*,p.name as product_name,p.resource_type,p.upstream_app_code,p.status as product_status')->where('s.status', 1);
        if ($owner > 0) $query->where('p.status', 1)->where('s.sale_status', 1);
        if ($params['scope'] === 'product') $query->whereIn('s.product_id', $params['ids']);
        if ($params['scope'] === 'sku') $query->whereIn('s.id', $params['ids']);
        if ($lock) $query->lock(true);
        $rows = $query->order('s.id')->select()->toArray();
        $actual = array_unique(array_column($rows, $params['scope'] === 'product' ? 'product_id' : 'id'));
        if (!$rows || ($params['scope'] !== 'global' && array_diff($params['ids'], $actual))) throw new RuntimeException('所选商品或规格不存在、已下架或不属于当前可售范围');
        return $rows;
    }

    private static function changedRules(array $old, array $params, array $rows): array
    {
        $productIds = array_unique(array_column($rows, 'product_id'));
        $skuIds = $params['scope'] === 'product'
            ? Db::name('power_market_sku')->whereIn('product_id', $params['ids'])->column('id')
            : array_column($rows, 'id');
        $skuIds = array_map('intval', $skuIds);
        $rules = array_values(array_filter($old, static function ($rule) use ($params, $productIds, $skuIds) {
            if ($rule['scope_type'] === $params['scope'] && in_array((int)$rule['scope_id'], $params['ids'], true)) return false;
            if (!$params['replace_descendants']) return true;
            if ($params['scope'] === 'global') return $rule['scope_type'] === 'global';
            return !($params['scope'] === 'product' && $rule['scope_type'] === 'sku' && in_array((int)$rule['scope_id'], $skuIds, true));
        }));
        foreach ($params['ids'] as $id) $rules[] = ['scope_type' => $params['scope'], 'scope_id' => $id, 'mode' => $params['mode'], 'value' => $params['value']];
        return $rules;
    }

    private static function view(int $owner, array $params, bool $lock = false): array
    {
        $rows = self::catalogue($owner, $params, $lock);
        $platformRules = self::rules(0); $ownRules = $owner === 0 ? $platformRules : self::rules($owner);
        $tenantPrices = $owner === 0 ? [] : Db::name('tenant_power_market_sku_price')->where('tenant_id', $owner)->whereIn('sku_id', array_column($rows, 'id'))->select()->toArray();
        $bySku = array_column($tenantPrices, null, 'sku_id');
        $newRules = self::changedRules($ownRules, $params, $rows);
        $preview = []; $changed = 0; $invalid = 0;
        foreach ($rows as $sku) {
            $before = $owner === 0 ? self::platform($sku, $ownRules) : self::tenant($owner, $sku, $bySku[$sku['id']] ?? null, $ownRules, $platformRules)['tenant'];
            $after = $owner === 0 ? self::platform($sku, $newRules) : self::tenant($owner, $sku, $bySku[$sku['id']] ?? null, $newRules, $platformRules)['tenant'];
            if ($before['price'] !== $after['price']) $changed++;
            if (!$after['valid']) $invalid++;
            $preview[] = ['sku_id' => (int)$sku['id'], 'product_id' => (int)$sku['product_id'], 'name' => $sku['product_name'], 'title' => $sku['title'],
                'usage_unit' => $sku['usage_unit'], 'usage_unit_size' => $sku['usage_unit_size'], 'base_price' => $after['base'],
                'before' => $before['price'], 'after' => $after['price'], 'valid' => $after['valid'], 'rule' => $after['rule']];
        }
        return ['fingerprint' => hash('sha256', json_encode([$rows, $platformRules, $ownRules, $tenantPrices])),
            'rows' => $preview, 'total' => count($rows), 'changed' => $changed, 'invalid' => $invalid,
            'next_rules' => $newRules, 'follow_apps' => $params['follow_apps']];
    }

    public static function preview(int $owner, int $admin, array $params): array
    {
        $params = self::request($params);
        $view = Db::transaction(static function () use ($owner, $params) { self::lockOwner($owner); return self::view($owner, $params); });
        $token = bin2hex(random_bytes(24));
        Cache::set('market_price_preview:'.$token, ['owner' => $owner, 'admin' => $admin, 'params' => $params, 'fingerprint' => $view['fingerprint']], 300);
        unset($view['next_rules'], $view['fingerprint']);
        $view['rows'] = array_slice($view['rows'], 0, 200);
        return $view + ['token' => $token, 'expires_in' => 300, 'action' => $params['action']];
    }

    public static function apply(int $owner, int $admin, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) throw new RuntimeException('请先预览价格');
        $saved = Cache::get('market_price_preview:'.$token);
        if (!$saved || $saved['owner'] !== $owner || $saved['admin'] !== $admin) throw new RuntimeException('价格预览已过期，请重新预览');
        $result = Db::transaction(static function () use ($owner, $saved) {
            self::lockOwner($owner);
            $view = self::view($owner, $saved['params'], true);
            // Creating the revision row at first apply is not a price change.
            $expected = $saved['fingerprint'];
            if ($view['fingerprint'] !== $expected) throw new RuntimeException('价格或规则已发生变化，请重新预览');
            if ($view['invalid']) throw new RuntimeException('存在低于进货价的规格，请调整后再保存');
            $root = Db::name('power_market_price_rule')->where(['owner_id' => $owner, 'scope_type' => 'global', 'scope_id' => 0])->find();
            Db::name('power_market_price_rule')->where('owner_id', $owner)->where('scope_type', '<>', 'global')->delete();
            foreach ($view['next_rules'] as $rule) self::put($owner, $rule['scope_type'], (int)$rule['scope_id'], $rule['mode'], (string)$rule['value']);
            if ($saved['params']['scope'] === 'global') Db::name('power_market_price_rule')->where('id', $root['id'])->update(['follow_apps' => $saved['params']['follow_apps'] ? 1 : 0]);
            self::bump($owner);
            return ['total' => $view['total'], 'changed' => $view['changed']];
        });
        Cache::delete('market_price_preview:'.$token);
        return $result;
    }
}
