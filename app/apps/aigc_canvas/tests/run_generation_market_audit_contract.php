<?php
/** Public catalogue -> editable form -> Canvas -> wire payload. Offline, no billing/network. */
require dirname(__DIR__,4).'/vendor/autoload.php';
use app\common\service\app\aigc_canvas\{CanvasGenerationParameters as P,AigcCanvasService as C};
use app\common\service\power\{MarketVideoRuntimeService as V,MarketImageModelRuntimeService as I};
$checks=0;
$check=static function($ok,$message)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($message);};
$call=static fn($class,$method,...$args)=>(new ReflectionMethod($class,$method))->invoke(null,...$args);
$fixtures=json_decode(file_get_contents(dirname(__DIR__,4).'/tests/fixtures/canvas-generation-market-contracts.json'),true)['contracts'];
$at=static function($data,$path){foreach(explode('.',$path)as$part){if(!is_array($data)||!array_key_exists($part,$data))return null;$data=$data[$part];}return $data;};
foreach($fixtures as $model){
 $name=$model['model_code']?:$model['app_code'];$type=$model['type'];
 $model+=['locked_params'=>[],'channel_code'=>'fixture','product_id'=>1,'sku_id'=>2,'sku_key'=>'fixture'];
 $model['market_metadata']=['params_schema'=>$model['params_schema'],'default_params'=>$model['default_params']];
 $fields=P::definitions($model,$type);$known=array_column($fields,'key');
 $schema=$model['params_schema']['properties']??$model['params_schema'];
 $core=['model','action','prompt','urls','image_urls','images','content','media','image_with_roles','video_urls','audio_url','resolution','image_size','aspect_ratio','ratio','duration','size','n','generation_type','callback_url','input.messages','input.prompt','parameters.n','parameters.size','parameters.ratio','parameters.duration','parameters.resolution'];
 $schemaFields=[];
 foreach($schema as $key=>$def){if(in_array($key,['input','parameters','metadata'],true)){foreach(($def['properties']??$def['children']??$def['example']??[]) as $child=>$v)$schemaFields[]=$key.'.'.$child;}else $schemaFields[]=$key;}
 foreach($schemaFields as $key)$check(in_array($key,$known,true)||in_array($key,$core,true)||($type==='video'&&$key==='quality'),"Unconnected catalogue field $name:$key");
 foreach($fields as $f){
  $key=$f['key'];$raw=match($f['type']){'boolean'=>false,'integer'=>max(0,$f['minimum']??0),'number'=>max(0,$f['minimum']??0),'array'=>[['type'=>'web_search']],default=>$f['options'][0]??'fixture'};
  if(($f['control']??'')==='image')$raw='https://example.test/mask.png';
  if(($f['format']??'')==='json')$raw='{"Label":"1"}';
  if($f['type']==='integer'&&$f['options'])$raw=(int)$f['options'][0];
  $base=['prompt'=>'Offline fixture','quality'=>'720p','duration'=>5,'ratio'=>'16:9','generation_method'=>'text_to_video'];
  if($type==='image')$base['quality']='1k';
  if($name==='q4-preview-video')$base+=['reference_images'=>['https://example.test/frame.png']];
  $request=P::apply($base+['advanced_params'=>[$key=>$raw]],$model,$type);
  $normal=$call(C::class,$type==='image'?'normalizeImageParams':'normalizeVideoParams',$request,0,0);
  $normal['provider_params']=$request['provider_params'];
  // Nano Banana's only fields are covered by its dedicated model/media/spec adapter.
  $wire=$type==='image'?$call(I::class,'payload',$model,$normal,'offline'):$call(V::class,$model['resource_type']==='model'?'modelPayload':'appPayload',$model,$normal,'offline');
  $check($at($wire,$key)===$raw,"Wire value lost for $name:$key ".json_encode($wire));
 }
}
$wan=['model_code'=>'wan3.0-video','channel_code'=>'fixture','params_schema'=>['input'=>['type'=>'object'],'parameters'=>['type'=>'object','example'=>['audio'=>'1']]],'locked_params'=>[]];
foreach([[],['generate_audio'=>null],['generateAudio'=>null]] as $absent){$normal=$call(C::class,'normalizeVideoParams',$absent+['prompt'=>'fixture'],0,0);$wire=$call(V::class,'modelPayload',$wan,$normal,'offline');$check(!array_key_exists('audio',$wire['parameters']),'Absent audio was changed to mute');}
foreach([false,true,'false','true',0,1] as $audio){$normal=$call(C::class,'normalizeVideoParams',['generateAudio'=>$audio,'prompt'=>'fixture'],0,0);$wire=$call(V::class,'modelPayload',$wan,$normal,'offline');$check($wire['parameters']['audio']===filter_var($audio,FILTER_VALIDATE_BOOLEAN),'Audio boolean alias not preserved');}
$wire=$call(V::class,'modelPayload',$wan,['prompt'=>'fixture','generate_audio'=>null],'offline');$check(!array_key_exists('audio',$wire['parameters']),'Legacy null still muted at shared boundary');
foreach(['invalid',[]] as $audio){try{$call(C::class,'normalizeVideoParams',['generateAudio'=>$audio],0,0);$check(false,'Invalid audio accepted');}catch(Exception $e){$check(true,'Rejected');}}
echo 'PASS '.$checks.' catalogue field and wire-value checks across '.count($fixtures)." contracts; no provider calls\n";
