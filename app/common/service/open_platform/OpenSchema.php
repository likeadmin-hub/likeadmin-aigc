<?php
namespace app\common\service\open_platform;
/** Supported JSON Schema subset is shared by admission, examples and the debugger. */
final class OpenSchema
{
    public static function validate(mixed $value,array $schema,string $path='$',int $depth=0): void
    {
        if($depth>32)throw new OpenException('invalid_request','参数嵌套过深');
        $type=$schema['type']??null;$valid=match($type){'object'=>is_array($value)&&($value===[]||!array_is_list($value)),'array'=>is_array($value)&&array_is_list($value),'string'=>is_string($value),'integer'=>is_int($value),'number'=>is_int($value)||is_float($value),'boolean'=>is_bool($value),'null'=>$value===null,null=>true,default=>false};
        if(!$valid)throw new OpenException('invalid_request',$path.' 类型不正确');
        if(isset($schema['enum'])&&!in_array($value,$schema['enum'],true))throw new OpenException('invalid_request',$path.' 不在允许值中');
        if(is_string($value)){if(isset($schema['maxLength'])&&mb_strlen($value)>(int)$schema['maxLength'])throw new OpenException('invalid_request',$path.' 过长');if(isset($schema['minLength'])&&mb_strlen($value)<(int)$schema['minLength'])throw new OpenException('invalid_request',$path.' 过短');}
        if(in_array($type,['number','integer'],true)){if(isset($schema['minimum'])&&$value<$schema['minimum']||isset($schema['maximum'])&&$value>$schema['maximum'])throw new OpenException('invalid_request',$path.' 超出范围');}
        if($type==='object'){foreach($schema['required']??[] as $key)if(!array_key_exists($key,$value))throw new OpenException('invalid_request',$path.'.'.$key.' 为必填参数');foreach($value as $key=>$item){if(isset($schema['properties'][$key]))self::validate($item,$schema['properties'][$key],$path.'.'.$key,$depth+1);elseif(($schema['additionalProperties']??true)===false)throw new OpenException('invalid_request',$path.'.'.$key.' 未定义');}}
        if($type==='array'){if(count($value)>(int)($schema['maxItems']??10000)||count($value)<(int)($schema['minItems']??0))throw new OpenException('invalid_request',$path.' 数量超限');foreach($value as $i=>$item)self::validate($item,$schema['items']??[],$path.'['.$i.']',$depth+1);}
    }
    public static function get(array $data,string $path): mixed { $value=$data;foreach(explode('.',$path) as $key){if(!is_array($value)||!array_key_exists($key,$value))return null;$value=$value[$key];}return $value; }
}
