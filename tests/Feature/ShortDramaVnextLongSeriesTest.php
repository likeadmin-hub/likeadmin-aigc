<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\NarrativeLedger;
use app\common\service\app\aigc_short_drama\vnext\SeriesContext;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Local dependency/index simulation. No model requests or claim of 500 creative episodes. */
final class ShortDramaVnextLongSeriesTest extends TestCase
{
    public function testFiveHundredPublishedEpisodesKeepBoundedQueriesAndInvalidateDownstreamRevisions(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to local DB fixtures');
        (new \think\App())->initialize();
        $scope = ['tenant_id' => 2000000797, 'user_id' => 7]; $run = 'long_series_' . bin2hex(random_bytes(8));
        Db::startTrans();
        try {
            $parent = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => $run]);
            $dependencies = []; $ledger = []; $episodes = []; $current = []; $versions = [];
            for ($number = 1; $number <= 500; $number++) {
                $project = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => $run . '_' . $number]);
                $text = '第' . $number . '集，陈默仍在书房。';
                $document = ContentDocument::normalize(['title' => '第' . $number . '集', 'scenes' => [['location' => 'l1', 'units' => [
                    ['type' => 'action', 'characters' => ['c1'], 'text' => $text]]]]], [
                    'revision_id' => $run . '_r' . $number, 'episode_id' => 'series:' . $parent . ':episode:' . $number,
                    'entity_scope' => $run, 'dependency_versions' => ['episodes' => $dependencies], 'entities' => [
                        ['ref' => 'c1', 'entity_id' => 'char1', 'kind' => 'character', 'name' => '陈默'],
                        ['ref' => 'l1', 'entity_id' => 'room1', 'kind' => 'location', 'name' => '书房']]]);
                $ledger = NarrativeLedger::evaluate($document, [['kind' => 'world', 'subject_id' => 'char1', 'predicate' => 'location',
                    'value' => '书房', 'observer_id' => null, 'certainty' => 'confirmed',
                    'evidence' => [['unit' => 'u1', 'start' => 0, 'end' => mb_strlen($text), 'quote' => $text]]]], $ledger);
                $plan = ['canonical_document' => $document, 'narrative_ledger' => $ledger]; $meta = SeriesContext::metadata($plan);
                $version = Db::name('aigc_short_drama_plan_version')->insertGetId($scope + ['project_id' => $project,
                    'task_id' => $run . '_' . $number, 'continuity_json' => json_encode($meta), 'plan_json' => json_encode($plan, JSON_UNESCAPED_UNICODE)]);
                Db::name('aigc_short_drama_project')->where('id', $project)->update(['current_version_id' => $version]);
                $episode = $scope + ['project_id' => $parent, 'episode_number' => $number, 'production_project_id' => $project,
                    'status' => 'success', 'completed_once' => 1];
                Db::name('aigc_short_drama_episode_task')->insert($episode);
                $episodes[] = $episode; $current[$project] = $meta; $versions[$number] = $version;
                $dependencies[$number] = array_intersect_key($meta['canonical'], array_flip(['revision_id', 'document_hash', 'ledger_hash']));
            }
            $queries = Db::getQueryTimes();
            $published = SeriesContext::publishedBefore($scope['tenant_id'], $scope['user_id'], $parent, 501);
            self::assertLessThanOrEqual(8, Db::getQueryTimes() - $queries, 'Dependency reads must not introduce N+1 per-episode queries');
            self::assertSame($dependencies, $published['dependencies']); self::assertCount(500, $published['dependencies']);
            self::assertSame($ledger, $published['ledger']); self::assertCount(1, $published['ledger']['state']);
            self::assertSame('第500集，陈默仍在书房。', $published['ledger']['delta'][0]['evidence'][0]['quote']);
            self::assertCount(0, array_filter(SeriesContext::dependencyStatus($episodes, $current), static fn($row) => $row['needs_review']));
            foreach ([[$scope['tenant_id'] + 1, $scope['user_id']], [$scope['tenant_id'], $scope['user_id'] + 1]] as [$tenant, $user]) {
                try { SeriesContext::publishedBefore($tenant, $user, $parent, 501); self::fail('Foreign series read'); }
                catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
            }
            $lastPlan = Db::name('aigc_short_drama_plan_version')->where('id', $versions[500])->value('plan_json');
            $middleProject = $episodes[249]['production_project_id'];
            $current[$middleProject]['canonical']['revision_id'] .= '_edited';
            Db::name('aigc_short_drama_plan_version')->where('id', $versions[250])->update(['continuity_json' => json_encode($current[$middleProject])]);
            $stale = SeriesContext::dependencyStatus($episodes, $current);
            self::assertCount(250, array_filter($stale, static fn($row) => $row['needs_review']));
            self::assertFalse($stale[249]['needs_review']); self::assertTrue($stale[250]['needs_review']); self::assertTrue($stale[499]['needs_review']);
            try { SeriesContext::publishedBefore($scope['tenant_id'], $scope['user_id'], $parent, 501); self::fail('Stale dependency chain accepted'); }
            catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
            self::assertSame($lastPlan, Db::name('aigc_short_drama_plan_version')->where('id', $versions[500])->value('plan_json'), 'Existing content must remain readable');
        } finally { Db::rollback(); }
    }
}
