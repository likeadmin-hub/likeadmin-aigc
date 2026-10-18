<?php
namespace app\common\command;
use app\common\service\app\aigc_micro_video\MicroVideoWorker;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
final class MicroVideoWorkerCommand extends Command
{
    protected function configure(){ $this->setName('micro-video:worker')->addOption('once',null,Option::VALUE_NONE,'Run one leased stage')->addOption('task',null,Option::VALUE_OPTIONAL,'Restrict to task',0)->setDescription('Generate and concatenate confirmed micro videos'); }
    protected function execute(Input $input,Output $output)
    {
        @set_time_limit(0);$running=true;
        if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);foreach([SIGTERM,SIGINT] as $signal)pcntl_signal($signal,static function()use(&$running){$running=false;});}
        do{
            if(function_exists('aigc_runtime_generation_changed')&&aigc_runtime_generation_changed())break;
            // Preparation shares the normal supervisor lifecycle; failures never stop legacy execution.
            $prepared=0;if(!(int)$input->getOption('task')){try{$prepared=\app\common\service\app\aigc_micro_video\MicroVideoWorkbenchWorker::tick();}catch(\Throwable $e){\think\facade\Log::warning('Micro preparation worker: '.get_class($e));}}
            try{$count=MicroVideoWorker::tick((int)$input->getOption('task'));}catch(\Throwable $e){$output->writeln('Micro video worker stopped: '.get_class($e));return 1;}
            if($input->getOption('once')){$output->writeln('processed='.$count);break;}
            if(!$count&&!$prepared)sleep(3);
        }while($running);return 0;
    }
}
