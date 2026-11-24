<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\service\app\aigc_animate_replace\AnimateReplaceTasks;
use app\common\service\power\MarketAnimateReplace;
use think\facade\Db;

final class WorkspaceAnimateReplaceAssetService
{
    private static function query(int $tenant, int $user)
    {
        return Db::name('ai_task_result_asset')->alias('asset')
            ->join(AnimateReplaceTasks::TABLE.' task',
                'task.consumption_id=asset.consumption_id AND task.app_task_id=asset.app_task_id AND task.tenant_id=asset.tenant_id AND task.user_id=asset.user_id')
            ->where([
                'asset.tenant_id'=>$tenant, 'asset.user_id'=>$user,
                'asset.asset_type'=>'video', 'asset.transfer_status'=>'stored',
                'task.status'=>'success', 'task.delete_time'=>0,
            ])
            ->where('asset.local_uri', '<>', '')
            ->where('asset.storage_scope', '<>', '')
            ->where('asset.storage_engine', '<>', '')
            ->field('asset.id,asset.local_uri,asset.storage_scope,asset.storage_engine,asset.storage_domain,task.id as task_id,task.create_time');
    }

    public static function lists(int $tenant, int $user, int $page): array
    {
        $rows = self::query($tenant, $user)->order('asset.id', 'desc')->limit((max(1, $page) - 1) * 30, 31)->select()->toArray();
        return [
            'lists'=>array_map(static fn(array $row): array => self::view($tenant, $row), array_slice($rows, 0, 30)),
            'has_more'=>count($rows) > 30,
        ];
    }

    public static function owned(int $tenant, int $user, string $kind, int $id): ?array
    {
        if ($kind !== 'result') return null;
        $row = self::query($tenant, $user)->where('asset.id', $id)->find();
        return $row ? self::view($tenant, $row) : null;
    }

    private static function view(int $tenant, array $row): array
    {
        $id = (int)$row['id'];
        $task = (int)$row['task_id'];
        return [
            'source'=>'animate_replace',
            'ref'=>'animate_replace:result:'.$id,
            'raw'=>[
                'id'=>$id, 'result_id'=>$id, 'task_id'=>$task,
                'asset_type'=>'video', 'status'=>'success', 'title'=>'动画替换', 'model_name'=>'Wan 2.2',
                'video_url'=>FileService::getFileUrlByStorage(
                    $row['local_uri'], $row['storage_scope'], $row['storage_engine'], $row['storage_domain'], $tenant
                ),
                'create_time'=>(int)$row['create_time'],
                'creation_source'=>['app_code'=>MarketAnimateReplace::APP, 'task_id'=>(string)$task],
            ],
        ];
    }
}
