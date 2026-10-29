<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** A small read-only path grammar for encoded material values. No expressions, filters or payload writes. */
final class MarketGenerationMaterialPath
{
    private static function tokens(string $path): array
    {
        if (strlen($path)>256 || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.(?:[a-zA-Z_][a-zA-Z0-9_]*|\*))*$/D',$path)) throw new InvalidArgumentException('素材路径格式尚未支持');
        $tokens=explode('.',$path);if(count($tokens)>16)throw new InvalidArgumentException('素材路径过深');return $tokens;
    }

    public static function values(array $body,string $path): array
    {
        $tokens=self::tokens($path);$walk=static function($value,int $i)use(&$walk,$tokens):array{
            if($i===count($tokens))return [$value];
            if(!is_array($value))return [];
            if($tokens[$i]==='*'){
                if(!array_is_list($value))return [];$out=[];foreach($value as $child)$out=array_merge($out,$walk($child,$i+1));return $out;
            }
            return array_key_exists($tokens[$i],$value)?$walk($value[$tokens[$i]],$i+1):[];
        };
        return $walk($body,0);
    }

    public static function count(array $body,array $field): int
    {
        $path=(string)$field['field'];
        if(!str_contains($path,'.')){$value=$body[$path]??null;return is_array($value)?count($value):(($value!==null&&$value!=='')?1:0);}
        $count=0;foreach(self::values($body,$path)as$value){
            if(is_array($value)&&array_is_list($value))$count+=count(array_filter($value,static fn($v)=>$v!==null&&$v!==''));
            elseif($value!==null&&$value!=='')$count++;
        }return $count;
    }

    public static function definitions(array $schema,string $path): array
    {
        $tokens=self::tokens($path);
        if(!isset($schema['properties'])&&($schema['type']??'')!=='object')$schema=['type'=>'object','properties'=>MarketGenerationSchema::properties($schema)];
        $walk=static function(array $node,int $i)use(&$walk,$tokens):array{
            if($i===count($tokens))return [$node];$out=[];
            foreach(['oneOf','anyOf','allOf']as$group)foreach((array)($node[$group]??[])as$branch)if(is_array($branch))$out=array_merge($out,$walk($branch,$i));
            if($tokens[$i]==='*'){
                if(($node['type']??'')==='array'&&is_array($node['items']??null))$out=array_merge($out,$walk($node['items'],$i+1));
            }elseif(is_array($node['properties'][$tokens[$i]]??null))$out=array_merge($out,$walk($node['properties'][$tokens[$i]],$i+1));
            return $out;
        };
        return $walk($schema,0);
    }

    public static function assertNestedField(array $contract,array $mode,array $field): void
    {
        if(($contract['generation_type']??'')!=='image' || !in_array($contract['generation_protocol']??'',['qwen_image_v1','image_messages_v1'],true)
            || ($field['kind']??'')!=='image' || isset($field['item_match'])) throw new InvalidArgumentException('该协议尚未实现此嵌套素材的共享计数与角色校验');
        $definitions=self::definitions($mode['schema'],$field['field']);
        if($definitions===[])throw new InvalidArgumentException('素材路径没有完整参数结构');
        foreach($definitions as$d)if(!(($d['type']??'')==='string'||(($d['type']??'')==='array'&&($d['items']['type']??'')==='string')))
            throw new InvalidArgumentException('嵌套素材路径必须指向字符串或字符串数组');
    }
}
