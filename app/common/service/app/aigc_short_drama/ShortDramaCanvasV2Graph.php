<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama;

use InvalidArgumentException;

/** V2 presentation over the existing versioned graph, never a second graph store. */
final class ShortDramaCanvasV2Graph
{
    private const CONTENT = ['text','prompt','ratio','duration_seconds','model_code','shot_id','quality','resolution','generation_method','material_mode','advanced_params'];

    public static function key(array $node): string { return (string)($node['metadata']['v2_node_key'] ?? $node['id']); }

    public static function project(array $document): array
    {
        $keys=[];$nodes=[];$runs=[];
        foreach ($document['runs']??[] as $run) $runs[(string)$run['node_id']]=$run;
        foreach ($document['nodes'] ?? [] as $node) {
            $key=self::key($node);$keys[(string)$node['id']]=$key;$meta=(array)($node['metadata']??[]);
            $content=array_intersect_key($meta,array_flip(self::CONTENT));
            $content['text']=(string)($meta['content']??$content['text']??'');
            if (isset($meta['duration'])) $content['duration_seconds']=$meta['duration'];
            if (isset($meta['channel'])) $content['model_code']=$meta['channel'];
            // Only server-projected managed URLs are visible as results.
            foreach (['url','image_url','video_url','audio_url'] as $field) if (!empty($meta[$field])) { $content['url']=$meta[$field];break; }
            $nodes[]=['id'=>(string)$node['id'],'node_key'=>$key,'node_type'=>$node['type']==='audio'?'music':$node['type'],
                'business_kind'=>(string)($meta['v2_business_kind']??''),'title'=>(string)($node['title']??''),'content'=>$content,
                'position'=>['x'=>(float)($node['x']??0),'y'=>(float)($node['y']??0)],'width'=>(float)($node['width']??320),
                'height'=>(float)($node['height']??240),'z_index'=>(int)($meta['v2_z_index']??0),
                'task'=>isset($runs[(string)$node['id']])?self::task($runs[(string)$node['id']]):null];
        }
        $edges=[];
        foreach ($document['edges']??[] as $edge) if (isset($keys[(string)($edge['from']??'')],$keys[(string)($edge['to']??'')])) {
            $edges[]=['edge_key'=>(string)($edge['id']??($edge['from'].'-'.$edge['to'])),'from_node_key'=>$keys[(string)$edge['from']],
                'to_node_key'=>$keys[(string)$edge['to']],'edge_type'=>$edge['v2_edge_type']??'default'];
        }
        $viewport=(array)($document['viewport']??[]);
        return ['nodes'=>$nodes,'edges'=>$edges,'viewport'=>['x'=>(float)($viewport['x']??260),'y'=>(float)($viewport['y']??160),'k'=>(float)($viewport['k']??$viewport['zoom']??1)],'version'=>(int)($document['graph_revision']??0)];
    }

