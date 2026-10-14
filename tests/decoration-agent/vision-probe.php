<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php'; (new think\App())->initialize();
set_exception_handler(static function(Throwable $e):void {fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
use app\common\service\power\MarketTextModelRuntimeService as T;
$checks=0;
function checkProbe(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
checkProbe(method_exists(T::class,'probeImageCapability'),'A separate operator capability probe entry is required');
$method=new ReflectionMethod(T::class,'visionProbeParams');$method->setAccessible(true);
$image='data:image/png;base64,'.base64_encode(file_get_contents(__DIR__.'/../../public/resource/image/tenantapi/default/banner002.png'));
$p=$method->invoke(null,1,['id'=>175],'描述两张图的差异',[$image,$image]);
checkProbe(count($p['reference_images'])===2,'A comparison probe transports both actual images');
checkProbe($p['model_selection']===['id'=>175],'The selected model remains exact');
foreach(['_disable_model_fallback','_disable_transient_retry','_disable_compatibility_retry','_require_final_content','_decoration_preserve_uncertain'] as $flag)checkProbe($p[$flag]===true,'Probe forbids hidden fallback/retry or uncertain settlement: '.$flag);
checkProbe($p['action_code']==='decoration_capability_probe'&&$p['business_table']==='decoration_vision_probe','Probe has its own audit scope');
foreach([[0,['id'=>175],'test',[$image]],[1,[],'test',[$image]],[1,['id'=>175],'',[$image]],[1,['id'=>175],'test',[]],[1,['id'=>175],'test',[$image,$image,$image]],[1,['id'=>175],'test',['https://example.com/anything.png']],[1,['id'=>175],'test',['data:image/png;base64,broken']]] as $args){try{$method->invokeArgs(null,$args);throw new RuntimeException('Invalid probe accepted');}catch(InvalidArgumentException $e){$checks++;}}
$guard=new ReflectionMethod(T::class,'assertVisionProbeScope');$guard->setAccessible(true);
$guard->invoke(null,1,0,$p,true);$checks++;
foreach([[1,2,$p,true],[1,0,$p,false],[0,0,$p,true],[1,0,array_replace($p,['action_code'=>'text_generate']),true],[1,0,array_replace($p,['_disable_model_fallback'=>false]),true]] as $args){try{$guard->invokeArgs(null,$args);throw new RuntimeException('Invalid probe operator accepted');}catch(InvalidArgumentException $e){$checks++;}}
$identity=new ReflectionMethod(T::class,'assertVisionProbeModel');$identity->setAccessible(true);
$model=['id'=>175,'market_sku_id'=>682,'market_input_sku_id'=>682,'market_output_sku_id'=>683];
$identity->invoke(null,['id'=>175,'sku_id'=>682],$model);$checks++;
foreach([['id'=>174,'sku_id'=>682],['id'=>175,'sku_id'=>99999999],['id'=>175,'product_id'=>176],['id'=>175,'market_sku_id'=>0]] as $selection){try{$identity->invoke(null,$selection,$model);throw new RuntimeException('Invalid resolved probe identity accepted');}catch(InvalidArgumentException $e){$checks++;}}
$uncertain=new ReflectionMethod(T::class,'preserveUncertainSubmission');$uncertain->setAccessible(true);
foreach(['cURL error 28: timeout','connection reset','empty response'] as $message)checkProbe($uncertain->invoke(null,true,'decoration_capability_probe',$p,$message,true),'An ambiguous probe must preserve its pending receipt');
checkProbe(!$uncertain->invoke(null,true,'decoration_capability_probe',$p,'upstream model unavailable',true),'A confirmed rejection remains a confirmed failure');
checkProbe(!$uncertain->invoke(null,true,'decoration_capability_probe',$p,'timeout',false),'Client params cannot enable the operator probe policy');
checkProbe($uncertain->invoke(null,true,'decoration_agent_text',$p,'timeout',false),'Existing decoration uncertain handling is unchanged');
$transport=new ReflectionMethod(T::class,'initialTransportOptions');$transport->setAccessible(true);
$wire=$transport->invoke(null,$model+['protocol'=>'openai_responses'],$p,true,true);
checkProbe($wire['include_market_context']===false&&$wire['include_channel']===true,'A trusted probe uses the same strict native Responses transport');
checkProbe($transport->invoke(null,$model+['protocol'=>'openai_responses'],$p,true,false)['include_market_context']===true,'Normal calls cannot opt into the server-only probe transport');
echo "vision probe: $checks checks passed (no provider calls or capability writes)\n";
