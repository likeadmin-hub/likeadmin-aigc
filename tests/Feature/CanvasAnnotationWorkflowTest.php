<?php
require dirname(__DIR__,2).'/vendor/autoload.php'; (new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_canvas\{AigcCanvasService as Canvas,CanvasTemplateService as Templates};
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
$db=config('database.connections.mysql');
if(PHP_SAPI!=='cli'||$db['hostname']!=='127.0.0.1'||!in_array($db['database'],['x_cn','z_cn'],true))throw new RuntimeException('Local rollback only');
request()->tenantId=1;request()->source=\app\common\enum\AdminTerminalEnum::TENANT;
$n=0;$check=static function($ok,$message)use(&$n){if(!$ok)throw new RuntimeException($message);$n++;};
Db::startTrans();
try{
 $nodes=[];
 foreach(['shape','sticky','text'] as $i=>$kind)$nodes[]=['id'=>'annotation-'.$i,'type'=>'annotation','title'=>$kind,'position'=>['x'=>$i*300,'y'=>100],'width'=>200,'height'=>$kind==='text'?42:250,'metadata'=>['annotation'=>['kind'=>$kind,'shape'=>'star','text'=>'可再次编辑','fill'=>'#68b0ff','fontFamily'=>'Poppins','fontSize'=>24,'align'=>'center']]];
 $created=Canvas::createProject(1,1999999761,['name'=>'Annotation rollback test','nodes'=>$nodes,'edges'=>[]]);
 $detail=Canvas::projectDetail(1,1999999761,$created['id']);
 $check(count($detail['nodes'])===3&&$detail['nodes'][1]['type']==='annotation','Reload converted annotations');
 $detail['nodes'][1]['metadata']['annotation']['text']='编辑后的标签纸';
 $saved=Canvas::saveProject(1,1999999761,['id'=>$created['id'],'nodes'=>$detail['nodes'],'edges'=>[],'viewport'=>$detail['viewport']]);
 $check($saved['nodes'][1]['metadata']['annotation']['text']==='编辑后的标签纸','Save lost authored text');
 $graph=Templates::capturePage(1,$created['id'],$saved['viewport']['pages'][0]['id']);
 $check($graph['nodes'][2]['height']==42,'Preset capture changed text dimensions');
 $copy=Templates::instantiate($graph);
 $used=Canvas::createProject(1,1999999762,$copy+['name'=>'Annotation preset reuse']);
 $reloaded=Canvas::projectDetail(1,1999999762,$used['id']);
 $check($reloaded['nodes'][1]['metadata']['annotation']['text']==='编辑后的标签纸','Preset use lost editable note');
 $check($reloaded['nodes'][2]['metadata']['annotation']['fontFamily']==='Poppins','Preset use lost font');
 $check($reloaded['nodes'][0]['id']!==$created['nodes'][0]['id'],'Preset shares object identity');
 try{Canvas::projectDetail(2,1999999762,$used['id']);throw new RuntimeException('Cross-tenant project readable');}catch(Exception $e){if($e->getMessage()==='Cross-tenant project readable')throw $e;$n++;}
 echo "PASS $n annotation save/reload, preset capture/reuse and tenant isolation checks; rolled back\n";
}finally{Db::rollback();}
