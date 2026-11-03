<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Ordinary creation policies, derived from the admitted contract, never examples. */
final class MarketGenerationCreatorMaterials
{
    public static function policies(array $metadata, string $native, array $fields): array
    {
        // Most modes contain only plain uploads. Avoid rebuilding their native
        // schemas/rules every time catalogue selection considers a candidate.
        $public=MarketGenerationSchema::properties(MarketGenerationSchema::array($metadata['params_schema']??[]));
        $fields=array_values(array_filter($fields,static fn($field)=>in_array($field['kind']??'',['video','audio'],true)
            &&empty($field['required'])&&empty($field['conditional_required'])
            &&($public[$field['field']]['type']??'')==='array'&&($public[$field['field']]['items']['type']??'')==='object'));
        if($fields===[])return [];
        $schema=MarketGenerationSchema::properties(MarketGenerationNativeContract::schema($metadata,$native));
        $rules=MarketGenerationNativeContract::rules($metadata,$native);$result=[];
        foreach($fields as$field){
            $key=$field['field'];$definition=$schema[$key]??[];$item=$definition['items']??[];$properties=$item['properties']??[];
            if(($definition['type']??'')!=='array'||($item['type']??'')!=='object'||!empty($field['required'])||!empty($field['conditional_required']))continue;
            $urls=array_keys(array_filter($properties,static fn($p)=>($p['type']??'')==='string'&&($p['format']??'')==='uri'));
            if(count($urls)!==1)continue;$url=$urls[0];$required=$item['required']??[];
            foreach($rules as$rule){
                if(($field['kind']??'')!=='video'||($rule['kind']??'')!=='clip_range'||($rule['field']??'')!==$key)continue;
                $start=$rule['start'];$end=$rule['end'];$seconds=true;
                foreach([$start,$end]as$part){
                    $description=$public[$key]['items']['properties'][$part]['description']??'';
                    $seconds=$seconds&&($properties[$part]['type']??'')==='number'
                        &&preg_match('/单位(?:为|是)?秒|\bseconds?\b/iu',$description)&&!preg_match('/毫秒|milliseconds?|\bms\b|帧|frames?/iu',$description);
                }
                if($seconds&&($rule['maximum']??0)>0&&array_diff($required,[$url,$start,$end])===[]
                    &&count(array_intersect($required,[$url,$start,$end]))===3)
                    $result[$key]=['kind'=>'full_video','url'=>$url,'start'=>$start,'end'=>$end,'maximum'=>(float)$rule['maximum']];
            }
            // A stable ordinary audio alias is a local reference name, not a provider voice ID.
            $referenceNames=array_filter($rules,static fn($rule)=>($rule['kind']??'')==='kling4'&&($rule['check']??'')==='names');
            if($referenceNames!==[]&&($field['kind']??'')==='audio'&&array_diff($required,[$url,'name'])===[]&&count($required)===2
                &&($properties['name']['type']??'')==='string'){
                try{MarketGenerationSchema::validate(['name'=>$properties['name']],['name'=>'音频1']);
                    $result[$key]=['kind'=>'named_audio','url'=>$url];
                }catch(InvalidArgumentException $e){/* Unknown naming contracts keep their normal validation. */}
            }
        }
        return $result;
    }

    public static function complete(array $metadata,array $mode,array $params,?callable $probe=null): array
    {
        $policies=self::policies($metadata,(string)($mode['native_mode']??''),$mode['fields']??[]);
        $names=[];foreach($params as$rows)if(is_array($rows))foreach($rows as$row)if(is_array($row)&&isset($row['name']))$names[]=$row['name'];
        foreach($policies as$key=>$policy){
            if(!is_array($params[$key]??null))continue;
            foreach($params[$key]as&$row){
                if(!is_array($row)||empty($row[$policy['url']]))continue;
                if($policy['kind']==='full_video'){
                    // Explicit old ranges, including partial invalid ranges, are never repaired or overwritten.
                    if(array_key_exists($policy['start'],$row)||array_key_exists($policy['end'],$row))continue;
                    $seconds=MarketGenerationMedia::seconds(['video_url'=>$row[$policy['url']]],false,$probe);
                    if($seconds>$policy['maximum'])throw new InvalidArgumentException('参考视频请使用不超过'.(string)$policy['maximum'].'秒的素材；普通创作引用完整视频');
                    $row[$policy['start']]=0.0;$row[$policy['end']]=$seconds;
                }elseif(!array_key_exists('name',$row)){
                    $index=1;do{$name='音频'.$index++;}while(in_array($name,$names,true));
                    $row['name']=$name;$names[]=$name;
                }
            }unset($row);
        }
        return $params;
    }
}
