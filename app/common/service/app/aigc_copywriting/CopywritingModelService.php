<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_copywriting;
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
use app\common\service\power\TextModelExecutionPolicy;
final class CopywritingModelService
{
    public static function publicModel(array $m): array
    {
        return array_intersect_key($m,array_flip(['id','product_id','name','description','display_icon','channel_source','channel_source_label','model_code','tenant_input_unit_price','tenant_output_unit_price','billing_unit','billing_unit_size','context_window','max_output_tokens']));
    }
    public static function options(int $tenantId,array $document=[]): array
    {
        $allowed=array_map('strval',$document['model']['allowed_model_ids']??[]);
        return array_values(array_map([self::class,'publicModel'],array_filter(Runtime::textOptions($tenantId),fn($m)=>!$allowed||in_array((string)$m['id'],$allowed,true))));
    }
    public static function resolve(int $tenantId,array $document,$selection): array
    {
        if(!is_scalar($selection)||trim((string)$selection)==='')throw new \InvalidArgumentException('请选择文本模型');
        $model=Runtime::resolveModel($tenantId,(string)$selection);
        $allowed=array_map('strval',$document['model']['allowed_model_ids']??[]);
        if($allowed&&!in_array((string)$model['id'],$allowed,true))throw new \InvalidArgumentException('该功能未开放所选模型');
        return $model;
    }
}
