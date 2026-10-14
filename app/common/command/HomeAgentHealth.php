<?php
namespace app\common\command;
use think\console\{Command,Input,Output};
use think\console\input\Option;
use app\common\service\app\aigc_llm\agent\AgentWorkerHealth;
final class HomeAgentHealth extends Command
{
    protected function configure(){ $this->setName('home-agent:health')->setDescription('首页 Agent 只读健康检查，不调用模型或修改账务')->addOption('attention',null,Option::VALUE_NONE,'待人工核对时返回退出码 3，供定时监控使用'); }
    protected function execute(Input $input,Output $output): int
    {
        try{
            $report=AgentWorkerHealth::report();
            $output->writeln(json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            return !$report['healthy']?2:($input->getOption('attention')&&$report['attention_required']?3:0);
        }catch(\Throwable $e){$output->writeln(json_encode(['healthy'=>false,'error'=>'健康检查失败，请检查数据库、目录权限和受保护日志'],JSON_UNESCAPED_UNICODE));return 1;}
    }
}
