<?php
namespace app\common\command;
use think\console\{Command,Input,Output};
use app\common\service\app\aigc_llm\agent\AgentWorkerHealth;
final class HomeAgentHealth extends Command
{
    protected function configure(){ $this->setName('home-agent:health')->setDescription('首页 Agent 只读健康检查，不调用模型或修改账务'); }
    protected function execute(Input $input,Output $output): int
    {
        try{
            $report=AgentWorkerHealth::report();
            $report['healthy']=$report['healthy']&&!in_array(false,$report['media_dependencies'],true)&&$report['receipt_storage_writable'];
            $output->writeln(json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            return $report['healthy']?0:2;
        }catch(\Throwable $e){$output->writeln(json_encode(['healthy'=>false,'error'=>'健康检查失败，请检查数据库、目录权限和受保护日志'],JSON_UNESCAPED_UNICODE));return 1;}
    }
}
