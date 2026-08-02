<?php

namespace app\common\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class RepairShortDramaDuplicateAssets extends Command
{
    protected function configure(): void
    {
        $this->setName('short-drama:dedupe-assets')
            ->setDescription('Audit or soft-delete duplicate short-drama output assets')
            ->addOption('tenant-id', null, Option::VALUE_OPTIONAL, 'Tenant ID', 0)
            ->addOption('user-id', null, Option::VALUE_OPTIONAL, 'User ID', 0)
            ->addOption('project-id', null, Option::VALUE_OPTIONAL, 'Project ID', 0)
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'Maximum duplicate groups', 200)
            ->addOption('apply', null, Option::VALUE_NONE, 'Soft-delete duplicates; defaults to audit only');
    }

    protected function execute(Input $input, Output $output): int
    {
        $tenantId = max(0, (int)$input->getOption('tenant-id'));
        $userId = max(0, (int)$input->getOption('user-id'));
        $projectId = max(0, (int)$input->getOption('project-id'));
        $limit = max(1, min(1000, (int)$input->getOption('limit')));
        $apply = (bool)$input->getOption('apply');

        $query = Db::name('aigc_short_drama_asset')
            ->field('tenant_id,user_id,project_id,task_id,shot_id,asset_type,uri,COUNT(*) AS total')
            ->where('delete_time', 0)
            ->where('uri', '<>', '')
            ->group('tenant_id,user_id,project_id,task_id,shot_id,asset_type,uri')
            ->having('COUNT(*) > 1')
            ->order('total', 'desc')
            ->limit($limit);
        if ($tenantId > 0) $query->where('tenant_id', $tenantId);
        if ($userId > 0) $query->where('user_id', $userId);
        if ($projectId > 0) $query->where('project_id', $projectId);
        $groups = $query->select()->toArray();

        $removed = 0;
        foreach ($groups as $group) {
            $removed += $apply ? $this->dedupeGroup($group) : max(0, (int)$group['total'] - 1);
        }

        $output->writeln(sprintf('%s groups=%d duplicate_assets=%d', $apply ? 'deduped' : 'matched', count($groups), $removed));
        return 0;
    }

    private function dedupeGroup(array $group): int
    {
        return Db::transaction(function () use ($group) {
            $where = [
                'tenant_id' => (int)$group['tenant_id'],
                'user_id' => (int)$group['user_id'],
                'project_id' => (int)$group['project_id'],
                'task_id' => (string)$group['task_id'],
                'shot_id' => (string)$group['shot_id'],
                'asset_type' => (string)$group['asset_type'],
                'uri' => (string)$group['uri'],
                'delete_time' => 0,
            ];
            $assets = Db::name('aigc_short_drama_asset')->where($where)->lock(true)->order('id', 'desc')->select()->toArray();
            if (count($assets) < 2) return 0;

            $keepId = (int)$assets[0]['id'];
            $selectionField = (string)$group['asset_type'] === 'shot_video' ? 'selected_video_asset_id' : 'selected_image_asset_id';
            if ((string)$group['shot_id'] !== '') {
                $selectedId = (int)Db::name('aigc_short_drama_storyboard')->where([
                    'tenant_id' => (int)$group['tenant_id'],
                    'project_id' => (int)$group['project_id'],
                    'shot_id' => (string)$group['shot_id'],
                    'delete_time' => 0,
                ])->value($selectionField);
                foreach ($assets as $asset) {
                    if ((int)$asset['id'] === $selectedId) {
                        $keepId = $selectedId;
                        break;
                    }
                }
            }

            $removeIds = array_values(array_filter(array_map(static fn(array $asset): int => (int)$asset['id'], $assets), static fn(int $id): bool => $id !== $keepId));
            if ($removeIds === []) return 0;

            $task = Db::name('aigc_short_drama_generation_task')->where([
                'tenant_id' => (int)$group['tenant_id'],
                'user_id' => (int)$group['user_id'],
                'task_id' => (string)$group['task_id'],
            ])->lock(true)->find();
            if ($task) {
                $result = json_decode((string)($task['result_json'] ?? ''), true);
                if (!is_array($result)) $result = [];
                $result['asset_ids'] = [$keepId];
                Db::name('aigc_short_drama_generation_task')->where('id', (int)$task['id'])->update([
                    'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'output_asset_ids' => json_encode([$keepId]),
                    'update_time' => time(),
                ]);
            }

            Db::name('aigc_short_drama_asset')->whereIn('id', $removeIds)->update([
                'delete_time' => time(),
                'update_time' => time(),
            ]);
            return count($removeIds);
        });
    }
}
