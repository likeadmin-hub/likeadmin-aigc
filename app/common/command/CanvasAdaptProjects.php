<?php
namespace app\common\command;
use app\common\service\app\aigc_canvas\{AigcCanvasService,CanvasProjectCompatibility};
use app\common\model\app\aigc_canvas\AigcCanvasProject;
use think\console\{Command,Input,Output};
use think\console\input\Option;
use think\facade\Db;

/** Explicit, restartable migration. Default mode is read-only. */
final class CanvasAdaptProjects extends Command
{
    protected function configure()
    {
        $this->setName('canvas:adapt-projects')->setDescription('检查或适配历史画布项目，不调用生成服务')
            ->addOption('apply',null,Option::VALUE_NONE,'保存适配结果和导入历史对话')
            ->addOption('tenant',null,Option::VALUE_OPTIONAL,'限定租户；默认检查全部租户',0);
    }
    protected function execute(Input $input,Output $output): int
    {
        $tenant=max(0,(int)$input->getOption('tenant'));$apply=(bool)$input->getOption('apply');
        $cursor=0;$total=0;$changed=0;$failed=0;
        do {
            $query=AigcCanvasProject::where('delete_time',0)->where('id','>',$cursor);
            if ($tenant) $query->where('tenant_id',$tenant);
            $rows=$query->order('id')->limit(100)->select()->toArray();
            foreach ($rows as $row) {
                $cursor=(int)$row['id'];$total++;
                try {
                    $adapted=CanvasProjectCompatibility::adapt($row);
                    foreach (['nodes_json','edges_json','viewport_json'] as $key) if ($adapted[$key]!==\app\common\service\app\aigc_llm\agent\AgentStore::json($row[$key])) {$changed++;break;}
                    if ($apply) {
                        $detail=AigcCanvasService::projectDetail((int)$row['tenant_id'],(int)$row['user_id'],(int)$row['id']);
                        if (!empty($detail['compatibility_warnings'])) throw new \RuntimeException('History import deferred');
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    // Log identity, never content, storage addresses or credentials.
                    $output->writeln('项目 '.$row['id'].' 适配失败，原记录保留：'.get_class($e));
                }
            }
        } while (count($rows)===100);
        $output->writeln(json_encode(['applied'=>$apply,'projects'=>$total,'projects_needing_adaptation'=>$changed,'failed'=>$failed,'paid_tasks_submitted'=>0],JSON_UNESCAPED_UNICODE));
        return $failed?1:0;
    }
}
