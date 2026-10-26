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
        $contract = ['version'=>1, 'summary'=>$summary, 'unit'=>$unit, 'round_up'=>false, 'basis'=>''];
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

    public static function context(array $contract, array $native): array
    {
        $images = [];
        $video = [];
        $walk = static function ($value, string $kind = '') use (&$walk, &$images, &$video): void {
            if (is_array($value)) {
                foreach ($value as $key=>$child) {
                    $next = is_int($key) ? $kind : (preg_match('/video|clip/i',(string)$key) ? 'video' : (preg_match('/image|frame/i',(string)$key) ? 'image' : $kind));
                    $walk($child, $next);
                }
            } elseif (is_string($value) && preg_match('~^https?://~i', $value)) {
                if ($kind === 'image') $images[] = $value;
                if ($kind === 'video') $video[] = $value;
            }
        };
        $walk($native);
        $context = ['reference_images'=>count(array_unique($images)), 'reference_video_seconds'=>0];
        if (in_array($contract['basis'], ['output_plus_video','output_plus_max_video'], true) && $video !== []) {
            $context['reference_video_seconds'] = MarketGenerationMedia::seconds(['video_urls'=>array_values(array_unique($video))]);
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
            $integer = bcadd($amount, '0', 0);
            return (float)(bccomp($amount, $integer, 12) > 0 ? bcadd($integer, '1', 0) : $integer);
        }
        return (float)bcadd($amount, '0.0000005', 6);
    }
}
