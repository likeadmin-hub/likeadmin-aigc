<?php
// Provider-result fixtures only: no database, generated media, model calls or billing.
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\app\aigc_product_video\{
    ProductVideoAnalysis as A, ProductVideoPolicy as P, ProductVideoService as S, ProductVideoWorker as W
};
$checks=0;
function structureCheck(bool $ok,string $why): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function structureRejects(callable $call,string $why): void {
    try{$call();}catch(InvalidArgumentException|RuntimeException $e){structureCheck(true,$why);return;}
    throw new RuntimeException($why);
}
$profile=['product_name'=>'白色随行杯','brand'=>'杯身自有标识','category'=>'饮水容器','colors'=>['白色','灰色'],
    'materials'=>'哑光表面','visible_text'=>'杯身清晰可见文字','structure'=>'杯身与杯盖',
    'back'=>'无法确认','interior'=>null,'dimensions'=>'未提供','product_anchor'=>'保持白色杯身、灰色杯盖及杯身标识',
    'source_notes'=>'根据商品正面与侧面图片；未提供背面或内部资料','unrequested'=>'不应进入输入框'];
$data=['product_profile'=>$profile,'product_ideas'=>['audience'=>'日常通勤人群','scenes'=>'桌面展示','style'=>'简洁明亮']];
$product=W::parse(['action'=>'product','request_json'=>'{}'],['content'=>P::encode($data)]);
$info=$product['product_info'];
structureCheck(str_contains($info,'[配色]：白色；灰色')&&str_contains($info,'[可辨认品牌]：杯身自有标识'),'Known scalar and list facts must have stable readable labels');
structureCheck(!str_contains($info,'[内部细节]')&&!str_contains($info,'[背面细节]')&&!str_contains($info,'[已确认规格]')&&!str_contains($info,'unrequested'),'Unknown fields and empty facts must not become product claims');
[$facts,$ideas]=explode('【创意建议，非商品事实】',$info);
structureCheck(!str_contains($facts,'通勤人群')&&str_contains($ideas,'通勤人群'),'Creative audiences and scenes must be visibly separate from product facts');
structureCheck(!str_contains($info,'product_profile')&&!str_contains($info,'{"'),'Editable product information cannot contain raw model JSON');
structureRejects(fn()=>W::parse(['action'=>'product','request_json'=>'{}'],['content'=>P::encode(['product_profile'=>['source_notes'=>'没有商品资料'],'product_ideas'=>['style'=>'漂亮']])]),'A provenance note and creative suggestions alone are not a recognized product');
structureRejects(fn()=>A::productInfo(['product_profile'=>['product_name'=>['value'=>'object']]]),'Unspecified nested model objects must not be flattened into factual text');
structureRejects(fn()=>A::productInfo(['product_profile'=>['not-an-object']]),'A list cannot be treated as the product profile');
$legacy=W::parse(['action'=>'product','request_json'=>'{}'],['content'=>P::encode(['product_info'=>"原有可见事实\n第二行"])]);
structureCheck($legacy['product_info']==="原有可见事实\n第二行",'Already submitted legacy product results must remain compatible');
$large=$data;
foreach(A::FACT_FIELDS as $key=>$label)$large['product_profile'][$key]=str_repeat('长商品细节',140);
$large['product_profile']['product_name']='商品身份';$large['product_profile']['brand']='已确认品牌';$large['product_profile']['product_anchor']='保持原有轮廓与标识';
$bounded=A::productInfo($large);
structureCheck(mb_strlen($bounded)<=3000&&str_contains($bounded,'商品身份')&&str_contains($bounded,'已确认品牌')&&str_contains($bounded,'保持原有轮廓与标识'),'Long supplementary detail must fit the editor while retaining product identity and consistency');

$body=P::body(['mode'=>'advanced','audio'=>false,'product_info'=>'原商品资料','images'=>[['uri'=>'uploads/own-product.png','reference_slot_id'=>'own_1']],
    'video_selection'=>['model_id'=>'own-video','quality'=>'720p','ratio'=>'16:9','duration'=>6]]);
$raw=$data+['summary'=>'整体到细节','video_prompt'=>'另一份过期镜头描述不应显示',
    'creative_brief'=>['subject'=>'白色随行杯','style'=>'干净柔和','audio'=>'不应保留的背景音乐','text'=>''],
    'selling_points'=>[['label'=>'杯盖外形','source_slots'=>['own_1']]],
    'shots'=>[
        ['start'=>0,'end'=>3,'action'=>'正面展示完整杯身和杯盖','framing'=>'中近景，正面','camera'=>'缓慢推进','scene'=>'简洁桌面','lighting'=>'侧前方柔光','focus'=>'杯身与杯盖清晰','sound'=>'不应保留的音效'],
        ['start'=>3,'end'=>6,'action'=>'展示侧面与杯盖连接处','framing'=>'侧面特写','camera'=>'固定机位','scene'=>'同一桌面','lighting'=>'保持同方向柔光','focus'=>'杯盖连接处清晰','sound'=>'不应保留的旁白'],
    ]];
