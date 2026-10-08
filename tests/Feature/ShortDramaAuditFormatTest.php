<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStructuredResponse as Response;
use app\common\service\app\aigc_short_drama\ShortDramaContinuityPatch as Patch;
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
}
