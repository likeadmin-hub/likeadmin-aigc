<?php
declare(strict_types=1);

namespace app\common\service\power;

use InvalidArgumentException;

final class MarketImageItemBilling
{
    private const CREDIT_MODELS = ['gpt-image-2-6/flare', 'gpt-image-2-6/sunburst', 'official-33-68384aba89044628f9', 'official-33-58e7520095e065af0c'];
    private const TOKEN_CODES = [
        'text_input_uncached', 'text_input_cached', 'image_input_uncached', 'image_input_cached', 'image_output',
    ];
    private const SVG_AUTHORS = ['gpt-5.6-luna', 'gpt-6-sol', 'gpt-5.6-terra'];

    /** Ingest supplier rates only. Editable downstream rates live in price rules. */
    public static function declaration(array $sku, string $model): ?array
    {
        $items = $sku['charge_items'] ?? [];
        if ($items === []) return null;
        if (!is_array($items) || !array_is_list($items)) throw new InvalidArgumentException('生图分项计费规则无效');
        $input = array_values(array_filter($items, static fn($row) => ($row['code'] ?? '') === 'input_images'));
        $basis = match ($sku['meter_contract']['basis'] ?? ($sku['meter_summary'] ?? '')) {
            'call', '按次', '按次，总点数向上取整' => 'call',
            'image', '生成张数', '生成张数，总点数向上取整' => 'image',
            default => '',
        };
        if (count($items) === 1 && count($input) === 1 && ($sku['charge_base_mode'] ?? 'add') === 'add') {
            $item = $input[0];
            $included = filter_var($item['included_count'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            if ($included === false || ($item['unit'] ?? '') !== 'input_image' || $basis === '') {
                throw new InvalidArgumentException('生图输入张数计费规则无效');
            }
            $keys = $sku['input_image_bindings'] ?? match ($model) {
                'official-33-7b77ec425b57b520cd' => ['image_urls'],
                'official-33-f9a445be9ef6a7f58d' => ['image_urls', 'elements.name'],
                'official-33-42b1deb0efa8cdf52d' => str_contains((string)($sku['sku_key'] ?? ''), 'webpage-')
                    ? ['style_image', 'subject_image'] : ['image_urls'],
                default => [],
            };
            if (!is_array($keys) || !$keys || array_diff($keys, ['image_urls','elements.name','style_image','subject_image'])) {
                throw new InvalidArgumentException('该规格缺少已核验的输入图片字段');
            }
            return ['mode'=>'image_count', 'basis'=>$basis, 'included'=>$included, 'bindings'=>array_values(array_unique($keys)),
                'items'=>[
                    'base'=>['label'=>$basis==='call'?'基础费':'输出图片', 'unit'=>$basis==='call'?'次':'张输出图',
                        'rate'=>MarketPricePolicy::amount($sku['price']['points'] ?? null)],
                    'input_images'=>['label'=>'输入图片','unit'=>'张输入图','rate'=>MarketPricePolicy::amount($item['rate'] ?? null)],
                ]];
        }
        if (($sku['charge_base_mode'] ?? '') !== 'replace') throw new InvalidArgumentException('该生图分项暂缺可执行的计量契约');
        $rates = [];
        foreach ($items as $item) {
            $code = (string)($item['code'] ?? '');
            $author = (string)($item['author'] ?? '');
            if (($item['unit'] ?? '') !== 'token' || (int)($item['unit_size'] ?? 0) !== 1000000
                || ($author === '' ? !in_array($code, self::TOKEN_CODES, true)
                    : (!in_array($author, self::SVG_AUTHORS, true) || !in_array($code, ['svg_author_input','svg_author_output'], true)))) {
                throw new InvalidArgumentException('该规格含未识别的生图 Token 费率');
            }
            $key = $author === '' ? $code : "$code:$author";
            $rate = MarketPricePolicy::amount($item['rate'] ?? null);
            if (isset($rates[$key]) || (float)$rate <= 0) throw new InvalidArgumentException('上游 Token 费率无效或重复');
            $rates[$key] = ['label'=>(string)($item['label'] ?? $code), 'unit'=>'百万 Token','rate'=>$rate, 'author'=>$author];
        }
        $cap = $sku['reserve_upper_points'] ?? null;
        // The deployed supplier publishes rates separately from its verified 300 Credit limit.
        // Apply that limit only to the identified Flare/Sunburst pricing profile.
        $supplier = preg_replace('/-(?:text|image)-to-image$/D', '', $model);
        if ($cap === null && in_array($supplier, self::CREDIT_MODELS, true) && isset($rates['text_input_uncached'])) {
            $cap = round((float)$rates['text_input_uncached']['rate'] / 900 * 300, 6);
        }
        if (array_diff(self::TOKEN_CODES, array_keys($rates)) || (float)$cap <= 0) {
            throw new InvalidArgumentException('该生图规格缺少完整费率或提交预占上限');
        }
        return ['mode'=>'token_items','cap'=>MarketPricePolicy::amount($cap),'items'=>$rates];
    }

    public static function scope(string $key): string
    {
        return 'i'.substr(hash('sha256', $key), 0, 11);
    }

    public static function prices(array $sku, int $owner, ?array $platformRules = null, ?array $tenantRules = null): ?array
    {
        $contract = $sku['source_payload']['image_item_billing'] ?? null;
        if ($contract === null) return null;
        if (isset($contract['error'])) return $contract + ['valid'=>false,'items'=>[]];
        $platformRules ??= MarketPriceRuleService::rules(0);
        $tenantRules ??= $owner > 0 ? MarketPriceRuleService::rules($owner) : [];
        $contract['valid'] = true;
        foreach ($contract['items'] as $key => &$item) {
            $platform = self::rate($key, $item['rate'], $sku, $platformRules);
            $tenant = self::rate($key, $platform['price'], $sku, $tenantRules);
            $item += ['platform'=>$platform['price'],'tenant'=>$tenant['price'],
                'platform_manual'=>($platform['rule']['mode'] ?? '')==='manual',
                'tenant_manual'=>($tenant['rule']['mode'] ?? '')==='manual','valid'=>$platform['valid'] && $tenant['valid']];
            $contract['valid'] = $contract['valid'] && $item['valid'];
        }
        unset($item);
        return $contract;
    }

    private static function rate(string $key, string $base, array $sku, array $rules): array
    {
        $group = [];
        foreach ($rules as $rule) {
            if ($rule['scope_type'] === self::scope($key) && (int)$rule['scope_id'] === (int)$sku['id'] && $rule['mode']==='manual') {
                $rule['scope_type'] = 'sku';
                $group = [$rule];
                break;
            }
            if ($key === 'base' || $rule['mode'] === 'percent') $group[] = $rule;
        }
        return MarketPricePolicy::resolve($base, $base, $group, (int)$sku['product_id'], (int)$sku['id']);
    }

    public static function display(?array $pricing, int $owner): ?array
    {
        if ($pricing === null) return null;
        $items = [];
        foreach ($pricing['items'] as $key => $item) {
            $items[] = ['code'=>$key,'label'=>$item['label'],'unit'=>$item['unit'],'author'=>$item['author'] ?? '',
                'cost'=>$item[$owner===0?'rate':'platform'], 'sale'=>$item[$owner===0?'platform':'tenant'],
                'manual'=>$item[$owner===0?'platform_manual':'tenant_manual'],'valid'=>$item['valid']];
        }
        return ['mode'=>$pricing['mode'] ?? '', 'included'=>$pricing['included'] ?? 0, 'valid'=>$pricing['valid'],
            'error'=>$pricing['error'] ?? '', 'items'=>$items];
    }

    public static function isToken(array $snapshot): bool
    {
        return ($snapshot['image_item_pricing']['mode'] ?? '') === 'token_items';
    }

    public static function facts(array $pricing, array $payload): array
    {
        if ($pricing['mode'] === 'image_count') return ['input_count'=>self::inputCount($pricing, $payload)];
        if (($payload['output_format'] ?? '') !== 'svg') return [];
        $author = (string)($payload['svg']['model'] ?? 'gpt-5.6-luna');
        if (!isset($pricing['items']['svg_author_input:'.$author],$pricing['items']['svg_author_output:'.$author])) {
            throw new InvalidArgumentException('该 SVG 作者模型缺少完整计费规则');
        }
        return ['author'=>$author];
    }

    public static function inputCount(array $contract, array $payload): int
    {
        $count = 0;
        foreach ($contract['bindings'] as $path) {
            $values = match ($path) {
                'image_urls' => (array)($payload['image_urls'] ?? []),
                'elements.name' => array_column((array)($payload['elements'] ?? []), 'name'),
                default => [$payload[$path] ?? null],
            };
            $count += count(array_filter($values, static fn($value) => is_string($value) && trim($value)!==''));
        }
        return $count;
    }

    public static function details(array $pricing, array $facts, int $output, ?array $receipt = null): array
    {
        $token = $pricing['mode'] === 'token_items';
        $rows = [];
        foreach ($pricing['items'] as $key => $item) {
            $author = $item['author'] ?? '';
            if ($author !== '' && $author !== ($facts['author'] ?? '')) continue;
            $quantity = $token ? ($receipt['quantities'][$key] ?? null) : ($key === 'base'
                ? ($pricing['basis'] === 'call' ? 1 : $output) : max(0, ($facts['input_count'] ?? 0)-$pricing['included']));
            $rows[] = ['code'=>$key, 'label'=>$item['label'], 'unit'=>$item['unit'], 'author'=>$author,'quantity'=>$quantity,
                'unit_points'=>(float)$item['tenant'], 'points'=>$quantity===null?null:round($quantity*(float)$item['tenant']*($token?($receipt['adjustment'] ?? 1)/1000000:1),6)];
        }
        return ['mode'=>$pricing['mode'], 'contract_revision'=>1, 'input_count'=>$facts['input_count'] ?? 0,'included'=>$pricing['included'] ?? 0,'items'=>$rows];
    }

    /** The supplier has already reconciled the line quantities with its Credit charge. */
    public static function receipt(array $pricing, array $response, array $facts): ?array
    {
        $bill = $response['token_billing'] ?? null;
        if (($bill['status'] ?? '') !== 'settled' || !is_array($bill['items'] ?? null)) return null;
        $actual = $bill['actual_points'] ?? null;
        if (!is_numeric($actual) || (float)$actual < 0 || (float)$actual > (float)$pricing['cap']) return null;
        $expected = array_filter($pricing['items'], static fn($item) => empty($item['author']) || $item['author']===($facts['author'] ?? ''));
        $quantities = [];
        $subtotal = 0.;
        foreach ($bill['items'] as $line) {
            $key = (string)($line['code'] ?? '').(empty($line['author'])?'':':'.$line['author']);
            $quantity = filter_var($line['tokens'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>1000000000]]);
            if (!isset($expected[$key]) || isset($quantities[$key]) || $quantity === false
                || !is_numeric($line['points_per_million_tokens'] ?? null)
                || MarketPricePolicy::amount($line['points_per_million_tokens']) !== $expected[$key]['rate']) return null;
            $quantities[$key] = $quantity;
            $subtotal += $quantity*(float)$expected[$key]['rate']/1000000;
        }
        if (count($quantities)!==count($expected) || ($subtotal <= 0 && (float)$actual>0)) return null;
        return ['quantities'=>$quantities,'adjustment'=>$subtotal>0?(float)$actual/$subtotal:1.];
    }

    public static function amounts(array $pricing, array $facts, int $output, ?array $response = null): array
    {
        $token = $pricing['mode'] === 'token_items';
        $receipt = $token && $response !== null ? self::receipt($pricing, $response, $facts) : null;
        if ($token && $response !== null && $receipt === null) throw new InvalidArgumentException('生图 Token 账单待核对');
        $details = self::details($pricing,$facts,$output,$receipt);
        $cost = $user = 0.;
        foreach ($details['items'] as $row) {
            $item = $pricing['items'][$row['code']];
            if ($token && $receipt === null) {
                $cost = max($cost,(float)$item['platform']/(float)$item['rate']);
                $user = max($user,(float)$item['tenant']/(float)$item['rate']);
                continue;
            }
            $factor = $row['quantity'] * ($token?$receipt['adjustment']/1000000:1);
            $cost += $factor*(float)$item['platform'];
            $user += $factor*(float)$item['tenant'];
        }
        if ($token && $receipt === null) {
            return [ceil($cost*(float)$pricing['cap']*1000000-0.0000001)/1000000,
                ceil($user*(float)$pricing['cap']*1000000-0.0000001)/1000000];
        }
        return [round($cost,6),round($user,6)];
    }
}
