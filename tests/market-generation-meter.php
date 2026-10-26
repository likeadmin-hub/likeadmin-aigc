<?php
require dirname(__DIR__).'/app/common/service/power/MarketGenerationSchema.php';
require dirname(__DIR__).'/app/common/service/power/MarketGenerationMeter.php';
use app\common\service\power\MarketGenerationMeter as M;
$checks=0;
function eq($actual,$expected,$message){global $checks;if($actual!==$expected)throw new RuntimeException($message.': '.json_encode([$actual,$expected]));$checks++;}
function snapshot($summary,$unit='business_unit',$context=[]){return ['billing_meter'=>M::contract(['usage_unit'=>$unit,'source_payload'=>['meter_summary'=>$summary.'，总点数向上取整']]),'meter_context'=>$context,'usage_unit_size'=>1];}
$seedance=snapshot('生成秒数 + 参考视频秒数','business_unit',['reference_video_seconds'=>7.25]);
eq(M::price(68,5,$seedance),833.0,'Seedance bills output plus measured input seconds, rounded up');
eq(M::price(68,10,$seedance),1173.0,'duration changes quote');
eq(M::price(68,4,$seedance),765.0,'actual output settles against frozen input duration');
eq(M::price(68,5,snapshot('生成秒数 + （参考视频秒数与生成秒数取较大值）','business_unit',['reference_video_seconds'=>2])),680.0,'Kling maximum input/output term');
eq(M::price(56,1,snapshot('生成张数 + 超过1的参考图片张数×2/56','business_unit',['reference_images'=>3])),60.0,'Seedream extra reference images use exact rational surcharge');
eq(M::price(26,2,snapshot('生成张数 + 参考图片张数×2/26','business_unit',['reference_images'=>3])),58.0,'Qwen reference image surcharge');
eq(M::price(52,5,snapshot('生成秒数 + 参考视频秒数 + 超过5的参考图片张数×4/13','business_unit',['reference_images'=>7,'reference_video_seconds'=>2])),396.0,'MiniMax video and image terms');
eq(M::price(16,2,snapshot('按次','pack')),16.0,'pack output count must not double charge one call');
eq(M::price(26,3,snapshot('生成张数','image')),78.0,'image quantity');
eq(M::price(1.1,1.1,snapshot('生成秒数','output_second')),2.0,'supplier whole-point rounding');
eq(M::price(1.1,1.1,['usage_unit_size'=>1]),1.21,'historical price snapshot keeps old rounding');
foreach(['business_unit','pack','token','unknown'] as $unit){try{M::contract(['usage_unit'=>$unit]);throw new RuntimeException('unsafe unit admitted');}catch(InvalidArgumentException $e){$checks++;}}
try{M::contract(['usage_unit'=>'business_unit','meter_summary'=>'新公式']);throw new RuntimeException('unknown formula admitted');}catch(InvalidArgumentException $e){$checks++;}
echo "PASS $checks meter, price and immutable snapshot checks\n";
