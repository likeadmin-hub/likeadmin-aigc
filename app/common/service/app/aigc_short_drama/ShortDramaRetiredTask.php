<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;
use think\facade\Db;

/** Read-only compatibility for records from the withdrawn trial engine. */
final class ShortDramaRetiredTask
{
    public static function isRetired(array $request): bool
    {
        return !in_array($request['_pipeline_version'] ?? 0, [0, '0'], true)
            || !empty($request['_vnext_series']);
    }

    public static function assertWritable(array $request): void
    {
        if (self::isRetired($request)) throw new RuntimeException('此试验流程已停用，历史内容保留。请新建项目使用原有流程', 409);
    }

    public static function read(array $task, bool $withResult = true): array
    {
        $content = '';
        $notice = '此试验流程已停用，历史内容保留。请新建项目使用原有流程';
        if ($withResult) {
            try {
                // Only withdrawn records query this archive. No table creation, settlement or replay.
                $candidate = Db::name('aigc_short_drama_vnext_job')->where([
                    'tenant_id' => (int)$task['tenant_id'], 'user_id' => (int)$task['user_id'],
                    'task_id' => (string)$task['task_id'],
                ])->value('candidate_json');
                $content = self::text(json_decode((string)$candidate, true) ?: []);
            } catch (\Throwable $error) {
                $notice .= '；历史正文暂时无法读取，请联系管理员';
            }
        }
        $result = json_decode((string)($task['result_json'] ?? ''), true) ?: [];
        return [
            'task_id' => (string)$task['task_id'], 'project_id' => (int)$task['project_id'],
            'status' => in_array($task['status'] ?? '', ['success', 'failed', 'canceled'], true) ? $task['status'] : 'failed',
            'progress' => (int)($task['progress'] ?? 0), 'current_step' => '试验流程已停用',
            'prompt' => (string)($task['prompt'] ?? ''), 'error' => $notice,
            'retired_pipeline' => true, 'retired_content' => $content,
            'result' => $withResult && ($task['status'] ?? '') === 'success' ? $result : null,
        ];
    }

    public static function text(array $candidate): string
    {
        $document = $candidate['document'] ?? [];
        $names = [];
        foreach (($document['entities'] ?? []) as $entity) $names[$entity['entity_id'] ?? ''] = $entity['canonical_name'] ?? '';
        $lines = [];
        foreach (($document['scenes'] ?? []) as $scene) {
            foreach (($scene['units'] ?? []) as $unit) {
                if (!is_string($unit['text'] ?? null)) continue;
                $speaker = $names[$unit['speaker_id'] ?? ''] ?? '';
                $lines[] = ($speaker !== '' ? $speaker . '：' : '') . $unit['text'];
            }
        }
        return implode("\n", $lines);
    }
}
