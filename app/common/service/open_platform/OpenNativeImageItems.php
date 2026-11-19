<?php
declare(strict_types=1);

namespace app\common\service\open_platform;

use app\common\service\power\MarketImageItemBilling as Billing;

/** Bind one published task SKU to the same image calculator used by applications. */
final class OpenNativeImageItems
{
    public const VERSION = 4;
    public const BASIS = 'native_image_items';
    public const RECEIPT_PATHS = ['token_billing', 'data.token_billing'];

    public static function structure(array $pricing): ?array
    {
        $mode = $pricing['mode'] ?? '';
        if (isset($pricing['error']) || !in_array($mode, ['image_count','token_items'], true)) return null;
        $codes = array_keys($pricing['items']);
        sort($codes);
        $meter = ['version'=>self::VERSION,'mode'=>$mode,'item_codes'=>$codes]
            + array_intersect_key($pricing, array_flip(['basis','bindings','included']));
        if (isset($meter['bindings'])) sort($meter['bindings']);
        return $meter;
    }

    public static function binding(array $product, array $sku): ?array
    {
        if (($product['resource_type'] ?? '') !== 'model' || ($product['model_type'] ?? '') !== 'image') return null;
        $source = OpenStore::json($sku['source_payload'] ?? []);
        return $sku['image_billing_items']['native_meter'] ?? self::structure((array)($source['image_item_billing'] ?? []));
    }

    public static function publishedRule(array $binding): array
    {
        // max_units is the count of settlement receipts, not the Token or Credit cap.
        return ['basis'=>'usage','max_units'=>1,'usage_path'=>$binding['mode']==='token_items'?'data.token_billing':'data.result',
            'native_meter'=>$binding];
    }

    /** Public pricing never includes supplier rates or the tenant's purchase price. */
    public static function publicPrices(array $sku): array
    {
        $prices = $sku['image_billing_items'] ?? null;
        if ($prices === null) return [];
        $items = empty($prices['valid']) ? [] : array_map(static fn($item) => [
            'code'=>$item['code'],'label'=>$item['label'],'unit'=>$item['unit'],'author'=>$item['author'],
            'unit_points'=>(float)$item['sale'],
        ], $prices['items']);
        return ['billing_details'=>['mode'=>$prices['mode'],'included'=>$prices['included'],'items'=>$items]];
    }

    /** TaskController prioritizes params as a whole. The supplied body is never modified. */
    public static function business(array $raw, array $defaults): array
    {
        $nested = $raw['params'] ?? null;
        if ($nested instanceof \stdClass) $nested = (array)$nested;
        return array_replace($defaults, is_array($nested) ? $nested : $raw);
    }

    public static function defaults(array $pricing, array $metadata): array
    {
        $fields = $pricing['mode']==='token_items' ? ['output_format','svg'] : ['count'];
        foreach ($pricing['bindings'] ?? [] as $path) $fields[] = explode('.', $path)[0];
        return array_intersect_key(OpenStore::json($metadata['default_params'] ?? []),
            OpenStore::json($metadata['params_schema'] ?? []), array_flip($fields));
    }

