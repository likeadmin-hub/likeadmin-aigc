<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

/** Pure, model-facing visual-review projection. Never replaces the authoritative run context. */
final class DecorationAgentReviewContext
{
    public static function project(array $context,array $validMaterials,array $designMaterials=[]): array
    {
        if(empty($context['visual_review']))return $context;
        $page=$context['page'];$nodes=DecorationAgentOperations::index($page['data']??[]);
        $names=array_values(array_unique(array_column(array_column($nodes,'widget'),'name')));
        $contracts=array_map(fn($name)=>DecorationAgentDesignContext::contract($name),$names);
        $context['related_contracts']=self::packContracts($contracts,$context['shared_style_contract']??[]);
        $rows=[];
        foreach(DecorationAgentCatalog::summary()['components'] as $definition)$rows[]=[
            $definition['name'],$definition['title'],$definition['category']??'',
            (bool)($definition['children']['supported']??false),!empty($definition['source']),
            count($definition['content_fields']??[])+count($definition['style_fields']??[]),
        ];
        $context['component_catalog']=['columns'=>['name','title','group','children_supported','source_supported','field_count'],'rows'=>$rows,
            'lookup'=>'catalog {names:[...]} returns complete defaults/fields/conditions/units/limits/runtime contracts. Query before editing a component class outside related_contracts.'];
        $context['shared_style_contract']=['defined_by'=>'related_contracts.contracts[*].style_fields after exact shared_schemas expansion',
            'rule'=>'Each current component retains its complete authoritative style schema/defaults/units/ranges/conditions. Other component styles require catalog(names[]).'];
        $context['links']=['currently_configured'=>self::configuredLinks($nodes),
            'discover'=>'links {} returns other permitted entries; server interaction_contract and operation validation retain the full allowlist.'];
        $scopedPage=$page;
        if(($context['scope']??'')==='selected')$scopedPage=['data'=>isset($nodes[$context['selected_id']??''])?[$nodes[$context['selected_id']]['widget']]:[],'meta'=>[]];
        $context['material_summary']=self::materials($scopedPage,$validMaterials,$context['material_summary']??[],$context['image_sources']??[]);
        $context['reusable_materials']=['same_as'=>'material_summary','discovery'=>'search_materials {query} uses authorized sources. Server retains trusted delivery aliases and revalidates all identities.'];
        $context['approved_design']=array_intersect_key($context['approved_design']??[],array_flip(['run_id','revision','hash','slot_id','asset']));
        $context['approved_design']['reference_materials']=array_map(static function(array $material):array {
            $identity=array_intersect_key($material,array_flip(['kind','id','uri','file_id','slot_id','source']));
            if(!empty($material['image_uri']))$identity['image_uri']=$material['image_uri'];
            return $identity;
        },$designMaterials);
        $context['conversation']=['omitted'=>'Prior prose remains in server history; current user review instructions remain in the user message.',
            'visual_priority'=>'Approved raster and actual screenshot define the comparison. Old proposals are not the approved visual specification.'];
        return $context;
    }

    /** Lossless subtree interning: all original field/default/condition metadata remains present. */
    private static function packContracts(array $contracts,array $sharedStyles): array
    {
        $bundle=['contracts'=>$contracts,'styles'=>$sharedStyles];$frequency=[];
        $count=function($value)use(&$count,&$frequency):void {
            if(!is_array($value))return;$json=DecorationAgentService::json($value);
            if(strlen($json)>=60)$frequency[$json]=($frequency[$json]??0)+1;
            foreach($value as $child)$count($child);
        };
        $count($bundle);$schemas=[];$keys=[];
        $encode=function($value,bool $root=false)use(&$encode,&$schemas,&$keys,$frequency){
            if(!is_array($value))return $value;$json=DecorationAgentService::json($value);
            if(!$root&&($frequency[$json]??0)>1){
                if(!isset($keys[$json])){$id='s'.count($keys);$keys[$json]=$id;$schemas[$id]=$encode($value,true);}
                return ['$ref'=>$keys[$json]];
            }
            foreach($value as &$child)$child=$encode($child);unset($child);return $value;
        };
        $encoded=$encode($bundle,true);
        return ['contracts'=>$encoded['contracts'],'shared_schemas'=>$schemas,
            'reference_rule'=>'An object with only $ref expands to its exact shared_schemas entry. All original labels/defaults/conditions/enums/units/limits/runtime notes are retained. $ref/shared_schemas are display-only: operations must use real fields and values, never write these references into components. catalog {names:[...]} returns the uncompressed original contracts.'];
    }

