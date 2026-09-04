<?php

namespace Tests\Feature;

use app\common\service\power\MarketTextModelRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketTextModelPayloadContractTest extends TestCase
{
    public function testSyncedCommaSeparatedProtocolsAreParsedBeforeSelectingPayloadContract(): void
    {
        $protocol = new ReflectionMethod(MarketTextModelRuntimeService::class, 'protocolForModel');
        $protocol->setAccessible(true);

        foreach (['gpt-5.5', 'gpt-5.4', 'gpt-5.3-codex'] as $modelCode) {
            self::assertSame('openai_responses', $protocol->invoke(null, [
                'model_code' => $modelCode,
                'protocols' => 'openai_responses',
            ]));
            self::assertSame('openai_responses', $protocol->invoke(null, [
                'model_code' => $modelCode,
                'protocols' => '',
            ]));
        }
        self::assertSame('openai_chat', $protocol->invoke(null, [
            'model_code' => 'DeepSeek-V4-Pro',
            'protocols' => 'openai_chat,openai_responses,openai_completions,anthropic_messages',
        ]));
        self::assertSame('openai_chat', $protocol->invoke(null, [
            'model_code' => 'Qwen3.5-27B-Claude-4.6-Opus-Reasoning-Distilled',
            'protocols' => 'openai_chat,openai_responses,openai_completions,anthropic_messages',
        ]));
        self::assertSame('anthropic_messages', $protocol->invoke(null, [
            'model_code' => 'claude-sonnet-5',
            'protocols' => 'anthropic_messages',
        ]));
    }

    public function testTextModelRequestPayloadMatchesSelectedProtocol(): void
    {
        $payload = new ReflectionMethod(MarketTextModelRuntimeService::class, 'requestPayload');
        $payload->setAccessible(true);
        $messages = [['role' => 'user', 'content' => 'hello']];

        $responsesPayload = $payload->invoke(null, 'openai_responses', [
            'model_code' => 'gpt-5.5',
        ], $messages, 'system text', 2048, ['temperature' => 0.7, 'stream_options' => ['include_usage' => true]]);
        self::assertSame('gpt-5.5', $responsesPayload['model']);
        self::assertSame('system text', $responsesPayload['instructions']);
        self::assertSame($messages, $responsesPayload['input']);
        self::assertSame(2048, $responsesPayload['max_output_tokens']);
        self::assertArrayNotHasKey('messages', $responsesPayload);
        self::assertArrayNotHasKey('max_tokens', $responsesPayload);
        self::assertArrayNotHasKey('stream_options', $responsesPayload);

        $anthropicPayload = $payload->invoke(null, 'anthropic_messages', [
            'model_code' => 'claude-sonnet-5',
        ], $messages, 'system text', 2048, ['temperature' => 0.7, 'top_p' => 0.9, 'presence_penalty' => 0.1]);
        self::assertSame('claude-sonnet-5', $anthropicPayload['model']);
        self::assertSame($messages, $anthropicPayload['messages']);
        self::assertSame('system text', $anthropicPayload['system']);
        self::assertSame(2048, $anthropicPayload['max_tokens']);
        self::assertSame(0.7, $anthropicPayload['temperature']);
        self::assertArrayNotHasKey('top_p', $anthropicPayload);
        self::assertArrayNotHasKey('presence_penalty', $anthropicPayload);

        $chatPayload = $payload->invoke(null, 'openai_chat', [
            'model_code' => 'DeepSeek-V4-Pro',
        ], $messages, 'system text', 2048, ['temperature' => 0.7, 'stream_options' => ['include_usage' => true]]);
        self::assertSame('DeepSeek-V4-Pro', $chatPayload['model']);
        self::assertSame([['role' => 'system', 'content' => 'system text'], $messages[0]], $chatPayload['messages']);
        self::assertSame(2048, $chatPayload['max_tokens']);
        self::assertSame(['include_usage' => true], $chatPayload['stream_options']);
    }

    public function testSchemaLessReasoningModelCanExplicitlyDisableThinking(): void
    {
        $generationParams = new ReflectionMethod(MarketTextModelRuntimeService::class, 'generationParams');
        $generationParams->setAccessible(true);

        self::assertSame([
            'enable_thinking' => false,
        ], $generationParams->invoke(null, [
            'model_code' => 'DeepSeek-V4-Pro',
            'default_params' => [],
            'params_schema' => [],
        ], [
            'enable_thinking' => false,
            'temperature' => 0.7,
        ]));
    }

    public function testUnsupportedSchemaLessThinkingParameterCanBeRemovedOnRetry(): void
    {
        $retry = new ReflectionMethod(MarketTextModelRuntimeService::class, 'compatibleGenerationParams');
        $retry->setAccessible(true);

        self::assertSame([
            'params' => [],
            'reason' => 'provider_unsupported_optional_parameter',
            'removed_params' => ['enable_thinking'],
            'temperature' => null,
        ], $retry->invoke(null, 'unknown parameter: enable_thinking', [
            'enable_thinking' => false,
        ]));
    }

    public function testProviderRejectedMarketContextIsRetriedWithoutLeakingBillingSelectors(): void
    {
        $retry = new ReflectionMethod(MarketTextModelRuntimeService::class, 'compatibleTransportOptions');
        $retry->setAccessible(true);

        self::assertSame([
            'params' => [],
            'transport' => ['include_market_context' => false, 'include_channel' => true],
            'reason' => 'provider_rejected_market_transport_context',
            'removed_params' => ['market_transport_context'],
            'temperature' => null,
        ], $retry->invoke(null, 'invalid_request_error: Unsupported parameter: input_pricing_sku_key', [
            'include_market_context' => true,
            'include_channel' => true,
        ]));
    }

    public function testTransportRetryDoesNotMaskAnUnrelatedInvalidRequest(): void
    {
        $retry = new ReflectionMethod(MarketTextModelRuntimeService::class, 'compatibleTransportOptions');
        $retry->setAccessible(true);

        self::assertNull($retry->invoke(null, 'invalid_request_error: Unsupported parameter: unsupported_feature', [
            'include_market_context' => true,
            'include_channel' => true,
        ]));
    }

    public function testTextModelErrorsKeepCodesAndParameterDetails(): void
    {
        $error = new ReflectionMethod(MarketTextModelRuntimeService::class, 'providerError');
        $error->setAccessible(true);

        self::assertSame('AccessDenied：上游暂时无法处理该模型，请稍后重试或切换模型', $error->invoke(null, [
            'error' => ['code' => 'AccessDenied', 'message' => 'model access is denied'],
        ]));
        self::assertSame('InvalidParameter：max_tokens must be less than 8192', $error->invoke(null, [
            'error' => ['code' => 'InvalidParameter', 'message' => 'max_tokens must be less than 8192'],
        ]));
        self::assertSame('上游暂时无法处理该模型，请稍后重试或切换模型', $error->invoke(null, [
            'error' => ['message' => 'provider is temporarily unavailable'],
        ]));
    }

    public function testClaudeCodeOnlyUpstreamErrorDoesNotExposePlaceholderCode(): void
    {
        $error = new ReflectionMethod(MarketTextModelRuntimeService::class, 'providerError');
        $error->setAccessible(true);

        self::assertSame('当前模型仅支持已授权的 Claude Code 渠道，不能通过当前算力市场转发。请切换模型，或由管理员配置官方 Claude API 渠道', $error->invoke(null, [
            'error' => [
                'code' => '<nil>',
                'message' => 'We have detected an anomaly in your client. Please use the standard Claude Code client for requests.',
            ],
        ]));
    }
}
