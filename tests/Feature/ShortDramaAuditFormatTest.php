<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStructuredResponse as Response;
use app\common\service\app\aigc_short_drama\ShortDramaContinuityPatch as Patch;
use app\common\service\app\aigc_short_drama\ShortDramaContinuity as Continuity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ShortDramaAuditFormatTest extends TestCase
{
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
}
