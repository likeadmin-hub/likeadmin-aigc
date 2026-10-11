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
    protected function execute(Input $input,Output $output){do{$count=OpenRecovery::tick();if($input->getOption('once')){$output->writeln('Processed '.$count.' open platform jobs');break;}if($count===0)usleep(1000000);}while(true);return 0;}
}
