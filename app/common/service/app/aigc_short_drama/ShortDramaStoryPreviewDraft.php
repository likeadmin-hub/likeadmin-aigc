<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;
use think\facade\Db;

/** Editable backup only: never a confirmed story, a receipt or generation input. */
final class ShortDramaStoryPreviewDraft
{
    private const KEY='checkpoint_story_preview_notes_v1';
    private const LIMIT=200000;

    private static function initial(array $preview): string
    {
        if (empty($preview['content']) && empty($preview['episodes']) && empty($preview['drafts'])) return '';
        $text=json_encode($preview,JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        return mb_strlen($text) <= self::LIMIT ? $text : '';
    }

    private static function token(string $text, int $revision): string
    {
        return hash('sha256',json_encode(['text'=>$text,'revision'=>$revision],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function view(int $tenant, int $user, string $task, array $preview): array
    {
        $row=Db::name('aigc_short_drama_planning_unit')->where(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task,
            'unit_key'=>self::KEY,'status'=>'checkpoint'])->find();
        $saved=$row ? json_decode((string)$row['result_json'],true) : null;
        $text=is_string($saved['text'] ?? null) ? $saved['text'] : self::initial($preview);
        if ($text === '' && !$row) return [];
        $revision=(int)($saved['revision'] ?? 0);
        return ['text'=>$text,'token'=>self::token($text,$revision)];
    }

    public static function save(int $tenant, int $user, string $task, int $project, string $token, string $text): array
    {
        if ($token === '' || mb_strlen($text) > self::LIMIT) throw new RuntimeException('备份内容超过允许长度或版本无效',422);
        return Db::transaction(static function () use ($tenant,$user,$task,$project,$token,$text): array {
            $scope=['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task];
            $owner=Db::name('aigc_short_drama_script_task')->where($scope+['delete_time'=>0])->lock(true)->find();
            if (!$owner || ($project > 0 && (int)$owner['project_id'] !== $project)) throw new RuntimeException('任务不存在',404);
            $request=json_decode((string)$owner['request_json'],true) ?: [];
            if (!ShortDramaStoryWorkflow::enabled($request) || !in_array($owner['status'],['failed','canceled'],true)) throw new RuntimeException('当前任务不支持编辑预览备份',409);
            $rowScope=$scope+['unit_key'=>self::KEY];
            $row=Db::name('aigc_short_drama_planning_unit')->where($rowScope)->lock(true)->find();
            $saved=$row ? json_decode((string)$row['result_json'],true) : null;
            if ($row && (($row['status'] ?? '') !== 'checkpoint' || !is_string($saved['text'] ?? null))) throw new RuntimeException('备份状态无效，请刷新',409);
            $state=json_decode((string)$owner['result_json'],true) ?: [];
            $current=$row ? $saved['text'] : self::initial((array)($state['__story_preview'] ?? []));
            $revision=(int)($saved['revision'] ?? 0);
            if (!$row && $current === '') throw new RuntimeException('没有可编辑的已保留预览',422);
            if (!hash_equals(self::token($current,$revision),$token)) throw new RuntimeException('备份已更新，请刷新后重试',409);
            $payload=json_encode(['version'=>1,'revision'=>++$revision,'text'=>$text],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($row) Db::name('aigc_short_drama_planning_unit')->where($rowScope)->update(['result_json'=>$payload,'update_time'=>time()]);
            else Db::name('aigc_short_drama_planning_unit')->insert($rowScope+['status'=>'checkpoint','attempt'=>0,'request_json'=>'{}',
                'result_json'=>$payload,'create_time'=>time(),'update_time'=>time()]);
            return ['text'=>$text,'token'=>self::token($text,$revision)];
        });
    }
}
