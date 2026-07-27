<?php

namespace app\common\service\app\aigc_canvas;

use app\common\model\app\aigc_canvas\AigcCanvasAgentMessage;
use app\common\model\app\aigc_canvas\AigcCanvasAgentBatch;
use app\common\model\app\aigc_canvas\AigcCanvasAgentThread;
use app\common\model\app\aigc_canvas\AigcCanvasAgentToolCall;
use app\common\model\app\aigc_canvas\AigcCanvasAgentWorkspaceAction;
use app\common\model\app\aigc_canvas\AigcCanvasProject;
use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
use app\common\service\app\aigc_canvas\agent\batch\EcommerceAgentBatchService;
use app\common\service\app\aigc_canvas\agent\delivery\CanvasDeliveryBatchService;
use app\common\service\app\aigc_canvas\agent\delivery\ConversationTaskResolver;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryItemService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryPlanService;
use app\common\service\app\aigc_canvas\agent\delivery\PendingActionProtocol;
use app\common\service\app\aigc_canvas\agent\memory\BrandMemoryService;
use app\common\service\app\aigc_canvas\agent\memory\ConversationDeliveryContext;
use app\common\service\app\aigc_canvas\agent\orchestrator\DesignAgentOrchestrator;
use app\common\service\app\aigc_canvas\agent\planning\EcommerceDetailSectionPlanner;
use app\common\service\app\aigc_canvas\agent\planning\FollowUpRouter;
use app\common\service\app\aigc_canvas\agent\prompt\PromptSubmissionException;
use app\common\service\app\aigc_canvas\agent\router\CanvasAgentRouterService;
use app\common\service\app\aigc_canvas\agent\runtime\AgentTraceLogger;
use app\common\service\app\aigc_canvas\agent\runtime\AgentLoopService;
use app\common\service\app\aigc_canvas\agent\runtime\AgentResponseProtocol;
use app\common\service\app\aigc_canvas\agent\runtime\AgentTurnTraceService;
use app\common\service\app\aigc_canvas\agent\runtime\SubAgentTaskService;
use app\common\service\app\aigc_canvas\agent\tools\CanvasAgentToolExecutor;
use app\common\service\FileService;
use Exception;
use think\facade\Db;

class AigcCanvasAgentRuntimeService
{
    private const ROLE_USER = 'user';
    private const ROLE_ASSISTANT = 'assistant';

