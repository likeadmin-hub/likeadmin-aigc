<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use app\common\service\app\aigc_llm\AigcLlmService;
use Exception;

final class AgentLlmGateway
{
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
            'max_tokens' => 4096,
            'source_app_code' => AigcCanvasService::APP_CODE,
            'source_type' => 'design_agent_' . $agentCode,
            'source_id' => (string)$context->messageId(),
        ];
        $agentConfig = AigcCanvasService::agentConfig($context->tenantId());
        if (!empty($agentConfig['router_available']) && !empty($agentConfig['router_model_code'])) {
            $base['model_code'] = (string)$agentConfig['router_model_code'];
        }

        if (!empty($tools)) {
            try {
                $result = AigcLlmService::generateText($context->tenantId(), $context->userId(), array_merge($base, [
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
            $result = AigcLlmService::generateText($context->tenantId(), $context->userId(), array_merge($base, [
                'content' => json_encode($portableTask, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_format' => ['type' => 'json_object'],
            ]));
        } catch (Exception) {
            try {
                $result = AigcLlmService::generateText($context->tenantId(), $context->userId(), array_merge($base, [
                    'content' => json_encode($portableTask, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]));
            } catch (Exception) {
                return ['content' => '', 'function_calls' => [], 'native_tools' => false];
            }
        }
        $content = (string)($result['content'] ?? '');
        return ['content' => $content, 'function_calls' => FunctionCallingRuntime::parseFunctionCalls($content), 'native_tools' => false];
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
}
