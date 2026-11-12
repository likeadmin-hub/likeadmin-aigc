<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $error): void { fwrite(STDERR, 'FAIL '.$error->getMessage().PHP_EOL); exit(1); });
use think\facade\Db;
use app\common\service\app\{RetiredAppService,AppAccessStateService};
$apply=in_array('--apply',$argv,true);$backup='';foreach($argv as $arg)if(str_starts_with($arg,'--backup-dir='))$backup=substr($arg,13);
$active=Db::name('ai_consumption_log')->where('app_code','aigc_micro_video')->where(function($q){$q->whereNotIn('run_status',['success','failed','canceled','cancelled'])->whereOr('billing_status','not in',['settled','refunded']);})->count();
if($apply){
 \app\common\service\app\RetiredAppWorkerGuard::assertStopped(root_path());
 if($active)throw new RuntimeException('退役前须先完成模型受理和费用核对；没有取消、重发或退款');
 $manifest=json_decode((string)@file_get_contents($backup.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
 if(($manifest['app_code']??'')!=='aigc_micro_video'||($manifest['database']??'')!==config('database.connections.mysql.database'))throw new RuntimeException('需要本数据库的退役备份');
 foreach($manifest['files'] as $name=>$entry){if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name)||!hash_equals($entry['sha256'],hash_file('sha256',$backup.'/'.$name.'.json')))throw new RuntimeException('退役备份校验失败');}
 AppAccessStateService::withSwitchLock(static fn()=>RetiredAppService::apply());
}
echo json_encode(['mode'=>$apply?'applied':'audit','pending_consumptions'=>$active,'app_status'=>Db::name('app')->where('code','aigc_micro_video')->value('status'),'frontend_entries'=>Db::name('app_frontend_entry')->where('app_code','aigc_micro_video')->count(),'api_entries'=>Db::name('app_api')->where('app_code','aigc_micro_video')->count(),'history_retained'=>true],JSON_UNESCAPED_UNICODE).PHP_EOL;
