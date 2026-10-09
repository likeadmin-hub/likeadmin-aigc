<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\AudioHandoff;
use app\common\service\app\aigc_short_drama\vnext\AudioMediaProbe;
use app\common\service\app\aigc_short_drama\vnext\AudioMeasurementWorker;
use app\common\service\app\aigc_short_drama\vnext\AudioProduction;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\ExecutionStore;
use app\common\service\app\aigc_short_drama\vnext\ReceiptBuffer;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaVnextAudioProductionTest extends TestCase
{
    private function candidate(): array
    {
        $document = ContentDocument::normalize(['title' => '完整配音', 'scenes' => [['location' => 'l1', 'units' => [
            ['type' => 'dialogue', 'speaker' => 'c1', 'speech_type' => 'voiceover', 'text' => "先听我说完。\n" . str_repeat('完整台词。', 200) . '最后一句😀。'],
            ['type' => 'dialogue', 'speech_type' => 'narration', 'text' => '故事继续。'],
        ]]]], ['revision_id' => 'audio-r1', 'episode_id' => 'e1', 'entity_scope' => 'fixture', 'entities' => [
            ['ref' => 'c1', 'entity_id' => 'speaker1', 'kind' => 'character', 'name' => '陈默'],
            ['ref' => 'l1', 'entity_id' => 'room1', 'kind' => 'location', 'name' => '书房'],
        ]]);
        $plan = ShotCompiler::compile($document, ['shots' => [
            ['visual_description' => '窗边', 'refs' => [['unit' => 'u1']]],
            ['visual_description' => '切换画面', 'refs' => [['unit' => 'u1'], ['unit' => 'u2']]],
        ]], 'audio-shots1');
        return ['document' => $document, 'shot_plan' => $plan, 'coverage_report' => ['status' => 'ready']];
    }

    public function testWholeDialoguePlaysOnceWithExplicitCharacterAndNarrator(): void
    {
        $candidate = $this->candidate(); $segments = AudioHandoff::segments($candidate);
        self::assertCount(2, $segments); self::assertSame(['陈默', '旁白'], array_column($segments, 'speaker_name'));
        self::assertSame(['voiceover', 'narration'], array_column($segments, 'speech_type'));
        self::assertSame($candidate['document']['scenes'][0]['units'][0]['text'], $segments[0]['text']);
        self::assertStringEndsWith('最后一句😀。', $segments[0]['text']);
        self::assertSame($segments[0], AudioHandoff::compile($candidate, $segments[0]['audio_segment_id'], 'audio-r1', $segments[0]['text_hash']));
        self::assertNull($segments[1]['speaker_id']);
    }

    public function testEvenResealedDuplicateOrTruncatedAudioSegmentsCannotPass(): void
    {
        foreach (['duplicate', 'truncate', 'speaker', 'order'] as $mutation) {
            $candidate = $this->candidate(); $plan = &$candidate['shot_plan'];
            if ($mutation === 'duplicate') $plan['audio_segments'][] = $plan['audio_segments'][0];
            if ($mutation === 'truncate') $plan['audio_segments'][0]['end']--;
            if ($mutation === 'speaker') $plan['audio_segments'][0]['speaker_id'] = null;
            if ($mutation === 'order') $plan['audio_segments'] = array_reverse($plan['audio_segments']);
            unset($plan['content_hash']); $plan['content_hash'] = ContentDocument::hash($plan);
            try { AudioHandoff::segments($candidate); self::fail('Invalid audio coverage accepted'); }
            catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        }
    }

    public function testOldVersionWrongConfirmationAndCorruptedContentAreRejected(): void
    {
        $candidate = $this->candidate(); $segment = AudioHandoff::segments($candidate)[0];
        foreach ([['old', $segment['text_hash'], 409], ['audio-r1', 'wrong', 422]] as [$revision, $hash, $code]) {
            try { AudioHandoff::compile($candidate, $segment['audio_segment_id'], $revision, $hash); self::fail('Stale audio accepted'); }
            catch (\RuntimeException $error) { self::assertSame($code, $error->getCode()); }
        }
        $candidate['document']['scenes'][0]['units'][0]['text'] .= '错误追加';
        $this->expectExceptionCode(409); AudioHandoff::segments($candidate);
    }

    public function testRecordingTraceIsSmallAndChangesWithActualMediaAndContent(): void
    {
        $candidate = $this->candidate(); $segment = AudioHandoff::segments($candidate)[0];
        $trace = AudioHandoff::recordingTrace($segment, AudioMediaProbe::measurement(['streams' => [['codec_type' => 'audio', 'duration' => 23.5]]]), str_repeat('a', 64), 7);
        self::assertLessThan(2000, strlen(json_encode($trace))); self::assertArrayNotHasKey('text', $trace);
        self::assertSame('user_confirmed', $trace['content_confirmation']);
        $asset = ['user_id' => 7, 'checksum' => str_repeat('a', 64), 'duration' => 23.5];
        self::assertTrue(AudioHandoff::matches($trace, $segment, $asset));
        self::assertFalse(AudioHandoff::matches($trace, $segment, array_replace($asset, ['user_id' => 8])));
        self::assertFalse(AudioHandoff::matches($trace, $segment, array_replace($asset, ['duration' => 14])));
        $trace['speaker_id'] = null; self::assertFalse(AudioHandoff::matches($trace, $segment, $asset));
    }

    public function testInvalidMediaNeverBecomesAReadyRecording(): void
    {
        foreach ([[], ['streams' => [['codec_type' => 'video', 'duration' => 3]]],
            ['streams' => [['codec_type' => 'audio', 'duration' => 0]]], ['streams' => [['codec_type' => 'audio', 'duration' => 7201]]],
            ['streams' => [['codec_type' => 'audio', 'duration' => 'N/A']]],
            ['streams' => [['codec_type' => 'audio', 'duration' => 3], ['codec_type' => 'audio', 'duration' => 3]]]] as $probe) {
            try { AudioMediaProbe::measurement($probe); self::fail('Invalid measurement accepted'); }
            catch (\RuntimeException $error) { self::assertSame(422, $error->getCode()); }
        }
    }

    public function testRealAudioRegistrationPaginationIsolationRecoveryAndRevisionRace(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional DB fixture');
        (new \think\App())->initialize(); Db::startTrans();
        $path = tempnam(sys_get_temp_dir(), 'canonical_audio_');
        try {
            // Real PCM WAV, 3.123375 seconds: precision exceeds the legacy decimal(8,2) asset column.
            $data = str_repeat("\0", (8000 * 3 + 987) * 2);
            file_put_contents($path, 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
                . 'data' . pack('V', strlen($data)) . $data);
            $file = new class($path) { private string $path; public function __construct($path) { $this->path = $path; }
                public function getRealPath() { return $this->path; } public function extension() { return 'wav'; } public function getError() { return 0; } };
            $tenant = 2000000759; $user = 7; $task = 'audio_' . bin2hex(random_bytes(8)); $scope = ['tenant_id' => $tenant, 'user_id' => $user];
            $project = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => 'Audio fixture', 'last_task_id' => $task]);
            Db::name('aigc_short_drama_script_task')->insert($scope + ['project_id' => $project, 'task_id' => $task,
                'status' => 'success', 'request_json' => '{"_pipeline_version":1}']);
            $candidate = $this->candidate(); $input = ['context' => ['dependency_versions' => [], 'revision_id' => 'audio-r1']];
            $store = new ExecutionStore($tenant, $user, $task, new ReceiptBuffer($path . '_receipts')); $store->create($task, $input, time() + 600);
            // Escaped Unicode reproduces current UTF-8 three-byte legacy database columns.
            Db::name('aigc_short_drama_vnext_job')->where($scope + ['task_id' => $task])->update([
                'execution_status' => 'succeeded', 'document_status' => 'ready', 'candidate_json' => json_encode($candidate)]);
            $segments = AudioHandoff::segments($candidate); $segment = $segments[0];
            $detail = Service::scriptPlanDetail($tenant, $user, $task, $project, 0, 0, 1);
            self::assertSame($segment['text'], $detail['audio_handoff']['records'][0]['text']);
            self::assertArrayNotHasKey('dependency_versions', $detail['audio_handoff']['records'][0]);
            self::assertArrayNotHasKey('generation_snapshot', $detail); self::assertSame(2, $detail['audio_handoff']['next_page']);
            $params = ['project_id' => $project, 'task_id' => $task, 'audio_segment_id' => $segment['audio_segment_id'],
                'expected_revision_id' => 'audio-r1', 'confirmed_text_hash' => $segment['text_hash'], 'duration' => 1, 'speaker_id' => 'forged'];
            $stored = ['tenant_id' => $tenant, 'source_id' => $user, 'uri' => 'uploads/audio_fixture.wav', 'storage_scope' => 'tenant',
                'storage_engine' => 'local', 'storage_domain' => '', 'file_size' => filesize($path), 'extension' => 'wav', 'mime_type' => 'audio/wav'];
            $persisted = 0; $persist = static function () use ($stored, &$persisted) { $persisted++; return $stored; };
            $resolver = new \ReflectionMethod(Service::class, 'resolveFfmpegBinary'); $resolver->setAccessible(true); $ffmpeg = $resolver->invoke(null);
            $probe = new \ReflectionMethod(Service::class, 'resolveFfprobeBinary'); $probe->setAccessible(true); $ffprobe = $probe->invoke(null, $ffmpeg);
            self::assertNotEmpty($ffprobe, 'Local media acceptance requires FFprobe');
            $asset = AudioProduction::register($tenant, $user, $params, $file, $ffprobe, $persist);
            self::assertSame(3.123375, $asset['duration']); self::assertSame('shot_audio', $asset['asset_type']);
            $again = AudioProduction::register($tenant, $user, $params, $file, $ffprobe, $persist);
            self::assertSame($asset['id'], (int)$again['id']); self::assertSame(1, $persisted, 'Response recovery must not upload another file');
            self::assertSame(1, AudioProduction::summary($tenant, $user, $project, $candidate)['ready']);
            self::assertSame(3.123375, Service::scriptPlanDetail($tenant, $user, $task, $project, 0, 0, 1)['audio_handoff']['records'][0]['asset']['duration_seconds']);
            foreach ([[$tenant + 1, $user, $project], [$tenant, $user + 1, $project], [$tenant, $user, $project + 1]] as [$t, $u, $p]) {
                try { AudioProduction::register($t, $u, array_replace($params, ['project_id' => $p]), $file, $ffprobe, $persist); self::fail('Foreign scope accepted'); }
                catch (\RuntimeException $error) { self::assertSame(404, $error->getCode()); }
            }
            foreach ([-1, 3] as $page) {
                if ($page > 0) { self::assertSame([], AudioProduction::page($tenant, $user, $project, $task, $page)['records']); continue; }
                try { AudioProduction::page($tenant, $user, $project, $task, $page); self::fail('Negative page accepted'); }
                catch (\RuntimeException $error) { self::assertSame(422, $error->getCode()); }
            }
            try { Service::scriptPlanDetail($tenant, $user, $task, $project, 1, 0, 1); self::fail('Mixed readers accepted'); }
            catch (\RuntimeException $error) { self::assertSame(422, $error->getCode()); }
            $params['audio_segment_id'] = $segments[1]['audio_segment_id']; $params['confirmed_text_hash'] = $segments[1]['text_hash'];
            try {
                AudioProduction::register($tenant, $user, $params, $file, $ffprobe, static function () use ($stored, $scope, $project) {
                    Db::name('aigc_short_drama_project')->where($scope + ['id' => $project])->update(['last_task_id' => 'new_revision']); return $stored;
                }); self::fail('Revision changed during upload');
            } catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
            self::assertSame(1, Db::name('aigc_short_drama_asset')->where($scope + ['project_id' => $project, 'asset_type' => 'shot_audio'])->count());
            self::assertSame($segment['text'], AudioProduction::page($tenant, $user, $project, $task, 1)['records'][0]['text'], 'Historical audio stays readable');
            Db::name('aigc_short_drama_project')->where($scope + ['id' => $project])->update(['last_task_id' => $task]);
            $timings = array_combine(array_column($candidate['shot_plan']['shots'], 'shot_id'), [2, 5]);
            $previewParams = ['project_id' => $project, 'task_id' => $task, 'task_type' => 'shot_video', 'production_timeline_preview' => 1,
                'expected_revision_id' => 'audio-r1', 'shot_timings' => $timings];
            try { Service::estimateShotGenerationTask($tenant, $user, $previewParams); self::fail('Unrecorded narration accepted'); }
            catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
            AudioProduction::register($tenant, $user, $params, $file, $ffprobe, $persist);
            $before = Db::name('ai_consumption_log')->where($scope)->count();
            $preview = Service::estimateShotGenerationTask($tenant, $user, $previewParams);
            self::assertTrue($preview['preview_only']); self::assertCount(2, $preview['production_timeline']['audio']);
            self::assertSame(7.0, $preview['production_timeline']['duration_seconds']);
            self::assertSame(3.123375, $preview['production_timeline']['audio'][0]['end_seconds']);
            self::assertSame(6.24675, $preview['production_timeline']['audio'][1]['end_seconds']);
            self::assertSame($before, Db::name('ai_consumption_log')->where($scope)->count(), 'Preview never reserves or submits');
            $params['audio_segment_id'] = $segment['audio_segment_id']; $params['confirmed_text_hash'] = $segment['text_hash'];
            $enqueue = static function (int $samples) use ($path, $stored, $tenant, $user, $params, $file): array {
                $data = str_repeat("\0", $samples * 2);
                file_put_contents($path, 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
                    . 'data' . pack('V', strlen($data)) . $data);
                clearstatcache(false, $path);
                $upload = array_replace($stored, ['uri' => 'uploads/worker-' . $samples . '.wav', 'file_size' => filesize($path)]);
                // A Web process without exec/FFprobe still stores an owned pending asset.
                return AudioProduction::register($tenant, $user, $params, $file, '', static fn() => $upload);
            };
            $queued = $enqueue(8000 * 4);
            self::assertSame('pending', $queued['status']); self::assertSame(0, $queued['duration']);
            self::assertSame('pending', AudioProduction::page($tenant, $user, $project, $task, 1)['records'][0]['job']['status']);
            self::assertSame(1, AudioProduction::summary($tenant, $user, $project, $candidate)['pending']);
            self::assertSame(3.123375, AudioProduction::page($tenant, $user, $project, $task, 1)['records'][0]['asset']['duration_seconds'], 'Previous valid audio remains playable');
            $reads = 0; $load = static function () use ($path, &$reads): array { $reads++; return ['path' => $path, 'temporary' => false]; };
            self::assertTrue(AudioMeasurementWorker::process((int)$queued['id'], $ffprobe, $load));
            self::assertSame(4.0, AudioProduction::page($tenant, $user, $project, $task, 1)['records'][0]['asset']['duration_seconds']);
            self::assertFalse(AudioMeasurementWorker::process((int)$queued['id'], $ffprobe, $load)); self::assertSame(1, $reads);
            $queued = $enqueue(8000 * 5); $oldClaim = AudioMeasurementWorker::claim((int)$queued['id']);
            self::assertNotNull($oldClaim); self::assertNull(AudioMeasurementWorker::claim((int)$queued['id']), 'Live worker is not dispatched twice');
            $newClaim = AudioMeasurementWorker::claim((int)$queued['id'], time() + AudioMeasurementWorker::LEASE_SECONDS + 1);
            self::assertNotNull($newClaim);
            $trace = AudioHandoff::recordingTrace($segment, AudioMediaProbe::measure($path, 'wav', $ffprobe), hash_file('sha256', $path), $user);
            self::assertFalse(AudioMeasurementWorker::finish($oldClaim, $trace), 'Expired worker cannot publish over a new lease');
            self::assertTrue(AudioMeasurementWorker::finish($newClaim, $trace));
            self::assertSame(5.0, AudioProduction::page($tenant, $user, $project, $task, 1)['records'][0]['asset']['duration_seconds']);
            $queued = $enqueue(8000 * 6);
            $failedLoad = static function () { throw new \RuntimeException('private signed storage URL and credentials'); };
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                self::assertTrue(AudioMeasurementWorker::process((int)$queued['id'], $ffprobe, $failedLoad));
                $row = Db::name('aigc_short_drama_asset')->where('id', $queued['id'])->find(); $meta = json_decode($row['meta_json'], true);
                self::assertSame($attempt < 3 ? 'pending' : 'failed', $row['status']);
                self::assertSame($attempt, $meta['probe_job']['attempts']); self::assertStringNotContainsString('credentials', $meta['probe_job']['error']);
                if ($attempt < 3) {
                    self::assertFalse(AudioMeasurementWorker::process((int)$queued['id'], $ffprobe, $load), 'Backoff must not immediately retry');
                    $meta['probe_job']['retry_at'] = 0; Db::name('aigc_short_drama_asset')->where('id', $queued['id'])->update(['meta_json' => json_encode($meta)]);
                }
            }
            self::assertSame(5.0, AudioProduction::page($tenant, $user, $project, $task, 1)['records'][0]['asset']['duration_seconds'], 'Failed replacement never destroys the last valid recording');
            $retry = $enqueue(8000 * 6); self::assertNotSame((int)$queued['id'], (int)$retry['id']);
            self::assertTrue(AudioMeasurementWorker::process((int)$retry['id'], $ffprobe, $load));
            self::assertSame(6.0, AudioProduction::page($tenant, $user, $project, $task, 1)['records'][0]['asset']['duration_seconds']);
            $queued = $enqueue(8000 * 7); file_put_contents($path, 'changed audio bytes');
            self::assertTrue(AudioMeasurementWorker::process((int)$queued['id'], $ffprobe, $load));
            self::assertSame('failed', Db::name('aigc_short_drama_asset')->where('id', $queued['id'])->value('status'));
            self::assertSame(6.0, AudioProduction::page($tenant, $user, $project, $task, 1)['records'][0]['asset']['duration_seconds'], 'Changed stored bytes never replace the valid recording');
            $queued = $enqueue(8000 * 7); Db::name('aigc_short_drama_asset')->where('id', $queued['id'])->update(['delete_time' => time()]);
            self::assertFalse(AudioMeasurementWorker::process((int)$queued['id'], $ffprobe, $load), 'Deleted pending assets are not resurrected');
            self::assertSame($before, Db::name('ai_consumption_log')->where($scope)->count(), 'Measurement and recovery are local, unbilled operations');
        } finally { Db::rollback(); if (is_file($path)) unlink($path); if (is_dir($path . '_receipts')) rmdir($path . '_receipts'); }
    }
}
