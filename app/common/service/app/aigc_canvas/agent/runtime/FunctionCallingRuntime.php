<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use Exception;

final class FunctionCallingRuntime
{
    private const MAX_TOOL_CALLS = 24;
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(array $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->code()] = $tool;
        }
    }

    public function schemas(): array
    {
        return array_values(array_map(static fn(ToolInterface $tool): array => $tool->schema(), $this->tools));
    }

    public function executeCalls(AgentExecutionContext $context, array $calls): array
    {
        if (count($calls) > self::MAX_TOOL_CALLS) {
            throw new Exception('Agent tool call limit exceeded');
        }
        $results = [
            'canvas_actions' => [],
            'tool_calls' => [],
            'workspace_actions' => [],
            'assets' => [],
        ];
        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }
            $name = (string)($call['name'] ?? $call['tool'] ?? '');
            $arguments = $call['arguments'] ?? $call['params'] ?? [];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            }
            if (!isset($this->tools[$name])) {
                throw new Exception('Unknown design tool: ' . $name);
            }
            $arguments = is_array($arguments) ? $arguments : [];
            $this->validateArguments($this->tools[$name]->schema(), $arguments, $name);
            $result = $this->tools[$name]->execute($context, $arguments);
            $results['canvas_actions'] = array_merge($results['canvas_actions'], (array)($result['canvas_actions'] ?? []));
            $results['tool_calls'] = array_merge($results['tool_calls'], (array)($result['tool_calls'] ?? []));
            $results['workspace_actions'] = array_merge($results['workspace_actions'], (array)($result['workspace_actions'] ?? []));
            $results['assets'] = array_merge($results['assets'], (array)($result['assets'] ?? []));
        }
        return $results;
    }

    private function validateArguments(array $schema, array $arguments, string $toolName): void
    {
        $parameters = is_array($schema['function']['parameters'] ?? null) ? $schema['function']['parameters'] : [];
        foreach ((array)($parameters['required'] ?? []) as $required) {
            if (!array_key_exists((string)$required, $arguments)) {
                throw new Exception("Missing {$toolName} argument: {$required}");
            }
        }
        $properties = is_array($parameters['properties'] ?? null) ? $parameters['properties'] : [];
        foreach ($arguments as $key => $value) {
            if (!isset($properties[$key])) {
                throw new Exception("Unsupported {$toolName} argument: {$key}");
            }
            $type = (string)($properties[$key]['type'] ?? '');
            $valid = match ($type) {
                'string' => is_string($value),
                'number', 'integer' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
                'object' => is_array($value),
                'array' => is_array($value),
                'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true),
                default => true,
            };
            if (!$valid) {
                throw new Exception("Invalid {$toolName} argument type: {$key}");
            }
        }
    }

    public static function parseFunctionCalls(string $content): array
    {
        $text = trim($content);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $json = json_decode($text, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $text, $match)) {
            $json = json_decode($match[0], true);
        }
        if (!is_array($json)) {
            return [];
        }
        if (isset($json['tool_calls']) && is_array($json['tool_calls'])) {
            return $json['tool_calls'];
        }
        if (isset($json['calls']) && is_array($json['calls'])) {
            return $json['calls'];
        }
        if (isset($json['name'])) {
            return [$json];
        }
        return [];
    }
}
