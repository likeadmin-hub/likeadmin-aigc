<?php
/** No DB/HTTP: exact monetary paths, scalar evidence and JSON type preservation. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\{OpenMarketResponse as R,OpenStore as S,OpenSchema,OpenException};
function moneyCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
function moneyAt(string $path,mixed $amount): string {$node=$amount;foreach(array_reverse(explode('.',$path)) as $key)$node=(object)[$key=>$node];return S::encode($node);}
function moneySchema(string $path,array $leaf): array {foreach(array_reverse(explode('.',$path)) as $key)$leaf=['type'=>'object','properties'=>[$key=>$leaf],'required'=>[$key]];return ['200'=>['content'=>['application/json'=>['schema'=>$leaf]]]];}
foreach(['usage.points','data.usage.points','data.result.usage.points'] as $path){
 foreach([123,123.5,'123','123.50','1.23e2'] as $original){
  $body=moneyAt($path,$original);moneyCheck(R::bodySupported($body),'Known finite money path rejected');$projected=R::project($body,['charged'=>0.636]);$value=OpenSchema::get(json_decode($projected,true),$path);
  moneyCheck(is_string($original)?$value==='0.64':!is_string($value)&&(float)$value===0.64,'Money type or two-place charge changed');
  $responses=R::retailSchemas(moneySchema($path,['type'=>is_string($original)?'string':'integer']));moneyCheck(R::schemaSupported($responses),'Registered amount schema rejected');OpenSchema::validate(json_decode($projected),$responses['200']['content']['application/json']['schema'],'$',0,null,true);
 }
 foreach([(object)['count'=>2],[],true,null,'free','12USD','NaN','1e400'] as $invalid){$body=moneyAt($path,$invalid);moneyCheck(!R::bodySupported($body),'Unknown monetary structure accepted');try{R::project($body,['charged'=>0.64]);throw new RuntimeException('Invalid amount was overwritten');}catch(OpenException $e){moneyCheck($e->errorType==='response_adapter_required','Wrong financial evidence rejection');}}
 foreach(['object','array','boolean','null'] as $type)moneyCheck(!R::schemaSupported(moneySchema($path,['type'=>$type])),'Non-numeric financial schema admitted');
}
foreach(['points','data.points','data.result.points','other.usage.points','data.billing.points'] as $unknown)moneyCheck(!R::bodySupported(moneyAt($unknown,123))&&!R::schemaSupported(moneySchema($unknown,['type'=>'number'])),'Broad points alias admitted');
moneyCheck(!R::bodySupported('{"usage":{"points":1e400}}'),'Infinite JSON amount accepted');
$body='{"data":{"task_id":"original-id","frozen_points":"999.00","actual_points":"888","usage":{"points":"700","points_cost":"700","actual_points":"700","total_tokens":17},"result":{"content":{"points":{"score":8}}}}}';
$result=json_decode(R::project($body,['reserved'=>1.234,'charged'=>0.636]));moneyCheck($result->data->frozen_points==='1.23'&&$result->data->actual_points==='0.64'&&$result->data->usage->points==='0.64'&&$result->data->usage->points_cost==='0.64'&&$result->data->usage->actual_points==='0.64','Existing money string aliases changed JSON type');moneyCheck($result->data->task_id==='original-id'&&$result->data->usage->total_tokens===17&&$result->data->result->content->points->score===8,'ID/token/opaque business payload changed');
foreach(['actual_points','data.actual_points','data.usage.points_cost'] as $known)moneyCheck(!R::bodySupported(moneyAt($known,(object)['value'=>1])),'Existing known monetary object was concealed by projection');
echo "PASS three registered usage.points aliases, numeric-string preservation, decimal schemas, finite monetary scalars, unknown/object/text blockers and untouched IDs/usage/opaque payload; pure fixture\n";
