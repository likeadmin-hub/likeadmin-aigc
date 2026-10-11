<?php
/** Local rollback only; no real supplier requests or persisted wallet changes. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\power\MarketMusicOperationRuntimeService as Runtime;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenCallContext as C;
use app\common\service\open_platform\OpenVault as V;
use think\facade\Db;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
check(class_exists(Runtime::class),'Real music operation runtime is missing');
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback only');
$wallets=fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
Db::startTrans();try{
 request()->tenantId=1;request()->userId=1;
 foreach(['lyrics','mashup_lyrics','style'] as $api){
  $params=$api==='mashup_lyrics'?['lyrics_a'=>'Synthetic first verse','lyrics_b'=>'Synthetic second verse']:['prompt'=>'Synthetic first light'];
  $quote=Runtime::quote(1,1,$api,$params);check($quote['user_charge_points']>0&&$quote['tenant_cost_points']>0,'Missing per-operation market pricing');
  $rid='req_fixture_'.bin2hex(random_bytes(16));$budget=Runtime::budget(1,1,$api,$params);$path='/api/v1/tools/aigc_music/'.($api==='lyrics'?'lyrics/index':($api==='mashup_lyrics'?'mashuplyrics/index':'style/index'));
  S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'kind'=>'tool','app_code'=>'aigc_music','method'=>'POST','path'=>$path,'status'=>'accepted','billing_status'=>'pending','active'=>1,'budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'snapshot'=>S::encode($budget['snapshot']),'consumption_ids'=>'[]','payload_hash'=>hash('sha256',$rid),'response_blob'=>'']+S::timestamp());C::enter($rid);
  $frozen=Runtime::selection(1,1,$api,$params);check($frozen['quote']===$quote,'Frozen operation quote changed');$denied=false;try{Runtime::selection(1,2,$api,$params);}catch(Exception){$denied=true;}check($denied,'Private operation quote leaked');
  Db::name('power_market_sku')->where('id',$quote['market_sku_id'])->update(['sale_points'=>999,'status'=>0]);check(Runtime::selection(1,1,$api,$params)['quote']===$quote,'Accepted operation repriced or lost SKU');
  $reserve=new ReflectionMethod(Runtime::class,'reserve');$cid=$reserve->invoke(null,1,1,$api,$params,$frozen);
  $c=app\common\model\ai\AiConsumptionLog::findOrEmpty($cid);$native=app\common\model\app\aigc_music\AigcMusicTask::where('consumption_id',$cid)->findOrEmpty();check(!$native->isEmpty()&&$native['action']===$api,'Native music task not linked');
  $snapshot=S::json($c['price_snapshot']);$text=$api==='style'?['upsampled_tags'=>'Synthetic bright piano pop']:['text'=>'Synthetic complete lyric','title'=>'First Light'];$wire=['code'=>1,'data'=>['result'=>['status'=>'completed','data'=>$text]]];$snapshot['music_receipt_blob']=V::seal(S::encode($wire));$c->save(['price_snapshot'=>$snapshot,'run_status'=>'running']);
  Runtime::refresh($cid);Runtime::refresh($cid);$c->refresh();check($c['billing_status']==='settled'&&(float)$c['actual_user_price']===$quote['user_charge_points'],'Real operation did not settle frozen price once');
  $row=S::table('request')->where('request_id',$rid)->find();check((float)$row['actual_user']===$quote['user_charge_points'],'Music charge attributed incorrectly');app\common\service\open_platform\OpenVerification::assertLedger($row);
  check(count(app\common\service\open_platform\OpenManagement::detail(1,1,$rid)['point_logs'])===1,'Repeated receipt recovery charged twice');
  C::leave();
 }
 $denied=false;try{Runtime::quote(1,1,'unknown',['prompt'=>'fixture']);}catch(Exception){$denied=true;}check($denied,'Arbitrary supplier operation allowed');
 $denied=false;try{Runtime::quote(1,1,'lyrics',[]);}catch(Exception){$denied=true;}check($denied,'Invalid native input accepted');
 echo "PASS fixed music lyrics/mashup/style quotes freeze SKU, cost, user price and payload; private recipes stay owned; durable synchronous receipt restores native task and independent ledger once without supplier calls\n";
}finally{C::leave();Db::rollback();}
check($wallets()===$before,'Rollback changed local wallets');
