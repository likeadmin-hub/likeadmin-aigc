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
echo "NOT_RUN selected-model limits, ownership, quote, billing and Provider submission; normalization behavior only\n";
