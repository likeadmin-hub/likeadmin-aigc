<?php
namespace app\common\service\decorate\agent;

/** Conservative three-way merge. Conflicts leave current values intact. */
class DecorationAgentMerge
{
    public static function merge(array $base, array $current, array $candidate): array
    {
        if ($current === $base) return ['merged'=>$candidate,'conflicts'=>[]];
        if ($candidate === $base || $candidate === $current) return ['merged'=>$current,'conflicts'=>[]];
        $conflicts=[];
        $b=DecorationAgentOperations::index($base['data']??[]);
        $c=DecorationAgentOperations::index($current['data']??[]);
        $a=DecorationAgentOperations::index($candidate['data']??[]);
        $structure=function(array $map){$result=[];foreach($map as $id=>$node)$result[$id]=$node['path'];return $result;};
        $bs=$structure($b);$cs=$structure($c);$as=$structure($a);
        $treeChangedC=$cs!==$bs;$treeChangedA=$as!==$bs;
        if($treeChangedC && $treeChangedA && $cs!==$as){
            $conflicts[]=['widget_id'=>null,'field'=>'data','reason'=>'concurrent_structure_change'];
            return ['merged'=>$current,'conflicts'=>$conflicts];
        }
        $tree=$treeChangedA?($candidate['data']??[]):($current['data']??[]);
        $values=[];
        foreach(array_unique(array_merge(array_keys($b),array_keys($c),array_keys($a))) as $id){
            $bn=$b[$id]['widget']??null;$cn=$c[$id]['widget']??null;$an=$a[$id]['widget']??null;
            foreach(['bn','cn','an'] as $variable)if($$variable!==null)unset($$variable['children']);
            if($bn!==null && ($cn===null || $an===null)){
                $survivor=$cn??$an;
                $survivorMap=$cn!==null?$c:$a;
                if($survivor!==$bn || (($survivorMap[$id]['path']??null)!==($b[$id]['path']??null))){
                    $conflicts[]=['widget_id'=>$id,'field'=>'data','reason'=>'delete_modify_conflict'];
                    if($cn!==null)$values[$id]=$cn;
                }
                continue;
            }
            if($bn===null){
                if($cn!==null && $an!==null && $cn!==$an)$conflicts[]=['widget_id'=>$id,'field'=>'data','reason'=>'concurrent_add_conflict'];
                $values[$id]=$cn??$an;continue;
            }
            $movedC=$c[$id]['path']!==$b[$id]['path'];$movedA=$a[$id]['path']!==$b[$id]['path'];
            if(($movedC && $an!==$bn && $an!==$cn)||($movedA && $cn!==$bn && $cn!==$an)){
                $conflicts[]=['widget_id'=>$id,'field'=>'data','reason'=>'move_modify_conflict'];$values[$id]=$cn;continue;
            }
            $values[$id]=self::value($bn,$cn,$an,'',$id,$conflicts);
        }
        if($conflicts)return ['merged'=>$current,'conflicts'=>$conflicts];
        $rebuild=function(array $list,array $path=[])use(&$rebuild,$values){$next=[];foreach($list as $i=>$node){$p=array_merge($path,[$i]);$id=(string)($node['id']??('legacy_'.preg_replace('/[^a-zA-Z0-9_-]/','_',$node['name']).'_'.implode('_',$p)));if(!isset($values[$id]))continue;$widget=$values[$id];if(isset($node['children']))$widget['children']=$rebuild($node['children'],$p);$next[]=$widget;}return $next;};
        $baseRest=$base;$currentRest=$current;$candidateRest=$candidate;unset($baseRest['data'],$currentRest['data'],$candidateRest['data']);
        $merged=self::value($baseRest,$currentRest,$candidateRest,'',null,$conflicts);
        if($conflicts)return ['merged'=>$current,'conflicts'=>$conflicts];
        $merged['data']=$rebuild($tree);
        return ['merged'=>$merged,'conflicts'=>[]];
    }

    private static function value($base,$current,$candidate,string $path,?string $id,array &$conflicts)
    {
        if($current===$base)return $candidate;
        if($candidate===$base||$current===$candidate)return $current;
        // Object fields merge individually. Lists are atomic so row reorder and
        // edit cannot silently produce a different meaning. Page meta has a
        // fixed component list and may merge its content fields independently.
        $list=is_array($base)&&array_values($base)===$base;
        if(is_array($base)&&is_array($current)&&is_array($candidate)&&(!$list||($path==='meta'&&count($base)===count($current)&&count($base)===count($candidate)))){
            $next=$current;
            foreach(array_unique(array_merge(array_keys($base),array_keys($current),array_keys($candidate))) as $key){
                $b=array_key_exists($key,$base);$c=array_key_exists($key,$current);$a=array_key_exists($key,$candidate);$field=$path===''?(string)$key:$path.'.'.$key;
                if(!$b){if($c&&$a&&$current[$key]!==$candidate[$key])$conflicts[]=['widget_id'=>$id,'field'=>$field,'reason'=>'concurrent_add_conflict'];elseif($a)$next[$key]=$candidate[$key];continue;}
                if(!$c||!$a){$survivor=$c?($current[$key]):($a?$candidate[$key]:null);if(($c||$a)&&$survivor!==$base[$key])$conflicts[]=['widget_id'=>$id,'field'=>$field,'reason'=>'delete_modify_conflict'];else unset($next[$key]);continue;}
                $next[$key]=self::value($base[$key],$current[$key],$candidate[$key],$field,$id,$conflicts);
            }
            return $next;
        }
        $conflicts[]=['widget_id'=>$id,'field'=>$path,'reason'=>'field_conflict'];return $current;
    }
}
