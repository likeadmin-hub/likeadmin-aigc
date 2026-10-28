<?php
// Local transactional fixtures. Estimation is intercepted; no capture/provider/billing calls.
namespace app\common\service\app\aigc_image {
 final class AigcImageService {
  public static array $payload=[];
  public static function estimate(int $tenant,array $payload):array{self::$payload=$payload;return ['user_charge_points'=>30,'settlement_mode'=>'fixed'];}
 }
}
namespace {
require dirname(__DIR__,2).'/vendor/autoload.php';(new \think\App())->initialize();
set_exception_handler(static function($e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString());exit(1);});
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as C;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as G;
use app\common\service\app\aigc_image\AigcImageService as Images;
function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
Db::startTrans();try{
 $t=1999999854;$u=$t;$now=time();
 $result=['uri'=>'uploads/aigc_video/frames/fixture.jpg','storage_scope'=>'tenant','storage_engine'=>'aliyun','storage_domain'=>'https://fixtures.example','mime_type'=>'image/jpeg','width'=>624,'height'=>832,'file_size'=>100];
 $url='https://fixtures.example/'.$result['uri'];
 $nodes=[['id'=>'101','type'=>'video','metadata'=>[]],['id'=>'102','type'=>'image','title'=>'视频首帧','metadata'=>['source'=>'video_frame','frame_source_node_id'=>'101','uri'=>$result['uri'],'url'=>$url,'image'=>$url]],['id'=>'103','type'=>'image','metadata'=>['channel'=>'fixture']]];
 $edges=[['from'=>'102','to'=>'103','kind'=>'reference','role'=>'reference']];
 $id=Db::name('aigc_short_drama_canvas')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'title'=>'frame reference fixture','nodes_json'=>json_encode($nodes),'edges_json'=>json_encode($edges),'viewport_json'=>'{}','removed_node_ids_json'=>'[]','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
 $doc=Db::name('aigc_short_drama_canvas')->where('id',$id)->find();
 $job=Db::name('aigc_short_drama_canvas_poster_job')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'canvas_id'=>$id,'node_id'=>'101','video_uri'=>'uploads/fixture.mp4','storage_scope'=>'tenant','storage_engine'=>'aliyun','storage_domain'=>'https://fixtures.example','status'=>'success','job_kind'=>'frame','capture_time'=>0.001,'idempotency_key'=>sha1('frame-fixture-'.getmypid()),'poster_uri'=>$result['uri'],'poster_scope'=>'tenant','poster_engine'=>'aliyun','poster_domain'=>'https://fixtures.example','result_json'=>json_encode($result),'create_time'=>$now,'update_time'=>$now]);
 $params=['canvas_id'=>$id,'node_id'=>'103','type'=>'image','channel'=>'fixture','quality'=>'1k','preview_only'=>true,'reference_images'=>[$url],'reference_assets'=>[['type'=>'image','url'=>$url,'uri'=>$result['uri']]]];
 $quote=C::quote($t,$u,$params);
 check($quote['quote']['user_charge_points']===30.0,'Captured frame did not reach estimate');
 check(Images::$payload['reference_images']===[$url],'Captured frame missing or duplicated in image request');
 $ready=G::registeredUploadNodes($doc,$nodes);$asset=(int)($ready[1]['metadata']['asset_id']??0);
 check($asset>0&&$ready[1]['metadata']['status']==='success','Legacy frame not projected ready');
 check(C::agentAutoDependencyState($ready,$edges,'103')['state']==='ready','Submit dependency still waits');
 C::quote($t,$u,$params);
 check(Db::name('aigc_short_drama_asset')->where('canvas_id',$id)->count()===1,'Repeated quote registered duplicate frame');
 check(Db::name('aigc_short_drama_canvas')->where('id',$id)->find()===$doc,'Quote changed graph/revisions');
 // Client claims must never replace the owned successful capture record.
 foreach(['tenant_id'=>$t+1,'user_id'=>$u+1,'id'=>$id+100000] as $key=>$value){
  $foreign=$doc;$foreign[$key]=$value;$projection=G::registeredUploadNodes($foreign,$nodes);
  check(empty($projection[1]['metadata']['asset_id']),'Capture crossed owner/canvas boundary');
 }
 $forged=$nodes;$forged[1]['metadata']['uri']='uploads/foreign.jpg';$forged[1]['metadata']['status']='success';$forged[1]['metadata']['asset_id']=$asset;
 check(empty(G::registeredUploadNodes($doc,$forged)[1]['metadata']['asset_id']),'Forged source/status/asset accepted');
 $wrongSource=$nodes;$wrongSource[1]['metadata']['frame_source_node_id']='999';check(empty(G::registeredUploadNodes($doc,$wrongSource)[1]['metadata']['asset_id']),'Capture associated with wrong video');
 foreach(['running','dead'] as $status){Db::name('aigc_short_drama_canvas_poster_job')->where('id',$job)->update(['status'=>$status]);check(empty(G::registeredUploadNodes($doc,$ready)[1]['metadata']['asset_id']),'Unfinished capture treated as ready');}
 Db::name('aigc_short_drama_canvas_poster_job')->where('id',$job)->update(['status'=>'success']);
 // Ordinary upload compatibility remains on its existing registered-asset path.
 $upload=$nodes;$upload[1]['metadata']['source']='upload';check(G::registeredUploadNodes($doc,$upload)[1]['metadata']['asset_id']===$asset,'Existing uploaded image references regressed');
 Db::name('aigc_short_drama_asset')->where('id',$asset)->update(['delete_time'=>$now]);
 check(empty(G::registeredUploadNodes($doc,$nodes)[1]['metadata']['asset_id']),'Deleted frame resurrected');
 check(Db::name('aigc_short_drama_asset')->where('canvas_id',$id)->count()===1,'Deleted frame duplicated');
 echo "PASS frame quote/submit readiness, one reference, idempotent recovery, graph unchanged, owner/source/status/deletion checks and upload compatibility; rolled back\n";
}finally{Db::rollback();}
}
