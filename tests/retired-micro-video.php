<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\{RetiredAppService as R,AppAccessService as Access,AppRegistryService as Registry,AppAccessStateService as State};
use app\common\service\ai\{StudioMediaInputService,StudioVideoCatalogService,MarketVideoSubmissionService};
if(getenv('RETIRED_VIDEO_LOCAL_TEST')!=='x_cn'||config('database.connections.mysql.database')!=='x_cn')throw new RuntimeException('Explicit local opt-in required');
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);exit(1);});
$n=0;function retirementCheck(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException($m);$n++;}
$prefix=(string)config('database.connections.mysql.prefix');
$history=function()use($prefix){$out=[];foreach(Db::query('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE ?',[$prefix.'aigc_micro_video_%']) as $t)$out[$t['name']]=hash('sha256',json_encode(Db::table($t['name'])->order('id')->select()->toArray()));$out['bills']=hash('sha256',json_encode(Db::name('ai_consumption_log')->where('app_code','aigc_micro_video')->order('id')->select()->toArray()));return $out;};
$otherApps=fn()=>[Db::name('app')->whereNotIn('code',R::CODES)->order('id')->select()->toArray(),Db::name('tenant_app')->whereNotIn('app_code',R::CODES)->order('id')->select()->toArray()];
$balance=fn()=>[Db::name('user')->where('id',1)->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];
$before=$history();$others=$otherApps();$money=$balance();$oldStatus=Db::name('app')->where('code','aigc_micro_video')->value('status');
retirementCheck(!in_array('aigc_micro_video',Access::DEFAULT_APP_CODES,true),'Retired tool is not a default');
retirementCheck(!Access::tenantCanUse(1,'aigc_micro_video',false),'No admission even before upgrade SQL');
retirementCheck(!Access::tenantCanManage(1,'aigc_micro_video'),'No stale management entitlement');
retirementCheck(class_exists(StudioMediaInputService::class)&&class_exists(StudioVideoCatalogService::class),'Shared dependencies load without retired app');
retirementCheck(!method_exists(StudioMediaInputService::class,'merge'),'Retired segment generator removed from shared input service');
foreach(['aigc_product_video','aigc_story_commerce','aigc_motion_sync'] as $code)retirementCheck(MarketVideoSubmissionService::receipt(['app_code'=>$code,'response_summary'=>['micro_provider_receipt'=>['accepted'=>true]]])===['accepted'=>true],'Historical durable receipt remains compatible');
$blocked=false;try{Registry::getManifest('aigc_micro_video');}catch(InvalidArgumentException){$blocked=true;}retirementCheck($blocked,'Retired local installation is blocked');
Db::startTrans();try{
 State::preserveExisting(static fn()=>R::apply());
 retirementCheck(Db::name('app')->where('code','aigc_micro_video')->value('status')==='removed','Retired registered status');
 retirementCheck(Db::name('tenant_app')->where('app_code','aigc_micro_video')->where('shelf_status','on')->count()===0,'Preserve-state layer cannot reopen retired tool');
 retirementCheck(Db::name('app_frontend_entry')->where('app_code','aigc_micro_video')->count()===0,'All terminal entries gone');
 retirementCheck(Db::name('app_api')->where('app_code','aigc_micro_video')->count()===0,'No executable API registrations');
 R::apply();retirementCheck($history()===$before,'Idempotent retirement preserves every history table and bill');
 retirementCheck($otherApps()===$others,'Every other app and tenant switch remains identical');
 retirementCheck($balance()===$money,'Retirement does not charge or refund');
}finally{Db::rollback();}
retirementCheck(Db::name('app')->where('code','aigc_micro_video')->value('status')===$oldStatus,'Test rolled back registry changes');
retirementCheck($history()===$before,'Test rolled back without losing archive');
$sql=file_get_contents(dirname(__DIR__).'/public/install/db/like.sql');retirementCheck(!str_contains($sql,'aigc_micro_video'),'Fresh install contains no retired schema or seeds');
$plan=app\common\service\database\RuntimeMigrationPlan::build('CREATE TABLE IF NOT EXISTS `la_retirement_probe` (`id` int PRIMARY KEY) ENGINE=InnoDB;');retirementCheck($plan['prepare']!==''&&!class_exists('app\\common\\service\\database\\MicroVideoFrameSchema',false),'Core migration planning no longer needs deleted feature');
echo 'PASS '.$n.' retirement, archive, shared-runtime, entitlement and migration checks'.PHP_EOL;
