<?php
namespace app\common\service\open_platform;
/** A commercial override needs an explicit unit conversion, never a renamed supplier unit. */
final class OpenSkuConsistency
{
    public static function assert(array $sku): void
    {
        $source=OpenStore::json($sku['source_payload']??[]);$unit=$source['usage_unit']??null;
        if(is_string($unit)&&$unit!==''&&$unit!==($sku['usage_unit']??''))throw new OpenException('pricing_spec_mismatch','商城计费单位与供应商规格不一致，须先核对价格规则',503);
        $size=$source['usage_unit_size']??null;
        if(is_numeric($size)&&(float)$size>0&&abs((float)$size-(float)($sku['usage_unit_size']??0))>0.000001)throw new OpenException('pricing_spec_mismatch','商城计费单位数量与供应商规格不一致，须先核对价格规则',503);
    }
}
