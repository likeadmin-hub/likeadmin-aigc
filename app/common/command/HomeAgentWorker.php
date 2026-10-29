<?php
namespace app\common\command;
use think\console\{Command,Input,Output};
use think\console\input\Option;
use app\common\service\app\aigc_llm\agent\AgentExecutionService;

final class HomeAgentWorker extends Command
{
    protected function configure(){ $this->setName('home-agent:worker')->setDescription('首页创作 Agent 执行与任务恢复')->addOption('once',null,Option::VALUE_NONE,'处理一个任务后退出')->addOption('tenant',null,Option::VALUE_REQUIRED,'Restrict to a tenant',0)->addOption('run',null,Option::VALUE_REQUIRED,'Restrict to a run',0); }
    protected function execute(Input $input,Output $output): int
    {
        foreach(["tenant", "run"] as $key)if(filter_var($input->getOption($key),FILTER_VALIDATE_INT)===false)throw new \InvalidArgumentException('Invalid worker scope');
        $tenant=(int)$input->getOption('tenant');$run=(int)$input->getOption('run');
        if($tenant<0 || $run<0 || ($run>0 && $tenant<=0))throw new \InvalidArgumentException('A scoped run requires its tenant');
        $running=true;$lastReconcile=0;$health=null;
        if(function_exists('pcntl_signal')){pcntl_async_signals(true);$stop=static function()use(&$running){$running=false;};pcntl_signal(SIGTERM,$stop);pcntl_signal(SIGINT,$stop);}
        try{
            $health=new \app\common\service\app\aigc_llm\agent\AgentWorkerHealth();
            do{
            $health->beat('busy');
            if(function_exists('aigc_runtime_generation_changed')&&\aigc_runtime_generation_changed())break;
            if(!$tenant && !$run && time()-$lastReconcile>=60){
                try{\app\common\service\app\aigc_llm\agent\AgentTranscriptionRecovery::scan();}
                catch(\Throwable $e){\think\facade\Log::error('Home Agent STT recovery: '.get_class($e));}
                try{\app\common\service\app\aigc_llm\agent\AgentSpeechTool::scan();}
                catch(\Throwable $e){\think\facade\Log::error('Home Agent speech recovery: '.get_class($e));}
                $lastReconcile=time();
            }
            $count=AgentExecutionService::tick($tenant,$run);
            $health->beat('idle');
            if($input->getOption('once')){$output->writeln('Processed '.$count.' home Agent run(s)');break;}
            if(!$count&&$running)usleep(1000000);
        }while($running);}catch(\Throwable $e){$info=$e instanceof \think\db\exception\PDOException?($e->getData()['PDO Error Info']??[]):[];\think\facade\Log::error('Home Agent worker: '.get_class($e).' state='.(string)($info['SQLSTATE']??'').' code='.(int)($info['Driver Error Code']??0));$output->writeln('Agent worker stopped; inspect protected diagnostics.');return 1;}finally{if($health)$health->close();}
        return 0;
    }
}
