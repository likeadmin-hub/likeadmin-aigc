<?php
// Read-only local audit. Quote validation never reserves, submits or contacts a provider.
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_micro_video\MicroVideoCatalog as C;
use app\common\service\app\aigc_story_commerce\{StoryCommerceJobs as SJ,StoryCommercePolicy as SP,StoryCommerceService as SS};
use app\common\service\app\aigc_product_video\{ProductVideoJobs as PJ,ProductVideoPolicy as PP,ProductVideoService as PS};
if(getenv('STUDIO_PRICING_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local database opt-in required');
$checks=0;function priceAssert(bool $v,string $why):void{global $checks;if(!$v)throw new RuntimeException($why);$checks++;}
$count=static fn()=>[Db::name('ai_consumption_log')->count(),Db::name('ai_task_job')->count(),SS::table('project')->count(),SS::table('quote')->count(),PS::table('project')->count(),PS::table('quote')->count()];
$models=C::models(1,false,'reference',null,true);$verified=0;
foreach($models as $summary){
 $m=C::models(1,false,'reference',[(string)$summary['id']])[0]??null;priceAssert($m!==null,'Summary must resolve');
 foreach(['specs','start_end_specs'] as $key){
  $groups=[];foreach($m[$key]??[] as $s)$groups[$s['quality'].'|'.$s['ratio']][]=$s;
  foreach($groups as $rows){usort($rows,fn($a,$b)=>$a['duration']<=>$b['duration']);foreach(array_unique([0,count($rows)-1]) as $index){$s=$rows[$index];$q=C::quote(1,$s,$m['id']);priceAssert(abs($q['user_charge_points']-$s['user_charge_points'])<0.000001,'Catalog/admission mismatch '.$m['id'].' '.$s['quality'].' '.$s['ratio'].' '.$s['duration']);$verified++;}}
 }
 echo 'catalog '.$m['id'].' checked'.PHP_EOL;
}
$before=$count();
foreach([[SJ::class,SP::class,SS::class],[PJ::class,PP::class,PS::class]] as [$jobs,$policy,$service]){
 $r=$jobs::table()->where(['action'=>'video','status'=>'success','delete_time'=>0])->order('id','desc')->find();if(!$r)continue;
 $request=$policy::json($r['request_json']);$args=['preview'=>true,'action'=>'video','body'=>$request['snapshot']];
 // Historic works may outlive their input uploads. Those inputs must remain unquotable.
 if($jobs===PJ::class){$source=SJ::table()->where(['action'=>'video','status'=>'success','delete_time'=>0])->order('id','desc')->find();$body=SP::json($source['request_json'])['snapshot'];$r['tenant_id']=$source['tenant_id'];$r['user_id']=$source['user_id'];$args['body']=PP::body(['mode'=>'normal','images'=>$body['images'],'video_selection'=>$body['video_selection'],'prompt'=>'按商品素材展示外观，保持标识和结构一致。']);}
 $q=$jobs::estimate((int)$r['tenant_id'],(int)$r['user_id'],$args);priceAssert(is_finite($q['user_charge_points'])&&$q['user_charge_points']>=0,'Current owned draft quote');priceAssert(!isset($q['quote_token']),'Preview must not authorize submit');
 $dto=$jobs::dto($jobs::table()->where('id',$r['id'])->find());priceAssert(abs($dto['estimated_user_charge_points']-$request['quote']['user_charge_points'])<0.000001,'History uses immutable submission quote, not catalog or final usage');
 $rejected=false;try{$jobs::estimate((int)$r['tenant_id'],999999,$args);}catch(Throwable){$rejected=true;}priceAssert($rejected,'Foreign media must be rejected');
}
priceAssert($count()===$before,'Read-only previews must not write drafts, quote rows, queues or billing records');
echo 'PASS '.$checks.' checks; '.$verified.' native price comparisons across '.count($models).' models'.PHP_EOL;
