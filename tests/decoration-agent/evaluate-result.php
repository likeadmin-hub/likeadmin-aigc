<?php
/** Shared assertions for a real-model runner; this file never invokes a model. */
function evaluateDecorationAgentFixture(array $fixture, array $result): array
{
    $index = function (array $data) use (&$index): array {
        $found=[];
        foreach($data as $widget){if(isset($widget['id']))$found[$widget['id']]=$widget;if(!empty($widget['children']))$found += $index($widget['children']);}
        return $found;
    };
    $read = function(array $value,string $path){foreach(explode('.',$path) as $part){if(!is_array($value)||!array_key_exists($part,$value))return ['present'=>false,'value'=>null];$value=$value[$part];}return ['present'=>true,'value'=>$value];};
    $initial=$index($fixture['initial']['data']??[]);$candidate=$index($result['candidate']['data']??[]);$checks=[];
    foreach($fixture['expected'] as $assertion){
        $ok=false;$kind=$assertion['kind'];
        if($kind==='equals'){$actual=$read($result,$assertion['path']);$ok=$actual['present']&&$actual['value']===$assertion['value'];}
        elseif($kind==='unchanged_widget'){$id=$assertion['widget_id'];$ok=isset($initial[$id],$candidate[$id])&&$initial[$id]===$candidate[$id];}
        elseif($kind==='component_count'){$ok=count(array_filter($candidate,fn($widget)=>$widget['name']===$assertion['name']))===$assertion['value'];}
        elseif($kind==='image_request_count')$ok=count($result['image_requests']??[])===$assertion['value'];
        elseif($kind==='image_request_slot'){foreach($result['image_requests']??[] as $request)if(($request['widget_id']??null)===$assertion['widget_id']&&($request['field']??null)===$assertion['field']&&($request['ratio']??null)===$assertion['ratio']){$ok=true;break;}}
        elseif($kind==='unique_ids'){
            $ids=[];$collect=function(array $data)use(&$collect,&$ids){foreach($data as $widget){$ids[]=$widget['id']??null;if(!empty($widget['children']))$collect($widget['children']);}};$collect($result['candidate']['data']??[]);$ok=!in_array(null,$ids,true)&&count($ids)===count(array_unique($ids));
        }
        $checks[]=['assertion'=>$assertion,'passed'=>$ok];
    }
    return ['fixture_id'=>$fixture['id'],'passed'=>!in_array(false,array_column($checks,'passed'),true),'checks'=>$checks];
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    if(count($argv)!==3){fwrite(STDERR,"Usage: php evaluate-result.php fixture-id result.json\n");exit(2);}
    $fixtures=json_decode(file_get_contents(__DIR__.'/evaluation-fixtures.json'),true,512,JSON_THROW_ON_ERROR);
    $fixture=null;foreach($fixtures['cases'] as $entry)if($entry['id']===$argv[1])$fixture=$entry;
    if(!$fixture){fwrite(STDERR,"Unknown fixture\n");exit(2);}
    $result=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
    $evaluation=evaluateDecorationAgentFixture($fixture,$result);echo json_encode($evaluation,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";exit($evaluation['passed']?0:1);
}
