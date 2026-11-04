<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\power\MarketImageModelRuntimeService as R;
use InvalidArgumentException;
final class MicroVideoFrameCatalog
{
    public static function models(int $tenant, int $minimumReferences = 2): array
    {
        $out=[];
        foreach(R::options($tenant) as $m){
            $limit = !empty($m['supports_reference_images']) ? (int)$m['max_reference_images'] : 0;
            if (empty($m['available']) || $limit < $minimumReferences) continue;
            $specs=[];
            foreach($m['skus'] as $s){
                if(($s['settlement_mode']??'')!=='reserved')continue;
                $counts = self::referenceCounts($m, $s, $limit);
                if (!$counts || ($minimumReferences > 0 && max($counts) < $minimumReferences)) continue;
                foreach($s['ratio_options'] as $ratio){
                    if(!is_string($ratio)||$ratio===''||in_array(strtolower($ratio),['adaptive','auto','original'],true))continue;
                    // The shared catalogue batches live SKU availability and tenant retail prices.
                    // This is advisory display data; exact quote/reserve still validate live selections.
                    foreach (($s['quality_options'] ?? []) ?: [$s['quality']] as $quality) {
                        $specs[] = ['quality'=>$quality, 'ratio'=>$ratio, 'market_sku_id'=>(int)$s['market_sku_id'],
                            'reference_counts'=>$counts, 'user_charge_points'=>round((float)$s['tenant_unit_price'], 4)];
                    }
                }
            }
            if($specs)$out[]=['channel_source'=>(string)($m['channel_source']??'unknown'),'channel_source_label'=>(string)($m['channel_source_label']??'渠道未标注'),'id'=>$m['id'],'label'=>$m['name'],'max_reference_images'=>$limit,'specs'=>$specs];
        }
        return $out;
    }
    private static function referenceCounts(array $model, array $sku, int $limit): array
    {
        $limit = min(15, $limit);
        $modes = $model['material_modes'] ?? [];
        if (!$modes) return range(0, $limit);
        $counts = [];
        $locked = $sku['locked_params']['generation_type'] ?? '';
        foreach ($modes as $mode) {
            if ($locked !== '' && $locked !== $mode['native_mode']) continue;
            $min = $max = 0;
            foreach ($mode['fields'] as $field) {
                $required = max((int)($field['minimum'] ?? 0), empty($field['required']) ? 0 : 1);
                if ($field['kind'] !== 'image') {
                    if ($required) continue 2;
                    continue;
                }
                $min += $required;
                $max += (int)($field['maximum'] ?? $required);
            }
            $max = min($max, $limit);
            if ($min <= $max) $counts = array_merge($counts, range($min, $max));
        }
        return array_values(array_unique($counts));
    }

    public static function compatible(array $models, array $counts): array
    {
        $out = [];
        foreach ($models as $model) {
            $model['specs'] = array_values(array_filter($model['specs'], static fn($spec) =>
                !array_diff($counts, $spec['reference_counts'])));
            if ($model['specs']) $out[] = $model;
        }
        return $out;
    }

    public static function model(int $tenant,string $id): array{foreach(self::models($tenant) as $m)if($m['id']===$id)return $m;throw new InvalidArgumentException('首尾帧图片模型暂不可用');}
    public static function selection(array $model,string $quality,string $ratio): array{foreach($model['specs'] as $s)if($s['quality']===$quality&&$s['ratio']===$ratio)return ['model_id'=>$model['id'],'market_sku_id'=>$s['market_sku_id'],'quality'=>$quality,'ratio'=>$ratio];throw new InvalidArgumentException('图片模型不支持当前画质或视频比例');}
    public static function quote(int $tenant,array $selection,array $request=[],bool $hasFutureReference=false): array
    {
        // The real worker prompt wins; a read-only catalogue quote may omit it.
        // Return only the owned selection so this fallback is never frozen for execution.
        $q=R::quote($tenant,array_replace(['prompt'=>'Image boundary pricing'],$request,$selection),1);
        if(($q['settlement_mode']??'reserved')!=='reserved')throw new InvalidArgumentException('图片规格不支持预报价');
        if($hasFutureReference&&isset($q['market_snapshot']['billing_meter']['image_denominator']))throw new InvalidArgumentException('上一张连续性锚图尚未生成，当前模型按参考图计费，请先逐张生成并重新估价');
        return ['selection'=>$selection,'market_sku_id'=>(int)$selection['market_sku_id'],'tenant_cost_points'=>(float)$q['tenant_cost_points'],'user_charge_points'=>(float)$q['user_charge_points']];
    }
}
