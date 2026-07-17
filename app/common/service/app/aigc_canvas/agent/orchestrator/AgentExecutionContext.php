<?php

namespace app\common\service\app\aigc_canvas\agent\orchestrator;

final class AgentExecutionContext
{
    private array $state;
    private array $agentResults = [];
    private array $canvasActions = [];
    private array $toolCalls = [];
    private array $workspaceActions = [];
    private array $assets = [];
    private array $trace = [];

    public function __construct(array $state)
    {
        $this->state = $state;
    }

    public static function from(array $state): self
    {
        return new self($state);
    }

    public function tenantId(): int
    {
        return (int)($this->state['tenant_id'] ?? 0);
    }

    public function userId(): int
    {
        return (int)($this->state['user_id'] ?? 0);
    }

    public function projectId(): int
    {
        return (int)($this->state['project_id'] ?? 0);
    }

    public function threadId(): int
    {
        return (int)($this->state['thread_id'] ?? 0);
    }

    public function messageId(): int
    {
        return (int)($this->state['message_id'] ?? 0);
    }

    public function request(): string
    {
        return trim((string)($this->state['user_request'] ?? ''));
    }

    public function context(): array
    {
        return is_array($this->state['canvas_context'] ?? null) ? $this->state['canvas_context'] : [];
    }

    public function memory(): array
    {
        return is_array($this->state['memory'] ?? null) ? $this->state['memory'] : [];
    }

    public function emit(): ?callable
    {
        return is_callable($this->state['emit'] ?? null) ? $this->state['emit'] : null;
    }

    public function route(): array
    {
        return is_array($this->state['route'] ?? null) ? $this->state['route'] : [];
    }

    public function toolOptions(string $toolCode): array
    {
        $route = $this->route();
        if (is_array($route['tool_options'][$toolCode] ?? null)) {
            return $route['tool_options'][$toolCode];
        }
        $config = $route['agent_media_config'] ?? [];
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }
        if ($toolCode === 'generate_image' && is_array($config['image'] ?? null)) {
            return $config['image'];
        }
        if ($toolCode === 'generate_video' && is_array($config['video'] ?? null)) {
            return $config['video'];
        }
        return [];
    }

    public function withTask(array $task): self
    {
        $clone = clone $this;
        $clone->state['task'] = $task;
        return $clone;
    }

    public function task(): array
    {
        return is_array($this->state['task'] ?? null) ? $this->state['task'] : [];
    }

    public function addAgentResult(string $agentCode, array $result): void
    {
        $this->agentResults[$agentCode] = $result;
        $this->trace[] = ['agent' => $agentCode, 'result' => $result];
    }

    public function result(string $agentCode): array
    {
        return is_array($this->agentResults[$agentCode] ?? null) ? $this->agentResults[$agentCode] : [];
    }

    public function addCanvasActions(array $actions): void
    {
        foreach ($actions as $action) {
            if (is_array($action)) {
                $this->canvasActions[] = $action;
            }
        }
    }

    public function addToolResult(array $result): void
    {
        $this->toolCalls = array_merge($this->toolCalls, (array)($result['tool_calls'] ?? []));
        $this->workspaceActions = array_merge($this->workspaceActions, (array)($result['workspace_actions'] ?? []));
        $this->assets = array_merge($this->assets, (array)($result['assets'] ?? []));
    }

    public function canvasActions(): array
    {
        return $this->canvasActions;
    }

    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    public function workspaceActions(): array
    {
        return $this->workspaceActions;
    }

    public function assets(): array
    {
        return $this->assets;
    }

    public function trace(): array
    {
        return $this->trace;
    }
}
