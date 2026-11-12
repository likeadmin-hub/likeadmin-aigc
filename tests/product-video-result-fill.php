<?php
// Pure result adoption checks: no database, model calls, queue work or billing.
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\app\aigc_product_video\{ProductVideoPolicy as P,ProductVideoService as S};
function resultFillAssert(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$apply=new ReflectionMethod(S::class,'applyResult');
$body=P::body(['mode'=>'advanced','product_info'=>'原商品信息','prompt'=>'原视频描述','images'=>[['uri'=>'uploads/sample.png']],
    'video_selection'=>['model_id'=>'sample','quality'=>'720p','ratio'=>'9:16','duration'=>5]]);
$data=['product_info'=>'图片可确认的商品信息','video_prompt'=>'完整展示商品后推近细节','summary'=>'商品全貌与细节',
    'shots'=>[['start'=>0,'end'=>2,'description'=>'完整商品'],['start'=>2,'end'=>5,'description'=>'局部细节']]];
foreach(['success','partial'] as $status){
    $filled=$apply->invoke(null,$body,['id'=>7,'action'=>'analysis','status'=>$status,'result_json'=>P::encode($data)]);
    resultFillAssert($filled['product_info']===$data['product_info'],'Analysis must fill the product information field');
    resultFillAssert($filled['prompt']===$data['video_prompt']&&$filled['plan']['data']['video_prompt']===$filled['prompt'],'Analysis must synchronize both description representations');
    resultFillAssert($filled['prompt_review_images']===['uploads/sample.png'],'Filled description must refer to the current product images');
    resultFillAssert($filled['plan']['input_key']===P::planKey($filled),'Filled product facts must not make their own plan stale');
    $normal=$filled;$normal['mode']='normal';
    resultFillAssert(str_contains(P::prompt($normal),$data['video_prompt']),'Switching to normal mode must retain the filled description');
}
foreach([null,''] as $legacyPrompt){
    $legacy=$data;unset($legacy['video_prompt']);if($legacyPrompt==='')$legacy['video_prompt']='';
    $filled=$apply->invoke(null,$body,['id'=>8,'action'=>'analysis','status'=>'success','result_json'=>P::encode($legacy)]);
    resultFillAssert(str_contains($filled['prompt'],'0-2秒：完整商品')&&str_contains($filled['prompt'],'2-5秒：局部细节'),'Older shot-only results must produce a visible complete description');
}
$legacy=$apply->invoke(null,$body,['id'=>9,'action'=>'plan','status'=>'success','result_json'=>P::encode($data)]);
resultFillAssert($legacy['product_info']===$body['product_info'],'Old plan results must preserve already confirmed product facts');
resultFillAssert($legacy['prompt']===$data['video_prompt'],'Old plan results must also fill the description field');
$uncertain=$data;$uncertain['product_info']='   ';
$preserved=$apply->invoke(null,$body,['id'=>12,'action'=>'analysis','status'=>'success','result_json'=>P::encode($uncertain)]);
resultFillAssert($preserved['product_info']===$body['product_info'],'Unconfirmed empty facts must not erase the existing product information');
$optimized=$apply->invoke(null,$filled,['id'=>10,'action'=>'optimize','status'=>'success','result_json'=>P::encode(['video_prompt'=>'增强后的描述'])]);
resultFillAssert($optimized['prompt']==='增强后的描述'&&$optimized['plan']['data']['video_prompt']===$optimized['prompt'],'Enhancement must not leave a stale normal-mode description');
try{$apply->invoke(null,$body,['id'=>11,'action'=>'analysis','status'=>'running','result_json'=>P::encode($data)]);throw new LogicException('Active work must not fill the form');}
catch(InvalidArgumentException $expected){}
resultFillAssert($body['product_info']==='原商品信息'&&$body['prompt']==='原视频描述','Result adoption cannot mutate a submitted snapshot');
echo "PASS product analysis fills product information and description, legacy results and mode continuity\n";
