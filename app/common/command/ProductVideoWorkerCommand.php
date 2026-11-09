<?php
namespace app\common\command;
use app\common\service\app\aigc_product_video\ProductVideoWorker;
use think\console\{Command,Input,Output};
use think\console\input\Option;
final class ProductVideoWorkerCommand extends Command
{
    protected function configure(){ $this->setName('product-video:worker')->addOption('once',null,Option::VALUE_NONE,'Process one stage')->addOption('job',null,Option::VALUE_OPTIONAL,'Restrict to job',0)->setDescription('Process independent product video jobs'); }
    protected function execute(Input $input,Output $output)
    {
        @set_time_limit(0);$running=true;
        if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);foreach([SIGTERM,SIGINT] as $signal)pcntl_signal($signal,static function()use(&$running){$running=false;});}
        do{
            if(function_exists('aigc_runtime_generation_changed')&&aigc_runtime_generation_changed())break;
            try{$count=ProductVideoWorker::tick((int)$input->getOption('job'));}catch(\Throwable $e){$output->writeln('Product video worker: '.get_class($e));return 1;}
            if($input->getOption('once')){$output->writeln('processed='.$count);break;}if(!$count)sleep(3);
        }while($running);return 0;
    }
}
