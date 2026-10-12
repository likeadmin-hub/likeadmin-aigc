<?php
/** Static protocol compilation only: no transport, sync, import or publication. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App())->initialize();
use app\common\service\open_platform\{OpenMarketProtocolSchemas as P, OpenMarketContractCompiler as C, OpenSchema as S, OpenMarketResponse as R, OpenNativeMarketPricing as N, OpenStore};
function checkProtocol(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$product = ['id'=>990001,'name'=>'Protocol fixture','resource_type'=>'model','model_type'=>'image','upstream_model_code'=>'fixture-image','upstream_channel_code'=>'fixture-channel','source_payload'=>['market_metadata'=>['call_type'=>2,'params_schema'=>['type'=>'object','properties'=>['prompt'=>['type'=>'string','example'=>'A paper boat on a quiet river.'],'type'=>['type'=>'string'],'content'=>['type'=>'array','items'=>['type'=>'object','properties'=>['type'=>['type'=>'string']]]]],'required'=>['prompt']]]],'skus'=>[['id'=>990002,'sku_key'=>'fixture','usage_unit'=>'per_call','usage_unit_size'=>1,'sale_points'=>1.23,'sale_status'=>1,'locked_params'=>[]]]];
$row=C::compileProduct($product);checkProtocol(count($row['drafts'])===1,'Registered model_task must compile without a prose JSON response sample');
$config=$row['drafts'][0]['config'];checkProtocol($config['input']['properties']['type']['type']==='string'&&$config['input']['properties']['content']['items']['properties']['type']['type']==='string','Business type fields were lost');
checkProtocol($config['compiler']['version']===2&&$config['compiler']['schema_sources']['response']['registry_version']===1&&!isset($config['verification']),'Registered structure must have versioned sources and no fake verification');
checkProtocol(isset($config['query_responses']['200']['content']['application/json']['schema']['properties']['data']['properties']['type']),'Query type schema missing');
foreach(['responses','query_responses'] as $field){
    checkProtocol(R::schemaSupported($config[$field]),'Registered financial schema is unsupported');
    $content=$config[$field]['200']['content']['application/json'];
    foreach($content['examples'] as $key=>$example){$body=$example['value'];S::validate($body,$content['schema'],'$',0,null,true);checkProtocol(R::bodySupported(OpenStore::encode($body)),'Registered example bypassed financial body rules');
        checkProtocol(!isset($body->data->model)||$body->data->model==='fixture-image','Static example model not bound to public product');
        foreach(['frozen_points','actual_points'] as $money)checkProtocol(!isset($body->data->{$money})||$body->data->{$money}===0,'Static amount is not neutral');
        checkProtocol(!isset($body->data->usage->points_cost)||$body->data->usage->points_cost===0,'Example exposes a supplier amount');
        if($key==='query_image_completion')checkProtocol($body->data->result->opaque_business->object instanceof \stdClass&&$body->data->result->opaque_business->list===[],'Typed example objects/lists collapsed');
        if($key==='model_sync_completion')checkProtocol(N::settledUnits(['kind'=>'image'],(array)$body,false)===1.,'Registered completed results sample cannot settle image slots');
    }
}
$fraction=$config['responses']['200']['content']['application/json']['examples']['model_async_submit']['value'];$fraction->data->frozen_points=1.23;S::validate($fraction,$config['responses']['200']['content']['application/json']['schema'],'$',0,null,true);
$fresh=P::modelTask($product);checkProtocol($fresh['submit_responses']['200']['content']['application/json']['examples']['model_async_submit']['value']->data->frozen_points===0,'Registry example mutation leaked into later compilation');
$manual=['review_note'=>'Intentionally absent reviewed responses','responses'=>[]];checkProtocol(!C::compileProduct($product,[],$manual)['drafts'],'Registry must not replace explicit reviewed responses');
$source=$product;$source['source_payload']['market_metadata']['developer_doc_content']="```json\n{\"code\":0,\"data\":{\"custom\":{\"points_cost\":999}}}\n```";checkProtocol(in_array('response_projection_required',array_column(C::compileProduct($source)['blockers'],'code'),true),'Fallback erased original source financial evidence');
$declared=$product;$declared['source_payload']['market_metadata']['developer_doc_content']="```json\n{\"code\":1,\"data\":{\"task_id\":\"declared\",\"custom\":\"kept\"}}\n```";$declaredRow=C::compileProduct($declared);checkProtocol(isset($declaredRow['drafts'][0]['config']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties']['custom'])&&!isset($declaredRow['schema_sources']['response']),'Declared response replaced by generic registry');
$unknown=$product;unset($unknown['source_payload']['market_metadata']['params_schema']);checkProtocol(in_array('schema_missing',array_column(C::compileProduct($unknown)['blockers'],'code'),true),'Generic routing envelope invented missing model business inputs');
$text=$product;$text['model_type']='text';$text['upstream_channel_code']='';$text['skus'][0]['usage_unit']='token';$text['source_payload']['market_metadata']=['call_type'=>1,'protocols'=>['openai_chat','openai_completions','openai_responses','anthropic_messages']];$textRow=C::compileProduct($text);checkProtocol(count($textRow['candidates'])===4&&!$textRow['drafts'],'Token limits were fabricated');
foreach($textRow['candidates'] as $candidate){checkProtocol(in_array('actual_usage_boundary_required',array_column($candidate['blockers'],'code'),true),'Token boundary blocker removed');$input=$candidate['input'];$example=$candidate['example'];S::validateRequest($example,$input,true);
    $field=match($candidate['protocol']){'openai_completions'=>'prompt','openai_responses'=>'input',default=>'messages'};
    checkProtocol(in_array($field,$input['required'],true)&&!isset($input['properties']['max_tokens']['maximum'])&&!isset($input['properties']['max_output_tokens']['maximum']),'Protocol-specific required input or unknown quota changed');
}
foreach(['grok_video','nano_banana'] as $app){$appProduct=$product;$appProduct['resource_type']='app_api';$appProduct['model_type']='';$appProduct['upstream_model_code']='';$appProduct['upstream_channel_code']='';$appProduct['upstream_app_code']=$app;$appProduct['upstream_api_code']='submit';$appProduct['source_payload']['market_metadata']=[];
    $appRow=C::compileProduct($appProduct);checkProtocol($appRow['schema_sources']['input']['definition']==='app.'.$app.'.submit'&&!in_array('schema_missing',array_column($appRow['blockers'],'code'),true),'Primary app request schema not supplied');
    checkProtocol(in_array('response_schema_missing',array_column($appRow['blockers'],'code'),true)&&!$appRow['drafts'],'App acquired an unreviewed generic model response');
    S::validateRequest($appRow['candidates'][0]['example'],$appRow['candidates'][0]['input'],true);
    if($app==='grok_video'){$appProduct['skus'][0]['source_payload']=['usage_unit'=>'output_second','usage_unit_size'=>1];checkProtocol(in_array('pricing_spec_mismatch',array_column(C::compileProduct($appProduct)['blockers'],'code'),true),'Request fallback bypassed Grok unit drift');}
}
checkProtocol(P::applicationInput('unknown_app','submit')===null&&P::definition('vendor_magic')===null,'Registry invented an executor or arbitrary route');
$merge=new ReflectionMethod(\app\common\service\power\PowerMarketService::class,'mergeAppApiRequestFields');$merge->setAccessible(true);
$api=['id'=>71,'code'=>'submit','pricing_v2'=>['commercial_override'=>'preserved']];$detail=['id'=>71,'code'=>'submit','params_schema'=>['type'=>'object'],'default_params'=>['duration'=>8],'usage_unit'=>'output_second','fixed_price'=>999,'api_key'=>'private','endpoint_path'=>'/api/v1/apps/grok_video/submit'];
$merged=$merge->invoke(null,$api,[$detail]);checkProtocol($merged['params_schema']===['type'=>'object']&&$merged['pricing_v2']===$api['pricing_v2']&&!array_intersect(['usage_unit','fixed_price','api_key'],array_keys($merged)),'App detail merge touched commercial/private fields');
checkProtocol($merge->invoke(null,$api,[array_replace($detail,['id'=>72])])===$api,'Different API identity supplied request schema');
$document=new ReflectionMethod(C::class,'document');$document->setAccessible(true);$content=$document->invoke(null,$row,$row['drafts'][0]);checkProtocol(str_contains($content,'提交响应结构示例')&&str_contains($content,'任务查询结构示例')&&str_contains($content,'中性占位值')&&!str_contains($content,'vendor_secret'),'Draft documentation omits neutral response examples');
echo "PASS source-derived model submit/query schemas, neutral typed examples, financial source gates, protocol-specific blocked Token docs, exact app request snapshots and unit/metadata boundaries; no transport/import/publication\n";
