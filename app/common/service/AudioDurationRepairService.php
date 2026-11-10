<?php
namespace app\common\service;

use RuntimeException;
use think\facade\Db;

/** Metadata repair only: never submits generation, charges a wallet, or changes task usage. */
final class AudioDurationRepairService
{
    public static function scan(int $tenantId = 0, int $limit = 100): array
    {
        $result = [];
        foreach (['aigc_music_asset'=>'uri', 'aigc_digital_human_voice'=>'audio_uri'] as $table=>$field) {
            $query = Db::name($table)->where('duration', '<=', 0)->where('delete_time', 0);
            if ($table === 'aigc_digital_human_voice') $query->where(function ($q): void { $q->where('audio_uri', '<>', '')->whereOr('preview_audio_uri', '<>', ''); });
            else $query->where($field, '<>', '');
            if ($tenantId > 0) $query->where('tenant_id', $tenantId);
            $fields = $field . ($table === 'aigc_digital_human_voice' ? ',preview_audio_uri' : '');
            $rows = $query->field('id,tenant_id,' . $fields . ',duration,storage_scope,storage_engine,storage_domain,update_time')
                ->order('id')->limit(max(1, min(1000, $limit)))->select()->toArray();
            foreach ($rows as $row) {
                $uri = trim((string)$row[$field]) ?: trim((string)($row['preview_audio_uri'] ?? ''));
                try { $seconds = AudioDurationService::detect($uri, (int)$row['tenant_id'], $row); }
                catch (\Throwable) { $seconds = 0; }
                $result[] = ['table'=>$table, 'before'=>$row, 'measured_seconds'=>$seconds,
                    'duration'=>$seconds > 0 ? ($table === 'aigc_digital_human_voice' ? (int)ceil($seconds) : $seconds) : null];
            }
        }
        return $result;
    }

    public static function apply(array $records): array
    {
        $dir = root_path() . 'runtime/audio-duration-repair/' . date('Ymd-His') . '-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true)) throw new RuntimeException('无法创建音频时长修复备份目录');
        $backup = $dir . '/before.json';
        $json = json_encode($records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($backup, $json, LOCK_EX) !== strlen($json) || !chmod($backup, 0600)) {
            throw new RuntimeException('音频时长修复备份失败，未执行更新');
        }
        $report = ['backup'=>$backup, 'updated'=>[], 'skipped'=>[]];
        Db::transaction(function () use ($records, &$report): void {
            foreach ($records as $record) {
                $table = $record['table']; $row = $record['before']; $seconds = (float)($record['duration'] ?? 0);
                if (!in_array($table, ['aigc_music_asset', 'aigc_digital_human_voice'], true)) throw new RuntimeException('不支持的音频时长修复表');
                $key = $table . ':' . $row['tenant_id'] . ':' . $row['id'];
                if (!is_finite($seconds) || $seconds <= 0) { $report['skipped'][] = $key; continue; }
                // Compare the complete measured snapshot so concurrent edits cannot receive stale durations.
                $query = Db::name($table)->where('delete_time', 0)->where('duration', '<=', 0);
                foreach ($row as $column=>$value) $query->where($column, $value);
                $changed = $query->update(['duration'=>$seconds, 'update_time'=>time()]);
                $report[$changed ? 'updated' : 'skipped'][] = $key;
            }
        });
        return $report;
    }
}
