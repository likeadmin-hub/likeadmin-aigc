<?php
namespace app\common\service\open_platform;

/** LikeAdmin protocol envelope uses local retail amounts; business output is opaque. */
final class OpenMarketResponse
{
    public const ADAPTER='likeadmin_retail_v1';
    private const OPAQUE_PATHS=['choices','output','content','data.content','result.content','data.result.content'];
    public static function financialPath(string $path): bool
    {
        $name=substr($path,(int)strrpos('.'.$path,'.'));$name=preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$name);
        return (bool)preg_match('/(?:^|_)(?:points?|price|cost|balance|amount)(?:$|_)/i',$name)||in_array($name,['actual_tenant','budget_tenant'],true);
    }
    public static function retailPath(string $path): bool
    {
        foreach(['','data.','data.result.'] as $prefix)if(in_array($path,[$prefix.'frozen_points',$prefix.'actual_points',$prefix.'points_cost'],true))return true;
        foreach(['usage.','data.usage.','data.result.usage.'] as $prefix)if(in_array($path,[$prefix.'points_cost',$prefix.'actual_points',$prefix.'points'],true))return true;
        return false;
    }
    private static function opaque(string $path): bool{return in_array(rtrim($path,'.'),self::OPAQUE_PATHS,true);}
    /** Numeric retail currency permits decimals; numeric-string contracts retain string type. */
    public static function retailSchemas(array $responses): array
    {
        if(!self::schemaSupported($responses))return $responses;
        foreach($responses as &$response)if(isset($response['content']['application/json']['schema'])){
            $schema=$response['content']['application/json']['schema'];$budget=5000;
            $response['content']['application/json']['schema']=self::numericNode($schema,$schema,'',0,$budget);
        }unset($response);return $responses;
    }
    private static function numericNode(array $schema,array $root,string $path,int $depth,int &$budget): array
    {
        if(self::opaque($path)||$depth>32||--$budget<0)return $schema;
        if(isset($schema['$ref'])){
            $node=$root;foreach(explode('/',substr($schema['$ref'],2)) as $part)$node=$node[str_replace(['~1','~0'],['/','~'],$part)];
            unset($schema['$ref']);
            // A ref and its siblings are conjunctive constraints. Normalize both
            // at this use site before combining, so no supplier integer remains
            // on a retail amount and no sibling erases business properties.
            $combined=['allOf'=>[self::numericNode($node,$root,$path,$depth+1,$budget),self::numericNode($schema,$root,$path,$depth+1,$budget)]];
            foreach(['$defs','definitions'] as $key)if(isset($schema[$key]))$combined[$key]=$schema[$key];
            return $combined;
        }
        if(self::retailPath(rtrim($path,'.'))&&isset($schema['type'])){
            if($schema['type']==='integer')$schema['type']='number';
            elseif(is_array($schema['type']))$schema['type']=array_values(array_unique(array_map(static fn($type)=>$type==='integer'?'number':$type,$schema['type'])));
        }
        foreach($schema['properties']??[] as $name=>$field)$schema['properties'][$name]=self::numericNode($field,$root,$path.$name.'.',$depth+1,$budget);
        foreach(['items','additionalProperties'] as $key)if(isset($schema[$key])&&is_array($schema[$key]))$schema[$key]=self::numericNode($schema[$key],$root,$path.'[].',$depth+1,$budget);
        foreach(['oneOf','anyOf','allOf'] as $union)foreach($schema[$union]??[] as $i=>$branch)$schema[$union][$i]=self::numericNode($branch,$root,$path,$depth+1,$budget);
        return $schema;
    }
    public static function schemaSupported(array $responses): bool
    {
        foreach($responses as $response){$schema=$response['content']['application/json']['schema']??[];$budget=5000;if(!is_array($schema)||!self::schemaNode($schema,$schema,'',[],0,$budget))return false;}
        return true;
    }
    private static function schemaNode(array $schema,array $root,string $path,array $refs,int $depth,int &$budget): bool
    {
        if(self::opaque($path))return true;
        if($depth>32||--$budget<0)return false;
        if(self::retailPath(rtrim($path,'.'))){
            foreach((array)($schema['type']??[]) as $type)if(!in_array($type,['integer','number','string'],true))return false;
            foreach(['properties','items','patternProperties'] as $key)if(isset($schema[$key]))return false;
        }
        if(isset($schema['$ref'])){
            $ref=$schema['$ref'];if(!is_string($ref)||!str_starts_with($ref,'#/')||in_array($ref,$refs,true))return false;
            $node=$root;foreach(explode('/',substr($ref,2)) as $part){$part=str_replace(['~1','~0'],['/','~'],$part);if(!is_array($node)||!array_key_exists($part,$node))return false;$node=$node[$part];}
            if(!is_array($node)||!self::schemaNode($node,$root,$path,array_merge($refs,[$ref]),$depth+1,$budget))return false;
        }
        foreach($schema['properties']??[] as $name=>$field){$full=$path.$name;if(!self::opaque($full)&&(OpenSchemaNormalizer::sensitiveName((string)$name)||self::financialPath($full)&&!self::retailPath($full)))return false;if(!is_array($field)||!self::schemaNode($field,$root,$full.'.',$refs,$depth+1,$budget))return false;}
        foreach(['items','additionalProperties'] as $key)if(isset($schema[$key])&&is_array($schema[$key])&&!self::schemaNode($schema[$key],$root,$path.'[].',$refs,$depth+1,$budget))return false;
        foreach(['oneOf','anyOf','allOf'] as $union)foreach($schema[$union]??[] as $branch)if(!is_array($branch)||!self::schemaNode($branch,$root,$path,$refs,$depth+1,$budget))return false;
        foreach($schema['patternProperties']??[] as $branch)if(!is_array($branch)||!self::schemaNode($branch,$root,$path.'[].',$refs,$depth+1,$budget))return false;
        return true;
    }
    /** Actual envelopes are checked independently of the documented schema. */
    public static function bodySupported(string $body): bool
    {
        $value=json_decode($body,false,64);if(json_last_error()!==JSON_ERROR_NONE||!($value instanceof \stdClass))return false;
        $budget=5000;return self::bodyNode($value,'',0,$budget);
    }
    private static function bodyNode(mixed $value,string $path,int $depth,int &$budget): bool
    {
        if(self::opaque($path))return true;if($depth>32||--$budget<0)return false;
        if($value instanceof \stdClass)foreach(get_object_vars($value) as $name=>$child){$full=$path.$name;if(self::removablePath($full))continue;if(!self::opaque($full)){if(self::financialPath($full)&&!self::retailPath($full))return false;if(self::retailPath($full)&&!self::moneyValue($child))return false;if(OpenSchemaNormalizer::sensitiveName($name))return false;}if(!self::bodyNode($child,$full.'.',$depth+1,$budget))return false;}
        elseif(is_array($value))foreach($value as $child)if(!self::bodyNode($child,$path.'[].',$depth+1,$budget))return false;
        return true;
    }
    private static function moneyValue(mixed $value): bool
    {
        return (is_int($value)||is_float($value)||is_string($value))&&is_numeric($value)&&is_finite((float)$value);
    }
    private static function removablePath(string $path): bool
    {
        foreach(['','data.','data.result.'] as $prefix)foreach(['api_key','api_secret','credentials','provider_config','source_blob','price_snapshot','pricing_snapshot','tenant_cost','platform_sale_points','upstream_price'] as $name)if($path===$prefix.$name)return true;
        return false;
    }
    public static function project(string $body,array $amounts): string
    {
        $value=json_decode($body);if(is_array($value)&&json_last_error()===JSON_ERROR_NONE)throw new OpenException('response_adapter_required','上游响应不是已适配的对象协议',502);
        if(!($value instanceof \stdClass)||json_last_error()!==JSON_ERROR_NONE)return $body;
        if(!self::bodySupported($body))throw new OpenException('response_adapter_required','上游响应包含尚未适配的协议字段，已保留原请求等待核对',502);
        $changed=false;
        foreach(['','data.','data.result.'] as $prefix){
            foreach(['frozen_points'=>'reserved','actual_points'=>'charged','points_cost'=>'charged'] as $name=>$amount)self::replace($value,$prefix.$name,(float)($amounts[$amount]??0),$changed);
        }
        foreach(['usage.','data.usage.','data.result.usage.'] as $prefix)foreach(['points_cost','actual_points','points'] as $name)self::replace($value,$prefix.$name,(float)($amounts['charged']??0),$changed);
        // Provider routing/configuration belongs to the server. Do not traverse
        // generated result text, tool arguments, or arbitrary user JSON output.
        foreach(['','data.','data.result.'] as $prefix)foreach(['api_key','api_secret','credentials','provider_config','source_blob','price_snapshot','pricing_snapshot','tenant_cost','platform_sale_points','upstream_price'] as $name)self::remove($value,$prefix.$name,$changed);
        return $changed?OpenStore::encode($value):$body;
    }
    private static function replace(\stdClass $root,string $path,float $value,bool &$changed): void
    {
        $parts=explode('.',$path);$name=array_pop($parts);$node=$root;foreach($parts as $part){if(!isset($node->$part)||!($node->$part instanceof \stdClass))return;$node=$node->$part;}
        if(property_exists($node,$name)){if(!is_finite($value))throw new OpenException('response_adapter_required','本地金额不是有限数值',502);$value=round($value,2);$node->$name=is_string($node->$name)?number_format($value,2,'.',''):$value;$changed=true;}
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
