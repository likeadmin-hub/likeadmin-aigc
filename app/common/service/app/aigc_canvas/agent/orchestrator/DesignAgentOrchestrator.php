<?php

namespace app\common\service\app\aigc_canvas\agent\orchestrator;

use app\common\model\app\aigc_canvas\AigcCanvasAgentWorkspaceAction;
use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\agent\contracts\CanvasProtocol;
use app\common\service\app\aigc_canvas\agent\memory\ProjectMemoryService;
use app\common\service\app\aigc_canvas\agent\runtime\AgentTraceLogger;
use app\common\service\app\aigc_canvas\agent\runtime\FunctionCallingRuntime;
use app\common\service\app\aigc_canvas\agent\runtime\JsonCanvasValidator;
use app\common\service\app\aigc_canvas\agent\tools\AddElementTool;
use app\common\service\app\aigc_canvas\agent\tools\CreatePageTool;
use app\common\service\app\aigc_canvas\agent\tools\GenerateImageTool;
use app\common\service\app\aigc_canvas\agent\tools\GenerateVideoTool;
use app\common\service\app\aigc_canvas\agent\tools\UpdateElementTool;
use Exception;

final class DesignAgentOrchestrator
{
    public static function supports(string $content): bool
    {
        $text = mb_strtolower(trim($content), 'UTF-8');
        if ($text === '') {
            return false;
        }

        foreach ([
            '插入画布', '落到画布', '放到画布', '生成画布', '画布结构', '画板结构',
            'json canvas', 'create_page', 'add_element', '生成页面', '页面结构',
            '设计页面', '做页面', '制作页面', '创建页面', '设计稿', '详情页', 'landing page',
        ] as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }
        return false;
    }

    public function run(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, string $content, array $context, array $route = [], ?callable $emit = null): array
    {
        $runId = (int)($route['agent_run_id'] ?? 0);
        if ($runId <= 0) {
            $runId = AgentTraceLogger::startRun($tenantId, $userId, $projectId, $threadId, 'design_orchestrator', [
                'content' => $content,
                'route' => $route,
            ], (string)($route['request_id'] ?? ''));
        }
        try {
            $route['agent_run_id'] = $runId;
            $this->progress($threadId, $messageId, $emit, "正在理解你的设计需求...\n");
            $contextObject = AgentExecutionContext::from([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'user_request' => $content,
                'canvas_context' => $context,
                'route' => $route,
                'memory' => ProjectMemoryService::load($tenantId, $projectId),
                'emit' => $emit,
            ]);
            $runtime = new FunctionCallingRuntime([
                new CreatePageTool(),
                new AddElementTool(),
                new UpdateElementTool(),
                new GenerateImageTool(),
                new GenerateVideoTool(),
            ]);

            $master = AgentRouter::resolve('master');
            $masterResult = $master->run($contextObject);
            $contextObject->addAgentResult('master', $masterResult);
            AgentTraceLogger::step($tenantId, $runId, 'master', 'plan', ['content' => $content], $masterResult);
            $this->progress($threadId, $messageId, $emit, "已拆解任务，开始规划画布结构。\n");

            $deferredMediaCalls = [];
            foreach ((array)($masterResult['agents'] ?? []) as $task) {
                $agentCode = (string)($task['agent'] ?? '');
                if ($agentCode === '' || $agentCode === 'master') {
                    continue;
                }
                $this->progress($threadId, $messageId, $emit, $this->agentProgressText($agentCode));
                $agent = AgentRouter::resolve($agentCode);
                $agentContext = $contextObject->withTask($task);
                $result = $agent->run($agentContext);
                $contextObject->addAgentResult($agentCode, $result);
                AgentTraceLogger::step($tenantId, $runId, $agentCode, 'run', $task, $result);
                $calls = (array)($result['function_calls'] ?? []);
                if (!empty($calls)) {
                    $mediaCalls = array_values(array_filter($calls, static fn($call): bool => is_array($call) && in_array((string)($call['name'] ?? $call['tool'] ?? ''), ['generate_image', 'generate_video'], true)));
                    $canvasCalls = array_values(array_filter($calls, static fn($call): bool => is_array($call) && !in_array((string)($call['name'] ?? $call['tool'] ?? ''), ['generate_image', 'generate_video'], true)));
                    if (!empty($canvasCalls)) {
                        $toolResult = $this->executeCallBatches($runtime, $contextObject, $canvasCalls, 24);
                        $contextObject->addCanvasActions((array)($toolResult['canvas_actions'] ?? []));
                        $contextObject->addToolResult($toolResult);
                        AgentTraceLogger::step($tenantId, $runId, $agentCode, 'function_call', ['calls' => $canvasCalls], $toolResult);
                    }
                    $deferredMediaCalls = array_merge($deferredMediaCalls, $mediaCalls);
                }
            }

            $canvasResult = $contextObject->result('canvas');
            $canvasJson = is_array($canvasResult['canvas_json'] ?? null)
                ? $canvasResult['canvas_json']
                : CanvasProtocol::document($contextObject->canvasActions());
            JsonCanvasValidator::assertValid($canvasJson);
            $this->progress($threadId, $messageId, $emit, "正在把 JSON Canvas 插入当前画布。\n");
            $canvasAction = $this->createCanvasWorkspaceAction($tenantId, $userId, $projectId, $threadId, $messageId, $runId, $canvasJson, $content, $emit);

            if (!empty($deferredMediaCalls)) {
                $mediaResult = $this->executeCallBatches($runtime, $contextObject, $deferredMediaCalls, 5);
                $contextObject->addToolResult($mediaResult);
                AgentTraceLogger::step($tenantId, $runId, 'visual', 'media_function_call', ['calls' => $deferredMediaCalls], $mediaResult);
            }

            $workspaceActions = array_merge([$canvasAction], $contextObject->workspaceActions());
            $reply = $this->reply($canvasJson, $contextObject->workspaceActions());
            $output = [
                'reply' => $reply,
                'tool_calls' => $contextObject->toolCalls(),
                'workspace_actions' => $workspaceActions,
                'assets' => $contextObject->assets(),
                'next_action' => 'insert_canvas',
                'canvas_json' => $canvasJson,
                'agent_trace' => $contextObject->trace(),
                'run_id' => $runId,
            ];
            ProjectMemoryService::rememberRun($tenantId, $userId, $projectId, [
                'summary' => $reply,
                'last_request' => $content,
                'canvas_action_count' => count((array)($canvasJson['actions'] ?? [])),
                'skill_key' => (string)($route['skill_key'] ?? ''),
                'slots' => is_array($route['slots'] ?? null) ? $route['slots'] : [],
                'source' => ['thread_id' => $threadId, 'message_id' => $messageId],
            ]);
            AgentTraceLogger::finishRun($runId, $output);
            return $output;
        } catch (Exception $e) {
            AgentTraceLogger::failRun($runId, $e->getMessage());
            throw $e;
        }
    }

