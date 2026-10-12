<?php
/** Verified local transaction only: grant and operating switch remain independent. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenManagement as M;
use app\common\service\open_platform\OpenException;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function denied(callable $fn){try{$fn();}catch(OpenException $e){check($e->httpStatus===403,'Wrong rejection');return;}throw new RuntimeException('Unauthorized operation allowed');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local fixtures only');
Db::startTrans();try {
 $id=1999999998;check(!Db::name('tenant')->where('id',$id)->find()&&!S::table('setting')->where('tenant_id',$id)->find(),'Fixture collision');
 Db::name('tenant')->insert(['id'=>$id,'sn'=>'api_access_fixture','name'=>'API fixture','create_time'=>time(),'update_time'=>time()]);
 denied(fn()=>M::saveSettings(0,['enabled'=>true,'rpm'=>10],false));
 M::persistSettings(0,true,['models'=>['188'],'apps'=>[]]);
 M::persistSettings($id,false,['models'=>[],'apps'=>[]]);
 M::saveSettings($id,['enabled'=>true,'rpm'=>10],false);
 check(!S::settings($id)['enabled'],'Tenant bypassed platform authorization');
 M::saveSettings($id,['enabled'=>true,'rpm'=>10],true);
 check(S::settings($id)['authorized']&&S::settings($id)['enabled'],'Platform grant missing');
 M::saveSettings($id,['enabled'=>true,'rpm'=>50],false);check(S::settings($id)['rpm']===10,'Tenant exceeded granted rate');
 M::saveSettings($id,['enabled'=>false,'rpm'=>10],false);
 check(S::settings($id)['authorized']&&!S::settings($id)['enabled'],'Tenant stop revoked platform grant');
 M::savePages($id,[]);check(S::settings($id)['authorized']&&!S::settings($id)['enabled'],'Saving pages changed grant or pause');
 M::saveSettings($id,['enabled'=>true,'rpm'=>10],false);
 check(S::settings($id)['enabled'],'Tenant restart failed within granted scope');
 M::saveSettings($id,['enabled'=>false,'rpm'=>10],true);
 M::saveSettings($id,['enabled'=>true,'authorized'=>true,'rpm'=>10],false);
 check(!S::settings($id)['enabled'],'Forged grant accepted');
 denied(fn()=>M::setMarketExposure($id,[188],true,false));
 M::saveSettings($id,['enabled'=>true,'rpm'=>10],true);
 M::saveSettings($id,['enabled'=>true,'rpm'=>10,'features'=>['keys'=>false]],true);
 M::saveSettings($id,['enabled'=>true,'rpm'=>10,'features'=>['keys'=>true]],false);check(!S::settings($id)['features']['keys'],'Tenant raised granted feature permissions');
 $before=Db::name('power_market_sku')->where('product_id',188)->select()->toArray();
 M::setMarketExposure($id,[188],true,false);check(in_array('188',S::settings($id)['models'],true),'API selection missing');
 denied(fn()=>M::setMarketExposure($id,[195],true,false));
 M::persistSettings(0,true,['models'=>[],'apps'=>[]]);
 M::saveSettings($id,['enabled'=>false,'rpm'=>10],false);check(!S::settings($id)['enabled'],'Removed global ability prevented tenant pause');
 M::persistSettings(0,true,['models'=>['188'],'apps'=>[]]);
 M::setMarketExposure($id,[188],false,false);check(!in_array('188',S::settings($id)['models'],true),'API removal failed');
 check($before===Db::name('power_market_sku')->where('product_id',188)->select()->toArray(),'API selection changed SKU pricing or shelf');
 M::persistSettings($id,true,['models'=>['188'],'rpm'=>10,'features'=>['keys'=>false]]);check(S::settings($id)['enabled'],'Legacy grant was closed by migration');
 M::saveSettings($id,['enabled'=>true,'rpm'=>60,'features'=>['keys'=>true]],false);check(S::settings($id)['rpm']===10&&!S::settings($id)['features']['keys'],'Legacy grant ceilings were widened');
 echo "PASS platform grants, tenant pause/restart, forged grant rejection, market scope and unchanged pricing/shelf, legacy settings compatibility\n";
}finally{Db::rollback();}
