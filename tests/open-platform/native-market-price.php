<?php
/** No transport/execution: pure binder plus rollback-only native SKU and point fixtures. */
require dirname(__DIR__,2).'/vendor/autoload.php';
use think\facade\Db;
use app\common\service\open_platform\{OpenNativeMarketPricing as N,OpenMarketBilling as B,OpenMarketContractCompiler as Compiler,OpenStore as S,OpenCallContext as C,OpenException,OpenVerification};
use app\common\model\power\{PowerMarketProduct as Product,PowerMarketSku as Sku,TenantPowerMarketSkuPrice as Retail};
use app\common\service\power\{MarketUsageSettlementService as Usage,MarketVideoRuntimeService as Video};
$nativeFailureHandler=static function(\Throwable $error): void {
 fwrite(STDERR,'FAIL native-market-price error_type='.($error instanceof OpenException?$error->errorType:get_class($error)).' file='.basename($error->getFile()).' line='.$error->getLine().PHP_EOL);
 exit(1);
};
set_exception_handler($nativeFailureHandler);
try{(new think\App())->initialize();}catch(\Throwable $error){$nativeFailureHandler($error);}
// Think initialization installs its own handler; restore strict CLI failure.
set_exception_handler($nativeFailureHandler);
function checkNative($ok,$why){if(!$ok)throw new RuntimeException($why);}
function rejectNative(callable $call,string $type){
 $line=(int)(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,1)[0]['line']??0);
 try{$call();}
 catch(OpenException $e){fwrite(STDERR,'CHECK rejectNative line='.$line.' expected='.$type.' actual='.$e->errorType.PHP_EOL);checkNative($e->errorType===$type,'Unexpected rejection type');return;}
 catch(\Throwable $error){fwrite(STDERR,'CHECK rejectNative line='.$line.' expected='.$type.' actual='.get_class($error).PHP_EOL);throw $error;}
 fwrite(STDERR,'CHECK rejectNative line='.$line.' expected='.$type.' actual=no_rejection'.PHP_EOL);throw new RuntimeException('Expected rejection was not observed');
}
$product=['id'=>900001,'resource_type'=>'model','model_type'=>'image','upstream_model_code'=>'fixture-image','upstream_channel_code'=>'fixture-channel'];
$raw=json_decode('{"model":"fixture-image","channel":"fixture-channel","input":{"messages":[{"content":[{"image":"https://example.com/owned.png"},{"text":"unchanged"}]}]},"parameters":{"n":2,"size":"1024*1024","quality":"1k"}}',true);
$before=S::encode($raw);$binding=N::bind($product,$raw,900002);checkNative(S::encode($raw)===$before&&$binding['selection']['quantity']===2&&count($binding['selection']['reference_images'])===1,'Structured pricing mapping changed wire JSON or reference modality');
checkNative(N::bind($product,['params'=>['quantity'=>3,'aspect_ratio'=>'16:9']],900002)['selection']['quantity']===3,'Nested params aliases ignored');
checkNative(N::bind($product,['num_images'=>'3','reference_image_urls'=>['https://example.com/a']],900002)['selection']['quantity']===3,'Native num_images batch quantity ignored');
checkNative(N::bind($product,['reference_image_urls'=>['https://example.com/a'],'generation_type'=>'image_to_image'],900002)['selection']['provider_params']['reference_image_urls']===['https://example.com/a'],'Native billable reference inputs lost during projection');
foreach([['n'=>2,'parameters'=>['n'=>1]],['quality'=>'1k','params'=>['quality'=>'2k']],['duration'=>5,'parameters'=>['seconds'=>6]],['reference_images'=>['a'],'input'=>['messages'=>[['content'=>[['image'=>'b']]]]]]] as $bad)rejectNative(fn()=>N::bind($product,$bad,900002),'invalid_request');
checkNative(N::bind($product,['quality'=>'high','parameters'=>['resolution'=>'2k']],900002,['locked_params'=>['resolution'=>'2k']])['selection']['quality']==='2k','Independent render quality conflicted with price resolution');
rejectNative(fn()=>N::bind($product,['n'=>0],900002),'invalid_request');rejectNative(fn()=>N::bind($product,['n'=>1.5],900002),'invalid_request');rejectNative(fn()=>N::bind($product,['model'=>'foreign'],900002),'invalid_sku');
$imageSnapshot=['kind'=>'image','submitted_units'=>3,'market_snapshot'=>[]];
checkNative(N::settledUnits($imageSnapshot,['data'=>['result'=>['images'=>[['url'=>'https://example.com/a'],['url'=>'https://example.com/b']]]]],false)===2.,'Partial-success images charged submitted quantity');
$duplicate=[['url'=>'https://example.com/a'],['url'=>'https://example.com/a']];checkNative(N::settledUnits($imageSnapshot,['images'=>$duplicate,'data'=>['result'=>['images'=>$duplicate]]],false)===2.,'Duplicate successful slots or equal aliases changed quantity');
checkNative(N::settledUnits($imageSnapshot,['images'=>$duplicate,'data'=>['images'=>[['url'=>'https://example.com/other']]]],false)===null,'Conflicting result aliases silently repriced usage');
checkNative(N::settledUnits($imageSnapshot,['data'=>['result'=>[]]],false)===null,'Missing output became zero-cost completion');
checkNative(N::settledUnits($imageSnapshot,[],true)===0.,'Confirmed failure retained generation charge');
$sourceCompleted=json_decode('{"code":1,"msg":"success","data":{"task_id":"task_source_fixture","model":"fixture-image","type":"image","call_type":"async","status":"completed","created_at":null,"result":{"results":[{"url":"https://example.com/a","type":"image","transfer_status":"success"},{"url":"https://example.com/a","type":"image","transfer_status":"success"}],"vendor_metadata":{}},"usage":{"input_tokens":0,"output_tokens":0,"total_tokens":0,"points_cost":1.23}}}',true);
checkNative(N::settledUnits($imageSnapshot,$sourceCompleted,false)===2.,'Actual TaskController completed results payload stayed pending usage or deduplicated slots');
$topResults=$sourceCompleted;$topResults['data']['results']=$topResults['data']['result']['results'];unset($topResults['data']['result']);checkNative(N::settledUnits($imageSnapshot,$topResults,false)===2.,'Configured top-level image results were ignored');
$equalResults=$sourceCompleted;$equalResults['images']=['https://example.com/a','https://example.com/a'];checkNative(N::settledUnits($imageSnapshot,$equalResults,false)===2.,'Equivalent URL and dictionary aliases double charged or conflicted');
$conflictingResults=$equalResults;$conflictingResults['images']=['https://example.com/other'];checkNative(N::settledUnits($imageSnapshot,$conflictingResults,false)===null,'Conflicting images/results aliases must await trustworthy usage');
$partialResults=['data'=>['result'=>['results'=>[['url'=>'https://example.com/a','type'=>'image','transfer_status'=>'success'],['url'=>'https://example.com/b','type'=>'image','transfer_status'=>'failed'],['url'=>'','type'=>'image','transfer_status'=>'hidden']]]]];
checkNative(N::settledUnits($imageSnapshot,$partialResults,false)===1.,'Failed or hidden transfer slots charged the complete submitted image quantity');
$pendingResults=$partialResults;$pendingResults['data']['result']['results'][1]['transfer_status']='pending';checkNative(N::settledUnits($imageSnapshot,$pendingResults,false)===null,'Pending transfer settled before evidence was complete');
foreach([null,'','unknown'] as $unknownTransfer)checkNative(N::settledUnits($imageSnapshot,['results'=>[['url'=>'https://example.com/a','type'=>'image','transfer_status'=>$unknownTransfer]]],false)===null,'Unknown transfer evidence charged a successful slot');
checkNative(N::settledUnits($imageSnapshot,['results'=>[['url'=>'https://example.com/video.mp4','type'=>'video']]],false)===null,'Video result became a successful image slot');
checkNative(N::settledUnits($imageSnapshot,['results'=>[['url'=>'https://example.com/a','type'=>'image'],['url'=>'https://example.com/video.mp4','type'=>'video']]],false)===1.,'Mixed results counted non-image output');
checkNative(N::settledUnits($imageSnapshot,['results'=>[['url'=>'https://example.com/a','type'=>'provider_magic']]],false)===null,'Unknown result type settled without a reviewed adapter');
checkNative(N::settledUnits($imageSnapshot,['results'=>[['url'=>'https://example.com/a','type'=>'image','transfer_status'=>'failed']]],false)===null,'No successful transferred image fabricated a zero-cost completion');
checkNative(N::settledUnits($imageSnapshot,['data'=>json_decode('{"results":[{"url":"https://example.com/a","type":"image"}]}')],false)===1.,'Typed JSON result dictionaries were ignored');

