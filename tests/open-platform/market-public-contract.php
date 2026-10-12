<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\{OpenMarketContractCompiler as Compiler,OpenContracts as C,OpenGateway as G,OpenDocuments as D,OpenException};
function checkPublic($ok,$why){if(!$ok)throw new RuntimeException($why);}
$product=['id'=>999001,'name'=>'Public fixture','resource_type'=>'model','model_type'=>'image','upstream_model_code'=>'fixture','upstream_channel_code'=>'channel-a','source_payload'=>['market_metadata'=>['call_type'=>2,'method'=>'POST','params_schema'=>['type'=>'object','properties'=>['parameters'=>['type'=>'object','properties'=>['quality'=>['type'=>'string','default'=>'1k']]]]],'developer_doc_content'=>"```json\n{\"code\":1,\"data\":{\"task_id\":\"fixture-task\"}}\n```"]],'skus'=>[['id'=>999002,'sku_key'=>'default','usage_unit'=>'per_call','usage_unit_size'=>1,'sale_points'=>2,'sale_status'=>1,'locked_params'=>['quality'=>'1k']]]];
$draft=Compiler::compileProduct($product)['drafts'][0];$config=$draft['config'];
checkPublic(!in_array('channel',$config['input']['required'],true)&&!isset($config['when']['channel'])&&$config['routing_channel']==='channel-a','Unique model forced an extra SDK argument');
checkPublic(!in_array('parameters',$config['input']['required'],true)&&C::selected($config,['model'=>'fixture']),'Documented optional default requires caller override');
checkPublic(!C::selected($config,['model'=>'fixture','channel'=>'foreign']),'Caller changed trusted supplier route');
checkPublic($config['billing'][0]['parameter_paths']['quality']==='parameters.quality'&&$config['when_defaults']['parameters.quality']==='1k','Nested fixed-price selector lost documented default or SKU parameter path');
$wire=new ReflectionMethod(G::class,'marketParameters');$raw=['model'=>'fixture','input'=>(object)['prompt'=>'example']];$sent=$wire->invoke(null,$config,$raw);
checkPublic(!isset($raw['channel'])&&$sent['channel']==='channel-a'&&$sent['input']===$raw['input'],'Routing mutated caller input or changed business fields');
$duplicate=Compiler::compileProduct($product,[],[],true)['drafts'][0]['config'];checkPublic(in_array('channel',$duplicate['input']['required'],true)&&!C::selected($duplicate,['model'=>'fixture']),'Ambiguous model routes guessed a channel');
$render=(new ReflectionMethod(Compiler::class,'document'))->invoke(null,Compiler::compileProduct($product),$draft);
foreach(['来源摘要','真实网关回执','待验收草稿'] as $internal)checkPublic(!str_contains($render,$internal),'Internal publication workflow reached user documentation');
foreach(['Authorization','Idempotency-Key','X-Request-Id','Python','JavaScript'] as $guide)checkPublic(str_contains($render,$guide),'Public guide missing integration detail');
$reviewed=['compiler'=>['source_digest'=>'private-audit'],'responses'=>['200'=>['description'=>'Internal receipt instruction','content'=>['application/json'=>['schema'=>['type'=>'object']]]]]];$public=D::publicResponses($reviewed);
checkPublic($public['200']['description']==='HTTP 200 response'&&$public['200']['content']===$reviewed['responses']['200']['content']&&$reviewed['responses']['200']['description']==='Internal receipt instruction','Public response projection changes private contract or protocol schema');
checkPublic(D::publicResponses(['responses'=>$reviewed['responses']])===$reviewed['responses'],'Unrelated authored response description was overwritten');
foreach([['usage_unit'=>'per_call','usage_unit_size'=>1,'source_payload'=>['usage_unit'=>'output_second','usage_unit_size'=>1]],['usage_unit'=>'character','usage_unit_size'=>1000,'source_payload'=>['usage_unit'=>'character','usage_unit_size'=>1]]] as $mismatch){try{app\common\service\open_platform\OpenSkuConsistency::assert($mismatch);throw new RuntimeException('Mismatched upstream unit accepted');}catch(OpenException $e){checkPublic($e->errorType==='pricing_spec_mismatch','Wrong unit mismatch rejection');}}
app\common\service\open_platform\OpenSkuConsistency::assert(['usage_unit'=>'per_call','usage_unit_size'=>1,'source_payload'=>['usage_unit'=>'per_call','usage_unit_size'=>1]]);
echo "PASS optional documented defaults and unique model SDK routing, ambiguous channel fence, immutable public response projection and user integration guide\n";
