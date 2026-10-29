<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Read-only paths with finite, schema-bound sibling discriminator matches. No expressions or payload writes. */
final class MarketGenerationMaterialPath
{
    private static function tokens(string $path): array
    {
        if (strlen($path)>256 || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.(?:[a-zA-Z_][a-zA-Z0-9_]*|\*))*$/D',$path)) throw new InvalidArgumentException('素材路径格式尚未支持');
        $tokens=explode('.',$path);if(count($tokens)>16)throw new InvalidArgumentException('素材路径过深');return $tokens;
    }

    private static function selector(array $tokens,array $match): int
    {
        if($match===[])return -1;
        $indices=array_keys($tokens,'*',true);
        if($indices===[]||count($match)>8)throw new InvalidArgumentException('素材分类必须绑定数组项');
        foreach($match as$key=>$value)if(!is_string($key)||!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$key)||!is_string($value)||$value==='')
            throw new InvalidArgumentException('素材分类只允许固定字符串');
        return (int)end($indices);
    }

    public static function values(array $body,string $path,array $itemMatch=[]): array
    {
        $tokens=self::tokens($path);$matchAt=self::selector($tokens,$itemMatch);
        $walk=static function($value,int $i)use(&$walk,$tokens,$matchAt,$itemMatch):array{
            if($i===count($tokens))return [$value];
            if(!is_array($value))return [];
            if($tokens[$i]==='*'){
                if(!array_is_list($value))return [];$out=[];
                foreach($value as $child){
                    if($i===$matchAt){$matched=is_array($child);foreach($itemMatch as$key=>$expected)if(($child[$key]??null)!==$expected)$matched=false;if(!$matched)continue;}
                    $out=array_merge($out,$walk($child,$i+1));
                }return $out;
            }
            return array_key_exists($tokens[$i],$value)?$walk($value[$tokens[$i]],$i+1):[];
        };
        return $walk($body,0);
    }

    public static function count(array $body,array $field): int
    {
        $path=(string)$field['field'];
        if(!str_contains($path,'.')){$value=$body[$path]??null;return is_array($value)?count($value):(($value!==null&&$value!=='')?1:0);}
        $count=0;foreach(self::values($body,$path,(array)($field['item_match']??[]))as$value){
            if(is_array($value)&&array_is_list($value))$count+=count(array_filter($value,static fn($v)=>$v!==null&&$v!==''));
            elseif($value!==null&&$value!=='')$count++;
        }return $count;
    }

    private static function matchedItemSchemas(array $item,array $match): array
    {
        if(isset($item['allOf']))throw new InvalidArgumentException('素材分类的组合结构尚未支持');
        $branches=$item['oneOf']??$item['anyOf']??null;
        if($branches!==null){$base=$item;unset($base['oneOf'],$base['anyOf']);$out=[];
            foreach($branches as$branch){$merged=array_replace($base,$branch);$merged['properties']=array_replace($base['properties']??[],$branch['properties']??[]);
                $merged['required']=array_values(array_unique(array_merge((array)($base['required']??[]),(array)($branch['required']??[]))));
                $out=array_merge($out,self::matchedItemSchemas($merged,$match));}return $out;
        }
        foreach($match as$key=>$value){$d=$item['properties'][$key]??[];
            if(($d['type']??'')!=='string'||(!in_array($key,(array)($item['required']??[]),true)&&($d['required']??false)!==true))return [];
            if(array_key_exists('const',$d)){if($d['const']!==$value)return [];}
            elseif(!is_array($d['enum']??null)||!in_array($value,$d['enum'],true))return [];
        }return [$item];
    }

    public static function definitions(array $schema,string $path,array $itemMatch=[]): array
    {
        $tokens=self::tokens($path);$matchAt=self::selector($tokens,$itemMatch);
        if(!isset($schema['properties'])&&($schema['type']??'')!=='object')$schema=['type'=>'object','properties'=>MarketGenerationSchema::properties($schema)];
        $walk=static function(array $node,int $i)use(&$walk,$tokens,$matchAt,$itemMatch):array{
            if($i===count($tokens))return [$node];$out=[];
            foreach(['oneOf','anyOf','allOf']as$group)foreach((array)($node[$group]??[])as$branch)if(is_array($branch))$out=array_merge($out,$walk($branch,$i));
            if($tokens[$i]==='*'){
                if(($node['type']??'')==='array'&&is_array($node['items']??null)){
                    $items=$i===$matchAt?self::matchedItemSchemas($node['items'],$itemMatch):[$node['items']];
                    foreach($items as$item)$out=array_merge($out,$walk($item,$i+1));
                }
            }elseif(is_array($node['properties'][$tokens[$i]]??null))$out=array_merge($out,$walk($node['properties'][$tokens[$i]],$i+1));
            return $out;
        };
        return $walk($schema,0);
    }

    public static function assertNestedField(array $contract,array $mode,array $field): void
    {
        $protocol=$contract['generation_protocol']??'';$type=$contract['generation_type']??'';$kind=$field['kind']??'';
        $supported=$type==='image'&&in_array($protocol,['qwen_image_v1','image_messages_v1'],true)&&$kind==='image';
        $supported=$supported||($type==='video'&&in_array($protocol,['wan_video_media_v1','video_input_parameters_v1','h3_content_v1'],true)&&in_array($kind,['image','video','audio'],true));
        $supported=$supported||($type==='video'&&in_array($protocol,['wan_video_media_v1','video_input_parameters_v1'],true)&&in_array($kind,['file','link'],true));
        if(!$supported)throw new InvalidArgumentException('该协议尚未实现此嵌套素材的共享计数与角色校验');
        if($type==='video'&&str_contains($field['field'],'*')&&empty($field['item_match']))throw new InvalidArgumentException('混合视频素材必须声明分类字段');
        if($type==='video'&&str_contains($field['field'],'*')){
            $role=$field['role']??'reference';
            if(!in_array($role,['first','last','reference'],true)||($role!=='reference'&&$kind!=='image'))throw new InvalidArgumentException('素材角色与编码器不一致');
            $tag=$role==='reference'?(in_array($kind,['file','link'],true)?$kind:'reference_'.$kind):($role==='first'?'first_frame':'last_frame');
            $expected=$protocol==='h3_content_v1'?['type'=>$kind.'_url','role'=>$tag]:['type'=>$tag];
            $actual=(array)$field['item_match'];ksort($expected);ksort($actual);
            if($actual!==$expected)throw new InvalidArgumentException('素材分类与编码器的类型或角色不一致');
        }
        $definitions=self::definitions($mode['schema'],$field['field'],(array)($field['item_match']??[]));
        if($definitions===[])throw new InvalidArgumentException('素材路径没有完整参数结构');
        foreach($definitions as$d)if(!(($d['type']??'')==='string'||(($d['type']??'')==='array'&&($d['items']['type']??'')==='string')))
            throw new InvalidArgumentException('嵌套素材路径必须指向字符串或字符串数组');
    }

    public static function signature(array $field): string
    {
        $match=(array)($field['item_match']??[]);ksort($match);
        return json_encode([$field['field'],$match],JSON_THROW_ON_ERROR);
    }

    public static function assertNonOverlapping(array $fields): void
    {
        foreach($fields as$i=>$a)foreach(array_slice($fields,0,$i)as$b){
            if($a['field']!==$b['field']||!str_contains($a['field'],'.'))continue;
            $left=(array)($a['item_match']??[]);$right=(array)($b['item_match']??[]);$overlap=true;
            foreach(array_intersect(array_keys($left),array_keys($right))as$key)if($left[$key]!==$right[$key])$overlap=false;
            if($overlap)throw new InvalidArgumentException('素材分类有重叠，会重复计数');
        }
    }
}
