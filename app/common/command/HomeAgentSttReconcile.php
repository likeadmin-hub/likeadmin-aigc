<?php
namespace app\common\command;
use think\console\{Command,Input,Output};
use think\console\input\Option;
use app\common\service\app\aigc_llm\agent\AgentTranscriptionRecovery as Recovery;

final class HomeAgentSttReconcile extends Command
{
    protected function configure()
    {
        $this->setName('home-agent:stt-reconcile')->setDescription('转写预占核对（默认只读，不重复提交）')
            ->addOption('tenant',null,Option::VALUE_REQUIRED,'租户 ID')
            ->addOption('task',null,Option::VALUE_REQUIRED,'本地任务 ID')
            ->addOption('apply',null,Option::VALUE_NONE,'依据已保存的回执恢复结算')
            ->addOption('evidence',null,Option::VALUE_REQUIRED,'人工核实的原始回执 JSON 文件')
            ->addOption('operator',null,Option::VALUE_REQUIRED,'操作人标识')
            ->addOption('reason',null,Option::VALUE_REQUIRED,'上游核对依据及工单编号');
    }
    protected function execute(Input $input,Output $output): int
    {
        try{
            $tenant=(int)$input->getOption('tenant');$task=(int)$input->getOption('task');
            $apply=(bool)$input->getOption('apply');$file=(string)$input->getOption('evidence');
            if(($apply||$file!=='')&&($tenant<1||$task<1))throw new \InvalidArgumentException('写操作及凭据核对必须指定租户和单个任务');
            $rows=Recovery::audit($tenant,$task);
            if(($apply||$file!=='')&&count($rows)!==1)throw new \InvalidArgumentException('未找到唯一的转写任务');
            if($file!==''){
                if(!is_file($file)||filesize($file)>2200000)throw new \InvalidArgumentException('回执文件不存在或过大');
                $evidence=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
                $result=Recovery::importEvidence((int)$rows[0]['id'],$tenant,$evidence,(string)$input->getOption('operator'),(string)$input->getOption('reason'),$apply);
            }elseif($apply)$result=Recovery::reconcile((int)$rows[0]['id'],$tenant);
            else $result=['dry_run'=>true,'records'=>$rows];
            $output->writeln(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
            return $apply&&isset($result['resolved'])&&!$result['resolved']?2:0;
        }catch(\Throwable $e){$output->writeln($e->getMessage());return 1;}
    }
}
