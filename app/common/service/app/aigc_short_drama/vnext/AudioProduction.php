<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;
use think\facade\Db;

/** Scoped recording registration uses the existing asset table and never mutates legacy media. */
final class AudioProduction
{
    public static function context(int $tenant, int $user, int $project, string $task, bool $current = true): array
    {
        $scope = ['tenant_id' => $tenant, 'user_id' => $user];
        $row = Db::name('aigc_short_drama_script_task')->where($scope + ['project_id' => $project, 'task_id' => $task, 'delete_time' => 0])->find();
        $parent = Db::name('aigc_short_drama_project')->where($scope + ['id' => $project, 'delete_time' => 0])->find();
        if (!$row || !$parent) throw new RuntimeException('项目任务不存在', 404);
        if (!ApplicationWorkflow::current(json_decode($row['request_json'], true) ?: [])) throw new RuntimeException('此任务使用原制作流程', 409);
        if ($current && $parent['last_task_id'] !== $task) throw new RuntimeException('请打开当前正文版本制作；历史录音已保留', 409);
        $job = ApplicationWorkflow::store($tenant, $user, $task)->snapshot();
        if (!$job || $job['document_status'] !== 'ready' || $job['execution_status'] !== 'succeeded') throw new RuntimeException('剧本尚未就绪', 409);
        $input = json_decode($job['input_json'], true) ?: []; $candidate = json_decode($job['candidate_json'], true) ?: [];
        if ($job['input_hash'] !== ContentDocument::hash($input)) throw new RuntimeException('制作来源校验失败', 409);
        AudioHandoff::segments($candidate);
        if ($current) SeriesContext::assertDependencies($input['context']);
        return $candidate;
    }

    public static function page(int $tenant, int $user, int $project, string $task, int $page): array
    {
        if ($page < 1) throw new RuntimeException('配音页码无效', 422);
        $candidate = self::context($tenant, $user, $project, $task, false); $segments = AudioHandoff::segments($candidate);
        $total = count($segments); $segment = $segments[$page - 1] ?? null;
        if ($segment) $segment['asset'] = self::assets($tenant, $user, $project, [$segment])[$segment['audio_segment_id']] ?? null;
        return ['revision_id' => $candidate['document']['revision_id'], 'document_hash' => $candidate['document']['content_hash'],
            'page' => $page, 'total' => $total, 'next_page' => $page < $total ? $page + 1 : null, 'records' => $segment ? [$segment] : []];
    }

    public static function summary(int $tenant, int $user, int $project, array $candidate): array
    {
        $segments = AudioHandoff::segments($candidate); $assets = self::assets($tenant, $user, $project, $segments);
        return ['revision_id' => $candidate['document']['revision_id'], 'document_hash' => $candidate['document']['content_hash'],
            'total' => count($segments), 'ready' => count($assets), 'status' => count($assets) === count($segments) ? 'ready' : 'waiting_recordings'];
    }