    public static function facts(array $pricing, array $raw, array $defaults): array
    {
        $business = self::business($raw, $defaults);
        try {
            foreach ($pricing['bindings'] ?? [] as $path) {
                $key = explode('.', $path)[0];
                if (!array_key_exists($key,$business)) continue;
                $value = $business[$key];
                if (in_array($key,['image_urls','elements'],true)) {
                    if (!is_array($value) || !array_is_list($value)) throw new \InvalidArgumentException('输入图片列表无效');
                    foreach ($value as $slot) {
                        $entry = $key==='elements' ? ((array)$slot)['name'] ?? null : $slot;
                        if (!is_string($entry) || trim($entry)==='') throw new \InvalidArgumentException('输入图片或主体引用无效');
                    }
                } elseif (!is_string($value)) {
                    throw new \InvalidArgumentException('输入图片字段无效');
                }
            }
            if (isset($business['svg']) && $business['svg'] instanceof \stdClass) $business['svg'] = (array)$business['svg'];
            $facts = Billing::facts($pricing,$business);
            if (($pricing['basis'] ?? '') === 'image') {
                // The published model schema owns the SKU-specific upper bound.
                $quantity = filter_var($business['count'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if ($quantity === false) throw new \InvalidArgumentException('出图数量超出计费边界');
                $facts['quantity'] = $quantity;
            }
            return $facts;
        } catch (\InvalidArgumentException $error) {
            throw new OpenException('invalid_request',$error->getMessage());
        }
    }

    public static function freeze(array $quote, array $sku, array $raw, ?array $publishedRule): array
    {
        $snapshot = $quote['market_snapshot'];
        $pricing = $snapshot['image_item_pricing'];
        $binding = self::structure($pricing);
        if ($binding === null || ($publishedRule['basis'] ?? '') !== 'usage'
            || (float)($publishedRule['max_units'] ?? 0) !== 1.
            || ($publishedRule['usage_path'] ?? '') !== self::publishedRule($binding)['usage_path']
            || !is_array($publishedRule['native_meter'] ?? null)
            || OpenPolicy::fingerprint($publishedRule['native_meter']) !== OpenPolicy::fingerprint($binding)) {
            throw new OpenException('native_usage_boundary_required','生图分项计费契约需要审核发布',503);
        }
        $defaults = self::defaults($pricing,$snapshot['market_metadata']);
        $quantity = (int)$quote['quantity'];
        $native = ['kind'=>'image','market_snapshot'=>$snapshot,'submitted_images'=>$quantity,'image_defaults'=>$defaults];
        self::assertTransport($native,$raw);
        return ['user'=>round((float)$quote['user_charge_points'],2),'tenant'=>round((float)$quote['tenant_cost_points'],2),'snapshot'=>[
            'native_media'=>$native,
            'rules'=>[['sku_id'=>(int)$sku['id'],'sku_key'=>$sku['sku_key'],'basis'=>self::BASIS,'units'=>$quantity,
                'unit_size'=>1,'usage_unit'=>$pricing['mode']==='token_items'?'itemized_token':'image',
                'billing_details'=>$quote['billing_details']]],
        ]];
    }

    public static function assertTransport(array $native, array $raw): void
    {
        $snapshot = $native['market_snapshot'] ?? [];
        if (!isset($snapshot['image_item_pricing'])) return;
        $facts = self::facts($snapshot['image_item_pricing'],$raw,$native['image_defaults']);
        $quantity = $facts['quantity'] ?? $native['submitted_images'];
        unset($facts['quantity']);
        if ($quantity !== $native['submitted_images'] || $facts !== $snapshot['image_item_facts']) {
            throw new OpenException('invalid_usage','原生请求与生图计费快照不一致');
        }
    }

    public static function settle(array $native,array $result,bool $failed): ?array
    {
        if ($failed) return ['tenant'=>0.,'user'=>0.,'usage'=>['units'=>0.]];
        $snapshot = $native['market_snapshot'];
        $pricing = $snapshot['image_item_pricing'];
        $facts = $snapshot['image_item_facts'];
        $outputs = OpenNativeMarketPricing::settledUnits($native,$result,false);
        if ($outputs === null) return null;
        if ($outputs > $native['submitted_images']) throw new OpenException('usage_boundary_exceeded','实际出图数量超出冻结边界，等待核对',502);
        $token = $pricing['mode']==='token_items';
        $receipt = null;
        $billBody = null;
        if ($token) {
            foreach (self::RECEIPT_PATHS as $path) {
                $candidate = OpenSchema::get($result,$path);
                if ($candidate === null) continue;
                if (!is_array($candidate) && !$candidate instanceof \stdClass) return null;
                $candidate = OpenStore::json(OpenStore::encode($candidate));
                if ($billBody !== null && OpenPolicy::fingerprint($billBody) !== OpenPolicy::fingerprint($candidate)) return null;
                $billBody = $candidate;
            }
            $billBody = ['token_billing'=>$billBody];
            $receipt = Billing::receipt($pricing,$billBody,$facts);
            if ($receipt === null) return null;
        }
        [$tenant,$user] = Billing::amounts($pricing,$facts,(int)$outputs,$billBody);
        $details = Billing::details($pricing,$facts,(int)$outputs,$receipt) + ['output_count'=>(int)$outputs];
        return ['tenant'=>$tenant,'user'=>$user,'usage'=>['units'=>$outputs,'billing_details'=>$details]];
    }
}
