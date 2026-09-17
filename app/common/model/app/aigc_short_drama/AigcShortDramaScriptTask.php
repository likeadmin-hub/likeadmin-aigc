<?php

namespace app\common\model\app\aigc_short_drama;


class AigcShortDramaScriptTask extends ShortDramaProductionModel
{
    protected $name = 'aigc_short_drama_script_task';

    public static function onBeforeInsert($model): void
    {
        parent::onBeforeInsert($model);
        $request = json_decode((string)($model['request_json'] ?? ''), true) ?: [];
        $snapshot = (array)($request['_skill_snapshot'] ?? []);
        if (!$snapshot) return;
        $model['skill_id'] = (int)$snapshot['id'];
        $model['skill_version'] = (int)$snapshot['version'];
        $model['skill_source'] = (string)($snapshot['source'] ?? 'manual');
        $model['skill_snapshot_json'] = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Edited/retried plans require confirmation of their new content.
        unset($request['_skill_confirmations']);
        $model['request_json'] = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function onAfterInsert($model): void
    {
        $snapshot = json_decode((string)($model['skill_snapshot_json'] ?? ''), true) ?: [];
        if ($snapshot) \app\common\service\app\aigc_short_drama\ShortDramaSkillService::recordUsage((int)$model['tenant_id'], (int)$model['user_id'], (int)$model['project_id'], (string)$model['task_id'], $snapshot);
    }
}
