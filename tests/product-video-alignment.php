<?php
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\app\aigc_product_video\{ProductVideoPolicy as P,ProductVideoProducts as Products,ProductVideoWorker as W};
function pvAligned(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$b=P::body(['normal_source'=>'template','template_code'=>'detail','product_info'=>'白色杯子']);pvAligned(str_contains(P::prompt($b),'完整商品'),'Template must work without handwritten prompt');
$b=P::body(['mode'=>'advanced','prompt'=>'用户直接填写的拍法','prompt_review_images'=>[]]);pvAligned(str_contains(P::prompt($b),'用户直接填写'),'Advanced direct description must remain available');
$plan=['summary'=>'全貌到细节','product_info'=>'白色杯子','selling_points'=>[['label'=>'杯口细节','source_slots'=>['product_1']]],'video_prompt'=>'先全景，再展示杯口','shots'=>[['start'=>0,'end'=>2,'description'=>'全景'],['start'=>2,'end'=>5,'description'=>'特写']]];
$r=P::plan($plan,5);pvAligned($r['selling_points'][0]['source_slots']===['product_1']&&$r['video_prompt']===$plan['video_prompt'],'Advanced products, points and final prompt must survive planning');
$html='<html><script type="application/ld+json">{"@graph":[{"@type":"Product","name":"自有杯子","description":"可见白色杯身","image":["/own.png"]}]}</script></html>';
$m=Products::metadata($html,'https://shop.example.test/item');pvAligned($m['images']===['https://shop.example.test/own.png']&&str_contains($m['product_info'],'自有杯子'),'Structured product page must import actual metadata without provider calls');
$r=W::parse(['action'=>'optimize','request_json'=>'{}'],['content'=>'{"video_prompt":"缓慢推进，保持商品外观"}']);pvAligned(str_contains($r['video_prompt'],'保持商品外观'),'Prompt optimization must preserve its result');
echo "PASS template/direct creation, advanced output, product metadata and prompt optimization contracts\n";