    public static function saveInput(array $document,array $request): array
    {
        if (!isset($request['version']) || !preg_match('/^(0|[1-9][0-9]*)$/D',(string)$request['version'])) throw new InvalidArgumentException('VERSION_REQUIRED');
        if ((int)$request['version']!==(int)($document['graph_revision']??0)) throw new InvalidArgumentException('VERSION_CONFLICT');
        $rows=$request['nodes']??null;$edges=$request['edges']??null;
        if (!is_array($rows)||!array_is_list($rows)||count($rows)>200||!is_array($edges)||!array_is_list($edges)||count($edges)>600) throw new InvalidArgumentException('INVALID_GRAPH');
        $old=[];$maximum=0;
        foreach ($document['nodes']??[] as $node) {$old[self::key($node)]=$node;$maximum=max($maximum,(int)$node['id']);}
        foreach ($document['removed_node_ids']??[] as $id) $maximum=max($maximum,(int)$id);
        $nodes=[];$ids=[];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('INVALID_NODE');
            $key=$row['node_key']??null;$type=$row['node_type']??null;
            if (!is_string($key)||!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D',$key)||isset($ids[$key])||!in_array($type,['text','image','video','music'],true)) throw new InvalidArgumentException('INVALID_NODE');
            $saved=$old[$key]??[];$id=(string)($saved['id']??++$maximum);$ids[$key]=$id;
            $meta=(array)($saved['metadata']??[]);$content=$row['content']??[];
            if (!is_array($content)||array_is_list($content)&&$content!==[]) throw new InvalidArgumentException('INVALID_NODE_CONTENT');
            foreach (self::CONTENT as $field) if (array_key_exists($field,$content)) $meta[$field]=$content[$field];
            $meta['content']=(string)($content['text']??$meta['content']??'');
            if (array_key_exists('duration_seconds',$content)) $meta['duration']=$content['duration_seconds'];
            if (array_key_exists('model_code',$content)) $meta['channel']=$content['model_code'];
            $business=$row['business_kind']??'';
            if (!in_array($business,['','script','character','scene','storyboard'],true)) throw new InvalidArgumentException('INVALID_BUSINESS_KIND');
            $meta['v2_node_key']=$key;$meta['v2_business_kind']=$business;$meta['v2_z_index']=(int)($row['z_index']??0);
            $geometry=[];foreach (['x','y'] as $axis) $geometry[$axis]=self::number($row['position'][$axis]??null,-1000000,1000000);
            $nodes[]=['id'=>$id,'type'=>$type==='music'?'audio':$type,'title'=>mb_substr((string)($row['title']??''),0,160),
                'x'=>$geometry['x'],'y'=>$geometry['y'],'width'=>self::number($row['width']??320,40,4000),'height'=>self::number($row['height']??240,40,4000),'metadata'=>$meta];
        }
        $normalized=[];$seen=[];
        foreach ($edges as $edge) {
            $key=$edge['edge_key']??null;$from=$edge['from_node_key']??null;$to=$edge['to_node_key']??null;
            if (!is_string($key)||!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D',$key)||isset($seen[$key])||!is_string($from)||!is_string($to)||!isset($ids[$from],$ids[$to])||$from===$to||!in_array($edge['edge_type']??'', ['default','reference','flow'],true)) throw new InvalidArgumentException('INVALID_EDGE');
            $seen[$key]=true;$normalized[]=['id'=>$key,'from'=>$ids[$from],'to'=>$ids[$to],'v2_edge_type'=>$edge['edge_type']];
        }
        $viewport=(array)($document['viewport']??[]);
        foreach (['x','y','k'] as $key) $viewport[$key]=self::number($request['viewport'][$key]??($key==='k'?1:0),$key==='k'?.2:-1000000,$key==='k'?4:1000000);
        return ['id'=>(int)$document['id'],'expected_revision'=>(int)$request['version'],'expected_document_token'=>$document['document_token'],
            'title'=>(string)($request['title']??$document['title']),'nodes'=>$nodes,'edges'=>$normalized,'viewport'=>$viewport,
            'removed_node_ids'=>array_values(array_diff(array_map('strval',array_column($document['nodes']??[],'id')),array_values($ids)))];
    }

    public static function task(array $run): array
    {
        $result=(array)($run['result']??[]);
        if (!isset($result['assets'])) $result['assets']=(array)($run['results']??[]);
        return ['id'=>(int)($run['id']??0),'kind'=>($run['node_type']??$run['type']??'')==='audio'?'music':($run['node_type']??$run['type']??''),'status'=>(string)($run['status']??'pending'),
            'version'=>(int)($run['version']??0),'generation_task_id'=>(string)($run['generation_task']['task_ref']??$result['generation_task']['task_ref']??''),
            'provider_task_id'=>'','error_message'=>(string)($run['error']??''),'result'=>$result];
    }
    private static function number($value,float $min,float $max): float
    {if (!is_numeric($value)||!is_finite((float)$value)||(float)$value<$min||(float)$value>$max) throw new InvalidArgumentException('INVALID_GRAPH_GEOMETRY');return (float)$value;}
}