    private function executeCallBatches(FunctionCallingRuntime $runtime, AgentExecutionContext $context, array $calls, int $batchSize): array
    {
        $merged = [
            'canvas_actions' => [],
            'tool_calls' => [],
            'workspace_actions' => [],
            'assets' => [],
        ];
        foreach (array_chunk($calls, max(1, $batchSize)) as $batch) {
            $result = $runtime->executeCalls($context, $batch);
            foreach (array_keys($merged) as $key) {
                $merged[$key] = array_merge($merged[$key], (array)($result[$key] ?? []));
            }
        }
        return $merged;
    }

    private function createCanvasWorkspaceAction(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, int $runId, array $canvasJson, string $prompt, ?callable $emit): array
    {
        $now = time();
        $action = AigcCanvasAgentWorkspaceAction::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'tool_call_id' => 0,
            'action_type' => 'apply_json_canvas',
            'status' => 'pending',
            'input_json' => [
                'canvas_json' => $canvasJson,
                'prompt' => $prompt,
                'run_id' => $runId,
                'placement' => 'viewport_center',
                'requires_confirmation' => false,
            ],
            'result_json' => [],
            'error' => '',
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);
        $formatted = AigcCanvasAgentRuntimeService::formatWorkspaceAction($action->toArray());
        if (is_callable($emit)) {
            $emit('agent.workspace.action_pending', $formatted);
        }
        return $formatted;
    }

    private function progress(int $threadId, int $messageId, ?callable $emit, string $text): void
    {
        if (!is_callable($emit) || $text === '') {
            return;
        }
        $emit('agent.message.delta', [
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'delta' => $text,
        ]);
    }

    private function agentProgressText(string $agentCode): string
    {
        return match ($agentCode) {
            'planner' => "Planner Agent 正在生成页面结构。\n",
            'copy' => "Copy Agent 正在生成文案内容。\n",
            'visual' => "Visual Agent 正在准备主视觉和媒体任务。\n",
            'canvas' => "Canvas Agent 正在整理 JSON Canvas 协议。\n",
            default => "Agent 正在执行 {$agentCode}。\n",
        };
    }

    private function reply(array $canvasJson, array $mediaActions): string
    {
        $count = count((array)($canvasJson['actions'] ?? []));
        $mediaCount = count($mediaActions);
        return $mediaCount > 0
            ? "已生成设计画布结构（{$count} 个动作），并提交 {$mediaCount} 个媒体生成任务。"
            : "已生成设计画布结构（{$count} 个动作），并自动插入当前画布。";
    }
}