checkNative(Video::openPlatformSettlementQuantity(['usage_unit'=>'per_call','quantity'=>1],['data'=>['usage'=>['duration'=>15]]])===1.,'Per-call video multiplied seconds');
checkNative(Video::openPlatformSettlementQuantity(['usage_unit'=>'input_second','quantity'=>6],['data'=>['usage'=>['duration'=>4]]])===4.,'Measured video quantity not shared with native settlement');
checkNative(Video::openPlatformSettlementQuantity(['usage_unit'=>'input_second','quantity'=>6],['data'=>['usage'=>['total_tokens'=>1,'duration'=>4]]])===4.,'Token count incorrectly charged as video seconds');
checkNative(Video::openPlatformSettlementQuantity(['usage_unit'=>'output_second','quantity'=>6],['data'=>['usage'=>['total_tokens'=>10,'input_seconds'=>4]]])===null,'Missing output seconds fabricated submitted output quantity');
checkNative(Video::openPlatformSettlementQuantity(['usage_unit'=>'output_second','quantity'=>6],['data'=>['usage'=>['duration'=>4],'result'=>['output_seconds'=>2.5]]])===2.5,'Corresponding output seconds did not take precedence or were rounded up');
checkNative(Video::openPlatformSettlementQuantity(['usage_unit'=>'token','quantity'=>6],['data'=>['usage'=>['total_tokens'=>1,'duration'=>4]]])===1.,'Token SKU interpreted seconds as tokens');
checkNative(Video::openPlatformSettlementQuantity(['usage_unit'=>'token','quantity'=>0],['data'=>['result'=>[]]])===null,'Token output without usage fabricated a price');
checkNative(Usage::price(12,3,['usage_unit_size'=>2])===18.,'Native billing-unit divisor lost');