    /** Deterministic expansion also proves the model-facing contract has lost no constraints. */
    public static function expandContracts(array $packed): array
    {
        $expand=function($value,array $path=[])use(&$expand,$packed){
            if(!is_array($value))return $value;
            if(array_keys($value)===['$ref']){
                $id=$value['$ref'];
                if(!is_string($id)||!array_key_exists($id,$packed['shared_schemas']??[])||in_array($id,$path,true))throw new \InvalidArgumentException('组件共享契约引用无效');
                return $expand($packed['shared_schemas'][$id],[...$path,$id]);
            }
            foreach($value as &$child)$child=$expand($child,$path);unset($child);return $value;
        };
        return $expand($packed['contracts']);
    }

    private static function configuredLinks(array $nodes): array
    {
        $links=[];
        foreach($nodes as $node){
            $widget=$node['widget'];$definition=DecorationAgentCatalog::definition($widget['name']);
            foreach($definition['content_fields']??[] as $field=>$schema)if(($schema['type']??'')==='link'&&!empty($widget['content'][$field]))$links[DecorationAgentService::json($widget['content'][$field])]=$widget['content'][$field];
            foreach($definition['lists']??[] as $field=>$list)foreach($widget['content'][$field]??[] as $row)foreach($list['fields']??[] as $key=>$schema)if(($schema['type']??'')==='link'&&!empty($row[$key]))$links[DecorationAgentService::json($row[$key])]=$row[$key];
        }
        return array_values($links);
    }

    /** Materials must already have passed server-side refresh/ownership/source checks. */
    private static function materials(array $page,array $valid,array $metadata,array $sources): array
    {
        $refs=[];$nodes=array_column(DecorationAgentOperations::index($page['data']??[]),'widget');
        foreach($page['meta']??[] as $meta)if(is_array($meta)&&($meta['name']??'')==='page-meta')$nodes[]=$meta;
        foreach($nodes as $widget){
            $definition=$widget['name']==='page-meta'?DecorationAgentCatalog::pageMeta():DecorationAgentCatalog::definition($widget['name']);
            foreach($widget['_decoration_asset_refs']??[] as $field=>$ref){
                if(!is_array($ref)||!is_string($ref['expected_value']??null)||DecorationAgentOperations::get($widget,$field)!==$ref['expected_value'])continue;
                $parts=explode('.',$field);$schema=$definition[$parts[0].'_fields'][$parts[1]??'']??null;
                if(count($parts)===4&&$parts[0]==='content'&&ctype_digit($parts[2]))$schema=$definition['lists'][$parts[1]]['fields'][$parts[3]]??null;
                if(($schema['type']??'')!=='image')continue;
                $refs[($ref['kind']??'').':'.(int)($ref['id']??0).':'.$ref['expected_value']]=true;
            }
        }
        $result=[];
        foreach($valid as $entry){
            $key=($entry['kind']??'').':'.(int)($entry['id']??0).':'.($entry['stable_uri']??'');
            if(!isset($refs[$key])||!in_array($entry['source']??'tenant',$sources,true))continue;
            $item=array_intersect_key($entry,array_flip(['kind','id','stable_uri','file_id','slot_id','width','height','title','name','role','tags','storage_scope','source']));
            foreach($metadata as $source){
                $id=$entry['kind']==='file'?(int)($source['file_id']??0):(int)($source['slot_id']??0);
                if($id!==(int)$entry['id'])continue;
                $item=array_replace($item,array_intersect_key($source,array_flip(['title','name','width','height','tags','role','type','usage'])));break;
            }
            $item['authorized_for_current_sources']=true;$result[$key]=$item;
        }
        return array_values($result);
    }
}
