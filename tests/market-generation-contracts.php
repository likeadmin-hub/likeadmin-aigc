<?php
require getenv('GENERATION_SCHEMA_SOURCE') ?: dirname(__DIR__) . '/app/common/service/power/MarketGenerationSchema.php';
require getenv('GENERATION_MATERIALS_SOURCE') ?: dirname(__DIR__) . '/app/common/service/power/MarketGenerationMaterials.php';
require getenv('GENERATION_NATIVE_SOURCE') ?: dirname(__DIR__) . '/app/common/service/power/MarketGenerationNativeContract.php';
use app\common\service\power\MarketGenerationSchema as S;
use app\common\service\power\MarketGenerationMaterials as M;
function check($actual, $expected, string $message): void { if ($actual !== $expected) throw new RuntimeException($message . ': ' . json_encode($actual)); echo "PASS $message\n"; }
check(S::choices(['enum'=>'["1:1","16:9"]']), ['1:1','16:9'], 'JSON encoded enum');
check(S::choices(['options'=>'1k / 2k / 4k']), ['1k','2k','4k'], 'legacy slash enum');
check(S::durationChoices(['type'=>'integer','description'=>'支持 3s - 15s']), range(3,15), 'duration units range');
check(S::durationChoices(['type'=>'integer','description'=>'时长 [2,30]；-1 表示智能时长']), array_merge([-1],range(2,30)), 'smart duration and bracket range');
check(S::normalize(['resolution'=>['type'=>'string','enum'=>['768P','2K']], 'duration'=>['type'=>'string','enum'=>['5','10']]], ['resolution'=>'2k','duration'=>5]), ['resolution'=>'2K','duration'=>'5'], 'native enum casing and scalar types');
try { S::validate(['n'=>['type'=>'integer','minimum'=>1,'maximum'=>4]], ['n'=>5]); throw new RuntimeException('accepted invalid count'); } catch (InvalidArgumentException $e) { echo "PASS out-of-range parameter rejected\n"; }
$schema=['image_url'=>['type'=>'string'],'end_image_url'=>['type'=>'string'],'generation_type'=>['type'=>'string','enum'=>['image_to_video']]];
$meta=['params_schema'=>$schema,'material_capabilities'=>['modes'=>[
 ['code'=>'image_to_video','first_last_frame'=>'不支持','fields'=>[['field'=>'image_url','kind'=>'image','role'=>'first','required'=>true,'minimum'=>1,'maximum'=>1]]],
 ['code'=>'image_to_video','view_key'=>'pair','first_last_frame'=>'支持','fields'=>[['field'=>'image_url','kind'=>'image','role'=>'first','required'=>true,'minimum'=>1,'maximum'=>1],['field'=>'end_image_url','kind'=>'image','role'=>'last','required'=>true,'minimum'=>1,'maximum'=>1]]]
]]];
check(array_column(M::modes($meta),'method'), ['image_to_video','start_end'], 'first and pair views share native mode');
$request=['generation_method'=>'start_end','reference_assets'=>[['type'=>'image','role'=>'first_frame_image','url'=>'https://test/first.png'],['type'=>'image','role'=>'last_frame_image','url'=>'https://test/last.png']]];
check(M::serialize($meta,$request), ['image_url'=>'https://test/first.png','end_image_url'=>'https://test/last.png','generation_type'=>'image_to_video'], 'Hailuo native first and last fields');
$image=['params_schema'=>['image_url'=>['type'=>'string']], 'material_capabilities'=>['modes'=>[['code'=>'','fields'=>[['field'=>'image_url','kind'=>'image','role'=>'reference','required'=>false,'minimum'=>0,'maximum'=>1]]]]]];
check(M::serialize($image,['reference_images'=>['https://test/image.png']]), ['image_url'=>'https://test/image.png'], 'scalar image reference remains scalar');
$image['params_schema']=['image_references'=>['type'=>'array','items'=>['type'=>'object','properties'=>['image_url'=>['type'=>'string'],'type'=>['type'=>'string','default'=>'subject']],'required'=>['image_url']]]];
$image['material_capabilities']['modes'][0]['fields'][0]['field']='image_references';
check(M::serialize($image,['reference_images'=>['https://test/image.png']]), ['image_references'=>[['image_url'=>'https://test/image.png','type'=>'subject']]], 'object-array image reference preserves object shape');
$optionalFrames=['params_schema'=>['first_frame_url'=>['type'=>'string'],'last_frame_url'=>['type'=>'string'],'keyframe_urls'=>['type'=>'array','items'=>['type'=>'string']]],'material_capabilities'=>['modes'=>[['code'=>'keyframe_to_video','first_last_frame'=>'支持','fields'=>[['field'=>'first_frame_url','kind'=>'image','role'=>'first','required'=>false,'maximum'=>1],['field'=>'last_frame_url','kind'=>'image','role'=>'last','required'=>false,'maximum'=>1],['field'=>'keyframe_urls','kind'=>'image','role'=>'keyframe','required'=>false,'maximum'=>8]]]]]];
check(array_column(M::modes($optionalFrames),'method'), ['text_to_video','image_to_video','last_frame','start_end'], 'optional native keyframes support text, first, last and pair independently');
$duplicate=$request;$duplicate['reference_images']=['https://test/first.png','https://test/last.png'];
check(M::serialize($meta,$duplicate), M::serialize($meta,$request), 'legacy image aliases do not duplicate typed assets');
try {M::serialize($meta,['generation_method'=>'last_frame','reference_images'=>['https://test/last.png']]);throw new RuntimeException('accepted unsupported tail-only');}catch(InvalidArgumentException $e){echo "PASS unsupported tail-only view rejected\n";}
try {S::validate(['type'=>'object','properties'=>['video_urls'=>['type'=>'array'],'image_urls'=>['type'=>'array']],'oneOf'=>[['type'=>'object','properties'=>['video_urls'=>['type'=>'array']],'required'=>['video_urls'],'additionalProperties'=>false],['type'=>'object','properties'=>['video_urls'=>['type'=>'array'],'image_urls'=>['type'=>'array']],'required'=>['video_urls','image_urls'],'additionalProperties'=>false]]],[]);throw new RuntimeException('accepted missing conditional video');}catch(InvalidArgumentException $e){echo "PASS missing conditional required material rejected\n";}
check(S::durationChoices(['type'=>'integer','minimum'=>0,'maximum'=>10,'description'=>'合法取值为 `0` 或 `[2,10]` 之间的整数']), array_merge([0],range(2,10)), 'follow-source duration excludes unsupported one second');
$subjects=['params_schema'=>['elements'=>['type'=>'array','items'=>['type'=>'object','properties'=>['video_url'=>['type'=>'string']]]]],'material_capabilities'=>['modes'=>[['code'=>'reference_to_video','fields'=>[['field'=>'elements','kind'=>'subject','role'=>'reference','maximum'=>7]]]]]];
check(M::billingInputs($subjects,['provider_params'=>['elements'=>[['video_url'=>'https://test/subject.mp4']]]]), ['_billing_video_input'=>true,'_billing_image_input'=>false], 'subject video selects the video-input price variant');
check(M::billingInputs($meta,$request), ['_billing_video_input'=>false,'_billing_image_input'=>true], 'native scalar first image selects the image-input price variant');
require getenv('GENERATION_REQUEST_SOURCE') ?: dirname(__DIR__) . '/app/common/service/power/MarketGenerationRequest.php';
use app\common\service\power\MarketGenerationRequest as R;
require dirname(__DIR__).'/app/common/service/power/MarketGenerationRules.php';
check(R::payload(['market_metadata'=>['params_schema'=>['num_images'=>['type'=>'string','enum'=>['1','2','3','4']]]]],['quantity'=>3])['num_images'],'3','image batch quantity reaches native num_images with its declared type');
check(R::parameterSignature(['provider_params'=>['seed'=>0,'audio'=>false]]),R::parameterSignature(['provider_params'=>['audio'=>false,'seed'=>0]]),'parameter signature ignores object key order');
if(R::parameterSignature(['provider_params'=>['seed'=>1]])===R::parameterSignature(['provider_params'=>['seed'=>2]]))throw new RuntimeException('Changed seed reused old task signature');echo "PASS changing generation parameters creates a distinct task signature\n";

