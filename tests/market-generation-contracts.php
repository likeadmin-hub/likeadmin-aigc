<?php
require getenv('GENERATION_SCHEMA_SOURCE') ?: dirname(__DIR__) . '/app/common/service/power/MarketGenerationSchema.php';
require getenv('GENERATION_MATERIALS_SOURCE') ?: dirname(__DIR__) . '/app/common/service/power/MarketGenerationMaterials.php';
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