$db=config('database.connections.mysql');checkNative($db['hostname']==='127.0.0.1'&&$db['database']===(getenv('GENERATION_TEST_DATABASE') ?: 'z_cn'),'Local rollback only');
$wallets=fn()=>[(float)Db::name('user')->where('id',1)->value('user_money'),(float)Db::name('tenant')->where('id',1)->value('point_balance')];$walletBefore=$wallets();
Db::startTrans();try{
 // Current pricing is rule-driven, not the legacy sale_points column. Isolate fixture rules.
 Db::name('power_market_price_rule')->whereIn('owner_id',[0,1])->delete();
 request()->tenantId=1;request()->userId=1;
 $base=Product::where(['resource_type'=>'model','model_type'=>'image','status'=>1])->find()->toArray();unset($base['id']);$base['create_time']=time();$base['update_time']=time();
 $skuBase=Sku::where(['status'=>1,'sale_status'=>1])->find()->toArray();unset($skuBase['id']);$skuBase['upstream_price']=3;$skuBase['source_payload']=['usage_unit'=>'per_call','usage_unit_size'=>1];$skuBase['create_time']=time();$skuBase['update_time']=time();$tag=bin2hex(random_bytes(5));
 $meta=['method'=>'POST','call_type'=>2,'max_reference_images'=>2,'supports_reference_images'=>true,'protocol'=>'image_generate','params_schema'=>['model'=>['type'=>'string'],'channel'=>['type'=>'string'],'parameters'=>['type'=>'object','children'=>['quality'=>['type'=>'string','enum'=>['1k','2k'],'required'=>true],'n'=>['type'=>'integer','minimum'=>1,'maximum'=>3],'size'=>['type'=>'string']]]],'developer_doc_content'=>"```json\n{\"model\":\"fixture-image\",\"channel\":\"fixture-channel\",\"parameters\":{\"quality\":\"1k\",\"n\":2}}\n```\n```json\n{\"code\":1,\"data\":{\"task_id\":\"known-task\",\"result\":{\"images\":[]}}}\n```"];
 $p=Product::create(array_replace($base,['name'=>'Native binding fixture','product_code'=>'opf_native_'.$tag,'upstream_resource_key'=>'opf_native_'.$tag,'upstream_model_code'=>'fixture-image','upstream_channel_code'=>'fixture-channel','source_payload'=>['market_metadata'=>$meta]]));
 $s=Sku::create(array_replace($skuBase,['product_id'=>$p['id'],'sku_key'=>'opf_native_'.$tag,'usage_unit'=>'per_call','usage_unit_size'=>1,'sale_points'=>3,'locked_params'=>['quality'=>'1k'],'status'=>1,'sale_status'=>1]));
 $s2=Sku::create(array_replace($skuBase,['product_id'=>$p['id'],'sku_key'=>'opf_native_2k_'.$tag,'usage_unit'=>'per_call','usage_unit_size'=>1,'sale_points'=>4,'locked_params'=>['quality'=>'2k'],'status'=>1,'sale_status'=>1]));
 Retail::create(['tenant_id'=>1,'sku_id'=>$s['id'],'sale_status'=>1,'sale_points'=>5,'create_time'=>time(),'update_time'=>time()]);
 \app\common\service\power\MarketPriceRuleService::manual(1,(int)$p['id'],(int)$s['id'],5);
 $params=['model'=>'fixture-image','channel'=>'fixture-channel','parameters'=>['quality'=>'1k','n'=>2]];$wire=S::encode($params);
 $budget=N::capture(1,$p->toArray(),$params,(int)$s['id']);checkNative($budget['user']===10.&&$budget['tenant']===6.&&S::encode($params)===$wire,'Native quote lost tenant retail, n or raw payload');
 $file='of_'.bin2hex(random_bytes(16));S::table('file')->insert(['tenant_id'=>1,'user_id'=>1,'file_id'=>$file,'uri'=>'uploads/native_fixture.png','name'=>'native fixture','mime'=>'image/png','size'=>1,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']+S::timestamp());rejectNative(fn()=>N::capture(1,$p->toArray(),['reference_images'=>[$file]],(int)$s['id']),'local_material_mapping_required');
 rejectNative(fn()=>N::capture(1,$p->toArray(),['parameters'=>['quality'=>'2k','n'=>2]],(int)$s['id']),'invalid_sku');
 // Independent quality/resolution needs its own exact declaration. The first
 // fixture requires parameters.quality and cannot validate this different wire.
 $splitMeta=$meta;$splitMeta['params_schema']['quality']=['type'=>'string','enum'=>['high'],'required'=>true];
 $splitMeta['params_schema']['parameters']['children']=['resolution'=>['type'=>'string','enum'=>['1k','2k'],'required'=>true],'n'=>['type'=>'integer','minimum'=>1,'maximum'=>3]];
 $splitProduct=Product::create(array_replace($base,['name'=>'Independent quality fixture','product_code'=>'opf_native_split_'.$tag,'upstream_resource_key'=>'opf_native_split_'.$tag,'upstream_model_code'=>'fixture-image','upstream_channel_code'=>'fixture-channel','source_payload'=>['market_metadata'=>$splitMeta]]));
 $split=Sku::create(array_replace($skuBase,['product_id'=>$splitProduct['id'],'sku_key'=>'opf_native_split_'.$tag,'usage_unit'=>'per_call','usage_unit_size'=>1,'sale_points'=>7,'locked_params'=>['resolution'=>'2k'],'status'=>1,'sale_status'=>1]));
 $split->save(['upstream_price'=>7]);
 $splitRaw=['model'=>'fixture-image','quality'=>'high','parameters'=>['resolution'=>'2k','n'=>2]];$splitWire=S::encode($splitRaw);$splitBudget=N::capture(1,$splitProduct->toArray(),$splitRaw,(int)$split['id']);checkNative($splitBudget['user']===14.&&$splitBudget['tenant']===14.&&S::encode($splitRaw)===$splitWire,'Independent quality/resolution failed actual native admission or changed wire');
 rejectNative(fn()=>N::capture(1,$splitProduct->toArray(),['quality'=>'high','resolution'=>'1k'],(int)$split['id']),'invalid_sku');
 $pixelSource=['meter_contract'=>['version'=>3,'basis'=>'megapixel','rounding'=>'ceil_2','maximum_pixels_per_image'=>1000000,'maximum_images'=>3,'result_path'=>'data.image_urls','result_item_url'=>'']];
 // An opaque nested count under the flat encoder is deliberately rejected
 // before publication checks. Keep the legacy nested per-call contract intact.
 $nestedPixel=Sku::create(array_replace($skuBase,['product_id'=>$p['id'],'sku_key'=>'opf_native_pixel_nested_'.$tag,'usage_unit'=>'business_unit','usage_unit_size'=>1,'sale_points'=>3,'locked_params'=>['quality'=>'1k'],'status'=>1,'sale_status'=>1,'source_payload'=>$pixelSource]));
 rejectNative(fn()=>N::capture(1,$p->toArray(),$params,(int)$nestedPixel['id']),'invalid_sku');
 // This distinct declaration proves the existing finite flat count mapping;
 // do not invent a structured protocol for the preceding nested fixture.
 $pixelMeta=$meta;$pixelMeta['params_schema']=['model'=>['type'=>'string'],'channel'=>['type'=>'string'],'quality'=>['type'=>'string','enum'=>['1k','2k'],'required'=>true],'n'=>['type'=>'integer','minimum'=>1,'maximum'=>3]];
 $pixelParams=['model'=>'fixture-image','channel'=>'fixture-channel','quality'=>'1k','n'=>2];$pixelWire=S::encode($pixelParams);
 $pixelMeta['developer_doc_content']="```json\n".S::encode($pixelParams)."\n```\n```json\n".S::encode(['code'=>1,'data'=>['task_id'=>'known-pixel-task','result'=>['image_urls'=>[]]]])."\n```";
 $pixelProduct=Product::create(array_replace($base,['name'=>'Finite flat pixel fixture','product_code'=>'opf_native_pixel_flat_'.$tag,'upstream_resource_key'=>'opf_native_pixel_flat_'.$tag,'upstream_model_code'=>'fixture-image','upstream_channel_code'=>'fixture-channel','source_payload'=>['market_metadata'=>$pixelMeta]]));
 $pixel=Sku::create(array_replace($skuBase,['product_id'=>$pixelProduct['id'],'sku_key'=>'opf_native_pixel_flat_'.$tag,'usage_unit'=>'business_unit','usage_unit_size'=>1,'sale_points'=>3,'locked_params'=>['quality'=>'1k'],'status'=>1,'sale_status'=>1,'source_payload'=>$pixelSource]));
 rejectNative(fn()=>N::capture(1,$pixelProduct->toArray(),$pixelParams,(int)$pixel['id']),'native_usage_boundary_required');
 $pixelBinding=N::pixelBinding($pixelProduct->toArray(),$pixel->toArray());$pixelBudget=N::capture(1,$pixelProduct->toArray(),$pixelParams,(int)$pixel['id'],['basis'=>'usage','max_units'=>3,'usage_path'=>'data.image_urls','native_meter'=>$pixelBinding]);
 checkNative($pixelBudget['snapshot']['native_media']['submitted_units']===2.&&$pixelBudget['snapshot']['native_media']['submitted_images']===2&&($pixelBudget['snapshot']['native_media']['market_snapshot']['billing_meter']['version']??null)===3&&S::encode($pixelParams)===$pixelWire,'Bounded flat pixel admission did not preserve its explicit count, meter or wire');
 checkNative(S::encode($params)===$wire,'The flat pixel fixture modified the legacy nested wire');
 $call=Sku::create(array_replace($skuBase,['product_id'=>$p['id'],'sku_key'=>'opf_native_call_'.$tag,'usage_unit'=>'per_call','usage_unit_size'=>1,'sale_points'=>3,'locked_params'=>['quality'=>'1k'],'status'=>1,'sale_status'=>1,'source_payload'=>['usage_unit'=>'per_call','meter_summary'=>'按次，总点数向上取整']]));
 $callBudget=N::capture(1,$p->toArray(),$params,(int)$call['id']);
 checkNative($callBudget['user']===3.&&$callBudget['snapshot']['native_media']['submitted_units']===1.,'Explicit per-call image meter multiplied output slots or created false partial-success units');
 $appMeta=['category_code'=>'video','generation_modes'=>['text_to_video'],'supported_asset_types'=>['video'],'params_schema'=>['model'=>['type'=>'string'],'duration'=>['type'=>'integer'],'resolution'=>['type'=>'string']]];
 $appBase=array_replace($base,['name'=>'Happy Horse fixture','resource_type'=>'app_api','model_type'=>'','upstream_model_code'=>'','upstream_channel_code'=>'','upstream_app_code'=>'happy_horse','source_payload'=>['market_metadata'=>$appMeta]]);
 Product::create(array_replace($appBase,['product_code'=>'opf_native_query_'.$tag,'upstream_resource_key'=>'opf_native_query_'.$tag,'upstream_api_code'=>'query']));
 $appProduct=Product::create(array_replace($appBase,['product_code'=>'opf_native_submit_'.$tag,'upstream_resource_key'=>'opf_native_submit_'.$tag,'upstream_api_code'=>'submit']));
 $appSku=Sku::create(array_replace($skuBase,['product_id'=>$appProduct['id'],'sku_key'=>'opf_native_app_'.$tag,'usage_unit'=>'per_call','usage_unit_size'=>1,'sale_points'=>3,'locked_params'=>['model'=>'happy-horse-1.0','resolution'=>'720p','duration'=>6],'status'=>1,'sale_status'=>1]));
 $appRaw=['model'=>'happy-horse-1.0','resolution'=>'720p','duration'=>6,'ratio'=>'16:9'];$appWire=S::encode($appRaw);$appBudget=N::capture(1,$appProduct->toArray(),$appRaw,(int)$appSku['id']);checkNative($appBudget['user']===3.&&$appBudget['tenant']===3.&&S::encode($appRaw)===$appWire,'App locked model rejected or per-call duration changed price');
 rejectNative(fn()=>N::capture(1,$appProduct->toArray(),array_replace($appRaw,['model'=>'foreign']),(int)$appSku['id']),'invalid_sku');
 $plan=Compiler::compileProduct($p->toArray()+['skus'=>[$s->toArray(),$s2->toArray()]]);checkNative(count($plan['drafts'])===2&&$plan['drafts'][0]['config']['native_pricing']===N::ADAPTER&&$plan['drafts'][0]['config']['when']['parameters.quality']==='1k','Compiler did not bind nested SKU dimensions to native pricing');
 $token=$s->toArray();$token['usage_unit']='token';$blocked=Compiler::compileProduct($p->toArray()+['skus'=>[$token]]);checkNative(!$blocked['drafts']&&in_array('actual_usage_boundary_required',array_column($blocked['blockers'],'code'),true),'Token SKU became fixed per-call or a made-up ceiling');
 $rid='req_native_fixture_'.bin2hex(random_bytes(10));$op=['native_pricing'=>N::ADAPTER,'executor'=>'json','billable'=>true,'role'=>'submit'];$budget['snapshot']+=['contract'=>$op,'product_id'=>(int)$p['id'],'billing_owner'=>'gateway'];
 $row=['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'market','method'=>'POST','path'=>'/api/v1/tasks','status'=>'accepted','billing_status'=>'pending','active'=>1,'consumption_ids'=>'[]','snapshot'=>S::encode($budget['snapshot']),'payload_hash'=>hash('sha256',$rid),'response_blob'=>'','budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant']]+S::timestamp();$row['id']=S::table('request')->insertGetId($row);C::enter($rid);
 B::reserve($row,$budget);$known=['code'=>1,'msg'=>'success','data'=>['task_id'=>'task_source_fixture','status'=>'completed','type'=>'image','call_type'=>'async','result'=>['results'=>[['url'=>'https://example.com/a','type'=>'image','transfer_status'=>'success'],['url'=>'https://example.com/b','type'=>'image','transfer_status'=>'failed']]],'usage'=>['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0,'points_cost'=>1.23]]];
 Retail::where('sku_id',$s['id'])->update(['sale_points'=>999]);B::settle($row,$known);$done=S::table('request')->where('id',$row['id'])->find();checkNative($done['status']==='partial_success'&&$done['billing_status']==='settled'&&(float)$done['actual_user']===5.&&(float)$done['actual_tenant']===3.,'Actual count or frozen price changed settlement');OpenVerification::assertLedger($done);
 $logs=Db::name('user_account_log')->where('source_sn',$rid)->count();B::settle($row,$known);checkNative(Db::name('user_account_log')->where('source_sn',$rid)->count()===$logs,'Repeated completion charged again');
}finally{C::leave();Db::rollback();}
checkNative($wallets()===$walletBefore,'Native fixture changed wallet after rollback');
echo "PASS native raw root/parameters/params binding, conflicts, unchanged wire, legacy nested per-call and guarded flat pixel count, exact published meter boundary, frozen partial settlement/ledger/idempotency; rollback and wallets verified, no transport\n";