$conditional=['properties'=>['video_urls'=>['type'=>'array'],'image_urls'=>['type'=>'array'],'duration'=>['type'=>'integer']],'oneOf'=>[['properties'=>['video_urls'=>['type'=>'array']],'required'=>['video_urls'],'additionalProperties'=>false],['properties'=>['video_urls'=>['type'=>'array'],'image_urls'=>['type'=>'array'],'duration'=>['type'=>'integer']],'required'=>['video_urls','image_urls'],'additionalProperties'=>false]]];
check(S::normalize(S::branch($conditional,['video_urls'=>['https://test/v.mp4'],'duration'=>1]),['video_urls'=>['https://test/v.mp4'],'duration'=>1]),['video_urls'=>['https://test/v.mp4']],'video-only branch omits inapplicable injected duration');
require getenv('VIDEO_RUNTIME_SOURCE') ?: dirname(__DIR__) . '/app/common/service/power/MarketVideoRuntimeService.php';
use app\common\service\power\MarketVideoRuntimeService as V;
$usage=new ReflectionMethod(V::class,'usage');
check($usage->invoke(null,['usage'=>['output_video_duration'=>5,'total_tokens'=>1234]],60,['usage_unit'=>'output_second'])['actual_quantity'],5.0,'measured video duration is used instead of token count');
check($usage->invoke(null,['usage'=>['input_seconds'=>18]],60,['usage_unit'=>'input_second'])['actual_quantity'],18.0,'measured input seconds are accepted');
check($usage->invoke(null,['usage'=>['total_tokens'=>100]],60,['usage_unit'=>'output_second'])['actual_quantity'],0.0,'missing measured output is not replaced with tokens');
$ratios=new ReflectionMethod(V::class,'ratioValues');
check($ratios->invoke(null,['aspect_ratio'=>['16:9','auto','adaptive']]),['16:9','auto','adaptive'],'follow-source auto ratio remains selectable');
require dirname(__DIR__).'/app/common/service/power/MarketGenerationMeter.php';
require dirname(__DIR__).'/app/common/service/power/PowerMarketService.php';
$billingUnit=new ReflectionMethod(V::class,'billingUnit');
$normalizer=new ReflectionMethod(\app\common\service\power\PowerMarketService::class,'normalizedUsageUnit');
foreach(['per_call'=>1.0,'output_second'=>5.0] as $unit=>$expected){
 $market=['product'=>['resource_type'=>'app_api','upstream_app_code'=>'grok_video'],'sku'=>['usage_unit'=>$unit,'usage_unit_size'=>1,'locked_params'=>[]]];
 check($billingUnit->invoke(null,$market['product'],$market['sku']),$unit,'Grok preserves '.$unit.' runtime unit');
 check($normalizer->invoke(null,$market['sku'],['type'=>'app_api','resource'=>['app_code'=>'grok_video']]),$unit,'Grok sync preserves '.$unit);
}
