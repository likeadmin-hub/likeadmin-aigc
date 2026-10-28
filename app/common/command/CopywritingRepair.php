<?php
namespace app\common\command;
use think\console\{Command,Input,Output};use think\console\input\Option;
use app\common\service\app\aigc_copywriting\CopywritingBootstrap;
class CopywritingRepair extends Command {
 protected function configure(){$this->setName('copywriting:repair')->addOption('apply',null,Option::VALUE_NONE)->addOption('tenant',null,Option::VALUE_OPTIONAL,'租户ID',0);}
 protected function execute(Input $input,Output $output){if(!$input->getOption('apply')){$output->writeln('dry-run: 将同步智能文案系统注册、菜单和缺失的默认模板');return 0;}$id=(int)$input->getOption('tenant');if($id){CopywritingBootstrap::ensureSystem();CopywritingBootstrap::ensureTenant($id);}else CopywritingBootstrap::migrate();$output->writeln('智能文案初始化完成');return 0;}
}
