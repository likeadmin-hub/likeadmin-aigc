<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\{OpenMarketResponse as R,OpenMarketContractCompiler as C,OpenStore as S,OpenSchema,OpenContracts,OpenVerification,OpenVault,OpenGateway,OpenException};
use think\facade\Db;
function boundaryCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
function boundaryReject(callable $call,string $type){try{$call();}catch(OpenException $e){boundaryCheck($e->errorType===$type,'Wrong rejection: '.$e->errorType);return;}throw new RuntimeException('Unsafe response was accepted');}
function boundaryFixture(string $doc,array $input=[]):array{return ['id'=>900001,'name'=>'Boundary fixture','resource_type'=>'app_api','model_type'=>'','upstream_app_code'=>'watermark_removal','upstream_api_code'=>'remove','upstream_model_code'=>'','upstream_channel_code'=>'','source_payload'=>S::encode(['market_metadata'=>['method'=>'POST','call_type'=>1,'params_schema'=>$input?:['type'=>'object','properties'=>[]],'developer_doc_content'=>$doc]]),'skus'=>[['id'=>900002,'sku_key'=>'fixture','usage_unit'=>'per_call','usage_unit_size'=>1,'sale_points'=>1.23,'sale_status'=>1,'locked_params'=>'{}']]];}
$mode=$argv[1]??'all';
if(in_array($mode,['all','acceptance'],true)){
 boundaryCheck(method_exists(OpenGateway::class,'marketAccepted'),'Market acceptance guard is missing');$accept=new ReflectionMethod(OpenGateway::class,'marketAccepted');$op=['task_id_path'=>'data.task_id','responses'=>['200'=>['content'=>['application/json'=>['schema'=>['type'=>'object','properties'=>['code'=>['type'=>'integer'],'data'=>['type'=>'object']]]]]]]];
 foreach([['headers'=>['content-type'=>'application/json'],'body'=>'{}'],['headers'=>['content-type'=>'application/json'],'body'=>'{invalid'],['headers'=>['content-type'=>'text/html'],'body'=>'{"code":1}'],['headers'=>['content-type'=>'application/json'],'body'=>'[]']] as $wire)boundaryCheck(!$accept->invoke(null,$op,['status'=>200]+$wire),'Untrusted 200 response accepted for fixed-call settlement');
 boundaryCheck($accept->invoke(null,$op,['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>'{"code":1}']),'Known successful envelope must not require data');
 boundaryCheck($accept->invoke(null,$op,['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>'{"data":{"task_id":"trusted-task"}}']),'Known task acceptance missing');
 $dict=['responses'=>['200'=>['content'=>['application/json'=>['schema'=>['type'=>'object','required'=>['result'],'properties'=>['result'=>['type'=>'object']]]]]]]];boundaryCheck($accept->invoke(null,$dict,['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>'{"result":{"text":"ok"}}']),'Declared synchronous business dictionary rejected');
 $protected=$op+['response_adapter'=>R::ADAPTER];foreach(['{"code":1,"data":{"usage":{"points_cost":{"unknown":2}}}}','{"code":1,"data":{"usage":{"points_cost":"bad"}}}','{"code":1,"data":{"billing":{"points_cost":2}}}'] as $body)boundaryCheck(!$accept->invoke(null,$protected,['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>$body]),'Untrusted economic envelope bypassed pre-settlement acceptance gate');
 boundaryCheck(OpenGateway::wireFailed(200,['code'=>0],$op),'Definitive business rejection must retain refund handling');
 echo "PASS trusted JSON/header/envelope acceptance, empty/malformed 200 uncertainty and definitive code-zero failure\n";
}
if(in_array($mode,['all','schema'],true)){
 $schema=['type'=>'object','$defs'=>['billing'=>['type'=>'object','properties'=>['points_cost'=>['type'=>'integer']]]],'properties'=>['data'=>['type'=>'object','properties'=>['billing'=>['$ref'=>'#/$defs/billing']]]]];
 boundaryCheck(!R::schemaSupported(['200'=>['content'=>['application/json'=>['schema'=>$schema]]]]),'Referenced financial path bypassed schema gate');
 foreach(['oneOf','anyOf','allOf'] as $union){$s=['type'=>'object',$union=>[$schema]];boundaryCheck(!R::schemaSupported(['200'=>['content'=>['application/json'=>['schema'=>$s]]]]),'Union financial path bypassed gate');}
 $cycle=['type'=>'object','$defs'=>['cycle'=>['$ref'=>'#/$defs/cycle']],'properties'=>['data'=>['$ref'=>'#/$defs/cycle']]];boundaryCheck(!R::schemaSupported(['200'=>['content'=>['application/json'=>['schema'=>$cycle]]]]),'Reference cycle must fail closed');
 foreach(['#/missing','https://example.com/schema'] as $ref)boundaryCheck(!R::schemaSupported(['200'=>['content'=>['application/json'=>['schema'=>['type'=>'object','properties'=>['data'=>['$ref'=>$ref]]]]]]]),'Invalid reference must fail closed');
 $schema['properties']['data']['properties']=['usage'=>['$ref'=>'#/$defs/billing']];boundaryCheck(R::schemaSupported(['200'=>['content'=>['application/json'=>['schema'=>$schema]]]]),'Known usage price reference should remain supported');
 boundaryReject(fn()=>R::project('{"code":1,"data":{"billing":{"points_cost":999}}}',[]),'response_adapter_required');
 boundaryReject(fn()=>R::project('{"code":1,"data":{"billing":{"upstream_unit_cost":999}}}',[]),'response_adapter_required');
 boundaryReject(fn()=>R::project('[{"points_cost":999}]',[]),'response_adapter_required');
 $opaque='{"code":1,"data":{"result":{"content":{"points_cost":999,"token":"user output"}},"usage":{"total_tokens":8,"points_cost":999}}}';$p=json_decode(R::project($opaque,['charged'=>1.23]));boundaryCheck($p->data->result->content->points_cost===999&&$p->data->usage->total_tokens===8&&$p->data->usage->points_cost===1.23,'Opaque JSON or token counters changed');
 echo "PASS response refs/unions/cycles and actual protocol economic boundary\n";
}
if(in_array($mode,['all','source'],true)){
 $doc="```json\n{\"code\":1,\"data\":{\"billing\":{\"points_cost\":999}}}\n```";
 $review=['review_note'=>'Review removes fields, cannot remove source risk','responses'=>['200'=>['content'=>['application/json'=>['schema'=>['type'=>'object','properties'=>['code'=>['type'=>'integer']]]]]]]];
 boundaryCheck(!C::compileProduct(boundaryFixture($doc),[],$review)['drafts'],'Review omission unlocked unsafe original source response');
 $opaque="```json\n{\"code\":1,\"data\":{\"result\":{\"content\":{\"points_cost\":999}}}}\n```";boundaryCheck(count(C::compileProduct(boundaryFixture($opaque))['drafts'])===1,'Opaque source content should remain ordinary business data');
 echo "PASS original source economics independent of reviewed response omissions\n";
}
if(in_array($mode,['all','typed'],true)){
 $input=['type'=>'object','required'=>['metadata','items'],'properties'=>['metadata'=>['type'=>'object'],'items'=>['type'=>'array']]];
 $doc="```json\n{\"metadata\":{},\"items\":[]}\n```\n```json\n{\"code\":1,\"data\":{\"metadata\":{},\"items\":[],\"actual_points\":1,\"status\":1}}\n```";
 $compiled=C::compileProduct(boundaryFixture($doc,$input));boundaryCheck(count($compiled['drafts'])===1,'Typed candidate failed');$draft=$compiled['drafts'][0];
 boundaryCheck($draft['config']['example']['metadata'] instanceof stdClass&&$draft['config']['example']['items']===[],'Request object/list identity lost');
 $schema=$draft['config']['responses']['200']['content']['application/json']['schema'];
 boundaryCheck($schema['properties']['data']['properties']['metadata']['type']==='object'&&$schema['properties']['data']['properties']['items']['type']==='array','Response object/list identity lost');
 boundaryCheck($schema['properties']['data']['properties']['actual_points']['type']==='number'&&$schema['properties']['data']['properties']['status']['type']==='integer'&&$schema['properties']['code']['type']==='integer','Only retail financial values use number');
 OpenSchema::validate(json_decode(R::project('{"code":1,"data":{"metadata":{},"items":[],"actual_points":999,"status":1}}',['charged'=>1.23])),$schema,'$',0,null,true);
 $reviewSchema=['type'=>'object','$defs'=>['amount'=>['type'=>'integer']],'properties'=>['code'=>['$ref'=>'#/$defs/amount'],'data'=>['type'=>'object','properties'=>['actual_points'=>['$ref'=>'#/$defs/amount']]]]];
 $review=['review_note'=>'Reviewed schema uses shared numeric ref','responses'=>['200'=>['content'=>['application/json'=>['schema'=>$reviewSchema]]]]];$reviewed=C::compileProduct(boundaryFixture($doc,$input),[],$review)['drafts'][0]['config']['responses']['200']['content']['application/json']['schema'];
 OpenSchema::validate(json_decode('{"code":1,"data":{"actual_points":1.23}}'),$reviewed,'$',0,null,true);
 boundaryReject(fn()=>OpenSchema::validate(json_decode('{"code":1.23,"data":{"actual_points":1.23}}'),$reviewed,'$',0,null,true),'invalid_request');
 $siblings=['$defs'=>['base'=>['type'=>'object','required'=>['code','task'],'properties'=>['code'=>['type'=>'integer'],'task'=>['type'=>'string']]],'amount'=>['type'=>'integer']],'$ref'=>'#/$defs/base','required'=>['data'],'properties'=>['code'=>['maximum'=>2],'data'=>['type'=>'object','required'=>['actual_points'],'properties'=>['actual_points'=>['$ref'=>'#/$defs/amount','type'=>'integer','minimum'=>0]]]]];
 $siblingSchema=R::retailSchemas(['200'=>['content'=>['application/json'=>['schema'=>$siblings]]]])['200']['content']['application/json']['schema'];
 OpenSchema::validate(json_decode('{"code":1,"task":"preserved","data":{"actual_points":1.23}}'),$siblingSchema,'$',0,null,true);
 foreach(['{"code":"wrong","task":"preserved","data":{"actual_points":1.23}}','{"code":1,"data":{"actual_points":1.23}}','{"code":3,"task":"preserved","data":{"actual_points":1.23}}','{"code":1,"task":7,"data":{"actual_points":1.23}}','{"code":1,"task":"preserved"}','{"code":1,"task":"preserved","data":{"actual_points":-1.23}}'] as $invalid)boundaryReject(fn()=>OpenSchema::validate(json_decode($invalid),$siblingSchema,'$',0,null,true),'invalid_request');
 echo "PASS typed document request/response objects, empty lists and fractional retail schema\n";
}
if(in_array($mode,['all','receipt'],true)){
 $db=config('database.connections.mysql');boundaryCheck($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');Db::startTrans();try{
  $code='boundary_'.bin2hex(random_bytes(6));$contract=['code'=>$code,'kind'=>'market','product_id'=>0,'path'=>'/api/v1/apps/watermark_removal/remove','method'=>'POST','config'=>['executor'=>'json','response_adapter'=>R::ADAPTER,'role'=>'submit','billable'=>false,'input'=>['type'=>'object'],'example'=>[],'responses'=>['200'=>['content'=>['application/json'=>['schema'=>['type'=>'object','properties'=>['code'=>['type'=>'integer']]]]]]]]];
  $contract=OpenContracts::save($contract);$rid='req_'.bin2hex(random_bytes(16));$row=['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'market','method'=>'POST','path'=>$contract['path'],'contract_id'=>$contract['id'],'status'=>'success','active'=>0,'billing_status'=>'none','consumption_ids'=>'[]','snapshot'=>S::encode(['contract_digest'=>OpenVerification::digest($contract),'market_response_blob'=>OpenVault::seal('{"code":1,"data":{"billing":{"points_cost":999}}}')]),'payload_hash'=>\app\common\service\open_platform\OpenFiles::exampleFingerprint($contract['config']),'response_blob'=>OpenVault::seal('{"code":1}'),'response_code'=>200,'response_type'=>'application/json']+S::timestamp();$row['id']=S::table('request')->insertGetId($row);$contract['config']['verification']=['request_id'=>$rid];boundaryReject(fn()=>OpenVerification::assertReceipt($contract),'response_adapter_required');
  $wallet=Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money');
  S::table('request')->where('id',$row['id'])->update(['status'=>'accepted','active'=>1,'billing_status'=>'reserved','budget_user'=>1.23,'budget_tenant'=>1,'response_blob'=>'','response_code'=>0]);$pending=S::table('request')->where('id',$row['id'])->find();
  $wire=['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>'{"code":1,"data":{"task_id":"known-upstream-task","billing":{"points_cost":999}}}'];
  $fields=['status'=>'running','upstream_task_id'=>'known-upstream-task','next_run_time'=>time()+30,'response_code'=>200,'response_type'=>'application/json','response_blob'=>OpenVault::seal($wire['body'])];
  $safe=(new ReflectionMethod(OpenGateway::class,'marketWire'))->invoke(null,$pending,$pending,$wire,$fields);$saved=S::table('request')->where('id',$row['id'])->find();
  boundaryCheck($safe['status']===502&&!str_contains($safe['body'],'999')&&OpenVault::open($saved['response_blob'])===$safe['body'],'Unsafe source body sent or stored as public replay');
  boundaryCheck($saved['status']==='running'&&$saved['billing_status']==='reserved'&&(int)$saved['active']===1&&(float)$saved['budget_user']===1.23&&$saved['upstream_task_id']==='known-upstream-task','Response rejection changed billing or lost task tracking');
  boundaryCheck(OpenVault::open(S::json($saved['snapshot'])['market_response_blob'])===$wire['body'],'Private source receipt missing');
  $untrusted=['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>'{}','acceptance_uncertain'=>true];$deferredFields=['status'=>'pending_confirmation','billing_status'=>'pending_confirmation','active'=>1,'response_code'=>200,'response_type'=>'application/json'];$uncertain=(new ReflectionMethod(OpenGateway::class,'marketWire'))->invoke(null,$saved,$saved,$untrusted,$deferredFields);$held=S::table('request')->where('id',$row['id'])->find();boundaryCheck($uncertain['status']===502&&$held['status']==='pending_confirmation'&&$held['billing_status']==='pending_confirmation'&&(int)$held['active']===1&&(float)$held['budget_user']===1.23,'Untrusted protocol cannot release reservation or settle');boundaryCheck(OpenVault::open(S::json($held['snapshot'])['market_response_blob'])==='{}','Untrusted private response missing');
  boundaryCheck(Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money')===$wallet,'Projection failure charged or refunded');
  $snapshot=S::json($saved['snapshot']);$snapshot['contract']=['response_adapter'=>R::ADAPTER];$snapshot['customer_callback']=['url'=>'https://example.com/callback','secret_blob'=>OpenVault::seal(str_repeat('s',32))];S::table('request')->where('id',$saved['id'])->update(['snapshot'=>S::encode($snapshot)]);$callbackRow=S::table('request')->where('id',$saved['id'])->find();
  boundaryReject(fn()=>\app\common\service\open_platform\OpenWebhooks::enqueue($callbackRow,S::json($wire['body'])),'response_adapter_required');boundaryCheck(S::table('webhook')->where('request_id',$rid)->count()===0,'Unsupported financial path created a callback');
  $typedDoc="```json\n{\"metadata\":{},\"items\":[]}\n```\n```json\n{\"code\":1,\"data\":{\"items\":[],\"metadata\":{}}}\n```";$typedInput=['type'=>'object','required'=>['metadata','items'],'properties'=>['metadata'=>['type'=>'object'],'items'=>['type'=>'array']]];$draft=C::compileProduct(boundaryFixture($typedDoc,$typedInput))['drafts'][0];$draft['code']=$code.'.typed';$stored=OpenContracts::save($draft);boundaryCheck($stored['config']['example']['metadata'] instanceof stdClass&&$stored['config']['example']['items']===[],'Stored draft changed typed example');
  echo "PASS rollback private/public receipt gate, retained reservation/task tracking and typed draft storage\n";
 }finally{\app\common\service\open_platform\OpenCallContext::leave();Db::rollback();}
}
