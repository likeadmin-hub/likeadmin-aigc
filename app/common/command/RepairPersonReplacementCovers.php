<?php
declare(strict_types=1);
namespace app\common\command;

use app\common\service\app\aigc_person_replacement\PersonReplacementAssetService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

final class RepairPersonReplacementCovers extends Command
{
    protected function configure(): void
    {
        $this->setName('person-replacement:repair-covers')
            ->setDescription('Audit or persist missing covers from saved successful person-replacement results')
            ->addOption('tenant-id', null, Option::VALUE_REQUIRED, 'Required tenant scope', 0)
            ->addOption('user-id', null, Option::VALUE_REQUIRED, 'Optional user scope', 0)
            ->addOption('result-id', null, Option::VALUE_REQUIRED, 'Optional result scope', 0)
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Maximum results', 200)
            ->addOption('apply', null, Option::VALUE_NONE, 'Persist covers; defaults to read-only audit');
    }

    protected function execute(Input $input, Output $output): int
    {
        $tenant = (int)$input->getOption('tenant-id');
        if ($tenant <= 0) { $output->writeln('A positive --tenant-id is required'); return 1; }
        request()->tenantId = $tenant;
        $query = Db::name('aigc_person_replacement_result')->alias('r')
            ->join('aigc_person_replacement_task t', 't.id=r.task_id AND t.tenant_id=r.tenant_id AND t.user_id=r.user_id')
            ->where(['r.tenant_id'=>$tenant,'r.delete_time'=>0,'t.delete_time'=>0,'t.status'=>'success'])
            ->where(function ($q) { $q->whereNull('r.cover_uri')->whereOr('r.cover_uri', ''); });
        foreach (['user-id'=>'r.user_id','result-id'=>'r.id'] as $option=>$field) {
            $id = (int)$input->getOption($option);
            if ($id > 0) $query->where($field, $id);
        }
        $ids = $query->order('r.id')->limit(max(1,min(1000,(int)$input->getOption('limit'))))->column('r.id');
        $apply = (bool)$input->getOption('apply');
        $counts = [];
        foreach ($ids as $id) {
            try { $result = PersonReplacementAssetService::repairCover($tenant, (int)$id, $apply); }
            catch (\Throwable $error) { $result = ['result_id'=>(int)$id, 'status'=>'failed', 'message'=>'封面补齐失败，请检查上游封面和素材存储配置']; }
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $output->writeln(json_encode(['tenant_id'=>$tenant,'apply'=>$apply,'matched'=>count($ids),'statuses'=>$counts], JSON_UNESCAPED_UNICODE));
        return !empty($counts['failed']) || !empty($counts['no_saved_cover']) ? 1 : 0;
    }
}
