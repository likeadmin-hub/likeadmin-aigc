<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use Exception;

final class AgentLlmGateway
{
    private const MAX_MODEL_STREAM_TIMEOUT_SECONDS = 180;

    public static function call(
        AgentExecutionContext $context,
        string $agentCode,
        string $systemPrompt,
        array $task,
        array $tools = []
    ): array {
        $base = [
            'content' => json_encode($task, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'system_prompt' => $systemPrompt,
            'max_tokens' => max(256, min(4096, (int)($task['max_tokens'] ?? 1200))),
            'enable_thinking' => !empty($task['enable_thinking']),
            'source_app_code' => AigcCanvasService::APP_CODE,
            'source_type' => 'design_agent_' . $agentCode,
            'source_id' => (string)$context->messageId(),
        ];
        $agentConfig = AigcCanvasService::agentConfig($context->tenantId());
        $base['request_timeout_seconds'] = max(10, min(
            self::MAX_MODEL_STREAM_TIMEOUT_SECONDS,
            (int)($task['request_timeout_seconds'] ?? $agentConfig['agent_loop_timeout_seconds'] ?? 180)
        ));
        if (!empty($agentConfig['router_available']) && !empty($agentConfig['router_model_code'])) {
            $base['model_code'] = (string)$agentConfig['router_model_code'];
        }

        if (!empty($tools)) {
            try {
                $result = AigcCanvasService::llmText($context->tenantId(), $context->userId(), array_merge($base, [
                    'tools' => array_values($tools),
                    'tool_choice' => 'auto',
                ]));
                $calls = self::normalizeNativeToolCalls((array)($result['tool_calls'] ?? []));
                if (!empty($calls)) {
                    return ['content' => (string)($result['content'] ?? ''), 'function_calls' => $calls, 'native_tools' => true];
                }
                $fallbackCalls = FunctionCallingRuntime::parseFunctionCalls((string)($result['content'] ?? ''));
                if (!empty($fallbackCalls)) {
                    return ['content' => (string)($result['content'] ?? ''), 'function_calls' => $fallbackCalls, 'native_tools' => false];
                }
            } catch (Exception) {
                // Retry once with the portable JSON tool-call contract below.
            }
        }

        $portableTask = array_merge($task, [
            'available_tools' => $tools,
            'output_contract' => [
                'summary' => 'string',
                'tool_calls' => [['name' => 'tool_name', 'arguments' => new \stdClass()]],
            ],
        ]);
        try {
            $result = AigcCanvasService::llmText($context->tenantId(), $context->userId(), array_merge($base, [
                'content' => json_encode($portableTask, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_format' => ['type' => 'json_object'],
            ]));
        } catch (Exception) {
            try {
                $result = AigcCanvasService::llmText($context->tenantId(), $context->userId(), array_merge($base, [
                    'content' => json_encode($portableTask, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]));
            } catch (Exception) {
                return ['content' => '', 'function_calls' => [], 'native_tools' => false];
            }
        }
        $content = (string)($result['content'] ?? '');
        return ['content' => $content, 'function_calls' => FunctionCallingRuntime::parseFunctionCalls($content), 'native_tools' => false];
    }

    public static function streamCall(
        AgentExecutionContext $context,
        string $agentCode,
        string $systemPrompt,
        array $task,
        array $tools = [],
        ?callable $onEvent = null
    ): array {
        $portableTask = array_merge($task, [
            'available_tools' => array_values($tools),
            'output_contract' => [
                'summary' => 'plain user-facing response or a short internal tool rationale',
                'tool_calls' => [['name' => 'tool_name', 'arguments' => new \stdClass()]],
            ],
        ]);
        $base = [
            'content' => json_encode($portableTask, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'system_prompt' => $systemPrompt,
            'max_tokens' => max(256, min(4096, (int)($task['max_tokens'] ?? 1200))),
            'enable_thinking' => !empty($task['enable_thinking']),
            'source_app_code' => AigcCanvasService::APP_CODE,
            'source_type' => 'design_agent_' . $agentCode,
            'source_id' => (string)$context->messageId(),
        ];
        $agentConfig = AigcCanvasService::agentConfig($context->tenantId());
        if (!empty($agentConfig['router_available']) && !empty($agentConfig['router_model_code'])) {
            $base['model_code'] = (string)$agentConfig['router_model_code'];
        }
        $base['request_timeout_seconds'] = max(10, min(
            self::MAX_MODEL_STREAM_TIMEOUT_SECONDS,
            (int)($task['request_timeout_seconds'] ?? $agentConfig['agent_loop_timeout_seconds'] ?? 180)
        ));
        $stream = !empty($agentConfig['agent_stream_enabled']);
        $callback = $stream && is_callable($onEvent)
            ? static function (string $event, array $payload) use ($onEvent): void {
                if ($event === 'delta' && trim((string)($payload['delta'] ?? '')) !== '') {
                    $onEvent('delta', ['delta' => (string)$payload['delta']]);
                    return;
                }
                if ($event === 'heartbeat') {
                    $onEvent('heartbeat', ['elapsed_ms' => (int)($payload['elapsed_ms'] ?? 0)]);
                }
            }
            : null;
        try {
            $result = AigcCanvasService::llmText($context->tenantId(), $context->userId(), array_merge($base, [
                'tools' => array_values($tools),
                'tool_choice' => 'auto',
            ]), $callback);
            $content = (string)($result['content'] ?? '');
            $calls = self::normalizeNativeToolCalls((array)($result['tool_calls'] ?? []));
            if (empty($calls)) {
                $calls = FunctionCallingRuntime::parseFunctionCalls($content);
            }
            if (empty($calls)) {
                $structured = self::parseJson($content);
                if (!empty($structured['summary']) || !empty($structured['reply'])) {
                    $content = (string)($structured['summary'] ?? $structured['reply']);
                }
            }
            return [
                'content' => $content,
                'function_calls' => $calls,
                'native_tools' => !empty($calls),
                'stream_mode' => (string)($result['stream_mode'] ?? ($stream ? 'unknown' : 'disabled')),
                'stream_delta_count' => (int)($result['stream_delta_count'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return [
                'content' => '',
                'function_calls' => [],
                'native_tools' => false,
                'error' => self::safeError($e->getMessage()),
            ];
        }
    }

    public static function json(
        AgentExecutionContext $context,
        string $agentCode,
        string $systemPrompt,
        array $task
    ): array {
        try {
            $result = self::call($context, $agentCode, $systemPrompt, $task);
            return self::parseJson((string)($result['content'] ?? ''));
        } catch (Exception) {
            return [];
        }
    }

    private static function normalizeNativeToolCalls(array $calls): array
    {
        $result = [];
        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $name = trim((string)($function['name'] ?? $call['name'] ?? ''));
            $arguments = $function['arguments'] ?? $call['arguments'] ?? [];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            }
            if ($name !== '') {
                $result[] = ['name' => $name, 'arguments' => is_array($arguments) ? $arguments : []];
            }
        }
        return $result;
    }

    private static function parseJson(string $content): array
    {
        $text = trim($content);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $json = json_decode($text, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $text, $match)) {
            $json = json_decode($match[0], true);
        }
        return is_array($json) ? $json : [];
    }

    private static function safeError(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        $message = preg_replace('/(bearer\s+)[^\s]+/i', '$1***', $message) ?? $message;
        $message = preg_replace('/(api[_-]?key\s*[=:]\s*)[^\s,;]+/i', '$1***', $message) ?? $message;
        return mb_substr($message !== '' ? $message : '文本模型调用失败，请稍后重试', 0, 500, 'UTF-8');
    }
}
