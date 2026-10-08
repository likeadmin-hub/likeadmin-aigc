<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStructuredResponse as Response;
use app\common\service\app\aigc_short_drama\ShortDramaContinuityPatch as Patch;
use app\common\service\app\aigc_short_drama\ShortDramaContinuity as Continuity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ShortDramaAuditFormatTest extends TestCase
{
    public function testInitialAuditReferencesUseCanonicalTextAndStillRejectUnsupportedMeaning(): void
    {
        $plan = ['storyboard'=>[['shot_id'=>'s1','visual_description'=>'甲合上手册，走到工作台左侧，将手册放进抽屉。','dialogue'=>'']]];
        $draft = ['summary'=>'存放手册','changes'=>[['entity_id'=>'world','field'=>'manual_location','before'=>null,'after'=>'左侧抽屉',
            'evidence_refs'=>[['shot_id'=>'s1','field'=>'visual_description']], 'quote'=>'甲...抽屉']], 'hooks'=>[], 'warnings'=>[]];
        $calls = 0;
        $ledger = Continuity::review($plan, [], 1, static function() use($draft,&$calls) { $calls++; return $draft; },
            static function($review) use($plan) {
                self::assertSame($plan['storyboard'][0]['visual_description'], $review['changes'][0]['evidence'][0]['quote']);
                return Patch::verifiedReview($review, ['checks'=>[['collection'=>'changes','index'=>0,'supported'=>true,'reason'=>'明确放入抽屉']]], []);
            });
        self::assertSame(1, $calls);
        self::assertSame('左侧抽屉', $ledger['state']['world:manual_location']);
        $draft['changes'][0]['after'] = '手册被销毁';
        $rejected = Continuity::review($plan, [], 1, static fn()=> $draft,
            static fn($review)=>Patch::verifiedReview($review, ['checks'=>[['collection'=>'changes','index'=>0,'supported'=>false,'failure_kind'=>'uncertain','reason'=>'原文只有存放']]], []));
        self::assertSame([], $rejected['state']);
        self::assertSame('pending_review', $rejected['audit_status']);
    }
    public function testInitialAuditReferenceValidationRejectsMissingEmptyAndNonNarrativeSources(): void
    {
        $plan = ['storyboard'=>[['shot_id'=>'s1','visual_description'=>'甲站着','dialogue'=>'','image_prompt'=>'图片提示']]];
        foreach ([['shot_id'=>'missing','field'=>'visual_description'], ['shot_id'=>'s1','field'=>'dialogue'], ['shot_id'=>'s1','field'=>'image_prompt']] as $ref) {
            $draft = ['summary'=>'甲站着','changes'=>[['entity_id'=>'world','field'=>'location','before'=>null,'after'=>'房间','evidence_refs'=>[$ref]]], 'hooks'=>[], 'warnings'=>[]];
            try { Continuity::assertAuditShape($draft, $plan); self::fail('Invalid source must be rejected before verification'); }
            catch (RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        }
    }
    public function testMalformedCompleteJsonIsRecoveredWithDistinctRequestAndRealVerdict(): void
    {
        $inputs = [];
        $result = Response::audit(['content'=>'原文及固定检查项'], function ($input) use (&$inputs) {
            $inputs[] = $input;
            return Response::decode(['finish_reason'=>'stop', 'content'=>count($inputs) === 1
                ? '{"checks":[{"reason":"镜头说"天线断折""}]}'
                : '{"checks":[{"collection":"hooks","index":0,"supported":false,"reason":"无证据"}]}']);
        });
        self::assertCount(2, $inputs);
        self::assertNotSame($inputs[0], $inputs[1]);
        self::assertFalse($result['checks'][0]['supported']);
    }
    public function testMalformedCheckCoverageIsRevalidatedAndRetriesAreBounded(): void
    {
        $calls = 0;
        try {
            Response::audit(['content'=>'同一检查项'], function () use (&$calls) {
                $calls++;
                Patch::assertMeaning(['changes'=>[['after'=>'真实事实']], 'hooks'=>[]], ['checks'=>[]]);
                return [];
            });
            self::fail('Missing check cannot pass');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
            self::assertSame(3, $calls);
        }
    }
    public function testDoesNotReplayTransportCapacityOrSemanticFailure(): void
    {
        foreach ([409, 413, 460, 502] as $code) {
            $calls = 0;
            try {
                Response::audit(['content'=>'request'], function () use ($code, &$calls) {
                    $calls++; throw new RuntimeException('连续性语义核对失败', $code);
                });
                self::fail('Failure must propagate');
            } catch (RuntimeException $e) {
                self::assertSame($code, $e->getCode()); self::assertSame(1, $calls);
            }
        }
    }
    public function testInvalidFieldIdentifierIsRecoveredBeforeSemanticReview(): void
    {
        $calls = 0;
        $result = Response::audit(['content'=>'审校结构'], function ($input) use (&$calls) {
            $calls++;
            $reply = ['summary'=>'清洁','changes'=>[['entity_id'=>'world','field'=>$calls === 1 ? 'state:cleaned_area' : 'cleaned_area','before'=>null,'after'=>'已清洁']], 'hooks'=>[], 'warnings'=>[]];
            Continuity::assertAuditShape($reply, []);
            return $reply;
        });
        self::assertSame(2, $calls); self::assertSame('cleaned_area', $result['changes'][0]['field']);
    }
    public function testAuditorMistakeIsRejectedWithoutClosingHookOrApprovingUnsupportedFact(): void
    {
        $context = ['continuity'=>['state'=>['world:owner'=>'父亲'],'open_hooks'=>['promise'=>'修好收音机']]];
        $plan = ['storyboard'=>[['shot_id'=>'s1','visual_description'=>'沈禾看着收音机','dialogue'=>'']]];
        $draft = ['summary'=>'沈禾看收音机','changes'=>[['entity_id'=>'world','field'=>'owner','before'=>'父亲','after'=>'沈禾','shot_id'=>'s1','quote'=>'沈禾看着收音机']],
            'hooks'=>[['id'=>'promise','description'=>'修好收音机','status'=>'resolved','shot_id'=>'s1','quote'=>'沈禾看着收音机']], 'warnings'=>[]];
        $verdict = ['checks'=>[
            ['collection'=>'changes','index'=>0,'supported'=>false,'failure_kind'=>'audit_claim','reason'=>'审校员把看着误记为所有权转移，正文无所有权变化'],
            ['collection'=>'hooks','index'=>0,'supported'=>false,'failure_kind'=>'audit_claim','reason'=>'正文未声称修好，审校员误记兑现，应保留伏笔']]];
        $ledger = Continuity::review($plan, $context, 2, static fn()=> $draft,
            static fn($review)=>Patch::verifiedReview($review, $verdict, $context));
        self::assertNotSame('pending_review', $ledger['audit_status'] ?? 'verified');
        self::assertSame($context['continuity']['state'], $ledger['state']);
        self::assertSame($context['continuity']['open_hooks'], $ledger['open_hooks']);
        self::assertCount(2, $ledger['unverified_claims']);
        foreach (['script_conflict','uncertain',null] as $kind) {
            $checks = $verdict;
            foreach ($checks['checks'] as &$check) { if ($kind === null) unset($check['failure_kind']); else $check['failure_kind'] = $kind; }
            unset($check);
            $blocked = Continuity::review($plan, $context, 2, static fn()=> $draft,
                static fn($review)=>Patch::verifiedReview($review, $checks, $context));
            self::assertSame('pending_review', $blocked['audit_status']);
        }
    }
    public function testExistingHookContinuationNeedsNoInventedEvidenceOrNewOperation(): void
    {
        $context = ['continuity'=>['open_hooks'=>['note'=>'父亲留下的小字内容尚未揭示']]];
        $plan = ['storyboard'=>[['shot_id'=>'s1','visual_description'=>'甲清洁收音机','dialogue'=>'']]];
        $draft = ['summary'=>'清洁收音机','changes'=>[], 'hooks'=>[['id'=>'note','description'=>'本段未涉及小字，伏笔延续','status'=>'open','shot_id'=>'','quote'=>'']], 'warnings'=>[]];
        $calls = 0;
        $ledger = Continuity::review($plan, $context, 2, static function() use($draft,&$calls) { $calls++; return $draft; },
            static function($review) use ($context) { self::assertSame([], $review['hooks']); return Patch::verifiedReview($review, ['checks'=>[]], $context); });
        self::assertSame(1, $calls);
        self::assertSame($context['continuity']['open_hooks'], $ledger['open_hooks']);
        self::assertNotSame('pending_review', $ledger['audit_status'] ?? 'verified');
        self::assertSame([], $ledger['unverified_claims']);
        self::assertCount(1, $ledger['audit_corrections']);
    }
    public function testFirstRegistrationUsesLedgerAbsenceInsteadOfProviderGuess(): void
    {
        foreach (['null（未出现）','未登记','模型猜测的旧状态'] as $before) {
            $plan = ['subjects'=>[['id'=>'p1']], 'storyboard'=>[['shot_id'=>'s1','visual_description'=>'甲站在门口','dialogue'=>'']]];
            $draft = ['summary'=>'甲出现','changes'=>[['entity_id'=>'p1','field'=>'location','before'=>$before,'after'=>'门口','shot_id'=>'s1','quote'=>'甲站在门口']], 'hooks'=>[], 'warnings'=>[]];
            $ledger = Continuity::review($plan, [], 1, static fn()=> $draft, static fn($review)=>Patch::verifiedReview($review,
                ['checks'=>[['collection'=>'changes','index'=>0,'supported'=>true,'reason'=>'镜头展示位置']]], []));
            self::assertSame('门口', $ledger['state']['p1:location']);
            self::assertSame($before, $ledger['audit_corrections'][0]['original']['before']);
            self::assertNull($ledger['audit_corrections'][0]['corrected']);
        }
    }
}
