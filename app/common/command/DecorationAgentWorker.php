<?php
declare(strict_types=1);
namespace app\common\command;
use app\common\service\decorate\agent\DecorationAgentService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
final class DecorationAgentWorker extends Command
{
    protected function configure(): void {$this->setName('decoration:agent-worker')->setDescription('Process isolated decoration candidates')->addOption('once',null,Option::VALUE_NONE)->addOption('tenant',null,Option::VALUE_OPTIONAL,'Restrict tenant',0)->addOption('sleep',null,Option::VALUE_OPTIONAL,'Idle seconds',2);}
    protected function execute(Input $input,Output $output): int
    {
        @set_time_limit(0);$running=true;$ready=false;$nextReadiness=0;
        if(function_exists('pcntl_signal')){pcntl_async_signals(true);$stop=function()use(&$running){$running=false;};pcntl_signal(SIGTERM,$stop);pcntl_signal(SIGINT,$stop);}
        do{
            if(function_exists('aigc_runtime_generation_changed')&&\aigc_runtime_generation_changed())break;
            try{
                if(!$ready && time()>=$nextReadiness){$ready=DecorationAgentService::workerReady();$nextReadiness=time()+30;}
                $count=$ready?DecorationAgentService::tick((int)$input->getOption('tenant')):0;
            }catch(\Throwable $e){$output->writeln('Decoration worker stopped: '.$e->getMessage());return 1;}
            if($input->getOption('once')){$output->writeln('processed='.$count);break;}
            if($count===0&&$running)sleep(max(1,min(30,(int)$input->getOption('sleep'))));
        }while($running);return 0;
    }
}
