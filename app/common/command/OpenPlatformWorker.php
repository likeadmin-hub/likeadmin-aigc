<?php
namespace app\common\command;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use app\common\service\open_platform\OpenRecovery;
final class OpenPlatformWorker extends Command
{
    protected function configure(){ $this->setName('open-platform:worker')->setDescription('恢复开放任务并投递签名通知')->addOption('once',null,Option::VALUE_NONE,'只处理一批'); }
    protected function execute(Input $input,Output $output): int
    {
        $running=true;$stop=static function()use(&$running){$running=false;};
        if(function_exists('pcntl_signal')){pcntl_async_signals(true);pcntl_signal(SIGTERM,$stop);pcntl_signal(SIGINT,$stop);}
        try{
            do{
                if(function_exists('aigc_runtime_generation_changed')&&\aigc_runtime_generation_changed())break;
                $count=OpenRecovery::tick();
                if($input->getOption('once')){$output->writeln('Processed '.$count.' open platform jobs');break;}
                if($count===0&&$running)usleep(1000000);
            }while($running);
        }catch(\Throwable $error){
            $output->writeln(OpenRecovery::connectionLost($error)?'Database connection lost; worker stopped for supervisor restart.':'Open platform worker failed; inspect protected server diagnostics.');
            return 1;
        }
        return 0;
    }
}
