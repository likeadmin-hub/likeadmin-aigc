<?php

namespace app\common\model\app\aigc_short_drama;

use app\common\model\app\AppBaseModel;
use think\facade\Db;

/** Project isolation is authoritative; episode columns also make ownership explicit in task/asset logs. */
class ShortDramaProductionModel extends AppBaseModel
{
    public static function onBeforeInsert($model): void
    {
        $data = $model->getData();
        $scope = ['tenant_id' => (int)($data['tenant_id'] ?? 0), 'user_id' => (int)($data['user_id'] ?? 0), 'delete_time' => 0];
        if (empty($data['project_id']) || !$scope['tenant_id']) return;
        $episode = Db::name('aigc_short_drama_episode_task')->where($scope)->where('production_project_id', (int)$data['project_id'])->find();
        if (!$episode) {
            $request = json_decode((string)($data['request_json'] ?? ''), true) ?: [];
            if (!empty($request['episode_id'])) $episode = Db::name('aigc_short_drama_episode_task')->where($scope)->where('id', (int)$request['episode_id'])->find();
        }
        if ($episode) {
            $model['episode_id'] = (int)$episode['id'];
            $model['episode_number'] = (int)$episode['episode_number'];
        }
    }
}
