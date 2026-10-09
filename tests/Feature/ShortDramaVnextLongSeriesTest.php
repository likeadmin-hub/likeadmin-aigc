<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow;
use app\common\service\app\aigc_short_drama\vnext\StructuralRevision;
use app\common\service\app\aigc_short_drama\vnext\SeriesVisualState;
use app\common\service\app\aigc_short_drama\vnext\NarrativeHistoryIndex;
use app\common\service\app\aigc_short_drama\vnext\NarrativeLedger;
use app\common\service\app\aigc_short_drama\vnext\SeriesContext;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Local dependency/index simulation. No model requests or claim of 500 creative episodes. */
final class ShortDramaVnextLongSeriesTest extends TestCase
{
    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFiveHundredPublishedEpisodesKeepBoundedQueriesAndInvalidateDownstreamRevisions(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to local DB fixtures');
        (new \think\App())->initialize();
        $scope = ['tenant_id' => 2000000797, 'user_id' => 7]; $run = 'long_series_' . bin2hex(random_bytes(8));
        Db::startTrans();
        try {
            $parent = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => $run]);
            $dependencies = []; $ledger = []; $episodes = []; $current = []; $versions = [];
            $identities = [['ref'=>'c1','entity_id'=>'char1','kind'=>'character','name'=>'陈默','identity_facts'=>['age'=>40]],
                ['ref'=>'c2','entity_id'=>'char2','kind'=>'character','name'=>'林青','identity_facts'=>[]],['ref'=>'l1','entity_id'=>'room1','kind'=>'location','name'=>'书房','identity_facts'=>[]]];
            $receipts = []; $priorEntities = [];
            for ($number = 1; $number <= 500; $number++) {
                $project = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => $run . '_' . $number]);
                $text = '第' . $number . '集，陈默仍在书房。';
                $beliefText = '我以为陈默已经离开书房。😀';
                $entities = $priorEntities ? SeriesVisualState::inherit($identities, $priorEntities, $receipts, $scope) : $identities;
                $context = ['revision_id'=>$run.'_r'.$number, 'episode_id'=>'series:'.$parent.':episode:'.$number,
                    'entity_scope'=>$run, 'dependency_versions'=>['episodes'=>$dependencies], 'entities'=>$entities];
                if ($receipts) $context['identity_revision_receipts'] = $receipts;
                $document = ContentDocument::normalize(['title' => '第' . $number . '集', 'scenes' => [['location' => 'l1', 'units' => [
                    ['type' => 'action', 'characters' => ['c1'], 'text' => $text], ['type'=>'dialogue','speaker'=>'c2','text'=>$beliefText]]]]], $context);
                if ($number === 250) {
                    $revisionTask = $run . '_identity_revision';
                    $document = ApplicationWorkflow::revisionInput(['context'=>$context], ['document'=>$document], [], $document['revision_id'], $revisionTask,
                        $scope+['created_at'=>time(),'parent_task_id'=>$run.'_250','task_id'=>$revisionTask], ['entities'=>[['entity_id'=>'char1',
                            'before_hash'=>StructuralRevision::targets($document)['entities'][0]['before_hash'],'identity_facts'=>['age'=>28]]]])['context']['canonical_input'];
                    $receipts = $document['identity_revision_receipts'];
                }
                $priorEntities = $document['entities'];
                self::assertSame($number < 250 ? 40 : 28, $priorEntities[0]['identity_facts']['age']);
                self::assertSame($text, $document['scenes'][0]['units'][0]['text']);self::assertSame($beliefText, $document['scenes'][0]['units'][1]['text']);
                $changes = [['kind' => 'world', 'subject_id' => 'char1', 'predicate' => 'location',
                    'value' => '书房', 'observer_id' => null, 'certainty' => 'confirmed',
                    'evidence' => [['unit' => 'u1', 'start' => 0, 'end' => mb_strlen($text), 'quote' => $text]]],
                    ['kind'=>'world','subject_id'=>'char1','predicate'=>'alive','value'=>'平安在书房','observer_id'=>null,'certainty'=>'confirmed',
                        'evidence'=>[['unit'=>'u1','start'=>0,'end'=>mb_strlen($text),'quote'=>$text]]],
                    ['kind'=>'belief','subject_id'=>'char1','predicate'=>'location','value'=>'以为已离开书房','observer_id'=>'char2','certainty'=>'confirmed',
                        'evidence'=>[['unit'=>'u2','start'=>0,'end'=>mb_strlen($beliefText),'quote'=>$beliefText]]]];
                if ($number % 50 === 0) $changes[] = ['kind'=>'world','subject_id'=>'char1','predicate'=>'milestone_'.$number,'value'=>'第'.$number.'集仍在场',
                    'observer_id'=>null,'certainty'=>'confirmed','evidence'=>[['unit'=>'u1','start'=>0,'end'=>mb_strlen($text),'quote'=>$text]]];
                $ledger = NarrativeLedger::evaluate($document, $changes, $ledger);
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
            self::assertSame($ledger, $published['ledger']); self::assertCount(13, $published['ledger']['state']);
            self::assertSame(28, $published['entities'][0]['identity_facts']['age']);self::assertSame($receipts,$published['identity_revision_receipts']);
            $selected = NarrativeLedger::relevant($published['ledger'], ['char1','char2']);$packed = NarrativeHistoryIndex::pack($selected);
            self::assertCount(12,$selected['world_facts']);self::assertCount(1,$selected['character_beliefs']);
            self::assertSame(ContentDocument::hash($selected), ContentDocument::hash(isset($packed['evidence_index_version']) ? NarrativeHistoryIndex::expand($packed) : $packed));
            $milestones = array_column($selected['world_facts'],null,'predicate');
            self::assertSame('第50集，陈默仍在书房。',$milestones['milestone_50']['evidence'][0]['quote']);
            self::assertSame('书房',$milestones['location']['value']);self::assertSame('平安在书房',$milestones['alive']['value']);
            self::assertSame('以为已离开书房',$selected['character_beliefs'][0]['value']);self::assertSame('char2',$selected['character_beliefs'][0]['observer_id']);
            self::assertSame('第500集，陈默仍在书房。', $published['ledger']['delta'][0]['evidence'][0]['quote']);
            self::assertCount(0, array_filter(SeriesContext::dependencyStatus($episodes, $current), static fn($row) => $row['needs_review']));
            foreach ([[$scope['tenant_id'] + 1, $scope['user_id']], [$scope['tenant_id'], $scope['user_id'] + 1]] as [$tenant, $user]) {
                try { SeriesContext::publishedBefore($tenant, $user, $parent, 501); self::fail('Foreign series read'); }
                catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
            }
            $lastPlan = Db::name('aigc_short_drama_plan_version')->where('id', $versions[500])->value('plan_json');
            foreach (['identity', 'document_version', 'ledger_binding'] as $corruption) {
                $broken = json_decode($lastPlan, true);
                if ($corruption === 'identity') $broken['canonical_document']['entities'][0]['canonical_name'] = '错误人物';
                if ($corruption === 'document_version') $broken['canonical_document']['revision_id'] = 'wrong_revision';
                if ($corruption === 'ledger_binding') {
                    $broken['narrative_ledger']['document_hash'] = 'another_document';
                    $broken['narrative_ledger']['content_hash'] = NarrativeLedger::digest($broken['narrative_ledger']);
                    $brokenMeta = $current[$episodes[499]['production_project_id']];
                    $brokenMeta['canonical']['ledger_hash'] = $broken['narrative_ledger']['content_hash'];
                    Db::name('aigc_short_drama_plan_version')->where('id', $versions[500])->update(['continuity_json' => json_encode($brokenMeta)]);
                }
                Db::name('aigc_short_drama_plan_version')->where('id', $versions[500])->update(['plan_json' => json_encode($broken, JSON_UNESCAPED_UNICODE)]);
                try { SeriesContext::publishedBefore($scope['tenant_id'], $scope['user_id'], $parent, 501); self::fail('Mismatched historical content accepted'); }
                catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
                Db::name('aigc_short_drama_plan_version')->where('id', $versions[500])->update(['plan_json' => $lastPlan,
                    'continuity_json' => json_encode($current[$episodes[499]['production_project_id']])]);
            }
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
