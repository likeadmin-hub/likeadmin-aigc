<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Supplier meter contract, captured with each task. Never infer a formula from a model name or SKU title. */
final class MarketGenerationMeter
{
    public static function contract(array $sku): array
    {
        $source = MarketGenerationSchema::array($sku['source_payload'] ?? []);
        $summary = trim((string)($source['meter_summary'] ?? $sku['meter_summary'] ?? ''));
        $unit = strtolower((string)($sku['usage_unit'] ?? ''));
        // A malformed explicit declaration must never fall back to a cheaper
        // legacy unit or a human-readable summary. Only this finite grammar is executable.
        if (array_key_exists('meter_contract', $source) || array_key_exists('meter_contract', $sku)) {
            return self::structured(array_key_exists('meter_contract', $source) ? $source['meter_contract'] : $sku['meter_contract'], $summary, $unit);
        }
        $contract = ['version'=>1, 'summary'=>$summary, 'unit'=>$unit, 'round_up'=>false, 'basis'=>'',
            'source'=>$summary === '' ? 'legacy_unit' : 'legacy_summary'];
        $suffix = '，总点数向上取整';
        if ($summary !== '') {
            if (!str_ends_with($summary, $suffix)) throw new InvalidArgumentException('模型计费规则尚未适配，请同步并核验模型价格');
            $formula = substr($summary, 0, -strlen($suffix));
            $contract['round_up'] = true;
            $contract['basis'] = match ($formula) {
                '按次'=>'call', '生成张数'=>'image', '生成秒数'=>'output', '输入素材秒数'=>'input',
                '生成秒数 + 参考视频秒数'=>'output_plus_video',
                '生成秒数 + （参考视频秒数与生成秒数取较大值）'=>'output_plus_max_video',
                '百万像素'=>'megapixel', default=>'',
            };
            if (preg_match('/^(生成张数|生成秒数 \+ 参考视频秒数) \+ (?:超过(\d+)的)?参考图片张数×(\d+)\/(\d+)$/u', $formula, $m) && (int)$m[4] > 0) {
                $contract += ['free_images'=>(int)$m[2], 'image_numerator'=>(int)$m[3], 'image_denominator'=>(int)$m[4]];
                $contract['basis'] = $m[1] === '生成张数' ? 'image' : 'output_plus_video';
            }
        } else {
            $contract['basis'] = match ($unit) {
                'per_call','count'=>'call', 'image','per_image'=>'image',
                'output_second','second','seconds','sec'=>'output', 'input_second'=>'input', default=>'',
            };
        }
        if ($contract['basis'] === '') throw new InvalidArgumentException('模型计费数量规则缺失，请同步并核验模型价格');
        return $contract;
    }

    public static function isExplicit(array $contract): bool
    {
        return ($contract['source'] ?? '') === 'structured' || ($contract['summary'] ?? '') !== '';
    }

    public static function hasDeclaration(array $sku): bool
    {
        $source = MarketGenerationSchema::array($sku['source_payload'] ?? []);
        return array_key_exists('meter_contract', $source) || array_key_exists('meter_contract', $sku)
            || trim((string)($source['meter_summary'] ?? $sku['meter_summary'] ?? '')) !== '';
    }

    /** Adapters without shared meter settlement must reject new declarations. */
    public static function assertLegacyPricing(array $sku): void
    {
        $source = MarketGenerationSchema::array($sku['source_payload'] ?? []);
        if (array_key_exists('meter_contract', $source) || array_key_exists('meter_contract', $sku)) {
            throw new InvalidArgumentException('该应用尚未接入结构化计费，请核验应用计费适配');
        }
    }

    private static function structured($value, string $summary, string $unit): array
    {
        $invalid = static fn() => new InvalidArgumentException('模型结构化计费规则无效或尚未适配，请同步并核验模型价格');
        // Version 2 is a trusted SKU-wide hard bound, not a browser budget or
        // an estimate inferred from resolution, seconds or a model name.
        if (is_array($value) && ($value['version'] ?? null) === 2) {
            if (array_diff(array_keys($value), ['version','basis','rounding','maximum_quantity','usage_path']) !== []
                || ($value['basis'] ?? null) !== 'token' || !str_contains($unit, 'token')
                || !in_array($value['rounding'] ?? null, ['ceil','ceil_2'], true)
                || !is_int($value['maximum_quantity'] ?? null) || $value['maximum_quantity'] < 1 || $value['maximum_quantity'] > 1000000000000
                || !in_array($value['usage_path'] ?? null, ['usage.total_tokens','data.usage.total_tokens','result.usage.total_tokens','data.result.usage.total_tokens'], true)) throw $invalid();
            return ['version'=>2,'source'=>'structured','summary'=>$summary,'unit'=>$unit,'basis'=>'token',
                'round_up'=>true,'rounding_scale'=>$value['rounding']==='ceil_2'?2:0,'maximum_quantity'=>$value['maximum_quantity'],'usage_path'=>$value['usage_path']];
        }
        if (!is_array($value) || array_diff(array_keys($value), ['version','basis','rounding','reference_images']) !== []
            || ($value['version'] ?? null) !== 1
            || !in_array($value['basis'] ?? null, ['call','image','output','input','output_plus_video','output_plus_max_video','megapixel'], true)
            || !in_array($value['rounding'] ?? null, ['ceil','half_up_6'], true)) throw $invalid();
        $contract = ['version'=>1, 'source'=>'structured', 'summary'=>$summary, 'unit'=>$unit,
            'basis'=>$value['basis'], 'round_up'=>$value['rounding'] === 'ceil'];
        if (array_key_exists('reference_images', $value)) {
            $term = $value['reference_images'];
            if (!in_array($value['basis'], ['image','output_plus_video'], true) || !is_array($term)
                || array_diff(array_keys($term), ['free','numerator','denominator']) !== []
                || count($term) !== 3) throw $invalid();
            foreach (['free','numerator','denominator'] as $field) {
                if (!is_int($term[$field] ?? null) || $term[$field] < ($field === 'denominator' ? 1 : 0)
                    || $term[$field] > 1000000) throw $invalid();
            }
            $contract += ['free_images'=>$term['free'], 'image_numerator'=>$term['numerator'], 'image_denominator'=>$term['denominator']];
        }
        return $contract;
    }

