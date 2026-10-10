<?php
namespace Tests\Feature;

use app\common\service\ai\UpstreamErrorMessageService as Errors;
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
use PHPUnit\Framework\TestCase;

class MarketTextModelFallbackMetadataTest extends TestCase
{
    public function testRoutingDecisionSurvivesNormalizationAndSseParsing(): void
    {
        $parse = new \ReflectionMethod(Runtime::class, 'parseProviderPayload');
        $parse->setAccessible(true);
        $exception = new \ReflectionMethod(Runtime::class, 'providerException');
        $exception->setAccessible(true);
        foreach (['model unavailable', 'upstream temporarily unavailable'] as $raw) {
            $event = $parse->invoke(null, ['error' => ['message' => $raw]], 'error', true);
            self::assertSame(Errors::TEMPORARILY_UNAVAILABLE, $event['message']);
            self::assertTrue($event['model_fallback_allowed']);
            self::assertTrue($exception->invoke(null, $event['message'], true)->allowsModelFallback);
            foreach ([['content' => 'partial'], ['reasoning' => 'thinking'], ['tool_calls' => [['id'=>'x']]], ['delta_count'=>1]] as $state) {
                self::assertFalse($exception->invoke(null, $event['message'], true, $state)->allowsModelFallback);
            }
        }
    }

    public function testBlockedFailuresRemainBlockedDespiteIdenticalPublicMessage(): void
    {
        foreach (['quota exceeded', 'insufficient balance', 'content moderation failed', 'content_policy_violation',
            'invalid_api_key', 'permission denied', 'provider timeout', '任务已取消', 'invalid parameter'] as $raw) {
            self::assertFalse(Errors::allowsModelFallback(['error'=>['message'=>$raw]]), $raw);
        }
        self::assertFalse(Errors::allowsModelFallback(['error'=>['code'=>'insufficient_quota','message'=>'unavailable']]));
        self::assertTrue(Errors::allowsModelFallback(['error'=>['code'=>'model_not_found','message'=>'unavailable']]));
    }
}
