<?php
// Offline contracts: no database writes, network requests or paid generations.
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\power\{ResponsesMessagesAdapter,MarketTextModelRuntimeService,MarketModelCapabilities};
function expectProductVideo(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$input=ResponsesMessagesAdapter::input([['role'=>'user','content'=>[
    ['type'=>'text','text'=>'分析镜头'],['type'=>'video_url','video_url'=>['url'=>'https://media.example.test/reference.mp4']],
    ['type'=>'image_url','image_url'=>['url'=>'https://media.example.test/product.png']]]]]);
expectProductVideo($input[0]['content'][1]===['type'=>'input_video','video_url'=>'https://media.example.test/reference.mp4'],'Responses video must use its native content type');
expectProductVideo($input[0]['content'][2]['type']==='input_image','Existing image conversion must remain intact');
$profile=static fn($extra)=>MarketModelCapabilities::profile(['resource_type'=>'model','model_type'=>'text','source_payload'=>['market_metadata'=>['upstream_metadata'=>['capabilities'=>$extra]]]]);
$get=static function($profile,$key){foreach($profile['fields'] as $f)if($f['key']===$key)return $f['value'];return null;};
expectProductVideo($get($profile(['max_reference_videos'=>1]),'supports_video')===true,'Declared video input limit must be available');
expectProductVideo($get($profile(['supports_video'=>false,'max_reference_videos'=>1]),'supports_video')===false,'Explicit unsupported flag must win');
$normalize=new ReflectionMethod(MarketTextModelRuntimeService::class,'normalizeUsage');
$usage=$normalize->invoke(null,['prompt_tokens'=>1000,'completion_tokens'=>200,'total_tokens'=>1200,'prompt_tokens_details'=>['cached_tokens'=>400]]);
expectProductVideo($usage['cached_input_tokens']===400 && $usage['token_fields']['valid'],'Cache usage must survive normalization');
$invalid=$normalize->invoke(null,['prompt_tokens'=>10,'completion_tokens'=>2,'prompt_tokens_details'=>['cached_tokens'=>11]]);
expectProductVideo(!$invalid['token_fields']['valid'],'Impossible cache receipts cannot settle');
$quote=new ReflectionMethod(MarketTextModelRuntimeService::class,'quote');
$model=['input'=>['platform_price'=>90,'tenant_price'=>180,'cached_platform_price'=>1.75,'cached_tenant_price'=>3.5,'usage_unit_size'=>1000000],
    'output'=>['platform_price'=>280,'tenant_price'=>560,'usage_unit_size'=>1000000]];
$price=$quote->invoke(null,$model,1000000,100000,400000);
expectProductVideo(abs($price['tenant_cost_points']-82.7)<0.000001,'Distinct input/cache/output costs must match upstream rates');
expectProductVideo(abs($price['user_charge_points']-165.4)<0.000001,'Retail rate snapshot must apply to every meter');
$legacy=['input'=>['platform_price'=>10,'tenant_price'=>20,'usage_unit_size'=>1000], 'output'=>['platform_price'=>30,'tenant_price'=>60,'usage_unit_size'=>1000]];
$price=$quote->invoke(null,$legacy,1000,100,400);
expectProductVideo($price['tenant_cost_points']===13.0 && $price['user_charge_points']===26.0,'Legacy scalar meters must keep existing behavior');
expectProductVideo(\app\common\service\app\aigc_micro_video\MicroVideoSubmission::applies(['app_code'=>'aigc_product_video']),'New application must use durable submission admission');
echo "PASS product video protocol, capability and token pricing contracts\n";
