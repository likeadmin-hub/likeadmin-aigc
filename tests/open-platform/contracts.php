<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\OpenContracts as C;
use app\common\service\open_platform\OpenPages as P;
use app\common\service\open_platform\OpenSchema as S;
use app\common\service\open_platform\OpenGateway as G;
use app\common\service\open_platform\OpenToolTasks as T;
function ok($yes,$reason){if(!$yes)throw new RuntimeException($reason);}
function reject($call){try{$call();}catch(app\common\service\open_platform\OpenException $e){return;}throw new RuntimeException('Expected rejection');}
$input=['code'=>'fixture.text','method'=>'POST','path'=>'/api/v1/chat/completions','kind'=>'market','product_id'=>1,'config'=>['executor'=>'json','role'=>'submit','billable'=>true,'input'=>['type'=>'object','required'=>['model'],'properties'=>['model'=>['type'=>'string']]],'example'=>['model'=>'fixture'],'responses'=>['200'=>['description'=>'OK']],'billing'=>[['sku_id'=>1,'basis'=>'usage','max_units'=>100,'usage_path'=>'usage.total_tokens']]]];
ok(C::validate($input)['kind']==='market','Market contract');
reject(fn()=>C::validate(array_replace($input,['path'=>'/platformapi/user/lists'])));
$bad=$input;$bad['config']['billing'][0]['max_units']=0;reject(fn()=>C::validate($bad));
$bad=$input;$bad['config']['api_key']='unsafe';reject(fn()=>C::validate($bad));
reject(fn()=>S::validate([],['type'=>'object','required'=>['model']]));
reject(fn()=>P::validate([['id'=>'admin','name'=>'Unsafe']]));
ok(P::validate([['id'=>'keys','name'=>'接口密钥','visible'=>false]])['keys']['visible']===false,'Page visibility');
ok(T::decode('aigc_image',T::encode('aigc_image',12))==='12','Typed task identifier');reject(fn()=>T::decode('aigc_video',T::encode('aigc_image',12)));
$stream="data: {\"message\":{\"usage\":{\"input_tokens\":10}}}\n\ndata: {\"usage\":{\"output_tokens\":4}}\n\ndata: [DONE]\n\n";
ok(G::streamUsage($stream)['usage']===['input_tokens'=>10,'output_tokens'=>4],'Provider usage events merge');
ok(G::wireFailed(200,['code'=>0,'msg'=>'Rejected'],[])===true,'HTTP 200 business rejection');
ok(G::wireFailed(200,['code'=>1,'data'=>[]],[])===false,'Successful business envelope');
ok(G::wireFailed(200,['choices'=>[['message'=>['content'=>'OK']]]],[])===false,'OpenAI success');
ok(G::wireFailed(200,['error'=>['type'=>'invalid_request']],[])===true,'Protocol error');
ok(C::selected(['when'=>['stream'=>false]],[])&&!C::selected(['when'=>['stream'=>true]],[]),'Default non-stream selector');
ok(C::selected(['when'=>['stream'=>true]],['stream'=>true]),'Streaming selector');
echo "PASS route allowlist, pricing boundary, page registry, typed tasks and SSE usage contracts\n";