    public static function tokenContract(array $sku): array
    {
        if (!self::hasDeclaration($sku)) throw new InvalidArgumentException('该模型缺少提交前的 Token 费用上限，暂不能报价或提交');
        $contract=self::contract($sku);
        $size=$sku['usage_unit_size'] ?? null;
        if (!self::boundedToken($contract) || !is_numeric($size) || !is_finite((float)$size) || (float)$size<1)
            throw new InvalidArgumentException('该模型缺少有效的 Token 费用上限或计费单位，暂不能报价或提交');
        return $contract;
    }

    public static function boundedToken(array $contract): bool
    {
        return ($contract['version'] ?? null)===2 && ($contract['basis'] ?? null)==='token'
            && is_int($contract['maximum_quantity'] ?? null) && $contract['maximum_quantity']>0;
    }

    public static function context(array $contract, array $native, ?callable $measure = null, ?callable $visualProbe = null, array $declaredKinds = []): array
    {
        $media=MarketGenerationMedia::inputs($native,$visualProbe,$declaredKinds);$images=$media['image'];$video=$media['video'];
        // Count submitted slots, including repeated URLs; canonical native input has no UI aliases.
        $context = ['reference_images'=>count($images), 'reference_video_seconds'=>0];
        if (in_array($contract['basis'], ['output_plus_video','output_plus_max_video'], true) && $video !== []) {
            $seconds=$measure!==null?$measure(['video_urls'=>$video]):MarketGenerationMedia::seconds(['video_urls'=>$video]);
            if((!is_int($seconds)&&!is_float($seconds))||!is_finite((float)$seconds)||$seconds<=0)throw new InvalidArgumentException('参考视频实测时长无效');
            $context['reference_video_seconds']=(float)$seconds;
        }
        if ($contract['basis'] === 'megapixel') {
            // Resolution labels and browser metadata do not prove billed pixel dimensions.
            throw new InvalidArgumentException('该模型按实际像素计费，当前缺少可核验的像素报价，请选择按张计费规格');
        }
        return $context;
    }

    public static function price(float $price, float $quantity, array $snapshot): float
    {
        $meter = $snapshot['billing_meter'] ?? null;
        if ($meter && self::boundedToken($meter) && (!is_finite($quantity) || $quantity<1 || floor($quantity)!==$quantity || $quantity>$meter['maximum_quantity']))
            throw new InvalidArgumentException('Token 计费数量超过合同上限或无效');
        $size = max(1, (float)($snapshot['usage_unit_size'] ?? 1));
        if (!$meter) return round($price * $quantity / $size, 6); // Historical snapshot semantics stay immutable.
        $context = (array)($snapshot['meter_context'] ?? []);
        $basis = $meter['basis'];
        $base = $basis === 'call' ? 1.0 : $quantity;
        if ($basis === 'output_plus_video') $base += (float)($context['reference_video_seconds'] ?? 0);
        if ($basis === 'output_plus_max_video') $base += max($quantity, (float)($context['reference_video_seconds'] ?? 0));
        $unitPrice = number_format($price, 6, '.', '');
        $amount = bcmul($unitPrice, number_format($base, 9, '.', ''), 12);
        if (isset($meter['image_denominator'])) {
            $count = max(0, (int)($context['reference_images'] ?? 0) - (int)$meter['free_images']);
            $extra = bcdiv(bcmul($unitPrice, (string)($count * $meter['image_numerator']), 12), (string)$meter['image_denominator'], 12);
            $amount = bcadd($amount, $extra, 12);
        }
        $amount = bcdiv($amount, number_format($size, 6, '.', ''), 12);
        if ($meter['round_up']) {
            // New Token contracts explicitly round at the account precision
            // or to whole points. Never reserve a sub-cent amount that the
            // two-decimal point account would round down to zero.
            $scale=$meter['rounding_scale'] ?? 0;
            $integer = bcadd($amount, '0', $scale);
            return (float)(bccomp($amount, $integer, 12) > 0 ? bcadd($integer, $scale===2?'0.01':'1', $scale) : $integer);
        }
        return (float)bcadd($amount, '0.0000005', 6);
    }
}
