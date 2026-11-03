<?php
namespace app\common\command;
use think\console\{Command,Input,Output};
use think\console\input\Option;
use app\common\service\app\aigc_copywriting\CopywritingWorkerService;
class CopywritingWorker extends Command
{
 protected function configure(){ $this->setName('copywriting:worker')->setDescription('智能文案文本任务')->addOption('once',null,Option::VALUE_NONE)->addOption('limit',null,Option::VALUE_OPTIONAL,'单轮任务数',1); }
 protected function execute(Input $input,Output $output)
 {
  $owner=gethostname().':'.getmypid();$worker=new CopywritingWorkerService();$lastSweep=0;$running=true;
  if(function_exists('pcntl_async_signals')&&function_exists('pcntl_signal')){
   pcntl_async_signals(true);
   $stop=static function()use(&$running){$running=false;};
   pcntl_signal(SIGTERM,$stop);pcntl_signal(SIGINT,$stop);
  }
  while($running){
   if(function_exists('aigc_runtime_generation_changed')&&aigc_runtime_generation_changed())break;
   if(time()-$lastSweep>=30){\app\common\service\app\aigc_copywriting\CopywritingRecovery::sweep();$lastSweep=time();}
   if(!$running)break;
   $jobs=CopywritingWorkerService::claim($owner,90,max(1,min(10,(int)$input->getOption('limit'))));
   // Finish the claimed task and its durable receipt before honoring shutdown.
   foreach($jobs as $job)$worker->run((int)$job['id'],$owner);
   if($input->getOption('once'))break;
   if(!$jobs&&$running)sleep(1);
  }
  return 0;
 }
}
