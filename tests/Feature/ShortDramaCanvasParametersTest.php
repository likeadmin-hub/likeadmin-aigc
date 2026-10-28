<?php
/** Offline: preserves advanced controls through the canvas boundary and reuses shared validation. */
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\power\MarketCreationParameters as Parameters;
function verify($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$call=static fn($method,...$args)=>(new ReflectionMethod(Canvas::class,$method))->invoke(null,...$args);
$model=['value'=>'fixture','resource_type'=>'model','params_schema'=>[
 'size'=>['type'=>'string','enum'=>['1:1','3:2','2:3']],
 'multi_shots'=>['type'=>'boolean'], 'seed'=>['type'=>'integer','minimum'=>0]
], 'material_modes'=>[['key'=>'text','method'=>'text_to_video','fields'=>[]]]];
foreach(['image','video'] as $type){
 $payload=$call('generationPayload',$type,['prompt'=>'Existing draft','channel'=>'fixture','advanced_params'=>['size'=>'3:2','multi_shots'=>false,'seed'=>0],'provider_params'=>['api_key'=>'forbidden']],1,1,422,true);
 verify($payload['advanced_params']===['size'=>'3:2','multi_shots'=>false,'seed'=>0],"$type retains size, false and zero");
 verify(!isset($payload['provider_params']),"$type rejects raw provider override");
 $normalized=Parameters::applyOptions($payload,[$model],$type);
 verify($normalized['provider_params']['size']==='3:2'&&$normalized['provider_params']['multi_shots']===false,"$type applies shared schema");
 $before=$call('quoteInput','node',$payload);$payload['advanced_params']['size']='2:3';
 verify($before!==$call('quoteInput','node',$payload),"$type changed parameters invalidate quote signature");
 foreach([['api_key'=>'forbidden'],['size'=>'unsupported'],['seed'=>-1]] as $invalid){
  try{Parameters::applyOptions(array_replace($payload,['advanced_params'=>$invalid]),[$model],$type);throw new RuntimeException('accepted invalid input');}
  catch(InvalidArgumentException $e){verify(true,"$type rejects ".array_key_first($invalid));}
 }
}
