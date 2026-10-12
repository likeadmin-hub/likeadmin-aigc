<?php
namespace app\common\service\open_platform;
/** Schema validation and query normalization share the published contract. */
final class OpenSchema
{
    public static function validate(mixed $value,array $schema,string $path='$',int $depth=0,?array $root=null,bool $strictObjects=false): void
    {
        if($depth>32)throw new OpenException('invalid_request','参数嵌套过深');$root??=$schema;
        if(isset($schema['$ref'])){self::validate($value,self::reference($root,$schema['$ref']),$path,$depth+1,$root,$strictObjects);unset($schema['$ref']);}
        foreach($schema['allOf']??[] as $branch)self::validate($value,$branch,$path,$depth+1,$root,$strictObjects);
        foreach(['oneOf','anyOf'] as $union)if(isset($schema[$union])){$matches=0;foreach($schema[$union] as $branch){try{self::validate($value,$branch,$path,$depth+1,$root,$strictObjects);$matches++;}catch(OpenException){}}if($matches===0||$union==='oneOf'&&$matches!==1)throw new OpenException('invalid_request',$path.' 不符合声明的参数组合');}
        if(isset($schema['not'])){try{self::validate($value,$schema['not'],$path,$depth+1,$root,$strictObjects);$matches=true;}catch(OpenException){$matches=false;}if($matches)throw new OpenException('invalid_request',$path.' 使用了禁止的参数组合');}
        $types=isset($schema['type'])?(array)$schema['type']:[];if($types&&!empty($schema['nullable']))$types[]='null';
        if($types&&!array_filter($types,fn($t)=>self::isType($value,$t,$strictObjects)))throw new OpenException('invalid_request',$path.' 类型不正确');
        if(isset($schema['enum'])&&!in_array($value,$schema['enum'],true)||array_key_exists('const',$schema)&&$value!==$schema['const'])throw new OpenException('invalid_request',$path.' 不在允许值中');
        if(is_string($value)){
            $length=mb_strlen($value);if(isset($schema['maxLength'])&&$length>$schema['maxLength']||isset($schema['minLength'])&&$length<$schema['minLength'])throw new OpenException('invalid_request',$path.' 长度超限');
            if(isset($schema['pattern'])){$pattern='~'.str_replace('~','\\~',$schema['pattern']).'~u';if(@preg_match($pattern,$value)!==1)throw new OpenException('invalid_request',$path.' 格式不正确');}
        }
        if(is_int($value)||is_float($value)){
            foreach(['minimum'=>fn($v,$n)=>$v<$n,'maximum'=>fn($v,$n)=>$v>$n,'exclusiveMinimum'=>fn($v,$n)=>$v<=$n,'exclusiveMaximum'=>fn($v,$n)=>$v>=$n] as $field=>$outside)if(isset($schema[$field])&&is_numeric($schema[$field])&&$outside($value,$schema[$field]))throw new OpenException('invalid_request',$path.' 超出范围');
            foreach(['Minimum'=>'minimum','Maximum'=>'maximum'] as $suffix=>$bound)if(($schema['exclusive'.$suffix]??null)===true&&isset($schema[$bound])&&($suffix==='Minimum'?$value<=$schema[$bound]:$value>=$schema[$bound]))throw new OpenException('invalid_request',$path.' 超出范围');
            if(isset($schema['multipleOf'])&&((float)$schema['multipleOf']<=0||abs($value/$schema['multipleOf']-round($value/$schema['multipleOf']))>0.0000001))throw new OpenException('invalid_request',$path.' 不符合步长');
        }
        $isObject=$value instanceof \stdClass;if($isObject)$value=get_object_vars($value);
        if(is_array($value)&&($isObject||$value===[]||!array_is_list($value))&&(in_array('object',$types,true)||isset($schema['properties'])||isset($schema['required'])||isset($schema['additionalProperties']))){
            foreach($schema['required']??[] as $key)if(!array_key_exists($key,$value))throw new OpenException('invalid_request',$path.'.'.$key.' 为必填参数');
            if(isset($schema['minProperties'])&&count($value)<$schema['minProperties']||isset($schema['maxProperties'])&&count($value)>$schema['maxProperties'])throw new OpenException('invalid_request',$path.' 字段数量超限');
            foreach($value as $key=>$item){$field=$schema['properties'][$key]??null;$matched=$field!==null;if($field)self::validate($item,$field,$path.'.'.$key,$depth+1,$root,$strictObjects);
                foreach($schema['patternProperties']??[] as $pattern=>$rule)if(@preg_match('~'.str_replace('~','\\~',$pattern).'~u',(string)$key)===1){self::validate($item,$rule,$path.'.'.$key,$depth+1,$root,$strictObjects);$matched=true;}
                if(!$matched){$additional=$schema['additionalProperties']??true;if($additional===false)throw new OpenException('invalid_request',$path.'.'.$key.' 未定义');if(is_array($additional))self::validate($item,$additional,$path.'.'.$key,$depth+1,$root,$strictObjects);}
            }
        }
        if(!$isObject&&is_array($value)&&array_is_list($value)&&(in_array('array',$types,true)||isset($schema['items']))){
            if(count($value)>(int)($schema['maxItems']??10000)||count($value)<(int)($schema['minItems']??0))throw new OpenException('invalid_request',$path.' 数量超限');
            if(!empty($schema['uniqueItems'])){$seen=[];foreach($value as $item){$hash=OpenPolicy::fingerprint(['value'=>$item]);if(isset($seen[$hash]))throw new OpenException('invalid_request',$path.' 包含重复项');$seen[$hash]=true;}}
            foreach($value as $i=>$item)self::validate($item,$schema['items']??[],$path.'['.$i.']',$depth+1,$root,$strictObjects);
        }
    }
    public static function validateRequest(array $params,array $schema,bool $json): void
    {
        self::validate($json?(object)$params:$params,$schema,'$',0,null,$json);
    }
    private static function isType(mixed $value,string $type,bool $strictObjects): bool
    {
        return match($type){'object'=>$value instanceof \stdClass||!$strictObjects&&is_array($value)&&($value===[]||!array_is_list($value)),'array'=>is_array($value)&&array_is_list($value),'string'=>is_string($value),'integer'=>is_int($value),'number'=>is_int($value)||is_float($value),'boolean'=>is_bool($value),'null'=>$value===null,default=>false};
    }
    private static function reference(array $root,string $ref): array
    {
        if(!str_starts_with($ref,'#/'))throw new OpenException('invalid_contract','只支持契约内部 Schema 引用');$node=$root;
        foreach(explode('/',substr($ref,2)) as $part){$key=str_replace(['~1','~0'],['/','~'],$part);if(!is_array($node)||!array_key_exists($key,$node))throw new OpenException('invalid_contract','Schema 引用不存在');$node=$node[$key];}
        if(!is_array($node))throw new OpenException('invalid_contract','Schema 引用无效');return $node;
    }
    public static function query(array $params,array $schema,int $depth=0,?array $root=null): array
    {
        if($depth>32)throw new OpenException('invalid_request','参数嵌套过深');$root??=$schema;
        foreach($params as $name=>&$value){$field=[];
            // Resolve only local refs. No request can initiate a schema fetch.
            if(isset($schema['properties'][$name]))$field=$schema['properties'][$name];elseif(($schema['type']??null)==='array')$field=$schema['items']??[];else $field=[];
            if(isset($field['$ref']))$field=self::reference($root,$field['$ref'])+$field;
            if($value instanceof \stdClass){$value=(object)self::query((array)$value,$field,$depth+1,$root);continue;}
            if(is_array($value)){$value=self::query($value,$field,$depth+1,$root);continue;}
            $types=(array)($field['type']??[]);if(is_string($value)&&(in_array('array',$types,true)&&str_starts_with($value,'[')||in_array('object',$types,true)&&str_starts_with($value,'{'))){$decoded=json_decode($value,false,32);if(json_last_error()===JSON_ERROR_NONE&&(is_array($decoded)||$decoded instanceof \stdClass)){$typed=self::query((array)$decoded,$field,$depth+1,$root);$value=$decoded instanceof \stdClass?(object)$typed:$typed;}continue;}if(!is_string($value)||in_array('string',$types,true))continue;
            if(in_array('integer',$types,true)&&preg_match('/^-?(?:0|[1-9][0-9]*)$/D',$value)&&filter_var($value,FILTER_VALIDATE_INT)!==false)$value=(int)$value;
            elseif(in_array('number',$types,true)&&is_numeric($value)&&is_finite((float)$value))$value=(float)$value;
            elseif(in_array('boolean',$types,true)&&in_array($value,['true','false'],true))$value=$value==='true';
        }return $params;
    }
    public static function get(array $data,string $path): mixed { $value=$data;foreach(explode('.',$path) as $key){if($value instanceof \stdClass)$value=(array)$value;if(!is_array($value)||!array_key_exists($key,$value))return null;$value=$value[$key];}return $value; }
}
