<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use app\common\service\app\aigc_video\AigcVideoReferenceAssetService as References;
$image=['type'=>'image','uri'=>'https://fixtures.invalid/shared.png'];
$dedup=References::normalize(['reference_assets'=>[$image,$image], 'reference_images'=>[$image['uri']]]);
agentCheck(count($dedup)===1,'M04 same-use uploaded and connected image is normalized once');
$frames=References::normalize(['reference_assets'=>[$image+['role'=>'first_frame_image'],$image+['role'=>'last_frame_image']], 'reference_images'=>[$image['uri']]]);
agentCheck(array_column($frames,'role')===['first_frame_image','last_frame_image'],'M05 same URI preserves distinct first and last frame roles without legacy duplicate');
$reverse=References::normalize(['reference_assets'=>[$image+['role'=>'last_frame_image'],$image+['role'=>'first_frame_image']]]);
agentCheck(array_column($reverse,'role')===['last_frame_image','first_frame_image'],'M06 input role order remains explicit');
$assets=[];
for ($i=0;$i<16;$i++) $assets[]=['type'=>'image','uri'=>'https://fixtures.invalid/'.$i.'.png'];
$rejected=false;
try { References::normalize(['reference_assets'=>$assets]); }
catch (Exception $error) {$rejected=$error->getMessage()==='参考素材数量超出限制';}
agentCheck($rejected,'M03 normalizer rejects oversized combined request instead of truncating');
agentCheck(count(References::normalize(['reference_assets'=>array_slice($assets,0,15)]))===15,'existing fifteen-asset boundary remains accepted');
$assertAssets=new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class,'assertAssets');
$assertAssets->setAccessible(true);
$market=['product'=>['upstream_model_code'=>'isolated-reference-contract','source_payload'=>['market_metadata'=>[
    'supported_asset_types'=>['image','video','audio'],
    'capabilities'=>['max_reference_images'=>3,'max_reference_videos'=>3,'max_reference_audios'=>3,'max_reference_assets'=>2],
]]]];
$mixed=[['type'=>'image','url'=>'https://fixtures.invalid/image.png'],['type'=>'video','url'=>'https://fixtures.invalid/video.mp4'],['type'=>'audio','url'=>'https://fixtures.invalid/audio.mp3']];
$assertAssets->invoke(null,$market,['reference_assets'=>array_slice($mixed,0,2)]);
agentCheck(true,'M03 live market validator accepts combined references at total limit');
$rejected=false;
try {$assertAssets->invoke(null,$market,['reference_assets'=>$mixed]);}
catch (Exception $error) {$rejected=str_contains($error->getMessage(),'at most 2 reference assets');}
agentCheck($rejected,'M03 live market validator rejects individually valid references exceeding combined limit');
$market['product']['source_payload']['market_metadata']['supported_asset_types']=['image'];
$rejected=false;
try {$assertAssets->invoke(null,$market,['reference_assets'=>[$mixed[1]]]);}
catch (Exception $error) {$rejected=str_contains($error->getMessage(),'does not support reference video');}
agentCheck($rejected,'M02 selected model validator rejects unsupported input despite another possible model');
$assetsMethod=new ReflectionMethod(app\common\service\power\MarketVideoRuntimeService::class,'assets');
$assetsMethod->setAccessible(true);
$slots=$assetsMethod->invoke(null,['reference_assets'=>$frames]);
agentCheck(count($slots['image'])===2,'M05 market runtime preserves two semantic frame slots for identical URI');
$slots=$assetsMethod->invoke(null,['reference_assets'=>[$image,$image],'reference_images'=>[$image['uri']]]);
agentCheck(count($slots['image'])===1,'M04 market runtime still deduplicates same-role image references');
echo "NOT_RUN ownership, public quote/reserve, billing and Provider submission; normalization and actual market validator only\n";
