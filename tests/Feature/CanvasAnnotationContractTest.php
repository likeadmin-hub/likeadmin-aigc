<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_canvas\{CanvasAnnotation,CanvasProjectCompatibility,CanvasTemplateService};
$n=0;
$check=static function($ok,$why)use(&$n){if(!$ok)throw new RuntimeException($why);$n++;};
$nodes=[];
foreach(['shape','sticky','text'] as $i=>$kind){
 $nodes[]=['id'=>'a'.$i,'type'=>'annotation','title'=>$kind,'position'=>['x'=>$i*250,'y'=>0],'width'=>200,'height'=>$kind==='text'?42:200,'metadata'=>['annotation'=>['kind'=>$kind,'text'=>'中文标签\nEdit me','fill'=>'#fffa66','fontFamily'=>'Inter','fontSize'=>28,'api_key'=>'private'],'agentGroupId'=>'g']];
}
$graph=CanvasTemplateService::sanitize($nodes,[]);
$check(count($graph['nodes'])===3,'Annotation types dropped');
$check($graph['nodes'][2]['height']===42.0,'Text bounds clamped to generation node size');
$check($graph['nodes'][1]['metadata']['annotation']['text']==='中文标签\nEdit me','Editable text lost');
$check(!str_contains(json_encode($graph),'private'),'Unknown annotation field retained');
$adapted=CanvasProjectCompatibility::adapt(['nodes_json'=>$nodes,'edges_json'=>[],'viewport_json'=>[]]);
$check($adapted['nodes_json'][0]['type']==='annotation','Legacy adapter converted annotation into generator');
$check($adapted['nodes_json'][1]['metadata']['annotation']===$nodes[1]['metadata']['annotation'],'Adapter changed authored content');
$a=CanvasAnnotation::normalize(['fill'=>'url(private)','fontFamily'=>'evil','fontSize'=>999,'strokeWidth'=>-5]);
$check($a['fill']==='#c7c7c7' && $a['fontFamily']==='Inter' && $a['fontSize']===224.0 && $a['strokeWidth']===0.0,'Unsafe styling accepted');
try{CanvasTemplateService::sanitize($nodes,[['fromNodeId'=>'a0','toNodeId'=>'a1']]);throw new RuntimeException('Annotation generation edge accepted');}catch(InvalidArgumentException $e){$n++;}
$instance=CanvasTemplateService::instantiate($graph);
$check($instance['nodes'][0]['id']!==$graph['nodes'][0]['id'],'Preset reuse did not isolate object IDs');
$check($instance['nodes'][1]['metadata']['annotation']===$graph['nodes'][1]['metadata']['annotation'],'Preset reuse lost editable styles');
$check(CanvasAnnotation::normalize(['kind'=>'text'])['fontSize']===18.0,'Body font must be 18px');
$check(CanvasAnnotation::normalize(['kind'=>'shape'])['align']==='center','Shape text defaults to centered');
$nodes[0]['metadata']['annotation']=['kind'=>'shape','text'=>'Shape label','autoResize'=>true,'bold'=>true,'radius'=>100];
$nodes[2]['metadata']['annotation']=['kind'=>'text','text'=>'Manual wrap','autoResize'=>false,'fontWeight'=>600,'bold'=>false];
$roundTrip=CanvasTemplateService::instantiate(CanvasTemplateService::sanitize($nodes,[]));
$check($roundTrip['nodes'][0]['metadata']['annotation']['autoResize'] && $roundTrip['nodes'][0]['metadata']['annotation']['bold'],'Shape editing state lost in template reuse');
$check(!$roundTrip['nodes'][2]['metadata']['annotation']['autoResize'] && !$roundTrip['nodes'][2]['metadata']['annotation']['bold'],'Manual text layout lost in template reuse');
$check(CanvasAnnotation::normalize(['autoResize'=>'false','bold'=>'false'])['autoResize']===false,'String boolean accepted');
echo "PASS $n annotation persistence, template and styling checks\n";