    /** Upload and FFprobe run outside locks. The final revision check and registration are atomic. */
    public static function register(int $tenant, int $user, array $params, object $file, string $ffprobe, callable $persist): array
    {
        $project = (int)($params['project_id'] ?? 0); $task = (string)($params['task_id'] ?? '');
        $candidate = self::context($tenant, $user, $project, $task);
        $segment = AudioHandoff::compile($candidate, (string)($params['audio_segment_id'] ?? ''),
            (string)($params['expected_revision_id'] ?? ''), (string)($params['confirmed_text_hash'] ?? ''));
        if ((method_exists($file, 'getError') ? $file->getError() : 0) !== 0) throw new RuntimeException('配音文件上传失败', 422);
        $path = (string)$file->getRealPath(); $extension = strtolower((string)$file->extension());
        $measurement = AudioMediaProbe::measure($path, $extension, $ffprobe);
        $checksum = hash_file('sha256', $path);
        $trace = AudioHandoff::recordingTrace($segment, $measurement, $checksum, $user);
        $existing = self::existing($tenant, $user, $project, $task, $trace, $segment);
        if ($existing) return $existing;
        $stored = $persist();
        if (($stored['tenant_id'] ?? 0) !== $tenant || ($stored['source_id'] ?? 0) !== $user || empty($stored['uri'])
            || empty($stored['storage_scope']) || empty($stored['storage_engine'])) throw new RuntimeException('配音文件存储来源无法确认', 409);
        return Db::transaction(static function () use ($tenant, $user, $project, $task, $segment, $trace, $stored, $measurement, $checksum): array {
            $scope = ['tenant_id' => $tenant, 'user_id' => $user];
            Db::name('aigc_short_drama_vnext_job')->where($scope + ['task_id' => $task])->lock(true)->find();
            Db::name('aigc_short_drama_project')->where($scope + ['id' => $project])->lock(true)->find();
            $candidate = self::context($tenant, $user, $project, $task);
            $current = AudioHandoff::compile($candidate, $segment['audio_segment_id'], $segment['document_revision_id'], $segment['text_hash']);
            if ($current['input_hash'] !== $segment['input_hash']) throw new RuntimeException('配音来源已变化，请刷新后上传', 409);
            $existing = self::existing($tenant, $user, $project, $task, $trace, $segment);
            if ($existing) return $existing;
            $now = time();
            $row = $scope + ['project_id' => $project, 'task_id' => $task, 'shot_id' => '', 'asset_type' => 'shot_audio',
                'title' => '配音 ' . $segment['order'],
                'uri' => $stored['uri'], 'storage_scope' => $stored['storage_scope'], 'storage_engine' => $stored['storage_engine'],
                'storage_domain' => $stored['storage_domain'] ?? '', 'mime_type' => $stored['mime_type'] ?? 'audio/' . $stored['extension'],
                'file_size' => $stored['file_size'], 'duration' => $measurement['duration_seconds'], 'checksum' => $checksum,
                'meta_json' => json_encode(['source' => 'canonical_recording', 'canonical_audio' => $trace], JSON_THROW_ON_ERROR),
                'status' => 'ready', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0];
            $id = Db::name('aigc_short_drama_asset')->insertGetId($row); return $row + ['id' => $id];
        });
    }

    /** Read only recordings signed for this full text, speaker, revision and owned asset. */
    public static function assets(int $tenant, int $user, int $project, array $segments): array
    {
        if (!$segments) return [];
        $byId = array_column($segments, null, 'audio_segment_id'); $result = [];
        $rows = Db::name('aigc_short_drama_asset')->where(['tenant_id' => $tenant, 'user_id' => $user, 'project_id' => $project,
            'asset_type' => 'shot_audio', 'status' => 'ready', 'delete_time' => 0])->order('id', 'desc')->select()->toArray();
        foreach ($rows as $row) {
            $meta = json_decode($row['meta_json'], true) ?: []; $trace = $meta['canonical_audio'] ?? []; $id = $trace['audio_segment_id'] ?? '';
            if ($row['uri'] !== '' && isset($byId[$id]) && !isset($result[$id]) && AudioHandoff::matches($trace, $byId[$id], $row)) {
                $result[$id] = ['id' => (int)$row['id'], 'duration_seconds' => (float)$row['duration'], 'title' => $row['title'],
                    'url' => \app\common\service\FileService::getFileUrlByStorage($row['uri'], $row['storage_scope'], $row['storage_engine'], $row['storage_domain']),
                    'content_confirmation' => 'user_confirmed'];
            }
        }
        return $result;
    }

    private static function existing(int $tenant, int $user, int $project, string $task, array $trace, array $segment): ?array
    {
        $rows = Db::name('aigc_short_drama_asset')->where(['tenant_id' => $tenant, 'user_id' => $user, 'project_id' => $project,
            'task_id' => $task, 'asset_type' => 'shot_audio', 'checksum' => $trace['checksum'], 'status' => 'ready', 'delete_time' => 0])->select()->toArray();
        foreach ($rows as $row) {
            $saved = json_decode($row['meta_json'], true)['canonical_audio'] ?? [];
            if (($saved['trace_hash'] ?? '') === $trace['trace_hash'] && $row['uri'] !== '' && AudioHandoff::matches($saved, $segment, $row)) return $row;
        }
        return null;
    }
}
