<?php
require __DIR__.'/lifecycle.php';
use app\common\service\power\TextModelExecutionPolicy as Policy;
check(class_exists(Policy::class),'pinned model policy missing');
$m=['id'=>7,'model_code'=>'selected','channel_code'=>'channel','input'=>['sku_id'=>1,'tenant_price'=>1],'output'=>['sku_id'=>2,'tenant_price'=>2]];
$policy=new Policy('aigc_copywriting',Policy::fingerprint($m));$policy->assertModel($m,'aigc_copywriting');$m['name']='renamed';$policy->assertModel($m,'aigc_copywriting');$m['input']['tenant_price']=3;
try{$policy->assertModel($m,'aigc_copywriting');throw new RuntimeException('changed price accepted');}catch(InvalidArgumentException $e){check($e->getMessage()==='MODEL_OR_PRICE_CHANGED','price change reason');}
echo "copywriting model policy PASS\n";

check($policy->generationParams(['model_code'=>'deepseek-v4-pro','protocol'=>'openai_chat'],[])['thinking']['type']==='disabled','copywriting disables default DeepSeek thinking');check($policy->generationParams(['model_code'=>'qwen3.6-plus','protocol'=>'openai_chat'],[])['enable_thinking']===false,'copywriting disables Qwen thinking');check($policy->generationParams(['model_code'=>'other-model'],[])===[],'ordinary models receive no unsupported parameters');
