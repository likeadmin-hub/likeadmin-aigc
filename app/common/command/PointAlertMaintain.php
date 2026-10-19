<?php
namespace app\common\command;
use app\common\service\point_alert\AlertService;
use think\console\{Command,Input,Output};
final class PointAlertMaintain extends Command
{
 protected function configure():void{$this->setName('point-alert:maintain')->setDescription('分批检查点数预警并发送已确认提醒');}
 protected function execute(Input $input,Output $output):int{$output->writeln(json_encode(AlertService::maintain(100),JSON_UNESCAPED_UNICODE));return 0;}
}
