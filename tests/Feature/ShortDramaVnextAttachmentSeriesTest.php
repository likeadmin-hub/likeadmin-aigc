<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService as Episodes;
use app\common\service\app\aigc_short_drama\ShortDramaAttachmentSource as Source;
use app\common\service\app\aigc_short_drama\vnext\ReceiptBuffer;
use app\common\service\power\MarketTextModelRuntimeService as Provider;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaVnextAttachmentSeriesTest extends TestCase
{
    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFrozenAttachmentRunsTwoEpisodesWithoutCreativeCallsAndKeepsSourceToProductionReferences(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional DB fixture');
        require __DIR__ . '/../fixtures/short_drama_vnext_fake_provider.php';
        require __DIR__ . '/../fixtures/short_drama_vnext_fake_queue.php';
        (new \think\App())->initialize(); $scope = ['tenant_id' => 2000000719, 'user_id' => 7];
        $task = 'attachment_series_' . bin2hex(random_bytes(8)); $project = 0;
        Db::startTrans();
        try {
            $project = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => '旧信', 'prompt' => '按原文制作', 'multi_episode' => 1, 'episode_count' => 2, 'last_task_id' => $task]);
            $story = ['title' => '旧信', 'type_judgement' => '悬疑', 'core_theme' => '真相', 'story_outline' => '陈默在书房寻找线索。',
                'subjects' => [['id' => 'hero', 'name' => '陈默', 'description' => '调查员']], 'locations' => [['id' => 'room', 'name' => '书房', 'description' => '旧书房']]];
            $plan = $story + ['episodes' => [
                ['episode_number' => 1, 'title' => '书信', 'story_outline' => '陈默发现旧信。', 'conflict_point' => '署名有疑点', 'ending_hook' => '发现印记'],
                ['episode_number' => 2, 'title' => '印记', 'story_outline' => '陈默辨认印记。', 'conflict_point' => '印记来源不明', 'ending_hook' => '找到线索']]];
            $path = tempnam(sys_get_temp_dir(), 'attachment_series_');
            file_put_contents($path, "第1集\n场景：书房\n陈默：我在书房发现旧信😀，署名有疑点，信封上还有印记。\n第2集\n场景：书房\n陈默：我在书房辨认印记，终于找到线索。\n");
            try { $source = Source::capture($path, 'txt', $scope['tenant_id'], 7, $task); }
            finally { unlink($path); }
            $plan['episodes'] = Source::bind($source, $plan['episodes']);
            $request = ['prompt' => '按原文制作', 'script_source' => 'upload', '_attachment_source' => $source, 'workflow_variant' => 'story_outline_v2',
                'multi_episode' => true, 'episode_count' => 2, 'multi_episode_stage' => 'episodes', '_generation_version' => 3,
                'confirmed_story_snapshot' => $story, 'confirmed_story_version' => 0, 'model_selections' => ['script_plan' => ['id' => 'frozen_model']]];
            Db::name('aigc_short_drama_script_task')->insert($scope + ['project_id' => $project, 'task_id' => $task, 'status' => 'success',
                'prompt' => $request['prompt'], 'request_json' => json_encode($request), 'result_json' => json_encode($plan)]);
            \think\facade\Config::set(['generation_version' => 3, 'vnext' => ['enabled' => true, 'series_enabled' => true, 'attachment_enabled' => true,
                'tenant_ids' => [$scope['tenant_id']], 'models' => ['frozen_model' => ['stage_timeout_seconds' => 100, 'lease_seconds' => 150, 'deadline_seconds' => 1800]]]], 'short_drama');
            $rows = Episodes::start($scope['tenant_id'], 7, ['project_id' => $project, 'task_id' => $task, 'draft_version' => 0])['lists'];
            self::assertCount(2, $rows); self::assertCount(0, Provider::$requests);
            $series = json_decode(Db::name('aigc_short_drama_episode_task')->where('id', $rows[0]['id'])->value('series_json'), true);
            self::assertSame($source, $series['request']['_vnext_series']['attachment_source']);
            \think\facade\Config::set(['generation_version' => 3, 'vnext' => ['enabled' => false, 'series_enabled' => false, 'attachment_enabled' => false]], 'short_drama');
            $documents = [];
            foreach ([0, 1] as $index) {
                self::assertSame(1, Episodes::tick($scope['tenant_id'], $project));
                $list = Episodes::lists($scope['tenant_id'], 7, $project)['lists'];
                self::assertSame('success', $list[$index]['status'], json_encode($list, JSON_UNESCAPED_UNICODE));
                self::assertCount(($index + 1) * 2, Provider::$requests);
                $detail = Service::scriptPlanDetail($scope['tenant_id'], 7, $list[$index]['task_id']);
                $document = $detail['result']['canonical_document']; $documents[] = $document;
                $unit = $document['scenes'][0]['units'][0]; $origin = end($document['transforms'])['origin'];
                self::assertSame($source['file_sha256'], $origin['file_sha256']);
                self::assertSame($unit['text'], $unit['source_links'][0]['quote']);
                self::assertStringNotContainsString('陈默：', $unit['text']);
                self::assertSame($plan['episodes'][$index]['attachment_trace']['start'], $origin['start']);
                self::assertSame($unit['unit_id'], $detail['result']['shot_plan']['audio_segments'][0]['unit_id']);
                self::assertSame('ready', $detail['generation_snapshot']['document_status']);
                $history = \app\common\service\app\aigc_short_drama\vnext\NarrativeLedger::relevant($detail['result']['narrative_ledger'], array_column($document['entities'], 'entity_id'));
                self::assertSame([], $history['world_facts']);
                self::assertNotEmpty($history['character_beliefs']);
                $stored = Db::name('aigc_short_drama_script_task')->where($scope)->where('task_id', $list[$index]['task_id'])->value('result_json');
                self::assertSame($detail['result'], json_decode($stored, true));
                $unsigned = $document; unset($unsigned['content_hash'], $unsigned['status'], $unsigned['transforms']);
                self::assertSame($document['content_hash'], \app\common\service\app\aigc_short_drama\vnext\ContentDocument::hash($unsigned));
                if ($index === 0) self::assertStringContainsString('\\ud83d\\ude00', strtolower($stored));
            }
            self::assertStringContainsString('😀', $documents[0]['scenes'][0]['units'][0]['text']);
            self::assertSame($documents[0]['scenes'][0]['units'][0]['speaker_id'], $documents[1]['scenes'][0]['units'][0]['speaker_id']);
            foreach (Provider::$requests as $request) {
                $input = json_decode($request['content'], true); self::assertArrayHasKey('document', $input);
                self::assertArrayNotHasKey('attachment_import', $input); self::assertArrayNotHasKey('transforms', $input['document']);
            }
        } finally {
            $tasks = Db::name('aigc_short_drama_episode_task')->where($scope + ['project_id' => $project])->column('task_id');
            $directory = runtime_path() . 'short_drama_vnext_receipts'; $buffer = new ReceiptBuffer($directory); $paths = [];
            foreach (Db::name('aigc_short_drama_vnext_stage')->where($scope)->whereIn('task_id', $tasks)->select()->toArray() as $stage) {
                $key = $buffer->key($scope + ['task_id' => $stage['task_id'], 'stage_key' => $stage['stage_key']], $stage['input_hash']);
                $paths[] = $directory . '/' . $key . '.json'; $paths[] = $directory . '/' . hash('sha256', 'provider-result:' . $key) . '.json';
            }
            Db::rollback(); foreach ($paths as $path) if (is_file($path)) unlink($path);
        }
    }
}
