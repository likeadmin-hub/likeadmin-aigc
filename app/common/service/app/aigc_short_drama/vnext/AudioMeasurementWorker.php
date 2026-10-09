<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;
use think\facade\Db;

/** Fenced local media inspection. A crashed or delayed worker cannot publish over a newer claim. */
final class AudioMeasurementWorker
{
    public const LEASE_SECONDS = 600;

    public static function tick(string $ffprobe, callable $load): int
    {
        if ($ffprobe === '' || !function_exists('exec')) return 0;
        $rows = Db::name('aigc_short_drama_asset')->where(['asset_type' => 'shot_audio', 'delete_time' => 0])
            ->whereRaw("JSON_VALID(meta_json) AND JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.source')) = 'canonical_recording' AND COALESCE(JSON_EXTRACT(meta_json, '$.probe_job.retry_at'), 0) <= ?", [time()])
            ->where(function ($q) { $q->where('status', 'pending')->whereOr(function ($r) { $r->where('status', 'processing')->where('update_time', '<=', time() - self::LEASE_SECONDS); }); })
            ->order('update_time')->limit(20)->field('id')->select()->toArray();
        foreach ($rows as $row) if (self::process((int)$row['id'], $ffprobe, $load)) return 1;
        return 0;
    }

    public static function claim(int $id, ?int $now = null): ?array
    {
        $now = $now ?? time();
        return Db::transaction(static function () use ($id, $now): ?array {
            $row = Db::name('aigc_short_drama_asset')->where(['id' => $id, 'asset_type' => 'shot_audio', 'delete_time' => 0])->lock(true)->find();
            if (!$row || !in_array($row['status'], ['pending', 'processing'], true)) return null;
            $meta = json_decode($row['meta_json'], true) ?: []; $job = $meta['probe_job'] ?? [];
            if (($meta['source'] ?? '') !== 'canonical_recording' || empty($meta['audio_upload'])
                || ($job['retry_at'] ?? 0) > $now || ($row['status'] === 'processing' && ($job['expires_at'] ?? PHP_INT_MAX) > $now)) return null;
            $meta['probe_job'] = array_replace($job, ['token' => bin2hex(random_bytes(16)), 'expires_at' => $now + self::LEASE_SECONDS,
                'attempts' => (int)($job['attempts'] ?? 0) + 1, 'retry_at' => 0, 'error' => '']);
            $row['status'] = 'processing'; $row['update_time'] = $now; $row['meta_json'] = json_encode($meta, JSON_THROW_ON_ERROR);
            Db::name('aigc_short_drama_asset')->where('id', $id)->update(['status' => $row['status'], 'update_time' => $now, 'meta_json' => $row['meta_json']]);
            return $row;
        });
    }

    public static function process(int $id, string $ffprobe, callable $load): bool
    {
        $row = self::claim($id); if (!$row) return false;
        $loaded = null;
        try {
            $meta = json_decode($row['meta_json'], true); $upload = $meta['audio_upload'];
            $candidate = AudioProduction::context((int)$row['tenant_id'], (int)$row['user_id'], (int)$row['project_id'], $row['task_id'], false);
            $segment = AudioHandoff::compile($candidate, $upload['audio_segment_id'], $upload['document_revision_id'], $upload['text_hash']);
            if (!AudioProduction::validUpload($upload, $row, $segment)) throw new RuntimeException('配音来源校验失败，请重新上传', 409);
            $loaded = $load($row, $upload['extension']); $path = (string)($loaded['path'] ?? '');
            AudioMediaProbe::assertFile($path, $upload['extension']);
            if (filesize($path) !== (int)$row['file_size'] || hash_file('sha256', $path) !== $row['checksum']) throw new RuntimeException('配音文件内容已变化，请重新上传', 409);
            $measurement = AudioMediaProbe::measure($path, $upload['extension'], $ffprobe);
            $trace = AudioHandoff::recordingTrace($segment, $measurement, $row['checksum'], (int)$row['user_id']);
            self::finish($row, $trace);
        } catch (\Throwable $error) {
            $meta = json_decode($row['meta_json'], true) ?: [];
            $retry = !in_array((int)$error->getCode(), [404, 409, 413, 422], true) && (int)($meta['probe_job']['attempts'] ?? 0) < 3;
            $meta['probe_job']['error'] = $retry ? '配音文件暂时读取失败，工作器稍后重试。'
                : (in_array((int)$error->getCode(), [409, 413, 422], true) ? mb_substr($error->getMessage(), 0, 200, 'UTF-8') : '配音文件无法读取或测量，请重新上传。');
            $meta['probe_job']['retry_at'] = $retry ? time() + 30 * (int)$meta['probe_job']['attempts'] : 0;
            unset($meta['probe_job']['token'], $meta['probe_job']['expires_at']);
            self::ownedClaim($row)->update(['status' => $retry ? 'pending' : 'failed', 'meta_json' => json_encode($meta, JSON_THROW_ON_ERROR), 'update_time' => time()]);
        } finally {
            if ($loaded && !empty($loaded['temporary']) && is_file($loaded['path'])) unlink($loaded['path']);
        }
        return true;
    }

    public static function finish(array $claim, array $trace): bool
    {
        $meta = json_decode($claim['meta_json'], true) ?: []; $upload = $meta['audio_upload'] ?? [];
        $signed = $trace; unset($signed['trace_hash']);
        if (($trace['trace_hash'] ?? '') !== ContentDocument::hash($signed) || ($trace['input_hash'] ?? '') !== ($upload['input_hash'] ?? null)
            || ($trace['checksum'] ?? '') !== $claim['checksum'] || ($trace['confirmed_by'] ?? 0) !== (int)$claim['user_id']) throw new RuntimeException('配音测量结果来源校验失败', 409);
        $meta['canonical_audio'] = $trace;
        $meta['probe_job'] = ['attempts' => $meta['probe_job']['attempts'] ?? 1, 'retry_at' => 0, 'error' => ''];
        return (bool)self::ownedClaim($claim)->update(['status' => 'ready', 'duration' => $trace['measurement']['duration_seconds'],
            'meta_json' => json_encode($meta, JSON_THROW_ON_ERROR), 'update_time' => time()]);
    }

    private static function ownedClaim(array $row)
    {
        return Db::name('aigc_short_drama_asset')->where(['id' => $row['id'], 'tenant_id' => $row['tenant_id'], 'user_id' => $row['user_id'],
            'project_id' => $row['project_id'], 'asset_type' => 'shot_audio', 'status' => 'processing', 'delete_time' => 0, 'meta_json' => $row['meta_json']])
            ->where('update_time', '>', time() - self::LEASE_SECONDS);
    }
}
