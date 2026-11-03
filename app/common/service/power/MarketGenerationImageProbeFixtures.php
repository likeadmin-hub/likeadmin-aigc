<?php
namespace app\common\service\power;

/** Synthetic browser-shaped fixtures for flat image/mask contracts; never infer opaque children. */
final class MarketGenerationImageProbeFixtures
{
    public static function recipes(array $mode): ?array
    {
        $fields=(array)($mode['fields']??[]);$counts=[];$optionalMasks=[];
        if($fields===[])return null;
        foreach($fields as$index=>$field){
            $name=(string)($field['field']??'');$role=(string)($field['role']??'reference');
            $schema=$mode['parameter_schema']['properties'][$name]??[];
            $plain=($schema['type']??'')==='string'||(($schema['type']??'')==='array'&&($schema['items']['type']??'')==='string');
            if($name===''||($field['kind']??'')!=='image'||!in_array($role,['reference','mask'],true)||!$plain)return null;
            $count=max((int)($field['minimum']??0),!empty($field['required'])?1:0);
            if($count>16||isset($field['maximum'])&&$count>(int)$field['maximum'])return null;
            $counts[$index]=$count;
            if($role==='mask'&&$count===0&&(int)($field['maximum']??1)>0)$optionalMasks[]=$index;
        }
        if(array_sum($counts)===0){
            foreach($fields as$index=>$field)if(($field['role']??'reference')==='reference'&&(int)($field['maximum']??1)>0){$counts[$index]=1;break;}
        }
        if(array_sum($counts)===0||array_sum($counts)>16)return null;
        $result=['assigned_required_images'=>self::assets($fields,$counts)];
        if($optionalMasks!==[]){foreach($optionalMasks as$index)$counts[$index]=1;
            if(array_sum($counts)>16)return null;
            $result['assigned_optional_masks']=self::assets($fields,$counts);
        }
        return $result;
    }

    private static function assets(array $fields,array $counts): array
    {
        $assets=[];
        foreach($fields as$index=>$field)for($i=0;$i<$counts[$index];$i++){
            $id='synthetic-'.count($assets);
            $assets[]=['id'=>$id,'name'=>'Offline '.$id,'type'=>'image','field'=>$field['field'],
                'role'=>($field['role']??'reference').'_image',
                'url'=>'blob:http://localhost:3000/'.$id,'uri'=>'https://fixtures.invalid/'.$id.'.png',
                'isObjectUrl'=>true,'uploading'=>false];
        }
        return $assets;
    }
}