$row=['action'=>'plan','request_json'=>P::encode(['duration'=>6,'snapshot'=>$body])];
$plan=W::parse($row,['content'=>P::encode($raw)]);
structureCheck($plan['product_info']===$info,'Advanced analysis and standalone recognition must use the same product structure');
structureCheck(str_contains($plan['video_prompt'],'画面比例：16:9')&&str_contains($plan['video_prompt'],'总时长：6秒'),'Description must use submitted settings rather than a fabricated ratio or duration');
foreach($plan['shots'] as $shot){
    structureCheck(substr_count($plan['video_prompt'],$shot['description'])===1,'The visible description must contain each validated timed shot exactly once');
    structureCheck(str_contains($shot['description'],'景别与视角：')&&str_contains($shot['description'],'运镜：')&&str_contains($shot['description'],'光线：'),'Structured filming details must survive normalization');
}
structureCheck(!str_contains($plan['video_prompt'],'另一份过期')&&!str_contains($plan['video_prompt'],'不应保留'),'One canonical description must override duplicate prose and honor the disabled audio switch globally and per shot');
structureCheck(str_contains($plan['video_prompt'],'不补造未展示的背面')&&str_contains($plan['video_prompt'],'不叠加额外字幕'),'Canonical description must retain global consistency and default overlay rules');
structureCheck(array_diff(array_keys($plan),['summary','shots','duration','product_info','video_prompt','selling_points'])===[],'The model-facing structure must not change the public result contract');
$invalid=$raw;$invalid['shots'][1]['start']=4;
structureRejects(fn()=>W::parse($row,['content'=>P::encode($invalid)]),'Rich output must still reject time gaps');
$invalid=$raw;$invalid['shots'][1]['end']=7;
structureRejects(fn()=>W::parse($row,['content'=>P::encode($invalid)]),'Rich output must still reject a mismatched duration');
$invalid=$raw;$invalid['shots'][0]['action']='';
structureRejects(fn()=>W::parse($row,['content'=>P::encode($invalid)]),'A structured shot without a subject or action cannot silently disappear');
$invalid=$raw;$invalid['creative_brief']=['not-an-object'];
structureRejects(fn()=>W::parse($row,['content'=>P::encode($invalid)]),'Malformed overall filming requirements must be rejected');

$apply=new ReflectionMethod(S::class,'applyResult');
$filled=$apply->invoke(null,$body,['id'=>501,'action'=>'analysis','status'=>'success','result_json'=>P::encode($plan)]);
structureCheck($filled['product_info']===$info&&$filled['prompt']===$plan['video_prompt']&&$filled['plan']['data']['video_prompt']===$filled['prompt'],'Structured analysis must fill both existing inputs without an API or form change');
structureCheck($filled['plan']['input_key']===P::planKey($filled),'Adopting richer product facts must not invalidate the adopted plan');
$generated=P::prompt($filled);
structureCheck(substr_count($generated,$plan['shots'][0]['description'])===1,'Video submission must not append the same shots to an already complete description');
$normal=$filled;$normal['mode']='normal';
structureCheck(str_contains(P::prompt($normal),$filled['prompt']),'Mode switching must preserve the complete description');
$edited=$filled;$edited['plan']['data']['video_prompt']='用户明确修改后的拍法';
structureCheck(str_contains(P::prompt($edited),'用户明确修改后的拍法')&&!str_contains(P::prompt($edited),'正面展示完整杯身'),'Manual description edits must not be contradicted by appended stale shot prose');
$old=['summary'=>'旧版描述','shots'=>[['start'=>0,'end'=>3,'description'=>'旧版全景'],['start'=>3,'end'=>6,'description'=>'旧版特写']]];
$oldParsed=W::parse($row,['content'=>P::encode($old)]);
structureCheck(str_contains(P::planDescription($oldParsed),'3-6秒：旧版特写'),'Legacy shot-only results must retain the readable fallback');
foreach(['write','optimize'] as $action){$r=W::parse(['action'=>$action,'request_json'=>'{}'],['content'=>'{"video_prompt":"用户已有描述的兼容结果"}']);structureCheck($r['video_prompt']==='用户已有描述的兼容结果','Existing writing and enhancement receipts must retain the same response shape');}
echo "PASS product analysis structure, canonical filming description and legacy adoption ($checks checks)\n";
