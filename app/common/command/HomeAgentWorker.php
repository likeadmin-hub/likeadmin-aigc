<?php
namespace app\common\command;
use think\console\{Command,Input,Output};
use think\console\input\Option;
use app\common\service\app\aigc_llm\agent\AgentExecutionService;

final class HomeAgentWorker extends Command
{
    protected function configure(){ $this->setName('home-agent:worker')->setDescription('首页创作 Agent 执行与任务恢复')->addOption('once',null,Option::VALUE_NONE,'处理一个任务后退出'); }
    protected function execute(Input $input,Output $output): int
    {
        $running=true;
        if(function_exists('pcntl_signal')){pcntl_async_signals(true);$stop=static function()use(&$running){$running=false;};pcntl_signal(SIGTERM,$stop);pcntl_signal(SIGINT,$stop);}
        try{do{
            if(function_exists('aigc_runtime_generation_changed')&&\aigc_runtime_generation_changed())break;
            $count=AgentExecutionService::tick();
            if($input->getOption('once')){$output->writeln('Processed '.$count.' home Agent run(s)');break;}
            if(!$count&&$running)usleep(1000000);
        }while($running);}catch(\Throwable $e){\think\facade\Log::error('Home Agent worker: '.get_class($e));$output->writeln('Agent worker stopped; inspect protected diagnostics.');return 1;}
        return 0;
    }
}