    public static function createThread(int $tenantId, int $userId, array $params): array
    {
        self::ensureSchema();
        $projectId = (int)($params['project_id'] ?? 0);
        if ($projectId > 0) {
            self::assertProject($tenantId, $userId, $projectId);
        }
        $now = time();
        $thread = AigcCanvasAgentThread::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'title' => mb_substr(trim((string)($params['title'] ?? 'Canvas Agent')), 0, 120, 'UTF-8'),
            'status' => 'active',
            'summary' => '',
            'meta_json' => self::sanitizeArray($params['meta'] ?? []),
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);
        return self::formatThread($thread->toArray());
    }

    public static function threadLists(int $tenantId, int $userId, array $params): array
    {
        self::ensureSchema();
        $projectId = (int)($params['project_id'] ?? 0);
        $limit = max(1, min(60, (int)($params['limit'] ?? 30)));
        $query = AigcCanvasAgentThread::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'delete_time' => 0,
        ]);
        if ($projectId > 0) {
            $query->where('project_id', $projectId);
        }
        $rows = $query
            ->order(['update_time' => 'desc', 'id' => 'desc'])
            ->limit($limit)
            ->select()
            ->toArray();
        $threadIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows)));
        $messageCounts = [];
        if (!empty($threadIds)) {
            $counts = AigcCanvasAgentMessage::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'delete_time' => 0,
            ])
                ->whereIn('thread_id', $threadIds)
                ->field('thread_id,count(*) as total')
                ->group('thread_id')
                ->select()
                ->toArray();
            foreach ($counts as $count) {
                $messageCounts[(int)($count['thread_id'] ?? 0)] = (int)($count['total'] ?? 0);
            }
        }
        return array_map(static function (array $row) use ($messageCounts): array {
            $item = self::formatThread($row);
            $item['message_count'] = $messageCounts[(int)$item['id']] ?? 0;
            return $item;
        }, $rows);
    }

    public static function threadDetail(int $tenantId, int $userId, array $params): array
    {
        self::ensureSchema();
        $thread = self::threadQuery($tenantId, $userId, (int)($params['thread_id'] ?? $params['id'] ?? 0))->findOrEmpty();
        if ($thread->isEmpty()) {
            throw new Exception('Agent thread not found');
        }
        $data = self::formatThread($thread->toArray());
        $data['messages'] = self::messageLists($tenantId, $userId, ['thread_id' => $data['id'], 'limit' => 80]);
        return $data;
    }

    public static function messageLists(int $tenantId, int $userId, array $params): array
    {
        self::ensureSchema();
        $threadId = (int)($params['thread_id'] ?? 0);
        if ($threadId <= 0) {
            return [];
        }
        self::assertThread($tenantId, $userId, $threadId);
        $limit = max(1, min(200, (int)($params['limit'] ?? 80)));
        $rows = AigcCanvasAgentMessage::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
        return array_map([self::class, 'formatMessage'], $rows);
    }

    public static function runStatus(int $tenantId, int $userId, array $params): array
    {
        self::ensureSchema();
        $requestId = preg_replace('/[^a-zA-Z0-9_.:-]/', '', (string)($params['request_id'] ?? '')) ?? '';
        $requestId = mb_substr($requestId, 0, 96, 'UTF-8');
        if ($requestId === '') {
            throw new Exception('request_id is required');
        }
        $run = AgentTraceLogger::findByRequest($tenantId, $userId, $requestId);
        if (empty($run)) {
            return [
                'request_id' => $requestId,
                'status' => 'pending',
                'run_id' => 0,
                'thread_id' => 0,
                'output' => [],
                'error' => '',
            ];
        }
        return [
            'request_id' => $requestId,
            'status' => (string)($run['status'] ?? 'running'),
            'run_id' => (int)($run['id'] ?? 0),
            'thread_id' => (int)($run['thread_id'] ?? 0),
            'output' => is_array($run['output'] ?? null) ? $run['output'] : [],
            'error' => (string)($run['error'] ?? ''),
            'update_time' => (int)($run['update_time'] ?? 0),
            'subtasks' => SubAgentTaskService::statusForRun($tenantId, $userId, (int)($run['id'] ?? 0)),
            'subtask_summary' => SubAgentTaskService::summaryForRun($tenantId, $userId, (int)($run['id'] ?? 0)),
        ];
    }

    public static function deleteThread(int $tenantId, int $userId, array $params): void
    {
        self::ensureSchema();
        $threadId = (int)($params['thread_id'] ?? $params['id'] ?? 0);
        $thread = self::threadQuery($tenantId, $userId, $threadId)->findOrEmpty();
        if ($thread->isEmpty()) {
            throw new Exception('Agent thread not found');
        }
        $time = time();
        $thread->save([
            'delete_time' => $time,
            'update_time' => $time,
        ]);
        AigcCanvasAgentMessage::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->update(['delete_time' => $time, 'update_time' => $time]);
        AigcCanvasAgentToolCall::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->update(['delete_time' => $time, 'update_time' => $time]);
        AigcCanvasAgentWorkspaceAction::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->update(['delete_time' => $time, 'update_time' => $time]);
        AigcCanvasAgentBatch::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->update(['delete_time' => $time, 'update_time' => $time]);
    }

    public static function send(int $tenantId, int $userId, array $params, ?callable $emit = null): array
    {
        self::ensureSchema();
        $projectId = (int)($params['project_id'] ?? 0);
        if ($projectId > 0) {
            self::assertProject($tenantId, $userId, $projectId);
        }
        $thread = self::resolveThread($tenantId, $userId, $params, $projectId);
        $threadId = (int)$thread['id'];
        $content = trim((string)($params['content'] ?? $params['prompt'] ?? $params['message'] ?? ''));
        if ($content === '') {
            throw new Exception('Please enter a request');
        }
        // Confirmation text must advance the persisted plan in both the legacy
        // router and the Agent Loop. Do not rely exclusively on client state.
        $pendingSkillContext = self::normalizePendingSkillContext($params['pending_skill_context'] ?? []);
        if (!empty($pendingSkillContext) && self::isExecutionConfirmation($content)) {
            $params['execution_confirmation'] = true;
        }
        if (empty($params['agent_decision_context']) && !empty($pendingSkillContext)) {
            $params['agent_decision_context'] = $pendingSkillContext;
        }
        $inlineReferences = self::extractInlineReferences($content);
        if (!empty($inlineReferences)) {
            $params['uploaded_references'] = array_merge(
                is_array($params['uploaded_references'] ?? null) ? $params['uploaded_references'] : [],
                $inlineReferences
            );
            $content = trim((string)preg_replace('/\[@(?:image|video|audio):[^\]]+\]/ui', '', $content));
        }
        $requestId = preg_replace('/[^a-zA-Z0-9_.:-]/', '', (string)($params['request_id'] ?? '')) ?? '';
        $requestId = mb_substr($requestId, 0, 96, 'UTF-8');
        if ($requestId === '') {
            $requestId = 'agent_' . $threadId . '_' . bin2hex(random_bytes(12));
        }
        $existingRun = AgentTraceLogger::findByRequest($tenantId, $userId, $requestId);
        if (!empty($existingRun['output']) && (string)($existingRun['status'] ?? '') === 'success') {
            $payload = (array)$existingRun['output'];
            self::emit($emit, 'agent.message.done', $payload);
            return $payload;
        }
        if (!empty($existingRun) && (string)($existingRun['status'] ?? '') === 'running' && (int)($existingRun['update_time'] ?? 0) > time() - 120) {
            throw new Exception('该请求正在处理中，请勿重复提交');
        }
        $params['request_id'] = $requestId;
        $turnStartedAtMs = (int)round(microtime(true) * 1000);
        // Do not make the browser wait for memory, snapshot, or model work
        // before it gets an unmistakable first response from the Agent.
        self::emit($emit, 'agent.turn.started', [
            'thread_id' => $threadId,
            'request_id' => $requestId,
            'execution_mode' => 'preparing',
        ]);
        self::emit($emit, 'agent.turn.status', [
            'thread_id' => $threadId,
            'stage' => 'preparing',
            'label' => '正在准备画布上下文',
        ]);
        $firstStatusAtMs = (int)round(microtime(true) * 1000);
        $runId = AgentTraceLogger::startRun($tenantId, $userId, $projectId, $threadId, 'agent_runtime', [
            'content' => $content,
            'request_id' => $requestId,
        ], $requestId);
        $uploadedReferences = self::normalizeUploadedReferences($params, $tenantId);
        $context = self::contextForUserRequest(
            $content,
            self::normalizeContext($params['canvas_snapshot'] ?? $params['context'] ?? [])
        );
        if (!empty($uploadedReferences)) {
            $context['uploaded_references'] = $uploadedReferences;
            $context['uploaded_reference_count'] = count($uploadedReferences);
        }
        $brandMemory = BrandMemoryService::context($tenantId, $userId, $projectId);
        if (!empty($brandMemory)) {
            $context['brand_memory'] = $brandMemory;
        }
        $deliveryContext = ConversationDeliveryContext::build($tenantId, $userId, $projectId, $threadId, $context);
        $context['conversation_delivery_context'] = $deliveryContext;
        $agentConfig = AigcCanvasService::agentConfig($tenantId);
        $loopDecision = self::agentLoopDecision($tenantId, $params, $agentConfig);
        if (!empty($loopDecision['use_loop'])) {
            return self::sendWithAgentLoop(
                $tenantId,
                $userId,
                $projectId,
                $thread,
                $threadId,
                $content,
                $context,
                $params,
                $uploadedReferences,
                $requestId,
                $runId,
                $agentConfig,
                $emit,
                $turnStartedAtMs,
                $firstStatusAtMs
            );
        }
        $legacyTurnId = AgentTurnTraceService::start(
            $tenantId,
            $userId,
            $projectId,
            $threadId,
            $requestId,
            'legacy_router',
            [
                'content' => $content,
                'fallback_reason' => (string)($loopDecision['reason'] ?? ''),
            ],
            $turnStartedAtMs,
            $firstStatusAtMs
        );
        self::emit($emit, 'agent.reasoning.step', [
            'thread_id' => $threadId,
            'stage' => 'request_analysis',
            'status' => 'running',
            'label' => '正在解析创作需求',
            'value' => !empty($uploadedReferences) ? '结合上传素材与画布上下文进行判断' : '结合当前需求与画布上下文进行判断',
        ]);
        $followUpDecision = self::shouldResolveFollowUp($params)
            ? FollowUpRouter::decide($tenantId, $userId, $content, $deliveryContext)
            : [];
        $route = self::routeFromFollowUpDecision($followUpDecision, $deliveryContext, $context);
        if (empty($route) || (string)($route['next_action'] ?? '') === 'route_new_task') {
            $route = self::routeAgentRequest($tenantId, $userId, $params, $content, $context);
        }
        $route = self::attachAgentToolOptions($route, $params);
        if (!empty($route['uploaded_references'])) {
            $context['uploaded_references'] = (array)$route['uploaded_references'];
            $context['uploaded_reference_count'] = count($context['uploaded_references']);
        }
        $route['request_id'] = $requestId;
        $route['agent_run_id'] = $runId;
        $routeMeta = self::routeContentMeta($route);
        self::emitRouteProtocolEvents($tenantId, $runId, $emit, $route, $routeMeta);
        self::emit($emit, 'agent.reasoning.step', [
            'thread_id' => $threadId,
            'stage' => 'request_analysis',
            'status' => 'success',
            'label' => '创作需求已解析',
            'value' => self::legacyReasoningSummary($route),
        ]);
        if ((string)($route['skill_key'] ?? '') === 'ecommerce_detail_page') {
            $sectionCount = count((array)($route['slots']['detail_sections'] ?? []));
            self::emit($emit, 'agent.reasoning.step', [
                'thread_id' => $threadId,
                'stage' => 'delivery_planning',
                'status' => $sectionCount > 0 ? 'success' : 'running',
                'label' => $sectionCount > 0 ? '详情页区块已规划' : '正在规划详情页区块',
                'value' => $sectionCount > 0 ? sprintf('%d 个独立区块等待确认', $sectionCount) : '将根据可用商品信息安排页面内容',
            ]);
        }
        self::emit($emit, 'agent.route.resolved', $routeMeta);
        $dbSkill = is_array($route['db_skill'] ?? null) ? $route['db_skill'] : [];
        $skillCode = (string)($route['skill_code'] ?? '');
        if ($skillCode === '') {
            $skillCode = !empty($dbSkill) ? (string)($dbSkill['skill_key'] ?? 'agent_skill') : 'creative_plan';
        }
        $skillMeta = !empty($dbSkill)
            ? [
                'skill_key' => (string)($dbSkill['skill_key'] ?? ''),
                'skill_type' => (string)($dbSkill['skill_type'] ?? ''),
                'skill_version' => (int)($dbSkill['version'] ?? 1),
            ]
            : [];
        $now = time();

        $userMessage = AigcCanvasAgentMessage::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'role' => self::ROLE_USER,
            'content' => $content,
            'content_json' => array_merge([
                'skill_code' => $skillCode,
                'canvas_summary' => $context,
                'attachments' => $uploadedReferences,
                'uploaded_references' => $uploadedReferences,
                'reference_images' => self::referenceImagesFromReferences($uploadedReferences),
                'reference_assets' => self::referenceAssetsFromReferences($uploadedReferences),
            ], $skillMeta, $routeMeta),
            'status' => 'success',
            'meta_json' => self::sanitizeArray($params['meta'] ?? []),
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);

        $assistant = AigcCanvasAgentMessage::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'role' => self::ROLE_ASSISTANT,
            'content' => '',
            'content_json' => array_merge(['skill_code' => $skillCode], $skillMeta, $routeMeta),
            'status' => 'running',
            'meta_json' => [],
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);

        try {
            if (($route['next_action'] ?? '') === 'clarify') {
                $result = self::clarificationResult($route);
            } elseif (($route['next_action'] ?? '') === 'execute_revision') {
                $delivery = self::deliveryForDecision($deliveryContext, $route);
                $result = EcommerceAgentBatchService::revise(
                    $tenantId,
                    $userId,
                    $projectId,
                    $threadId,
                    (int)$assistant['id'],
                    $content,
                    (array)($route['follow_up_decision'] ?? []),
                    $delivery,
                    $route
                );
            } elseif (($route['next_action'] ?? '') === 'continue_batch') {
                $batchId = (int)($route['target_scope']['batch_id'] ?? 0);
                $execution = EcommerceAgentBatchService::execute($tenantId, $userId, [
                    'batch_id' => $batchId,
                    'action' => 'next',
                    'request_id' => $requestId . ':continue',
                    'assistant_message_id' => (int)$assistant['id'],
                    'media_config_patch' => (array)($route['media_config_patch'] ?? []),
                ]);
                $result = [
                    'reply' => (string)($route['decision_trace_summary'] ?? '我会继续处理上一批尚未完成的内容。'),
                    'tool_calls' => (array)($execution['tool_calls'] ?? []),
                    'workspace_actions' => (array)($execution['workspace_actions'] ?? []),
                    'assets' => [],
                    'next_action' => 'execute_tool',
                    'batch_id' => $batchId,
                    'batch' => (array)($execution['batch'] ?? []),
                ];
            } elseif (($route['next_action'] ?? '') === 'delivery_plan') {
                $result = CanvasDeliveryBatchService::prepare(
                    $tenantId,
                    $userId,
                    $projectId,
                    $threadId,
                    (int)$assistant['id'],
                    $content,
                    $route
                );
            } elseif (($route['next_action'] ?? '') === 'confirm_execution') {
                $result = (string)($route['skill_key'] ?? '') === 'ecommerce_detail_page'
                    ? EcommerceAgentBatchService::prepare(
                        $tenantId,
                        $userId,
                        $projectId,
                        $threadId,
                        (int)$assistant['id'],
                        $content,
                        $context,
                        $route,
                        $emit
                    )
                    : self::confirmationResult($route);
            } elseif (($route['next_action'] ?? '') === 'local_reply') {
                $result = self::localReplyResult($route);
            } else {
                $result = in_array(($route['next_action'] ?? ''), ['compound_plan_image', 'compound_plan_video'], true)
                    ? self::runCompoundPlanWithMedia($tenantId, $userId, $projectId, $threadId, (int)$assistant['id'], $content, $context, $params, $route, $emit)
                    : (!empty($dbSkill)
                    ? AigcCanvasSkillService::runDbSkill($tenantId, $userId, $projectId, $threadId, (int)$assistant['id'], $dbSkill, $content, $context, $emit, $route)
                    : self::runSkill($tenantId, $userId, $projectId, $threadId, (int)$assistant['id'], $skillCode, $content, $context, $params, $emit));
            }
            if (empty($result['reply_streamed'])) {
                AgentTurnTraceService::firstToken($legacyTurnId);
                self::emitAssistantReplyDeltas($emit, $threadId, (int)$assistant['id'], (string)($result['reply'] ?? ''));
            }
            $legacyResponse = AgentResponseProtocol::fromResult($result, self::creativeSummary($result));
            $resultMeta = self::agentResultContentMeta($result);
            $assistant->save([
                'content' => $result['reply'],
                'content_json' => array_merge([
                    'skill_code' => $skillCode,
                    'tool_calls' => $result['tool_calls'],
                'workspace_actions' => $result['workspace_actions'],
                'assets' => $result['assets'],
                'next_action' => $result['next_action'] ?? ($route['next_action'] ?? 'chat'),
                'response' => $legacyResponse,
                'response_kind' => (string)$legacyResponse['response_kind'],
                'canvas_json' => is_array($result['canvas_json'] ?? null) ? $result['canvas_json'] : [],
                'agent_trace' => is_array($result['agent_trace'] ?? null) ? $result['agent_trace'] : [],
            ], $skillMeta, $routeMeta, $resultMeta),
                'status' => 'success',
                'update_time' => time(),
            ]);
            AigcCanvasAgentThread::where('id', $threadId)->update(['update_time' => time(), 'title' => self::threadTitle($thread['title'], $content)]);
            $payload = [
                'thread' => self::formatThread(AigcCanvasAgentThread::where('id', $threadId)->findOrEmpty()->toArray()),
                'user_message' => self::formatMessage($userMessage->toArray()),
                'assistant_message' => self::formatMessage($assistant->toArray()),
                'tool_calls' => $result['tool_calls'],
                'workspace_actions' => $result['workspace_actions'],
                'assets' => $result['assets'],
                'next_action' => $result['next_action'] ?? ($route['next_action'] ?? 'chat'),
                'skill_key' => (string)($route['skill_key'] ?? ($skillMeta['skill_key'] ?? '')),
                'intent' => (string)($route['intent'] ?? $skillCode),
                'slots' => is_array($route['slots'] ?? null) ? $route['slots'] : [],
                'missing_slots' => is_array($route['missing_slots'] ?? null) ? $route['missing_slots'] : [],
                'clarify_question' => (string)($route['clarify_question'] ?? ''),
                'pending_skill_context' => is_array($route['pending_skill_context'] ?? null) ? $route['pending_skill_context'] : [],
                'run_id' => $runId,
                'request_id' => $requestId,
                'subtasks' => is_array($result['subtasks'] ?? null) ? $result['subtasks'] : [],
                'canvas_json' => is_array($result['canvas_json'] ?? null) ? $result['canvas_json'] : [],
                'batch_id' => (int)($result['batch_id'] ?? 0),
                'batch' => is_array($result['batch'] ?? null) ? $result['batch'] : [],
                'design_analysis' => is_array($result['design_analysis'] ?? null) ? $result['design_analysis'] : [],
                'planned_sections' => is_array($result['planned_sections'] ?? null) ? $result['planned_sections'] : [],
                'total_count' => (int)($result['total_count'] ?? 0),
                'completed_count' => (int)($result['completed_count'] ?? 0),
                'remaining_count' => (int)($result['remaining_count'] ?? 0),
                'conversation_mode' => (string)($route['conversation_mode'] ?? ''),
                'operation' => (string)($route['operation'] ?? ''),
                'target_scope' => is_array($route['target_scope'] ?? null) ? $route['target_scope'] : [],
                'requested_changes' => is_array($route['changes'] ?? null) ? $route['changes'] : [],
                'preserved_constraints' => is_array($route['preserve'] ?? null) ? $route['preserve'] : [],
                'requires_tool' => !empty($route['requires_tool']),
                'revision_batch_id' => (int)($result['revision_batch_id'] ?? 0),
                'revision_of_batch_id' => (int)($result['revision_of_batch_id'] ?? 0),
                'decision_trace_summary' => (string)($result['decision_trace_summary'] ?? $route['decision_trace_summary'] ?? ''),
                'response' => $legacyResponse,
            ];
            AgentTraceLogger::finishRun($runId, $payload);
            AgentTurnTraceService::complete($legacyTurnId, 0, [
                'next_action' => (string)($payload['next_action'] ?? ''),
                'tool_count' => count((array)($payload['tool_calls'] ?? [])),
            ], (string)($loopDecision['reason'] ?? ''));
            self::emit($emit, 'agent.response', $legacyResponse + [
                'thread_id' => $threadId,
                'message_id' => (int)$assistant['id'],
            ]);
            self::emit($emit, 'agent.message.done', $payload);
            return $payload;
        } catch (Exception $e) {
            AgentTurnTraceService::fail($legacyTurnId, 0, $e->getMessage());
            $assistant->save([
                'content' => $e->getMessage(),
                'content_json' => array_merge(['skill_code' => $skillCode, 'error' => $e->getMessage()], $skillMeta),
                'status' => 'failed',
                'error' => $e->getMessage(),
                'update_time' => time(),
            ]);
            self::emit($emit, 'agent.error', ['message' => $e->getMessage(), 'message_id' => (int)$assistant['id']]);
            AgentTraceLogger::failRun($runId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * The loop is intentionally entered before legacy intent/Skill routing.
     * Legacy routing remains a controlled fallback for explicit compatibility.
     */
    private static function agentLoopDecision(int $tenantId, array $params, array $agentConfig): array
    {
        if (empty($agentConfig['agent_loop_enabled']) || empty($agentConfig['router_available'])) {
            return ['use_loop' => false, 'reason' => empty($agentConfig['agent_loop_enabled']) ? 'agent_loop_disabled' : 'agent_model_unavailable'];
        }
        $mode = trim((string)($params['execution_mode'] ?? $params['agent_mode'] ?? ''));
        if (in_array($mode, ['legacy_router', 'legacy_skill'], true)) {
            return ['use_loop' => false, 'reason' => 'manual_legacy_mode'];
        }
        if (!empty($agentConfig['agent_loop_auto_fallback_enabled'])) {
            $health = AigcCanvasService::agentLoopHealth($tenantId, $agentConfig);
            if (!empty($health['fallback'])) {
                return ['use_loop' => false, 'reason' => 'auto_fallback:' . (string)($health['message'] ?? 'degraded')];
            }
        }
        // A manual product Skill is an explicit contract for the Agent Loop.
        // It must not revive the old keyword/Skill execution path.
        return ['use_loop' => true, 'reason' => ''];
    }

    private static function sendWithAgentLoop(
        int $tenantId,
        int $userId,
        int $projectId,
        array $thread,
        int $threadId,
        string $content,
        array $context,
        array $params,
        array $uploadedReferences,
        string $requestId,
        int $runId,
        array $agentConfig,
        ?callable $emit,
        int $turnStartedAtMs = 0,
        int $firstStatusAtMs = 0
    ): array {
        $now = time();
        $selectedSkill = AigcCanvasSkillService::selectedForAgent($tenantId, $params);
        $userMessage = AigcCanvasAgentMessage::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'role' => self::ROLE_USER,
            'content' => $content,
            'content_json' => [
                'execution_mode' => 'agent_loop',
                'selected_skill_key' => (string)($selectedSkill['skill_key'] ?? ''),
                'canvas_summary' => $context,
                'attachments' => $uploadedReferences,
                'uploaded_references' => $uploadedReferences,
            ],
            'status' => 'success',
            'meta_json' => self::sanitizeArray($params['meta'] ?? []),
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);
        $deliveryResolution = ConversationTaskResolver::resolve(
            $tenantId,
            $userId,
            $projectId,
            $threadId,
            (int)$userMessage['id'],
            $content,
            $context,
            $params
        );
        if (!empty($deliveryResolution['selected_skill']) && empty($selectedSkill)) {
            $selectedSkill = (array)$deliveryResolution['selected_skill'];
        }
        if ($deliveryResolution !== []) {
            $userContent = is_array($userMessage['content_json'] ?? null) ? $userMessage['content_json'] : [];
            $userContent['delivery_plan'] = (array)($deliveryResolution['plan'] ?? []);
            $userContent['delivery_item'] = (array)($deliveryResolution['item'] ?? []);
            $userContent['delivery_operation'] = (string)($deliveryResolution['operation'] ?? '');
            $userMessage->save(['content_json' => $userContent, 'update_time' => time()]);
        }
        $assistant = AigcCanvasAgentMessage::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'role' => self::ROLE_ASSISTANT,
            'content' => '',
            'content_json' => [
                'execution_mode' => 'agent_loop',
                'selected_skill_key' => (string)($selectedSkill['skill_key'] ?? ''),
                'delivery_plan' => (array)($deliveryResolution['plan'] ?? []),
                'delivery_item' => (array)($deliveryResolution['item'] ?? []),
                'delivery_operation' => (string)($deliveryResolution['operation'] ?? ''),
            ],
            'status' => 'running',
            'meta_json' => [],
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);
        self::emit($emit, 'agent.turn.started', [
            'thread_id' => $threadId,
            'message_id' => (int)$assistant['id'],
            'run_id' => $runId,
            'request_id' => $requestId,
            'execution_mode' => 'agent_loop',
        ]);
        self::emit($emit, 'agent.turn.status', [
            'thread_id' => $threadId,
            'message_id' => (int)$assistant['id'],
            'stage' => 'understanding',
            'label' => '正在理解你的需求和画布',
        ]);
        try {
            $result = AgentLoopService::run([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'thread_id' => $threadId,
                'message_id' => (int)$assistant['id'],
                'request_id' => $requestId,
                'run_id' => $runId,
                'content' => $content,
                'context' => $context,
                'selected_skill' => $selectedSkill,
                'delivery_plan' => (array)($deliveryResolution['plan'] ?? []),
                'delivery_item' => (array)($deliveryResolution['item'] ?? []),
                'delivery_item_id' => (int)($deliveryResolution['item']['id'] ?? 0),
                'agent_decision_context' => $params['agent_decision_context'] ?? [],
                'execution_confirmation' => !empty($params['execution_confirmation']) || !empty($params['confirm_execution'])
                    || (($deliveryResolution['operation'] ?? '') === 'pending_action_resolved'
                        && in_array((string)($deliveryResolution['pending_action']['type'] ?? ''), ['confirm_execution', 'approve_plan', 'retry_item'], true)
                        && !empty($deliveryResolution['pending_action_accepted'])),
                'skill_binding_mode_enabled' => !array_key_exists('skill_binding_mode_enabled', $agentConfig) || !empty($agentConfig['skill_binding_mode_enabled']),
                'agent_intent_router_enabled' => !array_key_exists('agent_intent_router_enabled', $agentConfig) || !empty($agentConfig['agent_intent_router_enabled']),
                'agent_generic_capability_fallback_enabled' => !array_key_exists('agent_generic_capability_fallback_enabled', $agentConfig) || !empty($agentConfig['agent_generic_capability_fallback_enabled']),
                'agent_media_config' => self::sanitizeArray($params['agent_media_config'] ?? []),
                'emit' => $emit,
                'max_iterations' => (int)($agentConfig['agent_loop_max_iterations'] ?? 6),
                'timeout_seconds' => (int)($agentConfig['agent_loop_timeout_seconds'] ?? 60),
                'started_at_ms' => $turnStartedAtMs,
                'first_status_at_ms' => $firstStatusAtMs,
            ]);
            $canceled = !empty($result['canceled']);
            $deferred = !empty($result['subtasks_pending']);
            if (!$canceled && !$deferred && empty($result['reply_streamed'])) {
                AgentTurnTraceService::firstToken((int)($result['turn_id'] ?? 0));
                self::emitAssistantReplyDeltas($emit, $threadId, (int)$assistant['id'], (string)($result['reply'] ?? ''));
            }
            $creativeSummary = self::creativeSummary($result);
            $response = AgentResponseProtocol::fromResult($result, $creativeSummary);
            $deliveryItem = (array)($deliveryResolution['item'] ?? []);
            if ($deliveryItem !== []) {
                $itemStatus = match ((string)($result['next_action'] ?? '')) {
                    'clarify' => 'clarifying',
                    'confirm_execution', 'confirm_plan' => 'awaiting_confirmation',
                    'execute_tool' => 'queued',
                    default => (string)($deliveryItem['status'] ?? 'ready'),
                };
                try {
                    $pendingAction = match ((string)($result['next_action'] ?? '')) {
                        'clarify' => PendingActionProtocol::forMissingSlots((array)($result['selected_skill']['missing_slots'] ?? $deliveryItem['required_slots'] ?? [])),
                        'confirm_plan' => PendingActionProtocol::confirmation('approve_plan'),
                        'confirm_execution' => PendingActionProtocol::confirmation(),
                        default => [],
                    };
                    $deliveryItem = DeliveryItemService::transition($tenantId, $userId, (int)$deliveryItem['id'], $itemStatus, [
                        'source_message_id' => (int)$userMessage['id'],
                        'pending_action_json' => $pendingAction,
                        'meta_json' => array_merge((array)($deliveryItem['meta'] ?? []), [
                            'last_assistant_message_id' => (int)$assistant['id'],
                            'last_next_action' => (string)($result['next_action'] ?? ''),
                        ]),
                    ]);
                } catch (Exception) {
                    // A terminal item may have been changed by another browser turn.
                }
                $deliveryResolution['plan'] = DeliveryPlanService::detail($tenantId, $userId, (int)($deliveryItem['plan_id'] ?? 0));
            }
            $assistant->save([
                'content' => $canceled ? '已取消当前请求。' : (string)($result['reply'] ?? ''),
                'content_json' => [
                    'execution_mode' => 'agent_loop',
                    'tool_calls' => (array)($result['tool_calls'] ?? []),
                    'workspace_actions' => (array)($result['workspace_actions'] ?? []),
                    'assets' => (array)($result['assets'] ?? []),
                    'agent_trace' => (array)($result['agent_trace'] ?? []),
                    'turn_id' => (int)($result['turn_id'] ?? 0),
                    'skills' => (array)($result['skills'] ?? []),
                    'selected_skill' => (array)($result['selected_skill'] ?? []),
                    'task_decision' => (array)($result['task_decision'] ?? []),
                    'creative_summary' => $creativeSummary,
                    'response' => $response,
                    'response_kind' => (string)$response['response_kind'],
                    'estimated_tools' => (array)($result['estimated_tools'] ?? []),
                    'pending_skill_context' => (array)($result['pending_skill_context'] ?? []),
                    'subtasks' => (array)($result['subtasks'] ?? []),
                    'next_action' => (string)($result['next_action'] ?? 'chat'),
                    'delivery_plan' => (array)($deliveryResolution['plan'] ?? []),
                    'delivery_item' => $deliveryItem,
                    'delivery_operation' => (string)($deliveryResolution['operation'] ?? ''),
                ],
                'status' => $canceled ? 'canceled' : ($deferred ? 'running' : 'success'),
                'update_time' => time(),
            ]);
            AigcCanvasAgentThread::where('id', $threadId)->update(['update_time' => time(), 'title' => self::threadTitle((string)($thread['title'] ?? ''), $content)]);
            $payload = [
                'thread' => self::formatThread(AigcCanvasAgentThread::where('id', $threadId)->findOrEmpty()->toArray()),
                'user_message' => self::formatMessage($userMessage->toArray()),
                'assistant_message' => self::formatMessage($assistant->toArray()),
                'tool_calls' => (array)($result['tool_calls'] ?? []),
                'workspace_actions' => (array)($result['workspace_actions'] ?? []),
                'assets' => (array)($result['assets'] ?? []),
                'next_action' => (string)($result['next_action'] ?? 'chat'),
                'intent' => 'agent_loop',
                'skill_key' => (string)($result['selected_skill']['skill_key'] ?? ''),
                'task_decision' => (array)($result['task_decision'] ?? []),
                'creative_summary' => $creativeSummary,
                'response' => $response,
                'estimated_tools' => (array)($result['estimated_tools'] ?? []),
                'pending_skill_context' => (array)($result['pending_skill_context'] ?? []),
                'run_id' => $runId,
                'turn_id' => (int)($result['turn_id'] ?? 0),
                'request_id' => $requestId,
                'iterations' => (int)($result['iterations'] ?? 0),
                'subtasks' => (array)($result['subtasks'] ?? []),
                'delivery_plan' => (array)($deliveryResolution['plan'] ?? []),
                'delivery_item' => $deliveryItem,
                'delivery_operation' => (string)($deliveryResolution['operation'] ?? ''),
            ];
            if ($canceled) {
                AgentTraceLogger::cancelRun($runId, $payload);
            } elseif (!$deferred) {
                AgentTraceLogger::finishRun($runId, $payload);
            }
            self::emit($emit, $deferred ? 'agent.turn.delegated' : 'agent.turn.completed', [
                'thread_id' => $threadId,
                'message_id' => (int)$assistant['id'],
                'turn_id' => (int)($result['turn_id'] ?? 0),
                'iterations' => (int)($result['iterations'] ?? 0),
                'status' => $canceled ? 'canceled' : ($deferred ? 'running' : 'success'),
            ]);
            self::emit($emit, 'agent.response', $response + [
                'thread_id' => $threadId,
                'message_id' => (int)$assistant['id'],
            ]);
            self::emit($emit, 'agent.message.done', $payload);
            return $payload;
        } catch (Exception $e) {
            $assistant->save([
                'content' => $e->getMessage(),
                'content_json' => ['execution_mode' => 'agent_loop', 'error' => $e->getMessage()],
                'status' => 'failed',
                'error' => $e->getMessage(),
                'update_time' => time(),
            ]);
            self::emit($emit, 'agent.turn.failed', ['thread_id' => $threadId, 'message_id' => (int)$assistant['id'], 'message' => $e->getMessage()]);
            self::emit($emit, 'agent.error', ['message' => $e->getMessage(), 'message_id' => (int)$assistant['id']]);
            AgentTraceLogger::failRun($runId, $e->getMessage());
            throw $e;
        }
    }

    public static function cancel(int $tenantId, int $userId, array $params): array
    {
        self::ensureSchema();
        $messageId = (int)($params['message_id'] ?? 0);
        if ($messageId > 0) {
            AigcCanvasAgentMessage::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => $messageId,
            ])->update(['status' => 'canceled', 'update_time' => time()]);
            SubAgentTaskService::cancelByAssistantMessage($tenantId, $userId, $messageId);
        }
        return ['status' => 'canceled'];
    }

    public static function retry(int $tenantId, int $userId, array $params, ?callable $emit = null): array
    {
        self::ensureSchema();
        $messageId = (int)($params['message_id'] ?? 0);
        $message = AigcCanvasAgentMessage::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $messageId,
            'role' => self::ROLE_USER,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($message->isEmpty()) {
            throw new Exception('Retry message not found');
        }
        return self::send($tenantId, $userId, array_merge($params, [
            'thread_id' => (int)$message['thread_id'],
            'project_id' => (int)$message['project_id'],
            'content' => (string)$message['content'],
        ]), $emit);
    }

    public static function recordWorkspaceActionResult(int $tenantId, int $userId, array $params): array
    {
        self::ensureSchema();
        $id = (int)($params['action_id'] ?? $params['id'] ?? 0);
        $action = AigcCanvasAgentWorkspaceAction::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $id,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($action->isEmpty()) {
            throw new Exception('Workspace action not found');
        }
        $status = in_array((string)($params['status'] ?? ''), ['applied', 'rejected', 'failed'], true) ? (string)$params['status'] : 'applied';
        $action->save([
            'status' => $status,
            'result_json' => self::sanitizeArray($params['result'] ?? []),
            'error' => (string)($params['error'] ?? ''),
            'update_time' => time(),
        ]);
        return self::formatWorkspaceAction($action->toArray());
    }

    private static function runSkill(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, string $skillCode, string $content, array $context, array $params, ?callable $emit): array
    {
        if ($skillCode === 'creative_plan') {
            if (DesignAgentOrchestrator::supports($content)) {
                return (new DesignAgentOrchestrator())->run(
                    $tenantId,
                    $userId,
                    $projectId,
                    $threadId,
                    $messageId,
                    $content,
                    $context,
                    $params,
                    $emit
                );
            }
            $tool = self::executeTool($tenantId, $userId, $projectId, $threadId, $messageId, 'generate_text', [
                'prompt' => self::creativePlanPrompt($content, $context),
                'project_id' => $projectId,
                'canvas_snapshot' => $context,
            ], $emit);
            $reply = (string)($tool['output']['content'] ?? $tool['output']['text'] ?? '');
            return [
                'reply' => $reply !== '' ? $reply : '创作方案已生成。',
                'tool_calls' => [$tool],
                'workspace_actions' => [],
                'assets' => [],
                'reply_streamed' => !empty($tool['streamed']),
                'next_action' => 'chat',
            ];
        }

        $toolCode = $skillCode === 'generate_video' ? 'generate_video' : ($skillCode === 'generate_music' ? 'generate_music' : 'generate_image');
        $toolInput = self::toolInput($toolCode, $content, $context, $params, $projectId, $tenantId);
        $tool = self::executeTool($tenantId, $userId, $projectId, $threadId, $messageId, $toolCode, $toolInput, $emit);
        $assets = self::extractAssets($toolCode, $tool['output']);
        if (empty($assets)) {
            $pendingAsset = self::extractPendingAsset($toolCode, $tool['output']);
            if (!empty($pendingAsset)) {
                $assets[] = $pendingAsset;
            }
        }
        $actions = [];
        $submittedInput = (array)($tool['output']['submitted_input'] ?? $tool['input'] ?? $toolInput);
        $submittedPrompt = (string)($submittedInput['compiled_prompt'] ?? $submittedInput['prompt'] ?? $content);
        foreach ($assets as $asset) {
            $asset = self::enrichWorkspaceAsset($asset, $toolInput);
            if ($toolCode === 'generate_music') {
                $actions[] = self::createWorkspaceAction($tenantId, $userId, $projectId, $threadId, $messageId, (int)$tool['id'], 'insert_audio', $asset, $submittedPrompt, $context, $emit, $submittedInput);
            } elseif ($toolCode === 'generate_video') {
                $actions[] = self::createWorkspaceAction($tenantId, $userId, $projectId, $threadId, $messageId, (int)$tool['id'], 'insert_video', $asset, $submittedPrompt, $context, $emit, $submittedInput);
            } else {
                $actions[] = self::createWorkspaceAction($tenantId, $userId, $projectId, $threadId, $messageId, (int)$tool['id'], 'insert_image', $asset, $submittedPrompt, $context, $emit, $submittedInput);
            }
        }
        $hasResolvedAsset = !empty(array_filter($assets, fn($asset) => !empty($asset['url'])));
        $reply = empty($assets) ? '已提交生成任务。' : ($hasResolvedAsset ? '已生成结果。' : '已提交生成任务。');
        return [
            'reply' => $reply,
            'tool_calls' => [$tool],
            'workspace_actions' => $actions,
            'assets' => $assets,
        ];
    }

    private static function runCompoundPlanWithMedia(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, string $content, array $context, array $params, array $route, ?callable $emit): array
    {
        $mediaSkill = (string)($route['media_skill_code'] ?? 'generate_image');
        if (!in_array($mediaSkill, ['generate_image', 'generate_video'], true)) {
            $mediaSkill = 'generate_image';
        }
        $plan = self::runSkill($tenantId, $userId, $projectId, $threadId, $messageId, 'creative_plan', $content, $context, $params, $emit);
        $mediaParams = $params;
        if ($mediaSkill === 'generate_image') {
            $mediaParams['quantity'] = self::requestedMediaQuantity($content);
        }
        $media = self::runSkill($tenantId, $userId, $projectId, $threadId, $messageId, $mediaSkill, $content, $context, $mediaParams, $emit);
        return [
            'reply' => trim((string)($plan['reply'] ?? '') . "\n\n" . (string)($media['reply'] ?? '')),
            'tool_calls' => array_merge((array)($plan['tool_calls'] ?? []), (array)($media['tool_calls'] ?? [])),
            'workspace_actions' => array_merge((array)($plan['workspace_actions'] ?? []), (array)($media['workspace_actions'] ?? [])),
            'assets' => array_merge((array)($plan['assets'] ?? []), (array)($media['assets'] ?? [])),
            'next_action' => 'execute_tool',
        ];
    }

    private static function requestedMediaQuantity(string $content): int
    {
        if (preg_match('/(\d+)\s*(张|幅|个|份)/u', $content, $match)) {
            return max(1, (int)$match[1]);
        }
        if (self::containsAny($content, ['两张', '两幅', '两个'])) {
            return 2;
        }
        if (self::containsAny($content, ['三张', '三幅', '三个'])) {
            return 3;
        }
        if (self::containsAny($content, ['四张', '四幅', '四个'])) {
            return 4;
        }
        return 1;
    }

    private static function shouldResolveFollowUp(array $params): bool
    {
        $skillKey = trim((string)($params['skill_key'] ?? ''));
        $skillCode = trim((string)($params['skill_code'] ?? $params['skill'] ?? 'agent_auto'));
        if ($skillKey !== '' || ($skillCode !== '' && $skillCode !== 'agent_auto')) {
            return false;
        }
        $pending = self::normalizePendingSkillContext($params['pending_skill_context'] ?? []);
        return empty($pending['skill_key']);
    }

    private static function routeFromFollowUpDecision(array $decision, array $deliveryContext, array &$context): array
    {
        if (empty($decision) || (string)($decision['conversation_mode'] ?? '') === 'new_task') {
            return [];
        }
        $intent = (string)($decision['intent'] ?? 'clarify');
        $nextAction = (string)($decision['next_action'] ?? 'clarify');
        if ($intent === 'clarify' || $nextAction === 'clarify') {
            return array_merge($decision, [
                'matched' => true,
                'skill_code' => 'creative_plan',
                'next_action' => 'clarify',
                'clarify_question' => (string)($decision['clarify_question'] ?? ''),
                'missing_slots' => ['revision_target'],
                'slots' => [],
                'pending_skill_context' => [],
                'follow_up_decision' => $decision,
            ]);
        }
        if ($intent === 'continue_batch' || $nextAction === 'continue_batch') {
            return array_merge($decision, [
                'matched' => true,
                'skill_code' => 'ecommerce_detail_page',
                'next_action' => 'continue_batch',
                'slots' => [],
                'missing_slots' => [],
                'clarify_question' => '',
                'pending_skill_context' => [],
                'follow_up_decision' => $decision,
            ]);
        }
        $tool = (string)($decision['tool'] ?? 'none');
        if (!empty($decision['requires_tool']) && $tool === 'generate_image') {
            return array_merge($decision, [
                'matched' => true,
                'skill_code' => 'ecommerce_detail_page',
                'next_action' => 'execute_revision',
                'slots' => [],
                'missing_slots' => [],
                'clarify_question' => '',
                'pending_skill_context' => [],
                'follow_up_decision' => $decision,
            ]);
        }
        if (!empty($decision['requires_tool']) && $tool === 'generate_video') {
            $delivery = self::deliveryForDecision($deliveryContext, $decision);
            $keys = array_fill_keys((array)($decision['target_scope']['section_keys'] ?? []), true);
            foreach ((array)($delivery['assets'] ?? []) as $asset) {
                if (!is_array($asset) || (!empty($keys) && !isset($keys[(string)($asset['section_key'] ?? '')]))) {
                    continue;
                }
                $url = trim((string)($asset['url'] ?? ''));
                if ($url !== '') {
                    $context['uploaded_references'][] = ['type' => 'image', 'url' => $url, 'uri' => $url, 'role' => 'first_frame_image'];
                    break;
                }
            }
            return array_merge($decision, [
                'matched' => true,
                'skill_code' => 'generate_video',
                'next_action' => 'execute_tool',
                'slots' => [],
                'missing_slots' => [],
                'clarify_question' => '',
                'pending_skill_context' => [],
                'follow_up_decision' => $decision,
            ]);
        }
        return array_merge($decision, [
            'matched' => true,
            'skill_code' => 'creative_plan',
            'next_action' => 'chat',
            'slots' => [],
            'missing_slots' => [],
            'clarify_question' => '',
            'pending_skill_context' => [],
            'follow_up_decision' => $decision,
        ]);
    }

    private static function deliveryForDecision(array $deliveryContext, array $route): array
    {
        $batchId = (int)($route['target_scope']['batch_id'] ?? $route['follow_up_decision']['target_scope']['batch_id'] ?? 0);
        foreach (['last_delivery', 'active_batch'] as $key) {
            $delivery = is_array($deliveryContext[$key] ?? null) ? $deliveryContext[$key] : [];
            if (!empty($delivery) && ($batchId <= 0 || (int)($delivery['batch_id'] ?? 0) === $batchId)) {
                return $delivery;
            }
        }
        return [];
    }

    private static function routeAgentRequest(int $tenantId, int $userId, array $params, string $content, array $context): array
    {
        try {
            return CanvasAgentRouterService::route($tenantId, $userId, $params, $content, $context);
        } catch (\Throwable) {
            return self::legacyRouteAgentRequest($tenantId, $userId, $params, $content, $context);
        }
    }

    private static function legacyRouteAgentRequest(int $tenantId, int $userId, array $params, string $content, array $context): array
    {
        $manualDbSkill = self::resolveDbSkill($tenantId, $params);
        $pendingContext = self::normalizePendingSkillContext($params['pending_skill_context'] ?? []);
        if (!empty($manualDbSkill)) {
            return self::buildDbSkillRoute($tenantId, $userId, $manualDbSkill, $content, $context, $pendingContext, [
                'matched' => true,
                'manual' => true,
                'confidence' => 1,
            ]);
        }

        if (!empty($pendingContext['skill_key'])) {
            $pendingSkill = AigcCanvasSkillService::resolveSkill($tenantId, (string)$pendingContext['skill_key']);
            if (!empty($pendingSkill) && (int)($pendingSkill['status'] ?? 0) === 1) {
                return self::buildDbSkillRoute($tenantId, $userId, $pendingSkill, $content, $context, $pendingContext, [
                    'matched' => true,
                    'confidence' => 1,
                    'reason' => 'pending_clarification',
                ]);
            }
        }

        $explicitSkill = (string)($params['skill_code'] ?? $params['skill'] ?? '');
        if ($explicitSkill !== '' && $explicitSkill !== 'agent_auto' && in_array($explicitSkill, ['creative_plan', 'generate_image', 'generate_video', 'generate_music'], true)) {
            return [
                'matched' => true,
                'skill_code' => $explicitSkill,
                'intent' => $explicitSkill,
                'confidence' => 1,
                'slots' => [],
                'missing_slots' => [],
                'next_action' => $explicitSkill === 'creative_plan' ? 'chat' : 'execute_tool',
                'clarify_question' => '',
                'pending_skill_context' => [],
            ];
        }

        if (self::isCompoundPlanningMediaRequest($content)) {
            $mediaSkill = self::containsAny(mb_strtolower($content, 'UTF-8'), ['视频', '短片', '动画', 'video'])
                ? 'generate_video'
                : 'generate_image';
            return [
                'matched' => true,
                'skill_code' => 'creative_plan',
                'media_skill_code' => $mediaSkill,
                'intent' => 'compound_plan_media',
                'confidence' => 0.92,
                'reason' => 'compound_planning_media_rule',
                'slots' => [],
                'missing_slots' => [],
                'next_action' => $mediaSkill === 'generate_video' ? 'compound_plan_video' : 'compound_plan_image',
                'clarify_question' => '',
                'pending_skill_context' => [],
            ];
        }

        $dbRoute = self::resolveDbSkillWithRouter($tenantId, $userId, $content, $context, $pendingContext);
        if (!empty($dbRoute['db_skill'])) {
            $dbSkillKey = (string)($dbRoute['skill_key'] ?? ($dbRoute['db_skill']['skill_key'] ?? ''));
            if (
                DesignAgentOrchestrator::supports($content)
                && in_array($dbSkillKey, ['general_chat', 'script_planning', 'creative_plan'], true)
            ) {
                return [
                    'matched' => true,
                    'skill_code' => 'creative_plan',
                    'intent' => 'design_agent',
                    'confidence' => 0.95,
                    'reason' => 'design_agent_rule',
                    'slots' => [],
                    'missing_slots' => [],
                    'next_action' => 'insert_canvas',
                    'clarify_question' => '',
                    'pending_skill_context' => [],
                ];
            }
            return $dbRoute;
        }

        if (DesignAgentOrchestrator::supports($content)) {
            return [
                'matched' => true,
                'skill_code' => 'creative_plan',
                'intent' => 'design_agent',
                'confidence' => 0.95,
                'reason' => 'design_agent_rule',
                'slots' => [],
                'missing_slots' => [],
                'next_action' => 'insert_canvas',
                'clarify_question' => '',
                'pending_skill_context' => [],
            ];
        }

        $skillCode = self::resolveSkillCode($tenantId, $userId, $params, $content, $context);
        return [
            'matched' => false,
            'skill_code' => $skillCode,
            'intent' => $skillCode,
            'confidence' => 0,
            'slots' => [],
            'missing_slots' => [],
            'next_action' => $skillCode === 'creative_plan' ? 'chat' : 'execute_tool',
            'clarify_question' => '',
            'pending_skill_context' => [],
        ];
    }

    private static function isCompoundPlanningMediaRequest(string $content): bool
    {
        $text = mb_strtolower($content, 'UTF-8');
        $hasPlanning = self::containsAny($text, ['策划', '方案', '流程', '文案', '脚本', '计划', 'proposal', 'plan', 'copy', 'script']);
        if (!$hasPlanning) {
            return false;
        }
        $hasExplicitMediaAction = self::containsAny($text, ['并生成', '再生成', '同时生成', '顺便生成', '生成一张', '生成两张', '生成图片', '生成图', '生成视频']);
        $hasMediaTarget = self::containsAny($text, ['主视觉', '海报', 'kv', 'banner', '封面', '图片', '图像', '一张图', '视频', '短片', 'image', 'poster', 'video']);
        return $hasExplicitMediaAction && $hasMediaTarget;
    }

    private static function isSimpleChatRequest(string $content): bool
    {
        $text = trim(mb_strtolower($content, 'UTF-8'));
        $normalized = preg_replace('/[\s　，。！？!?,.～~、]+/u', '', $text) ?? $text;
        if ($normalized === '') {
            return false;
        }
        if (mb_strlen($normalized, 'UTF-8') <= 8 && in_array($normalized, [
            '你好',
            '您好',
            '哈喽',
            'hello',
            'hi',
            '嗨',
            '在吗',
            '谢谢',
            '多谢',
            '好的',
            'ok',
        ], true)) {
            return true;
        }
        return mb_strlen($normalized, 'UTF-8') <= 12
            && self::containsAny($normalized, ['你是谁', '能做什么', '怎么用']);
    }

    private static function simpleChatReply(string $content): string
    {
        $text = trim(mb_strtolower($content, 'UTF-8'));
        if (self::containsAny($text, ['谢谢', '多谢', 'thanks', 'thank'])) {
            return '不客气！需要继续生成图片、设计画布或调整内容时，直接告诉我就可以。';
        }
        if (self::containsAny($text, ['你是谁', '能做什么', '怎么用'])) {
            return '我是无限画布的 AI Design Agent，可以帮你生成图片、设计画布、写文案、做视频方案，也可以根据选中的画布内容继续修改。';
        }
        return '你好！我是无限画布的 AI Design Agent，请问有什么可以帮你？';
    }

    private static function attachAgentToolOptions(array $route, array $params): array
    {
        $config = $params['agent_media_config'] ?? [];
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }
        $image = is_array($config['image'] ?? null) ? self::sanitizeArray($config['image']) : [];
        $video = is_array($config['video'] ?? null) ? self::sanitizeArray($config['video']) : [];
        $music = is_array($config['music'] ?? null) ? self::sanitizeArray($config['music']) : [];
        $route['tool_options'] = [
            'generate_image' => $image,
            'generate_video' => $video,
            'generate_music' => $music,
        ];
        $route['uploaded_references'] = array_values(array_merge(
            (array)($route['uploaded_references'] ?? []),
            self::normalizeUploadedReferences($params)
        ));
        return $route;
    }

    private static function resolveDbSkillWithRouter(int $tenantId, int $userId, string $content, array $context, array $pendingContext = []): array
    {
        $skills = AigcCanvasSkillService::routerSkills($tenantId);
        if (empty($skills)) {
            return [];
        }
        $ruleSkill = self::matchSkillByRules($skills, $content, $context);
        if ((string)($ruleSkill['skill_key'] ?? '') === 'ecommerce_detail_page') {
            return self::buildDbSkillRoute($tenantId, $userId, $ruleSkill, $content, $context, $pendingContext, [
                'matched' => true,
                'confidence' => 0.9,
                'reason' => 'ecommerce_rule',
            ]);
        }
        $routerJson = [];
        try {
            $result = AigcCanvasService::llmText($tenantId, $userId, [
                'content' => self::skillRouterPrompt($content, $context, $skills, $pendingContext),
                'system_prompt' => self::skillRouterSystemPrompt(),
                'max_tokens' => 1200,
                'source_app_code' => AigcCanvasService::APP_CODE,
                'source_type' => 'skill_router',
            ]);
            $routerJson = self::parseRouterJson((string)($result['content'] ?? ''));
        } catch (Exception) {
            $routerJson = [];
        }

        $skillKey = (string)($routerJson['skill_key'] ?? '');
        $confidence = (float)($routerJson['confidence'] ?? 0);
        $matched = in_array($routerJson['matched'] ?? false, [true, 1, '1', 'true'], true);
        if ($matched && $skillKey !== '' && $confidence >= 0.72) {
            foreach ($skills as $skill) {
                if (
                    (string)($skill['skill_key'] ?? '') === $skillKey
                    && self::isRouterSkillCandidatePlausible($skill, $content, $context)
                ) {
                    return self::buildDbSkillRoute($tenantId, $userId, $skill, $content, $context, $pendingContext, $routerJson);
                }
            }
        }

        $fallbackSkill = self::matchSkillByRules($skills, $content, $context);
        return !empty($fallbackSkill)
            ? self::buildDbSkillRoute($tenantId, $userId, $fallbackSkill, $content, $context, $pendingContext, [
                'matched' => true,
                'confidence' => 0.5,
                'reason' => 'rule_fallback',
            ])
            : [];
    }

    private static function buildDbSkillRoute(int $tenantId, int $userId, array $skill, string $content, array $context, array $pendingContext = [], array $routerJson = []): array
    {
        $skill = AigcCanvasSkillService::formatSkill($skill, true);
        $pendingSlots = is_array($pendingContext['slots'] ?? null) ? $pendingContext['slots'] : [];
        $routerSlots = is_array($routerJson['slots'] ?? null) ? $routerJson['slots'] : [];
        $slots = array_merge($pendingSlots, $routerSlots, self::extractSlotsByRules($skill, $content, $context, $pendingContext));
        $slots = self::normalizeSlotsForSkill($skill, $slots);
        $defaults = is_array($skill['defaults_json'] ?? null) ? $skill['defaults_json'] : [];
        $effectiveSlots = array_merge($defaults, $slots);
        $skillKey = (string)($skill['skill_key'] ?? '');
        $isEcommerce = $skillKey === 'ecommerce_detail_page';
        if ($isEcommerce) {
            $savedSections = EcommerceDetailSectionPlanner::normalizeSections((array)($pendingContext['detail_sections'] ?? $pendingSlots['detail_sections'] ?? []));
            $isConfirmation = self::isExecutionConfirmation($content);
            $originalRequest = trim((string)($pendingContext['original_request'] ?? ''));
            $planningContent = !$isConfirmation && $originalRequest !== ''
                ? $originalRequest . "\n\n用户补充或修改：\n" . $content
                : $content;
            $referenceImages = self::referenceImagesFromReferences(array_values(array_merge(
                (array)($pendingContext['uploaded_references'] ?? []),
                (array)($context['uploaded_references'] ?? [])
            )));
            $missingBeforePlanning = self::missingRequiredSlots($skill, $slots, $context);
            $plan = !empty($savedSections) && $isConfirmation
                ? [
                    'section_count' => count($savedSections),
                    'recommended_section_count' => count($savedSections),
                    'count_reason' => (string)($pendingContext['count_reason'] ?? 'pending_confirmation'),
                    'detail_sections' => $savedSections,
                    'design_analysis' => is_array($pendingSlots['design_analysis'] ?? null) ? $pendingSlots['design_analysis'] : [],
                    'analysis_source' => (string)($pendingSlots['analysis_source'] ?? 'pending_confirmation'),
                ]
                : ($missingBeforePlanning === []
                    ? EcommerceDetailSectionPlanner::resolve($tenantId, $userId, $planningContent, $effectiveSlots, $referenceImages)
                    : []);
            $effectiveSlots = array_merge($effectiveSlots, $plan);
            $slots = array_merge($slots, $plan);
        }
        $missing = self::missingRequiredSlots($skill, $slots, $context);
        $skillType = (string)($skill['skill_type'] ?? '');
        $intent = $skillType === 'agent_workflow'
            ? 'skill_execution'
            : (string)($routerJson['intent'] ?? self::inferIntentFromSkill($skill));
        // The persisted pending context already contains the confirmed plan. A later
        // confirmation must advance into skill execution instead of reopening the same plan.
        $needsConfirmation = !$isConfirmation
            && $isEcommerce
            && empty($missing)
            && (int)($effectiveSlots['section_count'] ?? 0) > 0;
        $nextAction = !empty($missing)
            ? 'clarify'
            : ($needsConfirmation
                ? 'confirm_execution'
            : ($skillType === 'agent_workflow'
                ? 'skill_execution'
                : (self::toolIntentIsText($intent, $skill) ? 'chat' : 'execute_tool')));
        $pending = (!empty($missing) || $needsConfirmation) ? [
            'skill_key' => $skillKey,
            'skill_id' => (int)($skill['id'] ?? 0),
            'intent' => $intent,
            'slots' => $effectiveSlots,
            'missing_slots' => $missing,
            'confirmation_required' => $needsConfirmation,
            'detail_sections' => (array)($effectiveSlots['detail_sections'] ?? []),
            'section_count' => (int)($effectiveSlots['section_count'] ?? 0),
            'count_reason' => (string)($effectiveSlots['count_reason'] ?? ''),
            'original_request' => trim((string)($pendingContext['original_request'] ?? '')) ?: $content,
            'uploaded_references' => array_values(array_merge(
                (array)($pendingContext['uploaded_references'] ?? []),
                (array)($context['uploaded_references'] ?? [])
            )),
        ] : [];

        return [
            'matched' => true,
            'db_skill' => $skill,
            'skill_code' => $skillKey,
            'skill_key' => $skillKey,
            'intent' => $intent,
            'confidence' => (float)($routerJson['confidence'] ?? 1),
            'reason' => (string)($routerJson['reason'] ?? ''),
            'slots' => $effectiveSlots,
            'raw_slots' => $slots,
            'missing_slots' => $missing,
            'next_action' => $nextAction,
            'clarify_question' => !empty($missing) ? self::resolveClarifyQuestion($tenantId, $userId, $skill, $missing, $content, $effectiveSlots, $context, $pendingContext, $routerJson) : '',
            'confirmation_message' => $needsConfirmation ? self::ecommerceConfirmationMessage($effectiveSlots, $context) : '',
            'pending_skill_context' => $pending,
            'uploaded_references' => array_values(array_merge(
                (array)($pendingContext['uploaded_references'] ?? []),
                (array)($context['uploaded_references'] ?? [])
            )),
        ];
    }

    private static function normalizePendingSkillContext($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        return [
            'skill_key' => (string)($value['skill_key'] ?? ''),
            'skill_id' => (int)($value['skill_id'] ?? 0),
            'intent' => (string)($value['intent'] ?? ''),
            'slots' => is_array($value['slots'] ?? null) ? $value['slots'] : [],
            'missing_slots' => is_array($value['missing_slots'] ?? null) ? $value['missing_slots'] : [],
            'confirmation_required' => !empty($value['confirmation_required']),
            'detail_sections' => is_array($value['detail_sections'] ?? null) ? $value['detail_sections'] : [],
            'section_count' => (int)($value['section_count'] ?? 0),
            'count_reason' => (string)($value['count_reason'] ?? ''),
            'original_request' => (string)($value['original_request'] ?? ''),
            'uploaded_references' => is_array($value['uploaded_references'] ?? null) ? $value['uploaded_references'] : [],
        ];
    }

    private static function clarificationResult(array $route): array
    {
        return [
            'reply' => (string)($route['clarify_question'] ?? '请补充必要信息后我再继续。'),
            'tool_calls' => [],
            'workspace_actions' => [],
            'assets' => [],
            'next_action' => 'clarify',
        ];
    }

    private static function confirmationResult(array $route): array
    {
        return [
            'reply' => (string)($route['confirmation_message'] ?? '请确认生成方案后，我再开始提交任务。'),
            'tool_calls' => [],
            'workspace_actions' => [],
            'assets' => [],
            'next_action' => 'confirm_execution',
        ];
    }

    private static function localReplyResult(array $route): array
    {
        return [
            'reply' => (string)($route['reply'] ?? '你好！我是无限画布的 AI Design Agent，请问有什么可以帮你？'),
            'tool_calls' => [],
            'workspace_actions' => [],
            'assets' => [],
            'next_action' => 'chat',
        ];
    }

    private static function legacyReasoningSummary(array $route): string
    {
        $skill = trim((string)($route['skill_name'] ?? $route['skill_key'] ?? $route['skill_code'] ?? ''));
        $intent = trim((string)($route['intent'] ?? ''));
        if ($skill !== '') {
            return '已匹配 ' . $skill . '，正在整理可执行方案';
        }
        return $intent !== '' ? '已识别为' . $intent . '任务' : '已整理当前任务的执行方向';
    }

    private static function emitRouteProtocolEvents(int $tenantId, int $runId, ?callable $emit, array $route, array $routeMeta): void
    {
        $intent = [
            'intent' => (string)($route['intent'] ?? ''),
            'confidence' => (float)($route['confidence'] ?? 0),
            'reason' => (string)($route['reason'] ?? ''),
            'next_action' => (string)($route['next_action'] ?? ''),
            'request_id' => (string)($route['request_id'] ?? ''),
            'run_id' => $runId,
        ];
        self::emit($emit, 'agent.intent.detected', $intent);
        self::traceRouteStep($tenantId, $runId, 'intent_classifier', 'intent_detected', ['route' => $routeMeta], $intent);

        $skill = [
            'matched' => !empty($route['matched']),
            'skill_key' => (string)($route['skill_key'] ?? $route['skill_code'] ?? ''),
            'skill_type' => (string)($route['db_skill']['skill_type'] ?? ''),
            'confidence' => (float)($route['confidence'] ?? 0),
            'reason' => (string)($route['reason'] ?? ''),
            'request_id' => (string)($route['request_id'] ?? ''),
            'run_id' => $runId,
        ];
        self::emit($emit, 'agent.skill.retrieved', $skill);
        self::traceRouteStep($tenantId, $runId, 'skill_retriever', 'skill_retrieved', ['route' => $routeMeta], $skill);

        $slot = [
            'slots' => is_array($route['slots'] ?? null) ? $route['slots'] : [],
            'missing_slots' => is_array($route['missing_slots'] ?? null) ? $route['missing_slots'] : [],
            'request_id' => (string)($route['request_id'] ?? ''),
            'run_id' => $runId,
        ];
        self::emit($emit, 'agent.slot.extracted', $slot);
        self::traceRouteStep($tenantId, $runId, 'slot_filler', 'slot_extracted', ['route' => $routeMeta], $slot);

        if ((string)($route['next_action'] ?? '') === 'clarify') {
            $clarify = [
                'question' => (string)($route['clarify_question'] ?? ''),
                'missing_slots' => is_array($route['missing_slots'] ?? null) ? $route['missing_slots'] : [],
                'pending_skill_context' => is_array($route['pending_skill_context'] ?? null) ? $route['pending_skill_context'] : [],
                'request_id' => (string)($route['request_id'] ?? ''),
                'run_id' => $runId,
            ];
            self::emit($emit, 'agent.clarification.request', $clarify);
            self::traceRouteStep($tenantId, $runId, 'clarification_manager', 'clarification_request', ['route' => $routeMeta], $clarify);
        }

        $plan = [
            'next_action' => (string)($route['next_action'] ?? ''),
            'tool_policy' => is_array($route['db_skill']['tool_policy_json'] ?? null) ? $route['db_skill']['tool_policy_json'] : [],
            'estimated' => is_array($route['estimated'] ?? null) ? $route['estimated'] : [],
            'estimated_tools' => is_array($route['estimated_tools'] ?? null) ? $route['estimated_tools'] : [],
            'delivery_plan' => is_array($route['delivery_plan'] ?? null) ? $route['delivery_plan'] : [],
            'request_id' => (string)($route['request_id'] ?? ''),
            'run_id' => $runId,
        ];
        self::emit($emit, 'agent.plan.created', $plan);
        self::traceRouteStep($tenantId, $runId, 'agent_planner', 'plan_created', ['route' => $routeMeta], $plan);

        if (in_array((string)($route['next_action'] ?? ''), ['confirm_execution', 'confirm_initial_batch'], true)) {
            self::emit($emit, 'agent.plan.confirm_required', array_merge($plan, [
                'message' => (string)($route['confirmation_message'] ?? ''),
                'pending_skill_context' => is_array($route['pending_skill_context'] ?? null) ? $route['pending_skill_context'] : [],
            ]));
        }
    }

    private static function traceRouteStep(int $tenantId, int $runId, string $agentCode, string $stepType, array $input, array $output): void
    {
        if ($runId <= 0) {
            return;
        }
        try {
            AgentTraceLogger::step($tenantId, $runId, $agentCode, $stepType, $input, $output);
        } catch (Exception) {
            // Trace should never interrupt the user-facing Agent flow.
        }
    }

    private static function routeContentMeta(array $route): array
    {
        return [
            'next_action' => (string)($route['next_action'] ?? ''),
            'intent' => (string)($route['intent'] ?? ''),
            'skill_key' => (string)($route['skill_key'] ?? ''),
            'router_confidence' => (float)($route['confidence'] ?? 0),
            'slots' => is_array($route['slots'] ?? null) ? $route['slots'] : [],
            'missing_slots' => is_array($route['missing_slots'] ?? null) ? $route['missing_slots'] : [],
            'clarify_question' => (string)($route['clarify_question'] ?? ''),
            'confirmation_message' => (string)($route['confirmation_message'] ?? ''),
            'pending_skill_context' => is_array($route['pending_skill_context'] ?? null) ? $route['pending_skill_context'] : [],
            'uploaded_references' => is_array($route['uploaded_references'] ?? null) ? $route['uploaded_references'] : [],
            'request_id' => (string)($route['request_id'] ?? ''),
            'run_id' => (int)($route['agent_run_id'] ?? 0),
            'conversation_mode' => (string)($route['conversation_mode'] ?? ''),
            'operation' => (string)($route['operation'] ?? ''),
            'target_scope' => is_array($route['target_scope'] ?? null) ? $route['target_scope'] : [],
            'requested_changes' => is_array($route['changes'] ?? null) ? $route['changes'] : [],
            'media_config_patch' => is_array($route['media_config_patch'] ?? null) ? $route['media_config_patch'] : [],
            'preserved_constraints' => is_array($route['preserve'] ?? null) ? $route['preserve'] : [],
            'requires_tool' => !empty($route['requires_tool']),
            'decision_trace_summary' => (string)($route['decision_trace_summary'] ?? ''),
            'agent_process_steps' => self::routeProcessSteps($route),
        ];
    }

    private static function routeProcessSteps(array $route): array
    {
        $steps = [[
            'key' => 'intent',
            'label' => '已识别意图',
            'status' => 'success',
            'value' => self::intentLabel((string)($route['intent'] ?? $route['skill_code'] ?? 'chat')),
        ]];
        $skillKey = (string)($route['skill_key'] ?? $route['skill_code'] ?? '');
        if ($skillKey !== '') {
            $steps[] = [
                'key' => 'skill',
                'label' => '已匹配 Skill',
                'status' => !empty($route['matched']) ? 'success' : 'pending',
                'value' => (string)($route['db_skill']['name'] ?? $skillKey),
            ];
        }
        $slots = is_array($route['slots'] ?? null) ? $route['slots'] : [];
        $steps[] = [
            'key' => 'slots',
            'label' => '已抽取参数',
            'status' => 'success',
            'value' => empty($slots) ? '暂无明确参数' : self::slotSummary($slots),
        ];
        $missing = is_array($route['missing_slots'] ?? null) ? $route['missing_slots'] : [];
        if (!empty($missing)) {
            $steps[] = [
                'key' => 'clarify',
                'label' => '缺少信息',
                'status' => 'warning',
                'value' => implode('、', array_map('strval', $missing)),
            ];
        }
        $steps[] = [
            'key' => 'plan',
            'label' => '执行计划',
            'status' => in_array((string)($route['next_action'] ?? ''), ['clarify', 'confirm_execution'], true) ? 'pending' : 'success',
            'value' => self::nextActionLabel((string)($route['next_action'] ?? 'chat')),
        ];
        return $steps;
    }

    private static function slotSummary(array $slots): string
    {
        $parts = [];
        foreach ($slots as $key => $value) {
            if (is_array($value)) {
                $value = count($value) . '项';
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $parts[] = (string)$key . ' ' . mb_substr((string)$value, 0, 24, 'UTF-8');
            if (count($parts) >= 4) {
                break;
            }
        }
        return implode('、', $parts) ?: '暂无明确参数';
    }

    private static function intentLabel(string $intent): string
    {
        return [
            'chat' => '聊天答疑',
            'planning' => '内容策划',
            'copywriting' => '文案生成',
            'generate_text' => '文本生成',
            'generate_image' => '图片生成',
            'generate_video' => '视频生成',
            'generate_music' => '音乐生成',
            'canvas_design' => '画布设计',
            'canvas_edit' => '画布修改',
            'design_agent' => '画布设计',
            'skill_execution' => 'Skill 执行',
            'compound_plan_media' => '策划并生成媒体',
        ][$intent] ?? ($intent ?: '自动判断');
    }

    private static function nextActionLabel(string $nextAction): string
    {
        return [
            'clarify' => '等待补充信息',
            'confirm_execution' => '等待确认执行',
            'confirm_initial_batch' => '等待确认首批生成',
            'execute_tool' => '准备调用工具',
            'insert_canvas' => '准备写入画布',
            'insert_workflow' => '准备插入工作流',
            'skill_execution' => '准备执行 Skill',
            'chat' => '直接回复',
            'local_reply' => '直接回复',
        ][$nextAction] ?? ($nextAction ?: '待定');
    }

    private static function agentResultContentMeta(array $result): array
    {
        $keys = [
            'next_action',
            'batch_id',
            'batch',
            'design_analysis',
            'planned_sections',
            'delivery_plan',
            'delivery_groups',
            'total_count',
            'completed_count',
            'remaining_count',
            'revision_batch_id',
            'revision_of_batch_id',
            'decision_trace_summary',
        ];
        $meta = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $result)) {
                $meta[$key] = $result[$key];
            }
        }
        return $meta;
    }

    /**
     * Keeps the visible creative recap factual. Internal planning, generated copy,
     * model details, and low-confidence or unsupported claims stay out of chat.
     *
     * @return array<string, mixed>
     */
    private static function creativeSummary(array $result): array
    {
        $brief = (array)($result['task_decision']['creative_brief'] ?? []);
        return \app\common\service\app\aigc_canvas\agent\enrichment\CreativeBriefEnricher::userFacingSummary($brief);
    }

    private static function skillRouterSystemPrompt(): string
    {
        return 'You are a router for an infinite-canvas creation agent. Output only compact JSON. Do not write creative content.';
    }

    private static function skillRouterPrompt(string $content, array $context, array $skills, array $pendingContext = []): string
    {
        $items = array_map(static function (array $skill): array {
            return [
                'skill_key' => (string)($skill['skill_key'] ?? ''),
                'name' => (string)($skill['name'] ?? ''),
                'description' => (string)($skill['description'] ?? ''),
                'category' => (string)($skill['category'] ?? ''),
                'skill_type' => (string)($skill['skill_type'] ?? ''),
                'trigger_description' => (string)($skill['trigger_description'] ?? ''),
                'examples' => is_array($skill['examples_json'] ?? null) ? $skill['examples_json'] : [],
                'negative_examples' => is_array($skill['negative_examples_json'] ?? null) ? $skill['negative_examples_json'] : [],
                'required_slots' => is_array($skill['required_slots_json'] ?? null) ? $skill['required_slots_json'] : [],
                'optional_slots' => is_array($skill['optional_slots_json'] ?? null) ? $skill['optional_slots_json'] : [],
                'defaults' => is_array($skill['defaults_json'] ?? null) ? $skill['defaults_json'] : [],
                'tool_policy' => is_array($skill['tool_policy_json'] ?? null) ? $skill['tool_policy_json'] : [],
            ];
        }, $skills);

        return json_encode([
            'task' => 'match_skill_extract_slots',
            'priority' => 'manual skill is handled by server; here choose the best skill for natural language',
            'output_schema' => [
                'matched' => 'boolean; false when no listed skill clearly applies',
                'skill_key' => 'one skill_key from skills, or empty when matched=false',
                'intent' => 'generate_image|generate_video|generate_music|generate_text|insert_workflow|chat',
                'confidence' => '0-1',
                'slots' => new \stdClass(),
                'missing_slots' => [],
                'next_action' => 'clarify|execute_tool|insert_workflow|chat',
                'clarify_question' => '',
                'reason' => 'short reason',
            ],
            'rules' => [
                'A listed skill is optional. Never select a skill only because it is the sole available skill.',
                'For greetings, capability questions, normal conversation, planning, and copywriting without a matching listed skill, return matched=false and an empty skill_key.',
                'Do not default to image generation for planning, copywriting, analysis, or normal questions.',
                'If user asks for a plan, process, script, copywriting, or proposal, choose a text/planning skill.',
                'If core required information is missing, set next_action=clarify and ask at most 3 concise questions.',
                'When next_action=clarify, clarify_question must be tailored to user_request, extracted slots, and the matched skill.',
                'Do not ask for information that is already present in slots, defaults, pending_skill_context, or selected_elements.',
                'For image skills, mention what has already been understood, then ask only for the missing subject/product/reference/style details needed to generate.',
                'clarify_question must be natural Simplified Chinese, no JSON, no markdown table, no long form.',
                'Use canvas context only when selected_elements are present or the user explicitly references the canvas.',
            ],
            'skills' => $items,
            'pending_skill_context' => $pendingContext,
            'user_request' => $content,
            'context_used' => !empty($context['context_used']),
            'selected_elements_count' => count((array)($context['selected_elements'] ?? [])),
            'uploaded_references' => self::referenceSummaryForPrompt((array)($context['uploaded_references'] ?? [])),
            'brand_memory' => self::brandMemoryForPrompt($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function matchSkillByRules(array $skills, string $content, array $context): array
    {
        $text = mb_strtolower($content, 'UTF-8');
        $hasSelected = !empty($context['selected_elements']);
        $preferred = '';
        if ($hasSelected && self::containsAny($text, ['分析', '参考', '素材', '这张图', '选中', 'analysis', 'reference'])) {
            $preferred = 'asset_analysis';
        } elseif (self::containsAny($text, ['淘宝详情页', '淘宝详情图', '天猫详情页', '电商详情页', '电商详情图', '商品详情页', '商品详情图', '详情长图', 'detail page'])) {
            $preferred = 'ecommerce_detail_page';
        } elseif (self::containsAny($text, ['文案', '策划', '方案', '流程', '脚本', '发布会', '计划', 'copy', 'plan', 'proposal', 'script'])) {
            $preferred = 'script_planning';
        } elseif (self::containsAny($text, ['视频', '短片', '动画', '运镜', '镜头', 'video'])) {
            $preferred = 'video_generation';
        } elseif (self::containsAny($text, ['音乐', '音频', '配乐', '歌曲', 'bgm', 'audio', 'music'])) {
            $preferred = 'music_generation';
        } elseif (self::containsAny($text, ['图片', '图像', '海报', '插画', '照片', '封面', '视觉', '生图', '画', 'image', 'poster'])) {
            $preferred = 'general_image';
        } else {
            $preferred = 'general_chat';
        }
        foreach ($skills as $skill) {
            if ((string)($skill['skill_key'] ?? '') === $preferred) {
                return $skill;
            }
        }
        return [];
    }

    private static function isRouterSkillCandidatePlausible(array $skill, string $content, array $context): bool
    {
        if ((string)($skill['skill_key'] ?? '') !== 'ecommerce_detail_page') {
            return true;
        }
        $text = mb_strtolower($content, 'UTF-8');
        $explicitDetailRequest = self::containsAny($text, [
            '淘宝详情', '天猫详情', '电商详情', '商品详情', '产品详情', '详情长图',
            '详情页', '详情图', '商品长图', '整套商品视觉', 'detail page',
        ]);
        if (!$explicitDetailRequest) {
            return !empty($context['uploaded_references']) && self::containsAny($text, [
                '电商', '商品视觉', '产品视觉', '整套视觉',
            ]);
        }
        return true;
    }

    private static function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($text, mb_strtolower($keyword, 'UTF-8'))) {
                return true;
            }
        }
        return false;
    }

    private static function extractSlotsByRules(array $skill, string $content, array $context, array $pendingContext = []): array
    {
        $key = (string)($skill['skill_key'] ?? '');
        $slots = [];
        if (preg_match('/(\d+)\s*(张|个|份|段|秒)?/u', $content, $match)) {
            $number = (int)$match[1];
            if ($number > 0 && $number <= 20) {
                if (str_contains($match[2] ?? '', '秒')) {
                    $slots['duration'] = $number;
                } else {
                    $slots['quantity'] = $number;
                }
            }
        } elseif (self::containsAny($content, ['两张', '两份', '两个'])) {
            $slots['quantity'] = 2;
        }
        if (self::containsAny($content, ['淘宝', 'taobao'])) {
            $slots['platform'] = 'taobao';
        } elseif (self::containsAny($content, ['京东', 'jd'])) {
            $slots['platform'] = 'jd';
        } elseif (self::containsAny($content, ['小红书', 'rednote', 'xiaohongshu'])) {
            $slots['platform'] = 'xiaohongshu';
        }
        if (self::containsAny($content, ['详情图'])) {
            $slots['image_type'] = 'detail';
        } elseif (self::containsAny($content, ['主图'])) {
            $slots['image_type'] = 'main';
        } elseif (self::containsAny($content, ['海报'])) {
            $slots['image_type'] = 'poster';
        }
        if (!empty($context['selected_elements'])) {
            $slots['reference_asset'] = 'selected_canvas_element';
        }
        if ($key === 'ecommerce_detail_page' && self::looksLikeConcreteSubject($content, ['淘宝', '天猫', '详情页', '详情图', '商品图', '电商', '生成', '帮我', '做', '设计'])) {
            $slots['product_info'] = $content;
        }
        if ($key === 'ecommerce_detail_page') {
            if (preg_match('/(?:卖点|主打|优势|特点)[是为：:，,\s]*([^。！？\n]+)/u', $content, $match)) {
                $slots['selling_points'] = array_values(array_filter(
                    array_map('trim', preg_split('/[，,、;；]+/u', trim($match[1])) ?: []),
                    static fn(string $point): bool => $point !== '' && !preg_match('/(?:生成|制作|区块|详情页|详情图|\d+\s*张)/u', $point)
                ));
            } elseif (self::containsAny($content, ['降噪', '续航', '轻便', '防水', '保湿', '美白', '透气', '耐磨', '快充', '高颜值'])) {
                $slots['selling_points'] = $content;
            }
            if (preg_match('/(\d+)\s*(?:个|张|屏|段|区块)/u', $content, $match)) {
                $slots['section_count'] = max(1, (int)$match[1]);
            }
        }
        if ($key === 'general_image' && self::looksLikeConcreteSubject($content, ['生成', '帮我', '做', '一张', '两张', '图片', '图像', '海报', '插画'])) {
            $slots['visual_subject'] = $content;
        }
        if ($key === 'video_generation' && (self::looksLikeConcreteSubject($content, ['生成', '帮我', '做', '视频', '短片', '动画']) || !empty($context['selected_elements']))) {
            $slots['video_subject'] = $content;
        }
        if ($key === 'music_generation' && self::looksLikeConcreteSubject($content, ['生成', '帮我', '做', '音乐', '音频', '配乐', '歌曲'])) {
            $slots['music_subject'] = $content;
        }
        if (!empty($pendingContext['missing_slots']) && count($pendingContext['missing_slots']) === 1) {
            $missing = (string)$pendingContext['missing_slots'][0];
            if ($missing !== '' && !isset($slots[$missing]) && trim($content) !== '') {
                $slots[$missing] = $content;
            }
        }
        return $slots;
    }

    private static function normalizeSlotsForSkill(array $skill, array $slots): array
    {
        if ((string)($skill['skill_key'] ?? '') !== 'ecommerce_detail_page') {
            return $slots;
        }
        $productInfo = trim((string)($slots['product_info'] ?? ''));
        if ($productInfo !== '' && (!self::hasConcreteEcommerceProductInfo($productInfo) || self::isGenericEcommerceImageRequest($productInfo))) {
            unset($slots['product_info']);
        }
        return $slots;
    }

    private static function isGenericEcommerceImageRequest(string $content): bool
    {
        $text = mb_strtolower(trim($content), 'UTF-8');
        if ($text === '') {
            return true;
        }
        $text = preg_replace('/\d+\s*(张|个|份|套)?/u', '', $text) ?? $text;
        $genericWords = [
            '帮我',
            '请',
            '生成',
            '做',
            '制作',
            '创建',
            '设计',
            '一张',
            '两张',
            '二张',
            '三张',
            '四张',
            '几张',
            '电商',
            '淘宝',
            '天猫',
            '京东',
            '小红书',
            '详情图',
            '详情页',
            '详情长图',
            '主图',
            '商品图',
            '产品图',
            '卖点图',
            '海报',
            '图片',
            '图',
            'image',
            'poster',
            'product',
            'ecommerce',
        ];
        foreach ($genericWords as $word) {
            $text = str_replace($word, '', $text);
        }
        $text = preg_replace('/[，。、“”‘’！？；：,.!?;:\s]+/u', '', $text) ?? $text;
        return mb_strlen(trim($text), 'UTF-8') < 2;
    }

    private static function hasConcreteEcommerceProductInfo(string $content): bool
    {
        $text = trim($content);
        foreach ([
            '我想',
            '我要',
            '我需要',
            '帮我',
            '请',
            '生成',
            '制作',
            '创建',
            '做',
            '两张',
            '二张',
            '一张',
            '三张',
            '四张',
            '电商',
            '淘宝',
            '天猫',
            '京东',
            '小红书',
            '主图',
            '详情图',
            '详情页',
            '详情长图',
            '商品图',
            '产品图',
            '海报',
            '图片',
            '图',
        ] as $word) {
            $text = str_replace($word, '', $text);
        }
        $text = trim(preg_replace('/[，。,.！!？?\s\d一二两三四五六七八九十个张份]+/u', '', $text) ?? '');
        return mb_strlen($text, 'UTF-8') >= 2;
    }

    private static function looksLikeConcreteSubject(string $content, array $genericWords): bool
    {
        $text = trim($content);
        foreach ($genericWords as $word) {
            $text = str_replace($word, '', $text);
        }
        $text = trim(preg_replace('/[，。,.！!？?\s\d一二两三四五六七八九十个张份段秒]+/u', '', $text) ?? '');
        return mb_strlen($text, 'UTF-8') >= 2;
    }

    private static function missingRequiredSlots(array $skill, array $slots, array $context): array
    {
        $definitions = (array)($skill['required_slots_json'] ?? []);
        $required = self::slotKeys($definitions);
        $missing = [];
        foreach ($required as $slot) {
            if ($slot === 'reference_asset' && !empty($context['selected_elements'])) {
                continue;
            }
            $satisfiedByContext = false;
            foreach ($definitions as $definition) {
                if (!is_array($definition) || (string)($definition['key'] ?? $definition['name'] ?? '') !== $slot) {
                    continue;
                }
                foreach ((array)($definition['any_of_context'] ?? []) as $contextKey) {
                    if (!empty($context[(string)$contextKey])) {
                        $satisfiedByContext = true;
                        break 2;
                    }
                }
            }
            if ($satisfiedByContext) {
                continue;
            }
            $value = $slots[$slot] ?? null;
            if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                $missing[] = $slot;
            }
        }
        return array_slice($missing, 0, 3);
    }

    private static function slotKeys($slots): array
    {
        $result = [];
        foreach ((array)$slots as $key => $slot) {
            if (is_array($slot)) {
                $name = (string)($slot['key'] ?? $slot['name'] ?? '');
            } else {
                $name = is_string($key) ? (string)$key : (string)$slot;
            }
            $name = trim($name);
            if ($name !== '') {
                $result[] = $name;
            }
        }
        return array_values(array_unique($result));
    }

    private static function resolveClarifyQuestion(int $tenantId, int $userId, array $skill, array $missing, string $content, array $slots, array $context, array $pendingContext = [], array $routerJson = []): string
    {
        $contextualQuestion = self::contextualClarifyQuestion($skill, $missing, $slots, $context);
        if ((string)($skill['skill_key'] ?? '') === 'ecommerce_detail_page' && $contextualQuestion !== '') {
            return $contextualQuestion;
        }

        $routerQuestion = self::normalizeClarifyQuestion((string)($routerJson['clarify_question'] ?? ''));
        if (self::isUsefulClarifyQuestion($routerQuestion)) {
            return $routerQuestion;
        }

        try {
            $params = [
                'content' => self::clarifyQuestionPrompt($skill, $missing, $content, $slots, $context, $pendingContext),
                'system_prompt' => 'You write one contextual clarification for a Chinese infinite-canvas creation agent. Output only the user-facing clarification text.',
                'max_tokens' => 500,
                'source_app_code' => AigcCanvasService::APP_CODE,
                'source_type' => 'skill_clarification',
            ];
            $agentConfig = AigcCanvasService::agentConfig($tenantId);
            if (!empty($agentConfig['router_available']) && !empty($agentConfig['router_model_code'])) {
                $params['model_code'] = (string)$agentConfig['router_model_code'];
            }
            $result = AigcCanvasService::llmText($tenantId, $userId, $params);
            $question = self::normalizeClarifyQuestion((string)($result['content'] ?? ''));
            if (self::isUsefulClarifyQuestion($question)) {
                return $question;
            }
        } catch (Exception) {
            // Fall through to the deterministic policy question.
        }

        $contextualFallback = self::contextualClarifyQuestion($skill, $missing, $slots, $context);
        return $contextualFallback !== '' ? $contextualFallback : self::clarifyQuestion($skill, $missing);
    }

    private static function clarifyQuestionPrompt(array $skill, array $missing, string $content, array $slots, array $context, array $pendingContext = []): string
    {
        $policy = is_array($skill['clarification_policy_json'] ?? null) ? $skill['clarification_policy_json'] : [];
        return json_encode([
            'task' => 'write_contextual_clarification',
            'language' => 'Simplified Chinese',
            'rules' => [
                'Ask only for missing core information. Do not create a generation task.',
                'Mention what you already understood from the user request when helpful.',
                'Do not ask for values already present in extracted_slots, defaults, pending_skill_context, or selected_elements.',
                'Ask at most 3 short questions. Prefer one natural paragraph for simple cases.',
                'For ecommerce image requests, ask for product/category or product image first; optional selling points/style can be mentioned but not required.',
                'No JSON, no markdown table, no title, no code fence.',
            ],
            'skill' => [
                'skill_key' => (string)($skill['skill_key'] ?? ''),
                'name' => (string)($skill['name'] ?? ''),
                'description' => (string)($skill['description'] ?? ''),
                'required_slots' => is_array($skill['required_slots_json'] ?? null) ? $skill['required_slots_json'] : [],
                'optional_slots' => is_array($skill['optional_slots_json'] ?? null) ? $skill['optional_slots_json'] : [],
                'defaults' => is_array($skill['defaults_json'] ?? null) ? $skill['defaults_json'] : [],
                'clarification_policy' => $policy,
            ],
            'user_request' => $content,
            'extracted_slots' => $slots,
            'missing_slots' => array_values($missing),
            'pending_skill_context' => $pendingContext,
            'context_used' => !empty($context['context_used']),
            'selected_elements_count' => count((array)($context['selected_elements'] ?? [])),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function normalizeClarifyQuestion(string $question): string
    {
        $question = trim($question);
        $question = preg_replace('/^```(?:json|markdown)?\s*/i', '', $question) ?? $question;
        $question = preg_replace('/\s*```$/', '', $question) ?? $question;
        $question = trim($question);
        if (preg_match('/^\{.*\}$/s', $question) || preg_match('/^\[.*\]$/s', $question)) {
            $json = json_decode($question, true);
            if (is_array($json)) {
                $question = trim((string)($json['clarify_question'] ?? $json['question'] ?? ''));
            }
        }
        $question = trim(strip_tags($question));
        return mb_substr($question, 0, 500, 'UTF-8');
    }

    private static function isExecutionConfirmation(string $content): bool
    {
        $normalizedConfirmation = mb_strtolower(trim($content), 'UTF-8');
        if (in_array($normalizedConfirmation, ['确认无误', '确认提交', '立即生成', '马上生成'], true)) {
            return true;
        }
        $text = mb_strtolower(trim($content), 'UTF-8');
        return in_array($text, ['确认', '确认生成', '开始生成', '按这个生成', '可以', '执行', 'confirm'], true)
            || preg_match('/^(?:确认|可以|开始|按这个).{0,12}(?:生成|执行)?$/u', $text) === 1;
    }

    private static function ecommerceConfirmationMessage(array $slots, array $context = []): string
    {
        $sections = EcommerceDetailSectionPlanner::normalizeSections((array)($slots['detail_sections'] ?? []));
        $count = count($sections);
        $reason = (string)($slots['count_reason'] ?? 'agent_recommended');
        $reasonText = $reason === 'explicit_numbered_sections'
            ? '已按你提供的分段识别'
            : ($reason === 'explicit_requested_count' ? '已按你指定的数量规划' : '已根据商品信息自动规划');
        $lines = ["{$reasonText} {$count} 张独立详情图："];
        foreach ($sections as $section) {
            $index = (int)($section['section_index'] ?? 0);
            $title = (string)($section['title'] ?? '详情区块');
            $summary = mb_substr(trim((string)($section['purpose'] ?? $section['narrative'] ?? '')), 0, 54, 'UTF-8');
            $lines[] = "{$index}. {$title}" . ($summary !== '' ? "：{$summary}..." : '');
        }
        $referenceCount = count((array)($context['uploaded_references'] ?? []));
        if ($referenceCount > 0) {
            $lines[] = "参考素材：{$referenceCount} 个，将作为独立参考参数传入每个任务。";
        }
        $lines[] = '确认后开始生成，同时最多提交 5 张。请回复“确认生成”，或继续说明需要调整的内容。';
        return implode("\n", $lines);
    }

    private static function isUsefulClarifyQuestion(string $question): bool
    {
        if (mb_strlen(trim($question), 'UTF-8') < 6) {
            return false;
        }
        $lower = mb_strtolower($question, 'UTF-8');
        foreach (['clarify_question', 'missing_slots', 'json', '```'] as $noise) {
            if (str_contains($lower, $noise)) {
                return false;
            }
        }
        return true;
    }

    private static function contextualClarifyQuestion(array $skill, array $missing, array $slots, array $context = []): string
    {
        $key = (string)($skill['skill_key'] ?? '');
        if ($key === 'ecommerce_detail_page' && in_array('product_info', $missing, true)) {
            return self::ecommerceClarifyQuestion($slots, $context);
        }
        if ($key === 'ecommerce_detail_page' && in_array('selling_points', $missing, true)) {
            return '我已经收到商品信息。请再补充至少一个核心卖点，例如功能优势、材质特点、适用人群或使用效果；确认后我再生成详情页，不会提前提交任务。';
        }
        if ($key === 'general_image' && in_array('visual_subject', $missing, true)) {
            return '还差画面主体：你想生成什么内容？可以补充主体、场景、风格和用途，我再继续生成。';
        }
        if ($key === 'video_generation' && in_array('video_subject', $missing, true)) {
            return '还差视频内容：你想生成什么画面或动作？如果是图生视频，请选择或上传参考图。';
        }
        if ($key === 'music_generation' && in_array('music_subject', $missing, true)) {
            return '还差音乐方向：请补充用途、情绪、风格或参考类型，我再继续生成。';
        }
        return '';
    }

    private static function ecommerceClarifyQuestion(array $slots, array $context = []): string
    {
        $platform = self::labelForSlotValue('platform', (string)($slots['platform'] ?? 'taobao'));
        $quantity = max(1, (int)($slots['section_count'] ?? $slots['quantity'] ?? 5));
        $hasReferences = !empty($context['uploaded_references']) || !empty($context['selected_elements']);

        $intro = "我理解你想设计一套包含 {$quantity} 个区块的{$platform}商品详情页，但还缺少核心商品信息。请补充：";
        $referenceLine = $hasReferences
            ? '- 参考素材如何使用？我已收到参考图，也可以继续说明要保留的产品外观、角度或风格。'
            : '- 是否有参考图或风格要求？有商品图可以直接上传，也可以描述想要的视觉风格。';

        return implode("\n", [
            $intro,
            '',
            '- 产品是什么？例如蓝牙耳机、护肤品、服装、家居用品等。',
            '- 需要展示哪些内容？例如卖点、功能特点、细节特写、使用场景、规格说明或对比图。',
            $referenceLine,
            '- 如果有品牌色、目标人群或风格要求，也可以一起说明。',
            '',
            '你补充这些信息后，我再提交生成任务并自动插入画布。'
        ]);
    }

    private static function labelForSlotValue(string $slot, string $value): string
    {
        $labels = [
            'platform' => [
                'taobao' => '淘宝',
                'jd' => '京东',
                'xiaohongshu' => '小红书',
            ],
            'image_type' => [
                'main' => '主图',
                'detail' => '详情图',
                'poster' => '海报',
            ],
        ];
        return (string)($labels[$slot][$value] ?? $value);
    }

    private static function clarifyQuestion(array $skill, array $missing): string
    {
        $policy = is_array($skill['clarification_policy_json'] ?? null) ? $skill['clarification_policy_json'] : [];
        $questions = is_array($policy['questions'] ?? null) ? $policy['questions'] : [];
        $parts = [];
        foreach (array_slice($missing, 0, 3) as $slot) {
            if (!empty($questions[$slot])) {
                $parts[] = (string)$questions[$slot];
            } else {
                $parts[] = "请补充 {$slot}。";
            }
        }
        return implode("\n", array_values(array_unique($parts))) ?: '请补充必要信息后我再继续。';
    }

    private static function inferIntentFromSkill(array $skill): string
    {
        $policy = is_array($skill['tool_policy_json'] ?? null) ? $skill['tool_policy_json'] : [];
        $allowed = (array)($policy['allowed_tools'] ?? []);
        foreach (['generate_image', 'generate_video', 'generate_music', 'generate_text'] as $tool) {
            if (in_array($tool, $allowed, true)) {
                return $tool;
            }
        }
        return 'chat';
    }

    private static function toolIntentIsText(string $intent, array $skill): bool
    {
        if ($intent === 'generate_text' || $intent === 'chat') {
            return true;
        }
        $policy = is_array($skill['tool_policy_json'] ?? null) ? $skill['tool_policy_json'] : [];
        $allowed = (array)($policy['allowed_tools'] ?? []);
        return in_array('generate_text', $allowed, true) && !array_intersect($allowed, ['generate_image', 'generate_video', 'generate_music']);
    }

    private static function executeTool(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, string $toolCode, array $input, ?callable $emit): array
    {
        CanvasAgentToolExecutor::assertExecutable($tenantId, $toolCode, $input);
        $now = time();
        $requestId = trim((string)($input['request_id'] ?? 'message:' . $messageId));
        $idempotencyKey = hash('sha256', implode('|', [
            $tenantId,
            $userId,
            $requestId,
            $toolCode,
            (string)($input['target_element_id'] ?? ''),
            json_encode(self::sanitizeArray($input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
        $existing = AigcCanvasAgentToolCall::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'idempotency_key' => $idempotencyKey,
            'delete_time' => 0,
        ])->findOrEmpty();
        if (!$existing->isEmpty()) {
            if ((string)$existing['status'] === 'failed') {
                throw new Exception((string)($existing['error'] ?: '工具调用失败'));
            }
            $formatted = self::formatToolCall($existing->toArray());
            $formatted['reused'] = true;
            return $formatted;
        }
        $call = AigcCanvasAgentToolCall::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'request_id' => $requestId,
            'tool_code' => $toolCode,
            'idempotency_key' => $idempotencyKey,
            'status' => 'pending',
            'input_json' => self::sanitizeArray($input),
            'output_json' => [],
            'error' => '',
            'error_code' => '',
            'provider_task_id' => '',
            'retry_count' => 0,
            'started_at' => 0,
            'finished_at' => 0,
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);
        $call->save(['status' => 'running', 'started_at' => $now, 'update_time' => $now]);
        self::emit($emit, 'agent.tool.started', self::formatToolCall($call->toArray()));
        try {
            $execution = CanvasAgentToolExecutor::execute(
                $tenantId,
                $userId,
                $toolCode,
                $input,
                is_callable($emit)
                    ? function (string $event, array $data) use ($emit, $threadId, $messageId) {
                        if ($event === 'delta' && !empty($data['delta'])) {
                            self::emit($emit, 'agent.message.delta', [
                                'thread_id' => $threadId,
                                'message_id' => $messageId,
                                'delta' => (string)$data['delta'],
                            ]);
                        }
                    }
                    : null
            );
            $output = (array)($execution['output'] ?? []);
            $streamed = !empty($execution['streamed']);
            $providerTaskId = self::extractTaskId($output);
            $call->save([
                'status' => 'success',
                'output_json' => self::sanitizeArray($output),
                'provider_task_id' => $providerTaskId,
                'finished_at' => time(),
                'update_time' => time(),
            ]);
            $formatted = self::formatToolCall($call->toArray());
            $formatted['streamed'] = $streamed;
            self::emit($emit, 'agent.tool.completed', $formatted);
            self::emit($emit, 'agent.task.updated', $formatted);
            return $formatted;
        } catch (PromptSubmissionException $e) {
            $diagnostic = $e->diagnostic();
            $call->save([
                'status' => 'failed',
                'output_json' => self::sanitizeArray(['preflight' => $diagnostic]),
                'error_code' => (string)($diagnostic['provider_error_code'] ?? 'preflight_rejected'),
                'error' => $e->getMessage(),
                'finished_at' => time(),
                'update_time' => time(),
            ]);
            self::emit($emit, 'agent.tool.failed', self::formatToolCall($call->toArray()));
            throw $e;
        } catch (Exception $e) {
            $call->save(['status' => 'failed', 'error_code' => 'provider_error', 'error' => $e->getMessage(), 'finished_at' => time(), 'update_time' => time()]);
            self::emit($emit, 'agent.tool.failed', self::formatToolCall($call->toArray()));
            throw $e;
        }
    }

    public static function executeExternalTool(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, string $toolCode, array $input, ?callable $emit): array
    {
        return self::executeTool($tenantId, $userId, $projectId, $threadId, $messageId, $toolCode, $input, $emit);
    }

    public static function executeExternalToolWithActions(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, string $toolCode, array $input, string $prompt, array $context, ?callable $emit, int $maxActions = 0): array
    {
        $input = self::attachReferenceInputs($toolCode, $input, $context);
        $tool = self::executeTool($tenantId, $userId, $projectId, $threadId, $messageId, $toolCode, $input, $emit);
        $assets = self::extractAssets($toolCode, $tool['output']);
        if (empty($assets)) {
            $pendingAsset = self::extractPendingAsset($toolCode, $tool['output']);
            if (!empty($pendingAsset)) {
                $assets[] = $pendingAsset;
            }
        }
        if ($maxActions > 0) {
            $assets = array_slice($assets, 0, $maxActions);
        }
        $actions = [];
        $submittedInput = (array)($tool['output']['submitted_input'] ?? $tool['input'] ?? $input);
        $submittedPrompt = (string)($submittedInput['compiled_prompt'] ?? $submittedInput['prompt'] ?? $prompt);
        foreach ($assets as $asset) {
            $asset = self::enrichWorkspaceAsset($asset, $input);
            $actionType = $toolCode === 'generate_video'
                ? 'insert_video'
                : ($toolCode === 'generate_music' ? 'insert_audio' : 'insert_image');
            $actions[] = self::createWorkspaceAction($tenantId, $userId, $projectId, $threadId, $messageId, (int)$tool['id'], $actionType, $asset, $submittedPrompt, $context, $emit, $submittedInput);
        }
        self::emitGenerationProgress($emit, $tool, $actions, $assets);
        $hasResolvedAsset = !empty(array_filter($assets, fn($asset) => !empty($asset['url'])));
        $reply = empty($assets) ? '已提交生成任务。' : ($hasResolvedAsset ? '已生成结果。' : '已提交生成任务。');
        return [
            'reply' => $reply,
            'tool_calls' => [$tool],
            'workspace_actions' => $actions,
            'assets' => $assets,
            'next_action' => 'execute_tool',
        ];
    }

    /**
     * Keep media task lifecycle separate from the generic tool card so the
     * client can update image, video and music progress without inspecting
     * supplier-specific task payloads.
     */
    private static function emitGenerationProgress(?callable $emit, array $tool, array $actions, array $assets): void
    {
        $toolCode = (string)($tool['tool_code'] ?? '');
        if (!in_array($toolCode, ['generate_image', 'generate_video', 'generate_music'], true)) {
            return;
        }
        $output = is_array($tool['output'] ?? null) ? $tool['output'] : [];
        $status = (string)($output['status'] ?? 'running');
        self::emit($emit, 'agent.generation.progress', [
            'thread_id' => (int)($tool['thread_id'] ?? 0),
            'message_id' => (int)($tool['message_id'] ?? 0),
            'tool_call_id' => (int)($tool['id'] ?? 0),
            'tool_code' => $toolCode,
            'media_type' => str_replace('generate_', '', $toolCode),
            'status' => $status,
            'progress' => max(0, min(100, (int)($output['progress'] ?? (in_array($status, ['success', 'failed', 'canceled'], true) ? 100 : 0)))),
            'task_id' => self::extractTaskId($output),
            'action_ids' => array_values(array_filter(array_map(static fn(array $action): int => (int)($action['id'] ?? 0), $actions))),
            'asset_count' => count($assets),
        ]);
    }

    private static function createWorkspaceAction(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, int $toolCallId, string $actionType, array $asset, string $prompt, array $context, ?callable $emit, array $submittedInput = []): array
    {
        $now = time();
        $selected = $context['selected_elements'][0] ?? [];
        $submittedInput = self::sanitizeArray($submittedInput);
        $submittedPrompt = trim((string)($submittedInput['compiled_prompt'] ?? $submittedInput['prompt'] ?? $prompt));
        if ($submittedPrompt === '') {
            $submittedPrompt = $prompt;
        }
        $hasSubmissionSnapshot = trim((string)($submittedInput['compiled_prompt'] ?? '')) !== ''
            && trim((string)($submittedInput['prompt_hash'] ?? '')) !== ''
            && trim((string)($submittedInput['compiler_version'] ?? '')) !== '';
        $actionInput = [
            // Keep the exact tool payload with the canvas action. The client uses this
            // immutable snapshot when reopening a generated node instead of guessing
            // settings from the currently available model catalog.
            'submitted_input' => $submittedInput,
            'asset' => $asset,
            'prompt' => $submittedPrompt,
            'submitted_prompt' => $submittedPrompt,
            'compiled_prompt' => (string)($submittedInput['compiled_prompt'] ?? $submittedPrompt),
            'prompt_spec_json' => (array)($submittedInput['prompt_spec_json'] ?? []),
            'creative_spec_json' => (array)($submittedInput['creative_spec_json'] ?? []),
            'prompt_hash' => (string)($submittedInput['prompt_hash'] ?? ''),
            'compiler_version' => (string)($submittedInput['compiler_version'] ?? ''),
            'prompt_mode' => (string)($submittedInput['prompt_mode'] ?? ''),
            'original_user_request' => (string)($submittedInput['original_user_request'] ?? $submittedInput['user_request'] ?? ''),
            'prompt_enrichment' => (array)($submittedInput['prompt_enrichment'] ?? []),
            'submission_snapshot_status' => $hasSubmissionSnapshot ? 'submitted' : 'legacy_missing',
            'target_element_id' => (string)($asset['target_element_id'] ?? ''),
            'section_key' => (string)($asset['section_key'] ?? ''),
            'section_index' => (int)($asset['section_index'] ?? 0),
            'batch_id' => (int)($asset['batch_id'] ?? 0),
            'batch_kind' => (string)($asset['batch_kind'] ?? 'initial'),
            'parent_batch_id' => (int)($asset['parent_batch_id'] ?? 0),
            'revision_no' => (int)($asset['revision_no'] ?? 0),
            'source_node_id' => (string)($asset['source_node_id'] ?? ''),
            'source_target_element_id' => (string)($asset['source_target_element_id'] ?? ''),
            'source_section_key' => (string)($asset['source_section_key'] ?? ''),
            'selected_element_id' => (string)($selected['id'] ?? $selected['element_id'] ?? ''),
            'placement' => !empty($asset['source_node_id']) ? 'source_right' : 'viewport_right',
            'requires_confirmation' => false,
        ];
        // The workspace action must retain the actual provider submission.  The ORM's
        // JSON cast has previously persisted this field as an empty string during
        // create(), which leaves restored conversations without a task id or prompt.
        $storedInput = self::jsonString($actionInput);
        $actionId = (int)Db::name('aigc_canvas_agent_workspace_action')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'tool_call_id' => $toolCallId,
            'action_type' => $actionType,
            'status' => 'pending',
            'input_json' => $storedInput,
            'result_json' => '[]',
            'error' => '',
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);
        $formatted = self::formatWorkspaceAction([
            'id' => $actionId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'tool_call_id' => $toolCallId,
            'action_type' => $actionType,
            'status' => 'pending',
            'input_json' => $storedInput,
            'result_json' => '[]',
            'error' => '',
            'create_time' => $now,
        ]);
        self::emit($emit, 'agent.workspace.action_pending', $formatted);
        self::emit($emit, 'agent.canvas.patch', [
            'action_type' => $actionType,
            'workspace_action' => $formatted,
        ]);
        return $formatted;
    }

    private static function enrichWorkspaceAsset(array $asset, array $input): array
    {
        foreach ([
            'ratio', 'quality', 'resolution', 'duration', 'mode', 'channel', 'size',
            'target_element_id', 'section_key', 'section_index', 'batch_id', 'batch_kind',
            'parent_batch_id', 'revision_no', 'source_node_id', 'source_target_element_id',
            'source_section_key', 'design_width',
        ] as $key) {
            if (!isset($asset[$key]) && isset($input[$key]) && $input[$key] !== '') {
                $asset[$key] = $input[$key];
            }
        }
        foreach (['width', 'height'] as $key) {
            if (empty($asset[$key]) && !empty($input[$key])) {
                $asset[$key] = (int)$input[$key];
            }
        }
        return $asset;
    }

    private static function toolInput(string $toolCode, string $content, array $context, array $params, int $projectId, int $tenantId): array
    {
        $agentConfig = $params['agent_media_config'] ?? [];
        if (is_string($agentConfig)) {
            $decoded = json_decode($agentConfig, true);
            $agentConfig = is_array($decoded) ? $decoded : [];
        }
        $agentOptions = [];
        if ($toolCode === 'generate_image' && is_array($agentConfig['image'] ?? null)) {
            $agentOptions = $agentConfig['image'];
        } elseif ($toolCode === 'generate_video' && is_array($agentConfig['video'] ?? null)) {
            $agentOptions = $agentConfig['video'];
        } elseif ($toolCode === 'generate_music' && is_array($agentConfig['music'] ?? null)) {
            $agentOptions = $agentConfig['music'];
        }
        $input = [
            'prompt' => self::applyBrandMemoryToPrompt($content, $context),
            'project_id' => $projectId,
            'selected_mentions' => self::selectedMentions($context),
        ] + self::sanitizeArray($agentOptions);
        $references = array_merge(
            self::normalizeUploadedReferences($params, $tenantId),
            self::normalizeUploadedReferences([
                'reference_assets' => is_array($context['uploaded_references'] ?? null) ? $context['uploaded_references'] : [],
            ], $tenantId),
            self::normalizeSelectedReferences($context, $tenantId)
        );
        if (!empty($references)) {
            $input['reference_images'] = array_values(array_unique(array_merge(
                (array)($input['reference_images'] ?? []),
                self::referenceImagesFromReferences($references)
            )));
            $input['reference_assets'] = self::uniqueReferenceAssets(array_merge(
                (array)($input['reference_assets'] ?? []),
                self::referenceAssetsFromReferences($references)
            ));
        }
        foreach (['model', 'model_id', 'model_code', 'channel', 'quality', 'resolution', 'ratio', 'duration', 'quantity', 'size', 'market_product_id', 'market_sku_id', 'sku_id', 'market_input_sku_id', 'market_output_sku_id', 'market_input_sku_key', 'market_output_sku_key', 'price_source'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $input[$key] = $params[$key];
            }
        }
        if ($toolCode === 'generate_music') {
            $input['content'] = (string)$input['prompt'];
            $input['duration'] = (int)($input['duration'] ?? 30);
        }
        return $input;
    }

    private static function resolveSkillCode(int $tenantId, int $userId, array $params, string $content, array $context): string
    {
        $skill = (string)($params['skill_code'] ?? $params['skill'] ?? '');
        if (in_array($skill, ['creative_plan', 'generate_image', 'generate_video', 'generate_music'], true)) {
            return $skill;
        }
        if ($skill === '' || $skill === 'agent_auto') {
            $routed = self::resolveSkillCodeWithRouter($tenantId, $userId, $content, $context);
            if ($routed !== '') {
                return $routed;
            }
        }
        return self::resolveSkillCodeByRules($content);
    }

    private static function resolveDbSkill(int $tenantId, array $params): array
    {
        $skillKey = trim((string)($params['skill_key'] ?? ''));
        $skillId = (int)($params['skill_id'] ?? 0);
        if ($skillKey === '' && $skillId <= 0) {
            return [];
        }
        try {
            $skill = AigcCanvasSkillService::resolveSkill($tenantId, $skillKey, $skillId);
            return !empty($skill) && (int)($skill['status'] ?? 0) === 1 ? $skill : [];
        } catch (Exception) {
            return [];
        }
    }

    private static function resolveSkillCodeWithRouter(int $tenantId, int $userId, string $content, array $context): string
    {
        try {
            $agentConfig = AigcCanvasService::agentConfig($tenantId);
            if (empty($agentConfig['router_enabled']) || empty($agentConfig['router_available']) || empty($agentConfig['router_model_code'])) {
                return '';
            }
            $result = AigcCanvasService::llmText($tenantId, $userId, [
                'content' => self::agentRouterPrompt($content, $context),
                'model_code' => (string)$agentConfig['router_model_code'],
                'system_prompt' => self::agentRouterSystemPrompt(),
                'max_tokens' => 1024,
                'source_app_code' => AigcCanvasService::APP_CODE,
                'source_type' => 'agent_router',
            ]);
            $json = self::parseRouterJson((string)($result['content'] ?? ''));
            $intent = (string)($json['intent'] ?? '');
            $confidence = (float)($json['confidence'] ?? 0);
            if ($confidence < 0.55) {
                return '';
            }
            return in_array($intent, ['creative_plan', 'generate_image', 'generate_video', 'generate_music'], true)
                ? $intent
                : '';
        } catch (Exception) {
            return '';
        }
    }

    private static function resolveSkillCodeByRules(string $content): string
    {
        $lower = mb_strtolower($content, 'UTF-8');
        if (str_contains($lower, '视频') || str_contains($lower, 'video')) {
            return 'generate_video';
        }
        if (str_contains($lower, '音乐') || str_contains($lower, '音频') || str_contains($lower, '旁白') || str_contains($lower, 'audio') || str_contains($lower, 'music')) {
            return 'generate_music';
        }
        if (str_contains($lower, '方案') || str_contains($lower, '策划') || str_contains($lower, '文案') || str_contains($lower, 'plan')) {
            return 'creative_plan';
        }
        foreach (['图片', '图像', '海报', '插画', '照片', '封面', '视觉', '生图', '绘制', '设计', 'image', 'poster'] as $keyword) {
            if (str_contains($lower, $keyword)) {
                return 'generate_image';
            }
        }
        return 'creative_plan';
    }

    private static function agentRouterSystemPrompt(): string
    {
        return '你是无限画布 Agent 的任务路由器。只根据用户意图选择一个工具，不要生成创作内容。必须只输出 JSON，不要输出 Markdown。';
    }

    private static function agentRouterPrompt(string $content, array $context): string
    {
        $summary = mb_substr(\app\common\service\app\aigc_canvas\agent\memory\CanvasSnapshotBuilder::summaryText($context), 0, 400, 'UTF-8');
        $selected = [];
        foreach ((array)($context['selected_elements'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $selected[] = [
                'type' => (string)($item['type'] ?? ''),
                'title' => (string)($item['title'] ?? ''),
                'prompt' => mb_substr((string)($item['prompt'] ?? $item['content'] ?? ''), 0, 120, 'UTF-8'),
            ];
        }
        return json_encode([
            'task' => 'route_canvas_agent_intent',
            'allowed_intents' => ['creative_plan', 'generate_image', 'generate_video', 'generate_music'],
            'rules' => [
                'generate_image: 用户要生成/绘制/设计图片、海报、视觉、插画、照片或只给出视觉提示词',
                'generate_video: 用户要生成视频、短片、动画、镜头、分镜、运镜或把图片做成视频',
                'generate_music: 用户要生成音乐、歌曲、配乐、音频、旁白或歌词',
                'creative_plan: 用户要方案、分析、文案、策划、拆解、建议或普通对话',
            ],
            'output_schema' => [
                'intent' => 'creative_plan|generate_image|generate_video|generate_music',
                'confidence' => '0-1',
                'reason' => 'short Chinese reason',
            ],
            'user_request' => $content,
            'canvas_summary' => $summary,
            'selected_elements' => $selected,
            'uploaded_references' => self::referenceSummaryForPrompt((array)($context['uploaded_references'] ?? [])),
            'brand_memory' => self::brandMemoryForPrompt($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function parseRouterJson(string $content): array
    {
        $text = trim($content);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/s', $text, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private static function normalizeContext($context): array
    {
        if (!is_array($context)) {
            return [];
        }
        $summary = $context['canvas_summary'] ?? '';
        return [
            'project' => is_array($context['project'] ?? null) ? $context['project'] : [],
            'canvas_summary' => is_array($summary)
                ? $summary
                : mb_substr((string)$summary, 0, 600, 'UTF-8'),
            'elements' => array_slice(is_array($context['elements'] ?? null) ? $context['elements'] : [], 0, 120),
            'selection' => is_array($context['selection'] ?? null) ? $context['selection'] : [],
            'selected_ids' => array_slice(is_array($context['selected_ids'] ?? null) ? $context['selected_ids'] : [], 0, 24),
            'viewport' => is_array($context['viewport'] ?? null) ? $context['viewport'] : [],
            'visible_nodes' => array_slice(is_array($context['visible_nodes'] ?? null) ? $context['visible_nodes'] : [], 0, 60),
            'layer_tree' => array_slice(is_array($context['layer_tree'] ?? null) ? $context['layer_tree'] : [], 0, 120),
            'connections' => array_slice(is_array($context['connections'] ?? null) ? $context['connections'] : [], 0, 160),
            'selected_elements' => array_slice(is_array($context['selected_elements'] ?? null) ? $context['selected_elements'] : [], 0, 8),
            'recent_assets' => array_slice(is_array($context['recent_assets'] ?? null) ? $context['recent_assets'] : [], 0, 12),
            'brand_memory' => is_array($context['brand_memory'] ?? null) ? $context['brand_memory'] : [],
        ];
    }

    private static function attachReferenceInputs(string $toolCode, array $input, array $context): array
    {
        if (!in_array($toolCode, ['generate_image', 'generate_video', 'generate_music'], true)) {
            return $input;
        }
        $references = array_values(array_filter((array)($context['uploaded_references'] ?? []), 'is_array'));
        if ($references === []) {
            return $input;
        }
        $input['reference_images'] = array_values(array_unique(array_merge(
            (array)($input['reference_images'] ?? []),
            self::referenceImagesFromReferences($references)
        )));
        $input['reference_assets'] = self::uniqueReferenceAssets(array_merge(
            (array)($input['reference_assets'] ?? []),
            self::referenceAssetsFromReferences($references)
        ));
        if ($toolCode === 'generate_image' && !empty($input['reference_images'])) {
            $input['reference_identity_policy'] = [
                'source' => 'uploaded_reference',
                'preserve' => ['product_category', 'shape', 'proportions', 'colors', 'markings', 'logo_placement', 'visible_packaging_details'],
                'forbid' => ['replace_with_invented_product', 'alter_product_identity'],
            ];
        }
        return $input;
    }

    private static function contextForUserRequest(string $content, array $context): array
    {
        // The canvas is the Agent's workspace. Keep its snapshot for every turn when it
        // contains elements, rather than requiring the user to repeat a trigger keyword.
        if (!empty($context['elements']) || self::shouldUseCanvasContext($content, $context)) {
            $context['context_used'] = true;
            $context['selected_elements'] = self::selectedCanvasElementsOnly($context);
            return $context;
        }
        return [
            'project' => $context['project'] ?? [],
            'canvas_summary' => '',
            'elements' => [],
            'selection' => [],
            'selected_ids' => [],
            'viewport' => [],
            'visible_nodes' => [],
            'layer_tree' => [],
            'connections' => [],
            'selected_elements' => [],
            'recent_assets' => [],
            'brand_memory' => $context['brand_memory'] ?? [],
            'context_used' => false,
        ];
    }

    private static function shouldUseCanvasContext(string $content, array $context): bool
    {
        $selectedCanvasElements = self::selectedCanvasElementsOnly($context);
        if (!empty($selectedCanvasElements)) {
            return true;
        }
        $text = mb_strtolower($content, 'UTF-8');
        foreach ([
            '@',
            '当前画布',
            '画布',
            '节点',
            '选中',
            '已选',
            '这张图',
            '这个节点',
            '这些节点',
            '这段视频',
            '上面的图',
            '左边的图',
            '右边的图',
            '参考图',
            '参考视频',
            '根据当前',
            '基于当前',
            '根据画布',
            '基于画布',
            'canvas',
            'selected',
            'reference',
        ] as $keyword) {
            if ($keyword !== '' && str_contains($text, $keyword)) {
                return true;
            }
        }
        return false;
    }

    private static function selectedCanvasElementsOnly(array $context): array
    {
        return array_values(array_filter(
            (array)($context['selected_elements'] ?? []),
            static fn($item): bool => is_array($item) && !self::isUploadedReferenceElement($item)
        ));
    }

    private static function isUploadedReferenceElement(array $item): bool
    {
        $source = strtolower((string)($item['source'] ?? ''));
        if (in_array($source, ['agent_upload', 'local_upload', 'asset_library'], true)) {
            return true;
        }
        $role = strtolower((string)($item['role'] ?? ''));
        return in_array($role, ['reference_image', 'reference_video', 'reference_audio'], true)
            && trim((string)($item['url'] ?? $item['uri'] ?? $item['image'] ?? '')) !== '';
    }

    private static function selectedMentions(array $context): array
    {
        $mentions = [];
        foreach ((array)($context['selected_elements'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['url'] ?? $item['image'] ?? ''));
            $mentions[] = [
                'id' => (string)($item['id'] ?? ''),
                'node_id' => (string)($item['id'] ?? ''),
                'source' => 'canvas',
                'type' => in_array((string)($item['type'] ?? ''), ['image', 'video', 'audio', 'text'], true) ? (string)$item['type'] : 'text',
                'name' => (string)($item['name'] ?? $item['title'] ?? 'Canvas element'),
                'prompt' => (string)($item['prompt'] ?? $item['content'] ?? ''),
                'url' => $url,
                'role' => (string)($item['role'] ?? ''),
                'mime_type' => (string)($item['mime_type'] ?? ''),
                'asset_type' => (string)($item['asset_type'] ?? ''),
            ];
        }
        foreach ((array)($context['uploaded_references'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['url'] ?? $item['uri'] ?? ''));
            if ($url === '') {
                continue;
            }
            $type = strtolower((string)($item['type'] ?? 'asset'));
            if (!in_array($type, ['image', 'video', 'audio', 'asset'], true)) {
                $type = 'asset';
            }
            $mentions[] = [
                'id' => (string)($item['id'] ?? ''),
                'node_id' => (string)($item['node_id'] ?? ''),
                'source' => (string)($item['source'] ?? 'agent_upload'),
                'type' => $type,
                'name' => (string)($item['name'] ?? $item['title'] ?? 'Reference'),
                'prompt' => (string)($item['prompt'] ?? ''),
                'url' => $url,
                'role' => (string)($item['role'] ?? ($type === 'video' ? 'reference_video' : 'reference_image')),
                'mime_type' => (string)($item['mime_type'] ?? ''),
                'asset_type' => (string)($item['asset_type'] ?? $type),
            ];
        }
        return $mentions;
    }

    public static function selectedMentionsForSkill(array $context): array
    {
        return self::selectedMentions($context);
    }

    private static function brandMemoryForPrompt(array $context): string
    {
        $memory = is_array($context['brand_memory'] ?? null) ? $context['brand_memory'] : [];
        if ($memory === []) {
            $delivery = is_array($context['conversation_delivery_context'] ?? null)
                ? $context['conversation_delivery_context']
                : [];
            $projectMemory = is_array($delivery['project_memory'] ?? null) ? $delivery['project_memory'] : [];
            $brandProfile = is_array($projectMemory['brand_profile']['data'] ?? null)
                ? $projectMemory['brand_profile']['data']
                : [];
            return $brandProfile !== [] ? BrandMemoryService::promptContext($brandProfile) : '';
        }
        return trim((string)($memory['prompt_context'] ?? BrandMemoryService::promptContext((array)($memory['profile'] ?? []))));
    }

    private static function applyBrandMemoryToPrompt(string $prompt, array $context): string
    {
        $brand = self::brandMemoryForPrompt($context);
        if ($brand === '') {
            return $prompt;
        }
        return trim($prompt . "\n\n品牌记忆约束：\n" . $brand);
    }

    private static function creativePlanPrompt(string $content, array $context): string
    {
        $summary = trim(\app\common\service\app\aigc_canvas\agent\memory\CanvasSnapshotBuilder::summaryText($context));
        $content = self::applyBrandMemoryToPrompt($content, $context);
        $deliveryContext = is_array($context['conversation_delivery_context'] ?? null)
            ? $context['conversation_delivery_context']
            : [];
        if (!empty($deliveryContext['last_delivery']) || !empty($deliveryContext['active_batch'])) {
            $deliveryJson = json_encode(self::truncate($deliveryContext, 0), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return trim("你是无限画布的 AI Design Agent。请结合连续对话和最近交付准确回应本轮请求。\n"
                . "如果用户只要求分析、建议、策划、文案或脚本，只输出对应文本，不要声称已调用生成工具。\n"
                . "用户本轮请求：{$content}\n"
                . "连续对话与交付上下文：{$deliveryJson}");
        }
        $isPlanningRequest = self::containsAny(mb_strtolower($content, 'UTF-8'), [
            '策划', '方案', '流程', '计划', '提案', '脚本', '规划', 'plan', 'proposal', 'script',
        ]);
        if ($summary === '') {
            if (!$isPlanningRequest) {
                return trim("你是无限画布的 AI Design Agent。请直接、准确、简洁地回答用户当前问题。普通问答、能力询问和文案交流不要套用策划案模板，不要虚构或引用画布内容，也不要声称已经创建节点或生成媒体。\n用户问题：{$content}");
            }
            return trim("请作为创作策划助手，严格根据用户需求输出可执行方案，不要假设或引用当前画布里已有节点、素材或画面。\n用户需求：{$content}\n根据问题复杂度组织标题、步骤或清单，不要机械补齐无关章节。");
        }
        return trim("请作为无限画布 AI Design Agent，只基于用户明确引用的画布上下文回答。\n用户需求：{$content}\n明确引用的画布摘要：{$summary}\n直接解决用户问题；只有用户要求策划或规划时才输出结构化方案。");
    }

    private static function extractAssets(string $toolCode, array $output): array
    {
        $assets = [];
        $rows = (array)($output['results'] ?? $output['images'] ?? $output['videos'] ?? $output['audios'] ?? $output['music'] ?? []);
        if (empty($rows) && !empty($output['url'])) {
            $rows = [['url' => $output['url']]];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = (string)($row['url'] ?? $row['image_url'] ?? $row['video_url'] ?? $row['audio_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $type = $toolCode === 'generate_video' ? 'video' : ($toolCode === 'generate_music' ? 'audio' : 'image');
            $assets[] = [
                'id' => (int)($row['id'] ?? 0),
                'type' => $type,
                'url' => $url,
                'title' => (string)($row['title'] ?? ($type === 'image' ? 'Agent image' : ($type === 'video' ? 'Agent video' : 'Agent audio'))),
                'cover_url' => (string)($row['cover_url'] ?? ''),
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
                'duration' => (float)($row['duration'] ?? 0),
            ];
        }
        return $assets;
    }

    private static function extractPendingAsset(string $toolCode, array $output): array
    {
        $taskId = self::extractTaskId($output);
        if ($taskId === '') {
            return [];
        }
        $type = $toolCode === 'generate_video' ? 'video' : ($toolCode === 'generate_music' ? 'audio' : 'image');
        return [
            'id' => 0,
            'type' => $type,
            'url' => '',
            'task_id' => $taskId,
            'source_task_id' => (string)($output['source_task_id'] ?? $output['sourceTaskId'] ?? ''),
            'provider_task_id' => (string)($output['provider_task_id'] ?? $output['providerTaskId'] ?? ''),
            'title' => $type === 'image' ? 'Agent image' : ($type === 'video' ? 'Agent video' : 'Agent audio'),
            'cover_url' => '',
            'width' => 0,
            'height' => 0,
            'duration' => 0,
            'pending' => true,
        ];
    }

    private static function extractTaskId(array $output): string
    {
        foreach (['task_id', 'taskId', 'id', 'source_task_id', 'provider_task_id'] as $key) {
            $value = $output[$key] ?? null;
            if ($value !== null && $value !== '') {
                return (string)$value;
            }
        }
        $data = $output['data'] ?? [];
        if (is_array($data)) {
            foreach (['task_id', 'taskId', 'id', 'source_task_id', 'provider_task_id'] as $key) {
                $value = $data[$key] ?? null;
                if ($value !== null && $value !== '') {
                    return (string)$value;
                }
            }
        }
        return '';
    }

    private static function resolveThread(int $tenantId, int $userId, array $params, int $projectId): array
    {
        $threadId = (int)($params['thread_id'] ?? 0);
        if ($threadId > 0) {
            return self::assertThread($tenantId, $userId, $threadId);
        }
        return self::createThread($tenantId, $userId, [
            'project_id' => $projectId,
            'title' => mb_substr((string)($params['content'] ?? 'Canvas Agent'), 0, 32, 'UTF-8'),
        ]);
    }

    private static function assertProject(int $tenantId, int $userId, int $projectId): array
    {
        $project = AigcCanvasProject::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $projectId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($project->isEmpty()) {
            throw new Exception('Canvas project not found');
        }
        return $project->toArray();
    }

    private static function assertThread(int $tenantId, int $userId, int $threadId): array
    {
        $thread = self::threadQuery($tenantId, $userId, $threadId)->findOrEmpty();
        if ($thread->isEmpty()) {
            throw new Exception('Agent thread not found');
        }
        return self::formatThread($thread->toArray());
    }

    private static function threadQuery(int $tenantId, int $userId, int $threadId)
    {
        return AigcCanvasAgentThread::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $threadId,
            'delete_time' => 0,
        ]);
    }

    private static function threadTitle(string $current, string $content): string
    {
        if ($current !== '' && $current !== 'Canvas Agent') {
            return $current;
        }
        return mb_substr($content, 0, 36, 'UTF-8') ?: 'Canvas Agent';
    }

    public static function formatThread(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'summary' => (string)($row['summary'] ?? ''),
            'meta' => is_array($row['meta_json'] ?? null) ? $row['meta_json'] : [],
            'created_at' => (int)($row['create_time'] ?? 0),
            'updated_at' => (int)($row['update_time'] ?? 0),
        ];
    }

    public static function formatMessage(array $row): array
    {
        $contentJson = is_array($row['content_json'] ?? null) ? $row['content_json'] : [];
        $messageId = (int)($row['id'] ?? 0);
        if ($messageId > 0) {
            // Workspace actions are updated after the original message is saved. Hydrate
            // them from their audit rows so a restored thread never replays an applied action.
            $actions = AigcCanvasAgentWorkspaceAction::where([
                'message_id' => $messageId,
                'delete_time' => 0,
            ])->order('id', 'asc')->select()->toArray();
            if (!empty($actions)) {
                $contentJson['workspace_actions'] = array_map(
                    [self::class, 'formatWorkspaceAction'],
                    $actions
                );
            }
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'thread_id' => (int)($row['thread_id'] ?? 0),
            'role' => (string)($row['role'] ?? ''),
            'content' => (string)($row['content'] ?? ''),
            'content_json' => $contentJson,
            'status' => (string)($row['status'] ?? ''),
            'error' => (string)($row['error'] ?? ''),
            'created_at' => (int)($row['create_time'] ?? 0),
        ];
    }

    private static function formatToolCall(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'thread_id' => (int)($row['thread_id'] ?? 0),
            'message_id' => (int)($row['message_id'] ?? 0),
            'tool_code' => (string)($row['tool_code'] ?? ''),
            'request_id' => (string)($row['request_id'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'input' => self::jsonArray($row['input_json'] ?? []),
            'output' => self::jsonArray($row['output_json'] ?? []),
            'error' => (string)($row['error'] ?? ''),
            'idempotency_key' => (string)($row['idempotency_key'] ?? ''),
            'provider_task_id' => (string)($row['provider_task_id'] ?? ''),
            'retry_count' => (int)($row['retry_count'] ?? 0),
            'error_code' => (string)($row['error_code'] ?? ''),
            'started_at' => (int)($row['started_at'] ?? 0),
            'finished_at' => (int)($row['finished_at'] ?? 0),
            'created_at' => (int)($row['create_time'] ?? 0),
        ];
    }

    public static function formatWorkspaceAction(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'thread_id' => (int)($row['thread_id'] ?? 0),
            'message_id' => (int)($row['message_id'] ?? 0),
            'tool_call_id' => (int)($row['tool_call_id'] ?? 0),
            'action_type' => (string)($row['action_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'input' => self::jsonArray($row['input_json'] ?? []),
            'result' => self::jsonArray($row['result_json'] ?? []),
            'error' => (string)($row['error'] ?? ''),
            'created_at' => (int)($row['create_time'] ?? 0),
        ];
    }

    private static function jsonArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function jsonString(array $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return is_string($encoded) ? $encoded : '{}';
    }

    private static function extractInlineReferences(string $content): array
    {
        if (!preg_match_all('/\[@(image|video|audio):#?(\d+):([^:\]]*):(https?:\/\/[^\]]+)\]/ui', $content, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $references = [];
        foreach ($matches as $match) {
            $type = strtolower((string)($match[1] ?? 'image'));
            $url = trim((string)($match[4] ?? ''));
            if ($url === '') {
                continue;
            }
            $references[$type . '|' . $url] = [
                'id' => (string)($match[2] ?? ''),
                'type' => $type,
                'asset_type' => $type,
                'name' => trim((string)($match[3] ?? '')),
                'url' => $url,
                'uri' => $url,
                'source' => 'inline_mention',
                'role' => $type === 'image' ? 'reference_image' : 'reference_' . $type,
            ];
        }
        return array_values($references);
    }

    private static function normalizeUploadedReferences(array $params, int $tenantId = 0): array
    {
        $items = [];
        foreach (['attachments', 'uploaded_references', 'reference_assets'] as $key) {
            $value = $params[$key] ?? [];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }
            }
        }
        $referenceImages = $params['reference_images'] ?? $params['image_urls'] ?? [];
        if (is_string($referenceImages)) {
            $decoded = json_decode($referenceImages, true);
            $referenceImages = is_array($decoded) ? $decoded : [$referenceImages];
        }
        foreach ((array)$referenceImages as $image) {
            $url = is_array($image) ? (string)($image['url'] ?? $image['uri'] ?? '') : (string)$image;
            if (trim($url) !== '') {
                $items[] = ['type' => 'image', 'url' => $url, 'name' => is_array($image) ? (string)($image['name'] ?? '') : ''];
            }
        }

        $result = [];
        $seen = [];
        foreach ($items as $item) {
            $url = self::providerReferenceUrl(trim((string)($item['remoteUrl'] ?? $item['url'] ?? $item['uri'] ?? $item['path'] ?? '')), $tenantId);
            if ($url === '') {
                continue;
            }
            $type = self::referenceType($item, $url);
            if ($type === '') {
                continue;
            }
            $key = $type . '|' . $url;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = [
                'id' => (string)($item['id'] ?? ''),
                'type' => $type,
                'url' => $url,
                'uri' => $url,
                'name' => (string)($item['name'] ?? $item['title'] ?? ''),
                'source' => (string)($item['source'] ?? 'agent_upload'),
                'role' => (string)($item['role'] ?? ($type === 'video' ? 'reference_video' : ($type === 'audio' ? 'reference_audio' : 'reference_image'))),
                'mime_type' => (string)($item['mime_type'] ?? ''),
                'asset_type' => (string)($item['asset_type'] ?? $type),
            ];
        }
        return $result;
    }

    private static function normalizeSelectedReferences(array $context, int $tenantId = 0): array
    {
        $selected = array_merge(
            is_array($context['selected_elements'] ?? null) ? $context['selected_elements'] : [],
            is_array($context['selection']['elements'] ?? null) ? $context['selection']['elements'] : []
        );
        return self::normalizeUploadedReferences(['reference_assets' => $selected], $tenantId);
    }

    private static function providerReferenceUrl(string $url, int $tenantId = 0): string
    {
        if ($url === '' || preg_match('/^(https?:\/\/|data:image\/)/i', $url) === 1) {
            return $url;
        }
        $uri = ltrim($url, '/');
        if ($tenantId > 0) {
            try {
                $file = Db::name('tenant_file')->where(['tenant_id' => $tenantId, 'uri' => $uri])->order('id', 'desc')->find();
                if (!empty($file)) {
                    return FileService::getFileUrlByStorage($uri, (string)($file['storage_scope'] ?? ''), (string)($file['storage_engine'] ?? ''), (string)($file['storage_domain'] ?? ''));
                }
            } catch (\Throwable) {
                // Fall through to the active storage configuration.
            }
        }
        return FileService::getFileUrl($uri);
    }

    private static function referenceType(array $item, string $url): string
    {
        $raw = strtolower((string)($item['type'] ?? $item['asset_type'] ?? $item['mime_type'] ?? ''));
        if (str_contains($raw, 'video') || preg_match('/\.(mp4|webm|mov|m4v)(?:\?|$)/i', $url)) {
            return 'video';
        }
        if (str_contains($raw, 'audio') || preg_match('/\.(mp3|wav|m4a|aac|flac|ogg|opus|wma)(?:\?|$)/i', $url)) {
            return 'audio';
        }
        if (str_contains($raw, 'image') || preg_match('/\.(png|jpe?g|webp|gif|bmp)(?:\?|$)/i', $url) || $raw === '' || $raw === 'asset') {
            return 'image';
        }
        return '';
    }

    private static function referenceImagesFromReferences(array $references): array
    {
        $images = [];
        foreach ($references as $item) {
            if (!is_array($item) || (string)($item['type'] ?? '') !== 'image') {
                continue;
            }
            $url = trim((string)($item['url'] ?? $item['uri'] ?? ''));
            if ($url !== '') {
                $images[] = $url;
            }
        }
        return array_values(array_unique($images));
    }

    private static function referenceAssetsFromReferences(array $references): array
    {
        $assets = [];
        foreach ($references as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['url'] ?? $item['uri'] ?? ''));
            if ($url === '') {
                continue;
            }
            $assets[] = [
                'type' => (string)($item['type'] ?? 'image'),
                'uri' => $url,
                'url' => $url,
                'name' => (string)($item['name'] ?? ''),
            ];
        }
        return self::uniqueReferenceAssets($assets);
    }

    private static function uniqueReferenceAssets(array $assets): array
    {
        $result = [];
        $seen = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $type = trim((string)($asset['type'] ?? $asset['media_type'] ?? 'image'));
            $url = trim((string)($asset['uri'] ?? $asset['url'] ?? $asset['path'] ?? ''));
            if ($url === '') {
                continue;
            }
            $key = $type . '|' . $url;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = [
                'type' => $type,
                'uri' => $url,
                'url' => $url,
                'name' => (string)($asset['name'] ?? ''),
            ];
        }
        return $result;
    }

    private static function referenceSummaryForPrompt(array $references): array
    {
        return array_map(static function (array $item): array {
            return [
                'type' => (string)($item['type'] ?? ''),
                'name' => (string)($item['name'] ?? ''),
                'role' => (string)($item['role'] ?? ''),
                'url' => (string)($item['url'] ?? $item['uri'] ?? ''),
            ];
        }, array_slice(array_filter($references, 'is_array'), 0, 8));
    }

    private static function sanitizeArray($value): array
    {
        return is_array($value) ? self::truncate($value) : [];
    }

    private static function truncate($value, int $depth = 0)
    {
        if ($depth > 5) {
            return '[depth_limited]';
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = self::truncate($item, $depth + 1);
            }
            return $result;
        }
        if (is_string($value)) {
            if (str_starts_with($value, 'data:')) {
                return '[inline_data:' . strlen($value) . ' bytes]';
            }
            return mb_strlen($value, 'UTF-8') > 2000 ? mb_substr($value, 0, 2000, 'UTF-8') . '...' : $value;
        }
        return $value;
    }

    private static function emit(?callable $emit, string $event, array $data): void
    {
        if ($emit) {
            $emit($event, $data);
        }
    }

    private static function emitAssistantReplyDeltas(?callable $emit, int $threadId, int $messageId, string $reply): void
    {
        if (!$emit || trim($reply) === '') {
            return;
        }
        self::emit($emit, 'agent.message.delta', [
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'delta' => $reply,
        ]);
    }

    private static function ensureSchema(): void
    {
        return;
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_thread` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` int unsigned NOT NULL DEFAULT 0,
                `user_id` int unsigned NOT NULL DEFAULT 0,
                `project_id` int unsigned NOT NULL DEFAULT 0,
                `title` varchar(120) NOT NULL DEFAULT '',
                `status` varchar(30) NOT NULL DEFAULT 'active',
                `summary` text,
                `meta_json` longtext,
                `create_time` int unsigned NOT NULL DEFAULT 0,
                `update_time` int unsigned NOT NULL DEFAULT 0,
                `delete_time` int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_tenant_project` (`tenant_id`,`project_id`,`delete_time`),
                KEY `idx_tenant_user` (`tenant_id`,`user_id`,`delete_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent threads'");
            Db::execute("CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_message` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` int unsigned NOT NULL DEFAULT 0,
                `user_id` int unsigned NOT NULL DEFAULT 0,
                `project_id` int unsigned NOT NULL DEFAULT 0,
                `thread_id` int unsigned NOT NULL DEFAULT 0,
                `role` varchar(30) NOT NULL DEFAULT '',
                `content` longtext,
                `content_json` longtext,
                `status` varchar(30) NOT NULL DEFAULT 'success',
                `error` text,
                `meta_json` longtext,
                `create_time` int unsigned NOT NULL DEFAULT 0,
                `update_time` int unsigned NOT NULL DEFAULT 0,
                `delete_time` int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_thread` (`tenant_id`,`thread_id`,`delete_time`),
                KEY `idx_project` (`tenant_id`,`project_id`,`delete_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent messages'");
            Db::execute("CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_tool_call` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` int unsigned NOT NULL DEFAULT 0,
                `user_id` int unsigned NOT NULL DEFAULT 0,
                `project_id` int unsigned NOT NULL DEFAULT 0,
                `thread_id` int unsigned NOT NULL DEFAULT 0,
                `message_id` int unsigned NOT NULL DEFAULT 0,
                `request_id` varchar(96) NOT NULL DEFAULT '',
                `tool_code` varchar(80) NOT NULL DEFAULT '',
                `status` varchar(30) NOT NULL DEFAULT 'running',
                `input_json` longtext,
                `output_json` longtext,
                `error` text,
                `create_time` int unsigned NOT NULL DEFAULT 0,
                `update_time` int unsigned NOT NULL DEFAULT 0,
                `delete_time` int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_message` (`tenant_id`,`message_id`,`delete_time`),
                KEY `idx_request` (`tenant_id`,`request_id`,`delete_time`),
                KEY `idx_tool` (`tenant_id`,`tool_code`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent tool calls'");
            self::ensureAgentToolTraceColumns();
            Db::execute("CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_workspace_action` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `tenant_id` int unsigned NOT NULL DEFAULT 0,
                `user_id` int unsigned NOT NULL DEFAULT 0,
                `project_id` int unsigned NOT NULL DEFAULT 0,
                `thread_id` int unsigned NOT NULL DEFAULT 0,
                `message_id` int unsigned NOT NULL DEFAULT 0,
                `tool_call_id` int unsigned NOT NULL DEFAULT 0,
                `action_type` varchar(60) NOT NULL DEFAULT '',
                `status` varchar(30) NOT NULL DEFAULT 'pending',
                `input_json` longtext,
                `result_json` longtext,
                `error` text,
                `create_time` int unsigned NOT NULL DEFAULT 0,
                `update_time` int unsigned NOT NULL DEFAULT 0,
                `delete_time` int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_thread` (`tenant_id`,`thread_id`,`delete_time`),
                KEY `idx_project` (`tenant_id`,`project_id`,`delete_time`),
                KEY `idx_status` (`tenant_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas agent workspace actions'");
        } catch (Exception $e) {
            // The app migration creates these tables. Runtime creation is best-effort for upgraded installs.
        }
    }

    private static function ensureAgentToolTraceColumns(): void
    {
        try {
            $dbName = config('database.connections.mysql.database');
            $column = Db::query("SELECT COUNT(*) total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'la_aigc_canvas_agent_tool_call' AND COLUMN_NAME = 'request_id'", [$dbName]);
            if ((int)($column[0]['total'] ?? 0) === 0) {
                Db::execute("ALTER TABLE `la_aigc_canvas_agent_tool_call` ADD COLUMN `request_id` varchar(96) NOT NULL DEFAULT '' AFTER `message_id`");
            }
            $index = Db::query("SELECT COUNT(*) total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'la_aigc_canvas_agent_tool_call' AND INDEX_NAME = 'idx_request'", [$dbName]);
            if ((int)($index[0]['total'] ?? 0) === 0) {
                Db::execute("ALTER TABLE `la_aigc_canvas_agent_tool_call` ADD KEY `idx_request` (`tenant_id`,`request_id`,`delete_time`)");
            }
        } catch (Exception $e) {
        }
    }
}
