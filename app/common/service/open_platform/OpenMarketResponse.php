<?php
namespace app\common\service\open_platform;

/** LikeAdmin protocol envelope uses local retail amounts; business output is opaque. */
final class OpenMarketResponse
{
    public const ADAPTER='likeadmin_retail_v1';
    public static function schemaSupported(array $responses): bool
    {
        $allowed=[];foreach(['','data.','data.result.'] as $prefix)foreach(['frozen_points','actual_points','points_cost'] as $name)$allowed[]=$prefix.$name;
        foreach(['usage.','data.usage.','data.result.usage.'] as $prefix)foreach(['points_cost','actual_points'] as $name)$allowed[]=$prefix.$name;
        $walk=static function(array $schema,string $path='')use(&$walk,$allowed): bool {
            foreach($schema['properties']??[] as $name=>$field){$full=$path.$name;if(in_array($name,['points','points_cost','actual_points','frozen_points'],true)&&!in_array($full,$allowed,true))return false;if(OpenSchemaNormalizer::sensitiveName($name)||preg_match('/tenant_(?:cost|price)|(?:platform|upstream)_(?:price|cost)/i',$name))return false;if(is_array($field)&&!$walk($field,$full.'.'))return false;}
            if(isset($schema['items'])&&!$walk($schema['items'],$path.'[].'))return false;
            foreach(['oneOf','anyOf','allOf'] as $union)foreach($schema[$union]??[] as $branch)if(!$walk($branch,$path))return false;
            return true;
        };
        foreach($responses as $response)if(!$walk($response['content']['application/json']['schema']??[]))return false;
        return true;
    }
    public static function project(string $body,array $amounts): string
    {
        $value=json_decode($body);if(!($value instanceof \stdClass)||json_last_error()!==JSON_ERROR_NONE)return $body;
        $changed=false;
        foreach(['','data.','data.result.'] as $prefix){
            foreach(['frozen_points'=>'reserved','actual_points'=>'charged','points_cost'=>'charged'] as $name=>$amount)self::replace($value,$prefix.$name,(float)($amounts[$amount]??0),$changed);
        }
        foreach(['usage.','data.usage.','data.result.usage.'] as $prefix)foreach(['points_cost','actual_points'] as $name)self::replace($value,$prefix.$name,(float)($amounts['charged']??0),$changed);
        // Provider routing/configuration belongs to the server. Do not traverse
        // generated result text, tool arguments, or arbitrary user JSON output.
        foreach(['','data.','data.result.'] as $prefix)foreach(['api_key','api_secret','credentials','provider_config','source_blob','price_snapshot','pricing_snapshot','tenant_cost','platform_sale_points','upstream_price'] as $name)self::remove($value,$prefix.$name,$changed);
        return $changed?OpenStore::encode($value):$body;
    }
    private static function replace(\stdClass $root,string $path,float $value,bool &$changed): void
    {
        $parts=explode('.',$path);$name=array_pop($parts);$node=$root;foreach($parts as $part){if(!isset($node->$part)||!($node->$part instanceof \stdClass))return;$node=$node->$part;}
        if(property_exists($node,$name)){$node->$name=$value;$changed=true;}
    }
    private static function remove(\stdClass $root,string $path,bool &$changed): void
    {
        $parts=explode('.',$path);$name=array_pop($parts);$node=$root;foreach($parts as $part){if(!isset($node->$part)||!($node->$part instanceof \stdClass))return;$node=$node->$part;}
        if(property_exists($node,$name)){unset($node->$name);$changed=true;}
    }
    public static function amounts(array $row): array
    {
        $charged=(float)$row['actual_user'];$reserved=(float)$row['budget_user'];
        // Terminal receipts have cleared live budgets; retain the admission
        // amount from the immutable consumption, without repricing old SKUs.
        $records=\think\facade\Db::name('ai_consumption_log')->where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'consume_no'=>$row['request_id']])->field('reserved_user_price')->select()->toArray();
        if($records)$reserved=array_sum(array_column($records,'reserved_user_price'));
        return ['charged'=>round($charged,2),'reserved'=>round($reserved,2)];
    }
}
