<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\AigcCanvasAgentOrchestrationPolicyService;
use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
use app\common\model\app\aigc_canvas\AigcCanvasAgentMessage;
use app\common\service\app\aigc_canvas\agent\contracts\CanvasProtocol;
use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\canvas\CanvasReferenceResolver;
use app\common\service\app\aigc_canvas\agent\canvas\CanvasContextReducer;
use app\common\service\app\aigc_canvas\agent\batch\EcommerceAgentBatchService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryItemContextBinder;
use app\common\service\app\aigc_canvas\agent\delivery\CreativeDeliveryGraphService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryGraphExecutor;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryItemTaskSyncService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryPlanService;
use app\common\service\app\aigc_canvas\agent\enrichment\CreativeBriefEnricher;
use app\common\service\app\aigc_canvas\agent\enrichment\ProductFactPolicy;
use app\common\service\app\aigc_canvas\agent\memory\ConversationMemoryBuilder;
use app\common\service\app\aigc_canvas\agent\memory\CanvasSnapshotBuilder;
use app\common\service\app\aigc_canvas\agent\memory\MemoryExtractor;
use app\common\service\app\aigc_canvas\agent\memory\MemoryRetriever;
use app\common\service\app\aigc_canvas\agent\memory\ProjectMemoryService;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use app\common\service\app\aigc_canvas\agent\planning\EcommerceDetailSectionPlanner;
use app\common\service\app\aigc_canvas\agent\planning\BatchDeliveryPlanner;
use app\common\service\app\aigc_canvas\agent\router\ClarificationManager;
use app\common\service\app\aigc_canvas\agent\tools\CanvasAgentToolRegistryService;
use app\common\service\app\aigc_canvas\agent\tools\CanvasCapabilityTool;
use app\common\service\app\aigc_canvas\agent\tools\AskUserTool;
use app\common\service\app\aigc_canvas\agent\tools\RetrieveSkillsTool;
use Exception;

/**
 * The default canvas Agent runtime. A model response may invoke tools, receive
 * their compact result, and make another decision before the turn completes.
 */
final class AgentLoopService
{
    private const MAX_TOOL_CALLS = 24;
    private const MAX_MODEL_STREAM_TIMEOUT_SECONDS = 180;
    private const TURN_TIMEOUT_SAFETY_SECONDS = 5;

    public static function run(array $state): array
    {
        $tenantId = (int)$state['tenant_id'];
        $userId = (int)$state['user_id'];
        $projectId = (int)$state['project_id'];
        $threadId = (int)$state['thread_id'];
        $messageId = (int)$state['message_id'];
        $requestId = (string)$state['request_id'];
        $parentRunId = (int)($state['run_id'] ?? 0);
        $request = trim((string)$state['content']);
        $context = is_array($state['context'] ?? null) ? $state['context'] : [];
        $selectedSkill = is_array($state['selected_skill'] ?? null) ? $state['selected_skill'] : [];
        $emit = is_callable($state['emit'] ?? null) ? $state['emit'] : null;
        $runtimePolicy = AigcCanvasAgentOrchestrationPolicyService::runtime($tenantId);
        $maxIterations = min(max(1, min(6, (int)($state['max_iterations'] ?? 6))), (int)$runtimePolicy['max_iterations']);
        $timeout = min(max(15, min(300, (int)($state['timeout_seconds'] ?? 180))), (int)$runtimePolicy['timeout_seconds']);
        $turnId = AgentTurnTraceService::start(
            $tenantId,
            $userId,
            $projectId,
            $threadId,
            $requestId,
            'agent_loop',
            [
                'content' => $request,
                'canvas_context' => CanvasSnapshotBuilder::compact($context),
            ],
            (int)($state['started_at_ms'] ?? 0),
            (int)($state['first_status_at_ms'] ?? 0)
        );
        $sequence = 0;
        $iterations = 0;
        $toolCount = 0;
        $reply = '';
        $replyStreamed = false;
        $toolCalls = [];
        $workspaceActions = [];
        $assets = [];
        $subtasks = [];
        $subtasksPending = false;
        $unverifiedToolResults = [];
        $startedAt = microtime(true);
        $memory = ConversationMemoryBuilder::build($tenantId, $userId, $threadId, $messageId);
        $context = self::inheritConversationReferences($context, $memory);
        $toolRoute = self::toolRoute($state, $requestId);
        $toolRoute['delivery_item_id'] = (int)($state['delivery_item_id'] ?? 0);
        $projectMemory = !array_key_exists('project_memory_enabled', $state) || !empty($state['project_memory_enabled'])
            ? MemoryRetriever::retrieve($tenantId, $userId, $projectId, 8, empty($state['new_conversation']))
            : [];
        if ($projectMemory !== []) {
            $context['project_memory'] = $projectMemory;
        }
        $canvas = CanvasContextReducer::reduce($context);
        $canvas['reference_resolution'] = CanvasReferenceResolver::resolve($request, $canvas);
        $skills = self::retrieveSkills($tenantId, $request, (int)$runtimePolicy['max_skill_candidates']);
        AgentTurnTraceService::event($turnId, ++$sequence, 'turn.started', ['canvas_nodes' => count((array)($canvas['elements'] ?? [])), 'skill_count' => count($skills)]);
        $taskDecision = (array)($state['task_decision'] ?? []);
        if ($taskDecision === []) {
            $taskDecision = AgentTaskDecisionService::decide($tenantId, $request, $context, $selectedSkill, $state);
        }
        $explicitDelegationTasks = self::explicitDelegationTasks($request);
        $explicitTextOnlyDelegation = self::isExplicitTextOnlyDelegation($request, $explicitDelegationTasks);
        if ($explicitTextOnlyDelegation) {
            self::applyExplicitTextDelegationDecision($taskDecision);
        }
        $textProfile = self::textProfile($request, $taskDecision);
        $preflight = self::preflightEnrichmentDecision($request, $context, $taskDecision);
        if ($preflight['run']) {
            self::emit($emit, 'agent.reasoning.step', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'stage' => 'visual_analysis',
                'status' => 'running',
                'label' => '正在分析参考素材',
                'value' => '识别商品外观与可用特征',
            ]);
            self::emit($emit, 'agent.enrichment.started', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'skill_key' => (string)($taskDecision['selected_skill_key'] ?? ''),
            ]);
            $enrichment = CreativeBriefEnricher::enrich($tenantId, $userId, $request, $context, (array)($taskDecision['skill_contract'] ?? []), ['project_id' => $projectId]);
        } else {
            $enrichment = self::deferredEnrichment($request, $context, (string)$preflight['reason']);
            self::emit($emit, 'agent.reasoning.step', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'stage' => 'visual_analysis',
                'status' => 'success',
                'label' => '参考素材按需读取',
                'value' => '已携带画布摘要，需要细节时再分析素材',
            ]);
            AgentTurnTraceService::event($turnId, ++$sequence, 'enrichment_deferred', [
                'skill_key' => (string)($taskDecision['selected_skill_key'] ?? ''),
                'reason' => (string)$preflight['reason'],
            ]);
        }
        self::emit($emit, (string)($enrichment['status'] ?? '') === 'failed' ? 'agent.enrichment.failed' : 'agent.enrichment.completed', [
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'status' => (string)($enrichment['status'] ?? 'skipped'),
            'reason' => (string)($enrichment['reason'] ?? ''),
            'insight_count' => count((array)($enrichment['asset_insights'] ?? [])),
        ]);
        $claims = (array)($enrichment['copy_plan']['claims'] ?? []);
        $approvedClaims = count(array_filter($claims, static fn(array $claim): bool => (string)($claim['status'] ?? '') === 'approved_claim'));
        $verificationClaims = count(array_filter($claims, static fn(array $claim): bool => (string)($claim['status'] ?? '') === 'needs_verification'));
        self::emit($emit, 'agent.reasoning.step', [
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'stage' => 'evidence_review',
            'status' => (string)($enrichment['status'] ?? '') === 'failed' ? 'warning' : 'success',
            'label' => '已整理商品证据',
            'value' => sprintf(
                '%d 条可用证据，%d 条已采纳卖点%s',
                count((array)($enrichment['context']['creative_context']['evidence_catalog'] ?? [])),
                $approvedClaims,
                $verificationClaims > 0 ? sprintf('，%d 条待补充资料', $verificationClaims) : ''
            ),
        ]);
        AgentTurnTraceService::event($turnId, ++$sequence, $preflight['run'] ? 'enrichment_requested' : 'enrichment_completed', [
            'skill_key' => (string)($taskDecision['selected_skill_key'] ?? ''),
            'status' => (string)($enrichment['status'] ?? 'skipped'),
            'reason' => (string)($enrichment['reason'] ?? ''),
        ]);
        if (($enrichment['status'] ?? 'skipped') !== 'skipped') {
            $context = (array)($enrichment['context'] ?? $context);
            $canvas = CanvasContextReducer::reduce($context);
            $canvas['reference_resolution'] = CanvasReferenceResolver::resolve($request, $canvas);
            if (!empty($state['delivery_item'])) {
                $state['delivery_item'] = DeliveryItemContextBinder::mergeEnrichment(
                    $tenantId,
                    $userId,
                    (array)$state['delivery_item'],
                    $enrichment
                );
            }
            $insights = (array)($enrichment['asset_insights'] ?? []);
            AgentTurnTraceService::event($turnId, ++$sequence, $insights === [] ? 'asset_insight_failed' : 'asset_insight_ready', [
                'status' => (string)($enrichment['status'] ?? ''),
                'insight_count' => count($insights),
                'reason' => (string)($enrichment['reason'] ?? ''),
                'errors' => (array)($enrichment['errors'] ?? []),
            ]);
            AgentTurnTraceService::event($turnId, ++$sequence, 'fact_source_distribution', (array)($enrichment['fact_source_distribution'] ?? []));
            if (!empty($enrichment['copy_plan'])) {
                AgentTurnTraceService::event($turnId, ++$sequence, 'copy_plan_ready', ['claim_sources' => (array)($enrichment['copy_plan']['claim_sources'] ?? [])]);
            }
        }
        $taskDecision['creative_brief'] = self::creativeBriefSummary($enrichment);
        $creativeSummary = CreativeBriefEnricher::userFacingSummary((array)$taskDecision['creative_brief']);
        if ($creativeSummary !== []) {
            self::emit($emit, 'agent.creative_summary', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'creative_summary' => $creativeSummary,
            ]);
        }
        self::applyEvidenceRequirement($taskDecision, (array)($enrichment['conditional_claim_categories'] ?? []));
        foreach (MemoryExtractor::confirmedDecision($request, $taskDecision, $context) as $memoryItem) {
            ProjectMemoryService::remember(
                $tenantId,
                $userId,
                $projectId,
                (string)$memoryItem['type'],
                (string)$memoryItem['key'],
                (array)$memoryItem['data'],
                ['thread_id' => $threadId, 'message_id' => $messageId, 'source_type' => 'confirmed_agent_decision']
            );
        }
        $skillContract = (array)($taskDecision['skill_contract'] ?? []);
        $runtimeAllowedTools = (array)($taskDecision['runtime_allowed_tools'] ?? []);
        $isStrictContract = in_array((string)($taskDecision['binding_mode'] ?? 'none'), ['contract', 'generic_contract'], true) && $skillContract !== [];
        $modelDecision = $taskDecision;
        unset($modelDecision['skill_contract'], $modelDecision['runtime_allowed_tools']);
        $advisorySkill = !$isStrictContract && $skillContract !== []
            ? [
                'skill_key' => (string)($skillContract['skill_key'] ?? ''),
                'name' => (string)($skillContract['name'] ?? ''),
                'defaults' => (array)($skillContract['defaults'] ?? []),
                'capability_tools' => (array)($skillContract['capability_tools'] ?? []),
            ]
            : [];
        if ($isStrictContract) {
            $maxIterations = min($maxIterations, (int)$skillContract['max_iterations']);
        }
        $toolLimit = min(
            self::MAX_TOOL_CALLS,
            (int)$runtimePolicy['max_tool_calls'],
            $isStrictContract ? (int)$skillContract['max_tool_calls'] : self::MAX_TOOL_CALLS
        );
        $tools = self::toolSchemas($tenantId, $request, $canvas, $skillContract, $runtimeAllowedTools);
        $messages = [['role' => 'user', 'content' => $request]];
        self::emit($emit, 'agent.turn.status', ['thread_id' => $threadId, 'message_id' => $messageId, 'stage' => 'understanding', 'label' => '正在理解你的需求和画布']);
        AgentTurnTraceService::event($turnId, ++$sequence, 'turn.context_ready', ['canvas_nodes' => count((array)($canvas['elements'] ?? [])), 'skill_count' => count($skills), 'selected_skill_key' => (string)($skillContract['skill_key'] ?? '')]);
        self::emit($emit, 'agent.decision.resolved', $taskDecision + ['thread_id' => $threadId, 'message_id' => $messageId]);
        AgentTurnTraceService::event($turnId, ++$sequence, 'task.decision', [
            'intent' => (string)($taskDecision['intent'] ?? ''),
            'binding_mode' => (string)($taskDecision['binding_mode'] ?? 'none'),
            'skill_key' => (string)($taskDecision['selected_skill_key'] ?? ''),
            'missing_hard_slots' => (array)($taskDecision['missing_hard_slots'] ?? []),
            'default_sources' => (array)($taskDecision['default_sources'] ?? []),
            'upgrade_reasons' => (array)($taskDecision['upgrade_reasons'] ?? []),
        ]);
        AgentTurnTraceService::event($turnId, ++$sequence, 'text.profile', $textProfile);
        foreach ((array)($taskDecision['inferred_slots'] ?? []) as $slot => $source) {
            AgentTurnTraceService::event($turnId, ++$sequence, 'slot_promoted', ['slot' => (string)$slot, 'source' => (string)$source]);
        }
        $defaultSources = (array)($taskDecision['default_sources'] ?? []);
        foreach ((array)($taskDecision['default_slots'] ?? []) as $slot => $value) {
            AgentTurnTraceService::event($turnId, ++$sequence, 'slot_defaulted', ['slot' => (string)$slot, 'source' => (string)($defaultSources[$slot] ?? 'skill_default')]);
        }
        if ($skillContract !== []) {
            self::emit($emit, 'agent.skill.retrieved', ['skill_key' => $skillContract['skill_key'], 'explicit' => !empty($skillContract['explicit']), 'binding_mode' => (string)($taskDecision['binding_mode'] ?? 'none'), 'missing_slots' => $skillContract['missing_slots']]);
            AgentTurnTraceService::event($turnId, ++$sequence, 'skill.selected', ['skill_key' => $skillContract['skill_key'], 'version' => $skillContract['version'], 'missing_slots' => $skillContract['missing_slots'], 'binding_mode' => (string)($taskDecision['binding_mode'] ?? 'none')]);
            if (!empty($taskDecision['missing_hard_slots'])) {
                $clarificationQuestion = ClarificationManager::resolveQuestion(
                    $tenantId,
                    $userId,
                    [
                        'skill_key' => (string)($skillContract['skill_key'] ?? ''),
                        'name' => (string)($skillContract['name'] ?? ''),
                        'required_slots_json' => (array)($skillContract['required_slots'] ?? []),
                        'optional_slots_json' => (array)($skillContract['optional_slots'] ?? []),
                        'defaults_json' => (array)($skillContract['defaults'] ?? []),
                        'clarification_policy_json' => [],
                    ],
                    (array)$taskDecision['missing_hard_slots'],
                    $request,
                    array_merge(
                        (array)($taskDecision['default_slots'] ?? []),
                        (array)($taskDecision['inferred_slots'] ?? [])
                    ),
                    $context
                );
                $execution = AgentExecutionContext::from([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'thread_id' => $threadId,
                    'message_id' => $messageId,
                    'user_request' => $request,
                    'canvas_context' => $context,
                    'emit' => $emit,
                ]);
                $clarification = (new AskUserTool())->execute($execution, [
                    'question' => (string)($taskDecision['clarification_question'] ?? '')
                        ?: ($clarificationQuestion ?: (string)($skillContract['clarification_question'] ?? '')),
                    'missing_slots' => (array)($taskDecision['missing_hard_slots'] ?? $skillContract['missing_slots'] ?? []),
                    'reason' => 'required_input',
                ]);
                $question = (string)($clarification['tool_calls'][0]['output']['question'] ?? '请补充必要信息后我再继续。');
                self::emit($emit, 'agent.tool.completed', ['thread_id' => $threadId, 'message_id' => $messageId, 'tool_code' => 'ask_user', 'status' => 'success']);
                AgentTurnTraceService::event($turnId, ++$sequence, 'clarification.requested', ['missing_slots' => $skillContract['missing_slots']]);
                AgentTurnTraceService::event($turnId, ++$sequence, 'slot_asked', ['slot' => (string)(($taskDecision['missing_hard_slots'] ?? [])[0] ?? ''), 'reason' => 'selected_skill_required_slots']);
                $result = [
                    'reply' => $question,
                    'reply_streamed' => false,
                    'tool_calls' => (array)($clarification['tool_calls'] ?? []),
                    'workspace_actions' => [],
                    'assets' => [],
                    'next_action' => 'clarify',
                    'agent_trace' => ['execution_mode' => 'agent_loop', 'iterations' => 0, 'tool_calls' => 1, 'turn_id' => $turnId, 'task_decision' => $taskDecision],
                    'turn_id' => $turnId,
                    'iterations' => 0,
                    'skills' => $skills,
                    'selected_skill' => $skillContract,
                    'task_decision' => $taskDecision,
                    'estimated_tools' => (array)($taskDecision['estimated_tools'] ?? []),
                    'pending_skill_context' => [],
                    'subtasks' => [],
                    'subtasks_pending' => false,
                ];
                AgentTurnTraceService::complete($turnId, 0, $result);
                AgentTurnTraceService::event($turnId, ++$sequence, 'turn.completed', ['iterations' => 0, 'tool_count' => 1]);
                return $result;
            }
            if (($taskDecision['decision_mode'] ?? '') === 'confirm'
                && !empty($skillContract['output_policy']['batch_mode'])) {
                return self::prepareDeliveryBatch(
                    $tenantId,
                    $userId,
                    $projectId,
                    $threadId,
                    $messageId,
                    $parentRunId,
                    $requestId,
                    $request,
                    $context,
                    $state,
                    $taskDecision,
                    $skills,
                    $turnId,
                    $sequence,
                    $emit
                );
            }
            if (($taskDecision['decision_mode'] ?? '') === 'confirm') {
                $reply = '';
                $result = [
                    'reply' => $reply,
                    'reply_streamed' => false,
                    'tool_calls' => [],
                    'workspace_actions' => [],
                    'assets' => [],
                    'next_action' => 'confirm_execution',
                    'agent_trace' => ['execution_mode' => 'agent_loop', 'iterations' => 0, 'tool_calls' => 0, 'turn_id' => $turnId, 'task_decision' => $taskDecision],
                    'turn_id' => $turnId,
                    'iterations' => 0,
                    'skills' => $skills,
                    'selected_skill' => $skillContract,
                    'task_decision' => $taskDecision,
                    'estimated_tools' => (array)($taskDecision['estimated_tools'] ?? []),
                    'pending_skill_context' => (array)($taskDecision['pending_context'] ?? []),
                    'subtasks' => [],
                    'subtasks_pending' => false,
                ];
                AgentTurnTraceService::complete($turnId, 0, $result);
                AgentTurnTraceService::event($turnId, ++$sequence, 'confirmation.requested', ['reasons' => (array)($taskDecision['upgrade_reasons'] ?? [])]);
                return $result;
            }
        }

        $approvedProposal = (array)($taskDecision['confirmed_tool_proposal'] ?? []);
        if (!empty($approvedProposal['tool_code'])) {
            return self::executeApprovedToolProposal(
                $approvedProposal,
                $tenantId,
                $userId,
                $projectId,
                $threadId,
                $messageId,
                $parentRunId,
                $requestId,
                $request,
                $context,
                $emit,
                $turnId,
                $sequence,
                $skills,
                $skillContract,
                $taskDecision,
                $runtimeAllowedTools,
                $toolRoute
            );
        }

        if ($parentRunId > 0
            && $explicitDelegationTasks !== []
            && (!$isStrictContract || in_array('delegate_subagent', $runtimeAllowedTools, true))) {
            $execution = AgentExecutionContext::from([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'user_request' => $request,
                'canvas_context' => $context,
                'route' => $toolRoute,
                'emit' => $emit,
            ]);
            $delegation = self::executeTool(
                $execution,
                'delegate_subagent',
                ['tasks' => $explicitDelegationTasks, '_turn_id' => $turnId, '_iterations' => 0],
                $parentRunId,
                $requestId,
                $request,
                $context,
                $emit,
                $skillContract,
                $runtimeAllowedTools
            );
            $subtasks = (array)($delegation['subtasks'] ?? []);
            $result = [
                'reply' => '已开始分配协作任务，完成后会汇总为一份可执行方案。',
                'reply_streamed' => false,
                'tool_calls' => (array)($delegation['tool_calls'] ?? []),
                'workspace_actions' => [],
                'assets' => [],
                'next_action' => 'subagents_pending',
                'agent_trace' => ['execution_mode' => 'agent_loop', 'iterations' => 0, 'tool_calls' => 1, 'turn_id' => $turnId],
                'turn_id' => $turnId,
                'iterations' => 0,
                'skills' => $skills,
                'selected_skill' => $skillContract,
                'task_decision' => $taskDecision,
                'estimated_tools' => (array)($taskDecision['estimated_tools'] ?? []),
                'pending_skill_context' => [],
                'subtasks' => $subtasks,
                'subtasks_pending' => true,
            ];
            AgentTurnTraceService::event($turnId, ++$sequence, 'subagents.explicitly_queued', ['subtask_count' => count($subtasks)]);
            return $result;
        }

        while ($iterations < $maxIterations && $toolCount < $toolLimit) {
            if (self::isCanceled($messageId)) {
                return self::canceledResult($turnId, ++$sequence, $iterations, $toolCount, $emit, $threadId, $messageId);
            }
            if (microtime(true) - $startedAt > $timeout) {
                $error = 'Agent 思考超时，请缩短请求后重试';
                AgentTurnTraceService::event($turnId, ++$sequence, 'turn.failed', ['iteration' => $iterations, 'error' => $error]);
                AgentTurnTraceService::fail($turnId, $iterations, $error);
                throw new Exception($error);
            }
            $iterations++;
            self::emit($emit, 'agent.turn.status', ['thread_id' => $threadId, 'message_id' => $messageId, 'stage' => $iterations === 1 ? 'planning' : 'reviewing', 'label' => $iterations === 1 ? '正在规划下一步' : '正在检查工具结果']);
            AgentTurnTraceService::event($turnId, ++$sequence, 'model.requested', ['iteration' => $iterations]);

            $execution = AgentExecutionContext::from([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'user_request' => $request,
                'canvas_context' => $context,
                'route' => $toolRoute,
                'emit' => $emit,
            ]);
            $streamDecoder = new AssistantReplyStreamDecoder();
            $response = AgentLlmGateway::streamCall(
                $execution,
                'canvas_loop',
                self::systemPrompt(),
                [
                    'messages' => $messages,
                    'canvas_context' => $canvas,
                    'memory' => $memory,
                    'project_memory' => $projectMemory,
                    'retrieved_skills' => $skills,
                    'task_decision' => $modelDecision,
                    'creative_brief' => (array)($context['enriched_context'] ?? []),
                    'advisory_skill' => $advisorySkill,
                    'selected_skill_contract' => $isStrictContract ? $skillContract : [],
                    'max_tokens' => (int)$textProfile['max_tokens'],
                    'enable_thinking' => !empty($textProfile['enable_thinking']),
                    'constraints' => [
                        'max_iterations' => $maxIterations,
                        'remaining_tool_calls' => $toolLimit - $toolCount,
                        'media_provider' => 'power_market_only',
                        'canvas_changes_require_confirmation' => true,
                        'binding_mode' => (string)($taskDecision['binding_mode'] ?? 'none'),
                        'missing_hard_slots' => (array)($taskDecision['missing_hard_slots'] ?? []),
                    ],
                    'request_timeout_seconds' => self::modelStreamTimeout($timeout, $startedAt),
                ],
                $tools,
                function (string $event, array $data) use ($emit, $threadId, $messageId, $turnId, &$sequence, &$replyStreamed, $streamDecoder): void {
                    if ($event === 'heartbeat') {
                        self::emit($emit, 'agent.turn.status', [
                            'thread_id' => $threadId,
                            'message_id' => $messageId,
                            'stage' => 'waiting_model',
                            'label' => '模型正在生成回复',
                            'elapsed_ms' => (int)($data['elapsed_ms'] ?? 0),
                        ]);
                        return;
                    }
                    if ($event !== 'delta' || trim((string)($data['delta'] ?? '')) === '') {
                        return;
                    }
                    AgentTurnTraceService::firstToken($turnId);
                    $rawDelta = (string)$data['delta'];
                    AgentTurnTraceService::event($turnId, ++$sequence, 'message.delta', ['length' => mb_strlen($rawDelta, 'UTF-8')]);
                    $visibleDelta = $streamDecoder->push($rawDelta);
                    if ($visibleDelta === '') {
                        return;
                    }
                    self::emit($emit, 'agent.message.delta', [
                        'thread_id' => $threadId,
                        'message_id' => $messageId,
                        'delta' => $visibleDelta,
                    ]);
                    $replyStreamed = true;
                }
            );
            if (self::isCanceled($messageId)) {
                return self::canceledResult($turnId, ++$sequence, $iterations, $toolCount, $emit, $threadId, $messageId);
            }
            if (!empty($response['error'])) {
                $error = (string)$response['error'];
                AgentTurnTraceService::event($turnId, ++$sequence, 'model.failed', ['iteration' => $iterations, 'error' => $error]);
                AgentTurnTraceService::fail($turnId, $iterations, $error);
                throw new Exception($error);
            }
            $content = trim((string)($response['content'] ?? ''));
            $calls = array_slice((array)($response['function_calls'] ?? []), 0, $toolLimit - $toolCount);
            $streamMode = (string)($response['stream_mode'] ?? 'unknown');
            if ($streamMode === 'buffered_response') {
                self::emit($emit, 'agent.stream.degraded', [
                    'thread_id' => $threadId,
                    'message_id' => $messageId,
                    'mode' => 'buffered_response',
                    'label' => '当前文本模型未提供实时输出，正在等待完整回复',
                ]);
                AgentTurnTraceService::event($turnId, ++$sequence, 'stream.degraded', ['mode' => 'buffered_response']);
            }
            AgentTurnTraceService::event($turnId, ++$sequence, 'model.completed', [
                'iteration' => $iterations,
                'tool_count' => count($calls),
                'content_length' => mb_strlen($content, 'UTF-8'),
                'stream_mode' => $streamMode,
                'stream_delta_count' => (int)($response['stream_delta_count'] ?? 0),
            ]);

            if (empty($calls)) {
                $reply = $content !== '' ? $content : '我已分析当前内容。请告诉我希望继续生成、调整还是整理画布。';
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $content, 'tool_calls' => $calls];
            $mediaSubmitted = false;
            foreach ($calls as $call) {
                if (self::isCanceled($messageId)) {
                    return self::canceledResult($turnId, ++$sequence, $iterations, $toolCount, $emit, $threadId, $messageId);
                }
                $toolCount++;
                $code = trim((string)($call['name'] ?? ''));
                $input = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
                $invocation = AgentToolInvocationPolicy::evaluate(
                    $code,
                    $input,
                    $taskDecision,
                    $skillContract,
                    !empty($state['execution_confirmation'])
                );
                $input = (array)($invocation['normalized_input'] ?? $input);
                if (empty($invocation['allowed'])) {
                    throw new Exception('Tool invocation rejected by policy: ' . (string)($invocation['reason'] ?? 'not_allowed'));
                }
                if (!empty($invocation['requires_confirmation'])) {
                    return self::toolConfirmationResult(
                        $code,
                        $input,
                        (string)($invocation['reason'] ?? 'confirmation_required'),
                        $request,
                        $turnId,
                        $sequence,
                        $skills,
                        $skillContract,
                        $taskDecision
                    );
                }
                self::emit($emit, 'agent.tool.requested', ['thread_id' => $threadId, 'message_id' => $messageId, 'tool_code' => $code]);
                AgentTurnTraceService::event($turnId, ++$sequence, 'tool.requested', ['tool_code' => $code, 'input' => self::sanitize($input)]);
                try {
                    if ($code === 'delegate_subagent') {
                        $input['_turn_id'] = $turnId;
                        $input['_iterations'] = $iterations;
                    }
                    $result = self::executeTool($execution, $code, $input, $parentRunId, $requestId, $request, $context, $emit, $skillContract, $runtimeAllowedTools);
                    $validation = AgentResultValidator::validate($code, $result);
                    self::emit($emit, 'agent.validation.completed', [
                        'thread_id' => $threadId,
                        'message_id' => $messageId,
                        'tool_code' => $code,
                        'valid' => !empty($validation['valid']),
                        'status' => (string)($validation['status'] ?? 'unknown'),
                        'issues' => (array)($validation['issues'] ?? []),
                    ]);
                    AgentTurnTraceService::event($turnId, ++$sequence, 'validation.completed', [
                        'tool_code' => $code,
                        'validation' => $validation,
                    ]);
                    $repair = AgentRepairPlanner::plan($code, $validation, 0);
                    if ($repair !== []) {
                        self::emit($emit, 'agent.repair.started', [
                            'thread_id' => $threadId,
                            'message_id' => $messageId,
                            'tool_code' => $code,
                            'kind' => (string)$repair['kind'],
                        ]);
                        AgentTurnTraceService::event($turnId, ++$sequence, 'repair.planned', ['tool_code' => $code, 'repair' => $repair]);
                        try {
                            $recovered = \app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationTaskCenterService::recover(
                                $tenantId,
                                $userId,
                                ['task_id' => (int)$repair['task_id']]
                            );
                            AgentTurnTraceService::event($turnId, ++$sequence, 'repair.completed', ['tool_code' => $code, 'repair' => $repair, 'result' => $recovered]);
                            self::emit($emit, 'agent.repair.completed', [
                                'thread_id' => $threadId,
                                'message_id' => $messageId,
                                'tool_code' => $code,
                                'status' => (string)($recovered['status'] ?? 'running'),
                            ]);
                        } catch (\Throwable $repairError) {
                            AgentTurnTraceService::event($turnId, ++$sequence, 'repair.failed', ['tool_code' => $code, 'error' => mb_substr($repairError->getMessage(), 0, 500, 'UTF-8')]);
                            self::emit($emit, 'agent.repair.completed', [
                                'thread_id' => $threadId,
                                'message_id' => $messageId,
                                'tool_code' => $code,
                                'status' => 'failed',
                            ]);
                        }
                    }
                    if (empty($validation['valid'])) {
                        $unverifiedToolResults[] = [
                            'tool_code' => $code,
                            'issues' => (array)($validation['issues'] ?? []),
                            'status' => (string)($validation['status'] ?? 'unknown'),
                        ];
                        $toolResult = [
                            'status' => 'unverified',
                            'issues' => (array)($validation['issues'] ?? []),
                        ];
                        self::emit($emit, 'agent.tool.completed', [
                            'thread_id' => $threadId,
                            'message_id' => $messageId,
                            'tool_code' => $code,
                            'status' => 'failed',
                            'error' => 'tool_result_unverified',
                        ]);
                        AgentTurnTraceService::event($turnId, ++$sequence, 'tool.unverified', [
                            'tool_code' => $code,
                            'validation' => $validation,
                        ]);
                        if (in_array($code, ['generate_image', 'generate_video', 'generate_music'], true)) {
                            $reply = self::mediaSubmissionVerificationFailureReply($code);
                            break 2;
                        }
                        $messages[] = ['role' => 'tool', 'name' => $code, 'content' => $toolResult];
                        continue;
                    }
                    $toolCalls = array_merge($toolCalls, (array)($result['tool_calls'] ?? []));
                    $workspaceActions = array_merge($workspaceActions, (array)($result['workspace_actions'] ?? []));
                    $assets = array_merge($assets, (array)($result['assets'] ?? []));
                    $subtasks = array_merge($subtasks, (array)($result['subtasks'] ?? []));
                    $subtasksPending = $subtasksPending || !empty($result['subtasks_pending']);
                    $toolResult = self::toolMessage($result);
                    self::emit($emit, 'agent.tool.completed', ['thread_id' => $threadId, 'message_id' => $messageId, 'tool_code' => $code, 'status' => 'success']);
                    AgentTurnTraceService::event($turnId, ++$sequence, 'tool.completed', ['tool_code' => $code, 'output' => $toolResult]);
                    if ($code === 'ask_user') {
                        $reply = (string)($result['tool_calls'][0]['output']['question'] ?? '请补充必要信息后我再继续。');
                        break 2;
                    }
                    if (in_array($code, (array)($skillContract['output_policy']['terminal_tools'] ?? []), true)) {
                        $reply = $toolResult !== '' ? $toolResult : '当前任务已创建。';
                        break 2;
                    }
                    if (AgentResultValidator::isConfirmedMediaSubmission($code, $validation)) {
                        $reply = self::mediaSubmissionReply($code, $result, $request);
                        $mediaSubmitted = true;
                        continue;
                    }
                } catch (Exception $e) {
                    $toolResult = ['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 500, 'UTF-8')];
                    self::emit($emit, 'agent.tool.completed', ['thread_id' => $threadId, 'message_id' => $messageId, 'tool_code' => $code, 'status' => 'failed', 'error' => $toolResult['message']]);
                    AgentTurnTraceService::event($turnId, ++$sequence, 'tool.failed', ['tool_code' => $code, 'error' => $toolResult['message']]);
                    if (in_array($code, ['generate_image', 'generate_video', 'generate_music'], true)) {
                        $reply = self::mediaSubmissionFailureReply($code, $input, $request);
                        break 2;
                    }
                }
                $messages[] = ['role' => 'tool', 'name' => $code, 'content' => $toolResult];
                if ($subtasksPending) {
                    break 2;
                }
            }
            if ($mediaSubmitted) {
                break;
            }
        }

        if ($unverifiedToolResults !== []) {
            // A model may describe an attempted tool call as completed even
            // when the output did not pass the result contract. This is a
            // terminal truthfulness guard for every non-media tool path.
            $reply = self::unverifiedToolResultReply($unverifiedToolResults);
        } elseif ($subtasksPending) {
            $reply = 'I have split this complex request into focused tasks and am consolidating the results.';
        } elseif ($reply === '') {
            $reply = !empty($workspaceActions)
                ? '我已准备好画布修改提案，请确认后应用。'
                : (!empty($assets)
                    ? self::mediaSubmissionReply(self::mediaToolCode($toolCalls), [
                        'tool_calls' => $toolCalls,
                        'workspace_actions' => $workspaceActions,
                        'assets' => $assets,
                    ], $request)
                    : '我已完成当前步骤。');
        }
        $result = [
            'reply' => $reply,
            'reply_streamed' => $replyStreamed,
            'tool_calls' => $toolCalls,
            'workspace_actions' => $workspaceActions,
            'assets' => $assets,
            // Media workspace actions are auto-applied as pending canvas nodes.
            // They are not a user confirmation step, even though they also write
            // to the canvas.
            'next_action' => $unverifiedToolResults !== [] ? 'chat' : ($subtasksPending ? 'subagents_pending' : (!empty($assets) ? 'generation_submitted' : (!empty($workspaceActions) ? 'confirm_canvas_mutation' : 'chat'))),
            'agent_trace' => ['execution_mode' => 'agent_loop', 'iterations' => $iterations, 'tool_calls' => $toolCount, 'turn_id' => $turnId],
            'turn_id' => $turnId,
            'iterations' => $iterations,
            'skills' => $skills,
            'selected_skill' => $skillContract,
            'task_decision' => $taskDecision,
            'estimated_tools' => (array)($taskDecision['estimated_tools'] ?? []),
            // Keep the original user wording available only until the response
            // projection is built. The compiled provider prompt must never be
            // used as a chat-facing creation direction.
            'original_user_request' => $request,
            'pending_skill_context' => [],
            'unverified_tool_results' => $unverifiedToolResults,
            'subtasks' => $subtasks,
            'subtasks_pending' => $subtasksPending,
        ];
        $deliveryItemId = (int)($state['delivery_item_id'] ?? 0);
        if (!$subtasksPending && $deliveryItemId > 0) {
            try {
                $syncedItem = DeliveryItemTaskSyncService::syncTextStage($tenantId, $userId, $deliveryItemId, $result);
                $deliveryItem = $syncedItem !== [] ? $syncedItem : DeliveryPlanService::item($tenantId, $userId, $deliveryItemId);
                if ($deliveryItem !== []) {
                    $planId = (int)($deliveryItem['plan_id'] ?? 0);
                    $result['delivery_item'] = $deliveryItem;
                    $result['delivery_plan'] = DeliveryPlanService::detail($tenantId, $userId, $planId);
                    $result['creative_graph'] = (array)($result['delivery_plan']['graph'] ?? []);
                    $result['runnable_delivery_items'] = CreativeDeliveryGraphService::runnableItems($tenantId, $userId, $planId);
                    self::emit($emit, 'agent.delivery.graph.updated', [
                        'thread_id' => $threadId,
                        'message_id' => $messageId,
                        'plan_id' => $planId,
                        'delivery_plan' => $result['delivery_plan'],
                        'graph' => $result['creative_graph'],
                        'active_item_id' => (int)($deliveryItem['id'] ?? 0),
                        'runnable_item_ids' => array_values(array_map(
                            static fn(array $item): int => (int)($item['id'] ?? 0),
                            (array)$result['runnable_delivery_items']
                        )),
                    ]);
                    AgentTurnTraceService::event($turnId, ++$sequence, 'delivery.graph', [
                        'plan_id' => $planId,
                        'active_item_id' => (int)($deliveryItem['id'] ?? 0),
                        'runnable_item_count' => count((array)$result['runnable_delivery_items']),
                    ]);
                }
            } catch (Exception) {
                // A durable workflow projection must not discard an otherwise valid Agent reply.
            }
        }
        if ($subtasksPending) {
            AgentTurnTraceService::event($turnId, ++$sequence, 'subagents.queued', ['subtask_count' => count($subtasks)]);
        } else {
            AgentTurnTraceService::complete($turnId, $iterations, $result);
            AgentTurnTraceService::event($turnId, ++$sequence, 'turn.completed', ['iterations' => $iterations, 'tool_count' => $toolCount]);
        }
        return $result;
    }

    /** Keep a bounded stream window while reserving time for the Agent turn to close cleanly. */
    private static function modelStreamTimeout(int $turnTimeout, float $startedAt): int
    {
        $remaining = (int)floor($turnTimeout - (microtime(true) - $startedAt));
        return max(10, min(
            self::MAX_MODEL_STREAM_TIMEOUT_SECONDS,
            $remaining - self::TURN_TIMEOUT_SAFETY_SECONDS
        ));
    }

    private static function inheritConversationReferences(array $context, array $memory): array
    {
        $references = [];
        foreach ((array)($context['uploaded_references'] ?? []) as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $url = trim((string)($reference['url'] ?? $reference['uri'] ?? ''));
            if ($url !== '') {
                $references[$url] = $reference + ['url' => $url, 'uri' => $url];
            }
        }
        foreach ((array)($memory['uploaded_references'] ?? []) as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $url = trim((string)($reference['url'] ?? $reference['uri'] ?? ''));
            if ($url !== '') {
                $references[$url] = $reference + ['url' => $url, 'uri' => $url];
            }
        }
        if ($references !== []) {
            $context['uploaded_references'] = array_values($references);
            $context['uploaded_reference_count'] = count($context['uploaded_references']);
        }
        return $context;
    }

    private static function prepareDeliveryBatch(
        int $tenantId,
        int $userId,
        int $projectId,
        int $threadId,
        int $messageId,
        int $parentRunId,
        string $requestId,
        string $request,
        array $context,
        array $state,
        array $taskDecision,
        array $skills,
        int $turnId,
        int &$sequence,
        ?callable $emit
    ): array {
        $slots = array_merge(
            (array)($taskDecision['default_slots'] ?? []),
            (array)($taskDecision['inferred_slots'] ?? [])
        );
        if (!empty($context['creative_context'])) {
            $slots['creative_context'] = (array)$context['creative_context'];
        }
        $skillKey = (string)($taskDecision['selected_skill_key'] ?? '');
        self::emit($emit, 'agent.reasoning.step', [
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'stage' => 'delivery_planning',
            'status' => 'running',
            'label' => '正在编排交付计划',
            'value' => $skillKey === 'ecommerce_detail_page' ? '按商品证据设计动态详情页区块' : '正在组织独立交付素材',
        ]);
        $plan = $skillKey === 'ecommerce_detail_page'
            ? EcommerceDetailSectionPlanner::resolve(
                $tenantId,
                $userId,
                $request,
                $slots,
                (array)($context['uploaded_references'] ?? [])
            )
            : BatchDeliveryPlanner::plan(
                $skillKey,
                $request,
                $slots,
                (array)($taskDecision['skill_contract']['allowed_tools'] ?? [])
            );
        $mediaConfig = $state['agent_media_config'] ?? [];
        if (is_string($mediaConfig)) {
            $mediaConfig = json_decode($mediaConfig, true);
        }
        $route = [
            'request_id' => $requestId . ':detail_batch',
            'agent_run_id' => $parentRunId,
            'delivery_item_id' => (int)($state['delivery_item_id'] ?? 0),
            'skill_key' => $skillKey,
            'creative_context' => (array)($context['creative_context'] ?? []),
            'slots' => array_merge($slots, $plan),
            'uploaded_references' => (array)($context['uploaded_references'] ?? []),
            'tool_options' => [
                'generate_image' => is_array($mediaConfig['image'] ?? null) ? $mediaConfig['image'] : [],
                'generate_video' => is_array($mediaConfig['video'] ?? null) ? $mediaConfig['video'] : [],
                'generate_music' => is_array($mediaConfig['music'] ?? null) ? $mediaConfig['music'] : [],
            ],
        ];
        $prepared = EcommerceAgentBatchService::prepare(
            $tenantId,
            $userId,
            $projectId,
            $threadId,
            $messageId,
            $request,
            $context,
            $route,
            $emit
        );
        $deliveryItemId = (int)($state['delivery_item_id'] ?? 0);
        if ($deliveryItemId > 0 && $skillKey === 'ecommerce_detail_page') {
            $prepared['delivery_items'] = DeliveryPlanService::syncDetailSections(
                $tenantId,
                $userId,
                $deliveryItemId,
                (array)($prepared['planned_sections'] ?? []),
                (int)($prepared['batch_id'] ?? 0),
                (array)($prepared['batch']['creative_context'] ?? $context['creative_context'] ?? []),
                (array)($context['uploaded_references'] ?? [])
            );
            EcommerceAgentBatchService::bindDeliveryItems(
                $tenantId,
                $userId,
                (int)($prepared['batch_id'] ?? 0),
                $deliveryItemId,
                (array)$prepared['delivery_items']
            );
        }
        self::emit($emit, 'agent.reasoning.step', [
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'stage' => 'delivery_planning',
            'status' => 'success',
            'label' => '交付计划已完成',
            'value' => sprintf('%d 个独立区块已就绪', (int)($prepared['total_count'] ?? 0)),
        ]);
        $prepared['agent_trace'] = [
            'execution_mode' => $skillKey === 'ecommerce_detail_page' ? 'ecommerce_detail_batch' : 'delivery_batch',
            'turn_id' => $turnId,
            'task_decision' => $taskDecision,
        ];
        $prepared['turn_id'] = $turnId;
        $prepared['iterations'] = 0;
        $prepared['skills'] = $skills;
        $prepared['selected_skill'] = (array)($taskDecision['skill_contract'] ?? []);
        $prepared['task_decision'] = $taskDecision;
        $prepared['estimated_tools'] = (array)($taskDecision['estimated_tools'] ?? []);
        $prepared['pending_skill_context'] = [];
        $prepared['subtasks'] = [];
        $prepared['subtasks_pending'] = false;
        AgentTurnTraceService::event($turnId, ++$sequence, 'delivery_batch.prepared', [
            'batch_id' => (int)($prepared['batch_id'] ?? 0),
            'section_count' => (int)($prepared['total_count'] ?? 0),
            'skill_key' => $skillKey,
        ]);
        AgentTurnTraceService::complete($turnId, 0, $prepared);
        return $prepared;
    }

    private static function toolRoute(array $state, string $requestId): array
    {
        $config = $state['agent_media_config'] ?? [];
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }
        return [
            'request_id' => $requestId,
            'tool_options' => [
                'generate_image' => is_array($config['image'] ?? null) ? $config['image'] : [],
                'generate_video' => is_array($config['video'] ?? null) ? $config['video'] : [],
                'generate_music' => is_array($config['music'] ?? null) ? $config['music'] : [],
            ],
        ];
    }

    private static function toolConfirmationResult(string $toolCode, array $input, string $reason, string $request, int $turnId, int &$sequence, array $skills, array $skillContract, array $taskDecision): array
    {
        $pending = [
            'skill_key' => (string)($skillContract['skill_key'] ?? ''),
            'original_request' => $request,
            'delivery_item_id' => (int)($taskDecision['delivery_item_id'] ?? 0),
            'tool_proposal' => [
                'tool_code' => $toolCode,
                'arguments' => $input,
            ],
        ];
        $taskDecision['decision_mode'] = 'confirm';
        $taskDecision['action_mode'] = 'confirm';
        $taskDecision['next_action'] = 'confirm_execution';
        $taskDecision['requires_confirmation'] = true;
        $taskDecision['confirmation_reason'] = $reason;
        $taskDecision['pending_context'] = $pending;
        AgentTurnTraceService::event($turnId, ++$sequence, 'tool.confirmation_required', [
            'tool_code' => $toolCode,
            'reason' => $reason,
        ]);
        $result = [
            'reply' => '',
            'reply_streamed' => false,
            'tool_calls' => [],
            'workspace_actions' => [],
            'assets' => [],
            'next_action' => 'confirm_execution',
            'agent_trace' => ['execution_mode' => 'agent_loop', 'iterations' => 1, 'tool_calls' => 0, 'turn_id' => $turnId],
            'turn_id' => $turnId,
            'iterations' => 1,
            'skills' => $skills,
            'selected_skill' => $skillContract,
            'task_decision' => $taskDecision,
            'estimated_tools' => [],
            'pending_skill_context' => $pending,
            'subtasks' => [],
            'subtasks_pending' => false,
        ];
        AgentTurnTraceService::complete($turnId, 1, $result);
        return $result;
    }

    private static function executeApprovedToolProposal(array $proposal, int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, int $parentRunId, string $requestId, string $request, array $context, ?callable $emit, int $turnId, int &$sequence, array $skills, array $skillContract, array $taskDecision, array $runtimeAllowedTools, array $toolRoute): array
    {
        $code = trim((string)($proposal['tool_code'] ?? ''));
        $input = is_array($proposal['arguments'] ?? null) ? $proposal['arguments'] : [];
        $invocation = AgentToolInvocationPolicy::evaluate($code, $input, $taskDecision, $skillContract, true);
        if (empty($invocation['allowed'])) {
            throw new Exception('Approved tool proposal is no longer permitted: ' . (string)($invocation['reason'] ?? 'not_allowed'));
        }
        $input = (array)($invocation['normalized_input'] ?? $input);
        $execution = AgentExecutionContext::from([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'user_request' => $request,
            'canvas_context' => $context,
            'route' => $toolRoute,
            'emit' => $emit,
        ]);
        self::emit($emit, 'agent.turn.status', ['thread_id' => $threadId, 'message_id' => $messageId, 'stage' => 'generating', 'label' => '正在生成海报第一版']);
        self::emit($emit, 'agent.tool.requested', ['thread_id' => $threadId, 'message_id' => $messageId, 'tool_code' => $code]);
        AgentTurnTraceService::event($turnId, ++$sequence, 'tool.requested', ['tool_code' => $code, 'input' => self::sanitize($input), 'strategy' => 'approved_tool_proposal']);
        try {
            $toolResult = self::executeTool($execution, $code, $input, $parentRunId, $requestId, $request, $context, $emit, $skillContract, $runtimeAllowedTools);
        } catch (Exception $e) {
            $error = mb_substr($e->getMessage(), 0, 500, 'UTF-8');
            self::emit($emit, 'agent.tool.completed', ['thread_id' => $threadId, 'message_id' => $messageId, 'tool_code' => $code, 'status' => 'failed', 'error' => $error]);
            AgentTurnTraceService::event($turnId, ++$sequence, 'tool.failed', ['tool_code' => $code, 'error' => $error]);
            AgentTurnTraceService::fail($turnId, 0, $error);
            throw $e;
        }
        $validation = AgentResultValidator::validate($code, $toolResult);
        self::emit($emit, 'agent.validation.completed', [
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'tool_code' => $code,
            'valid' => !empty($validation['valid']),
            'status' => (string)($validation['status'] ?? 'unknown'),
            'issues' => (array)($validation['issues'] ?? []),
        ]);
        AgentTurnTraceService::event($turnId, ++$sequence, 'validation.completed', ['tool_code' => $code, 'validation' => $validation]);
        if (empty($validation['valid'])) {
            self::emit($emit, 'agent.tool.completed', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'tool_code' => $code,
                'status' => 'failed',
                'error' => 'tool_result_unverified',
            ]);
            AgentTurnTraceService::event($turnId, ++$sequence, 'tool.unverified', [
                'tool_code' => $code,
                'validation' => $validation,
                'strategy' => 'approved_tool_proposal',
            ]);
            $result = [
                'reply' => self::mediaSubmissionVerificationFailureReply($code),
                'reply_streamed' => false,
                'tool_calls' => [],
                'workspace_actions' => [],
                'assets' => [],
                'next_action' => 'chat',
                'agent_trace' => ['execution_mode' => 'agent_loop', 'iterations' => 0, 'tool_calls' => 1, 'turn_id' => $turnId, 'strategy' => 'approved_tool_proposal'],
                'turn_id' => $turnId,
                'iterations' => 0,
                'skills' => $skills,
                'selected_skill' => $skillContract,
                'task_decision' => $taskDecision,
                'estimated_tools' => (array)($taskDecision['estimated_tools'] ?? []),
                'original_user_request' => $request,
                'pending_skill_context' => [],
                'unverified_tool_results' => [[
                    'tool_code' => $code,
                    'issues' => (array)($validation['issues'] ?? []),
                    'status' => (string)($validation['status'] ?? 'unknown'),
                ]],
                'subtasks' => [],
                'subtasks_pending' => false,
            ];
            AgentTurnTraceService::complete($turnId, 0, $result);
            AgentTurnTraceService::event($turnId, ++$sequence, 'turn.completed', ['iterations' => 0, 'tool_count' => 1, 'outcome' => 'unverified']);
            return $result;
        }
        self::emit($emit, 'agent.tool.completed', ['thread_id' => $threadId, 'message_id' => $messageId, 'tool_code' => $code, 'status' => 'success']);
        AgentTurnTraceService::event($turnId, ++$sequence, 'tool.completed', ['tool_code' => $code, 'output' => self::toolMessage($toolResult)]);
        $result = [
            'reply' => in_array($code, ['generate_image', 'generate_video', 'generate_music'], true)
                ? self::mediaSubmissionReply($code, $toolResult, $request)
                : '',
            'reply_streamed' => false,
            'tool_calls' => (array)($toolResult['tool_calls'] ?? []),
            'workspace_actions' => (array)($toolResult['workspace_actions'] ?? []),
            'assets' => (array)($toolResult['assets'] ?? []),
            'next_action' => in_array($code, ['generate_image', 'generate_video', 'generate_music'], true)
                ? 'generation_submitted'
                : (!empty($toolResult['workspace_actions']) ? 'confirm_canvas_mutation' : 'chat'),
            'agent_trace' => ['execution_mode' => 'agent_loop', 'iterations' => 0, 'tool_calls' => 1, 'turn_id' => $turnId, 'strategy' => 'approved_tool_proposal'],
            'turn_id' => $turnId,
            'iterations' => 0,
            'skills' => $skills,
            'selected_skill' => $skillContract,
            'task_decision' => $taskDecision,
            'estimated_tools' => (array)($taskDecision['estimated_tools'] ?? []),
            'original_user_request' => $request,
            'pending_skill_context' => [],
            'subtasks' => [],
            'subtasks_pending' => false,
        ];
        AgentTurnTraceService::complete($turnId, 0, $result);
        AgentTurnTraceService::event($turnId, ++$sequence, 'turn.completed', ['iterations' => 0, 'tool_count' => 1]);
        return $result;
    }

    /** Do not let an unverified tool response be rewritten as completed work. */
    private static function unverifiedToolResultReply(array $results): string
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (array $item): string => trim((string)($item['tool_code'] ?? '')),
            $results
        ))));
        $label = count($codes) === 1 && $codes[0] !== '' ? '“' . $codes[0] . '”' : '当前操作';
        return $label . '的结果尚未通过验证，暂不标记为完成。请稍后重试，或调整需求后继续。';
    }

    private static function isCanceled(int $messageId): bool
    {
        if ($messageId <= 0) {
            return false;
        }
        return (string)(AigcCanvasAgentMessage::where('id', $messageId)->value('status') ?? '') === 'canceled';
    }

    private static function canceledResult(int $turnId, int $sequence, int $iterations, int $toolCount, ?callable $emit, int $threadId, int $messageId): array
    {
        AgentTurnTraceService::event($turnId, $sequence, 'turn.canceled', ['iterations' => $iterations, 'tool_count' => $toolCount]);
        AgentTurnTraceService::cancel($turnId, $iterations, ['canceled' => true]);
        self::emit($emit, 'agent.turn.canceled', [
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'turn_id' => $turnId,
        ]);
        return [
            'reply' => '',
            'reply_streamed' => false,
            'tool_calls' => [],
            'workspace_actions' => [],
            'assets' => [],
            'next_action' => 'canceled',
            'agent_trace' => ['execution_mode' => 'agent_loop', 'iterations' => $iterations, 'tool_calls' => $toolCount, 'turn_id' => $turnId],
            'turn_id' => $turnId,
            'iterations' => $iterations,
            'skills' => [],
            'canceled' => true,
        ];
    }

    /**
     * Respect an explicit, concrete request for parallel collaborators instead
     * of relying on the model to infer that the delegation tool is mandatory.
     */
    private static function explicitDelegationTasks(string $request): array
    {
        if (!preg_match('/(?:协作助手|子代理|子任务)/u', $request)
            || !preg_match('/(?:分配|并行|拆分)/u', $request)) {
            return [];
        }
        $tasks = [];
        $definitions = [
            ['agent_code' => 'planner', 'terms' => ['品牌定位', '定位策略', '品牌策略'], 'task' => '制定品牌定位、受众和价值主张。'],
            ['agent_code' => 'copy', 'terms' => ['主视觉文案', '文案', '标题'], 'task' => '制定主视觉标题、卖点文案和行动号召。'],
            ['agent_code' => 'visual', 'terms' => ['短视频脚本', '视频脚本', '分镜'], 'task' => '制定短视频脚本、镜头节奏和旁白建议。'],
        ];
        foreach ($definitions as $definition) {
            foreach ($definition['terms'] as $term) {
                if (mb_stripos($request, $term, 0, 'UTF-8') !== false) {
                    $tasks[] = ['agent_code' => $definition['agent_code'], 'task' => $definition['task']];
                    break;
                }
            }
        }
        return count($tasks) >= 2 ? $tasks : [];
    }

    /**
     * An explicit text-only collaboration request is an orchestration command,
     * not a request to generate the media type mentioned in a subtask.
     */
    private static function isExplicitTextOnlyDelegation(string $request, array $tasks): bool
    {
        if ($tasks === []) {
            return false;
        }
        $forbidsMedia = preg_match('/(?:不要|不)(?:生成|制作|产出)?(?:媒体|图片|图像|视频|音频)|纯文本/u', $request) === 1;
        $forbidsCanvasWrites = preg_match('/(?:不要|不)(?:写入|修改|编辑|操作)(?:画布)?|不写入画布/u', $request) === 1;
        return $forbidsMedia && $forbidsCanvasWrites;
    }

    private static function applyExplicitTextDelegationDecision(array &$decision): void
    {
        $decision['intent'] = 'collaborative_text_plan';
        $decision['selected_skill_key'] = '';
        $decision['skill_contract'] = [];
        $decision['runtime_allowed_tools'] = ['ask_user', 'retrieve_skills', CanvasProtocol::TOOL_QUERY, 'delegate_subagent'];
        $decision['binding_mode'] = 'none';
        $decision['missing_hard_slots'] = [];
        $decision['next_action'] = 'execute_tool';
        $decision['delegation_override'] = 'explicit_text_only';
    }

    private static function executeTool(AgentExecutionContext $context, string $code, array $input, int $parentRunId, string $requestId, string $prompt, array $canvasContext, ?callable $emit, array $skillContract = [], array $runtimeAllowedTools = []): array
    {
        if ($context->deliveryItemId() > 0) {
            $input['delivery_item_id'] = $context->deliveryItemId();
        }
        self::assertSkillAllowsTool($skillContract, $code, $runtimeAllowedTools);
        if (!empty($skillContract['missing_slots']) && in_array($code, ['generate_image', 'generate_video', 'generate_music'], true)) {
            throw new Exception('请先补充必要信息：' . implode('、', (array)$skillContract['missing_slots']));
        }
        if ($code === 'ask_user') {
            return (new AskUserTool())->execute($context, $input);
        }
        if ($code === 'retrieve_skills') {
            return (new RetrieveSkillsTool())->execute($context, $input);
        }
        if ($code === 'delegate_subagent') {
            return SubAgentDispatcher::dispatch($context, $input, $parentRunId, $requestId, $prompt, $canvasContext, $emit);
        }
        if (in_array($code, [CanvasProtocol::TOOL_QUERY, CanvasProtocol::TOOL_MUTATION, CanvasProtocol::TOOL_SELECTION_ACTION], true)) {
            $tool = new CanvasCapabilityTool($code);
            return $tool->execute($context, $input);
        }
        if (!in_array($code, self::directToolCodes(), true)) {
            throw new Exception('Agent 请求了不可用工具：' . $code);
        }
        $input = self::mergeMediaToolOptions($context->toolOptions($code), $input);
        $input = self::toolInput($code, $input, $requestId, $prompt, $canvasContext, $context->projectId(), $skillContract, $context->deliveryItemId());
        if (in_array($code, ['generate_image', 'generate_video', 'generate_music'], true)) {
            if ($context->deliveryItemId() > 0) {
                return DeliveryGraphExecutor::executeFromAgentTool(
                    $context->tenantId(),
                    $context->userId(),
                    $context->projectId(),
                    $context->threadId(),
                    $context->messageId(),
                    $context->deliveryItemId(),
                    $code,
                    $input,
                    $prompt,
                    $canvasContext,
                    $emit
                );
            }
            return AigcCanvasAgentRuntimeService::executeExternalToolWithActions(
                $context->tenantId(), $context->userId(), $context->projectId(), $context->threadId(), $context->messageId(),
                $code, $input, $prompt, $canvasContext, $emit
            );
        }
        $tool = AigcCanvasAgentRuntimeService::executeExternalTool(
            $context->tenantId(), $context->userId(), $context->projectId(), $context->threadId(), $context->messageId(), $code, $input, $emit
        );
        return ['tool_calls' => [$tool], 'workspace_actions' => [], 'assets' => []];
    }

    private static function mergeMediaToolOptions(array $toolOptions, array $input): array
    {
        if ($toolOptions === []) {
            return $input;
        }
        $merged = array_merge($toolOptions, $input);
        $isExplicit = array_key_exists('model_selection_explicit', $toolOptions)
            && filter_var($toolOptions['model_selection_explicit'], FILTER_VALIDATE_BOOL);
        if (!$isExplicit) {
            return $merged;
        }

        // The LLM can decide creative arguments, but it must never replace the
        // model, SKU, or billing route explicitly chosen by the user.
        foreach (['channel', 'model', 'model_id', 'image_model_id', 'market_product_id', 'market_sku_id', 'sku_id', 'model_selection_explicit'] as $key) {
            if (array_key_exists($key, $toolOptions)) {
                $merged[$key] = $toolOptions[$key];
            }
        }
        return $merged;
    }

    private static function toolSchemas(int $tenantId, string $request, array $canvas, array $skillContract = [], array $runtimeAllowedTools = []): array
    {
        $allowed = array_flip(self::candidateToolCodes($request, $canvas, $skillContract, $runtimeAllowedTools));
        $schemas = [];
        foreach (CanvasAgentToolRegistryService::toolList($tenantId) as $tool) {
            $code = (string)($tool['tool_code'] ?? '');
            if (!isset($allowed[$code]) || (int)($tool['status'] ?? 0) !== 1 || !is_array($tool['schema'] ?? null)) {
                continue;
            }
            $schemas[] = $tool['schema'];
        }
        if (isset($allowed['delegate_subagent'])) {
            $schemas[] = FunctionCallSchema::function('delegate_subagent', 'Delegate only a complex multi-part request to up to three focused sub-agents. Do not use for chat, one image, or one canvas edit.', [
                'tasks' => ['type' => 'array'],
            ], ['tasks']);
        }
        return $schemas;
    }

    private static function candidateToolCodes(string $request, array $canvas, array $skillContract = [], array $runtimeAllowedTools = []): array
    {
        // Core creative capabilities are exposed from the tenant's live tool
        // registry. The model, rather than a keyword gate, decides whether a
        // request needs text, image, video, music, or a canvas operation.
        $codes = [
            'ask_user',
            'retrieve_skills',
            CanvasProtocol::TOOL_QUERY,
            CanvasProtocol::TOOL_MUTATION,
            CanvasProtocol::TOOL_SELECTION_ACTION,
            'generate_text',
            'generate_image',
            'generate_video',
            'generate_music',
            'delegate_subagent',
            'web_fetch',
            'url_to_design_brief',
            'search_web_info',
            'search_image',
            'brand_research',
            'asset_analyze',
        ];
        if (preg_match('/https?:\/\//i', $request) === 1) {
            $codes = array_merge($codes, ['web_fetch', 'url_to_design_brief']);
        }
        if (preg_match('/搜索|资料|竞品|品牌|参考图|research|search/u', mb_strtolower($request, 'UTF-8')) === 1) {
            $codes = array_merge($codes, ['search_web_info', 'search_image', 'brand_research']);
        }
        if ((int)($canvas['uploaded_reference_count'] ?? 0) > 0) {
            $codes[] = 'asset_analyze';
        }
        if (!empty($canvas['selected_ids'])) {
            $codes[] = 'canvas_select';
        }
        $permissionTools = $runtimeAllowedTools;
        if ($permissionTools === [] && $skillContract !== []) {
            $permissionTools = (array)($skillContract['allowed_tools'] ?? []);
        }
        if ($permissionTools !== []) {
            $always = ['ask_user', 'retrieve_skills', CanvasProtocol::TOOL_QUERY];
            $codes = array_values(array_filter($codes, static fn(string $code): bool => in_array($code, $always, true) || in_array($code, $permissionTools, true)));
        }
        return array_values(array_unique($codes));
    }

    private static function directToolCodes(): array
    {
        return ['ask_user', 'retrieve_skills', 'generate_text', 'generate_image', 'generate_video', 'generate_music', 'web_fetch', 'search_web_info', 'search_image', 'brand_research', 'url_to_design_brief', 'asset_analyze', 'canvas_select', 'canvas_read', 'canvas_patch'];
    }

    private static function toolInput(string $code, array $input, string $requestId, string $prompt, array $context, int $projectId, array $skillContract = [], int $deliveryItemId = 0): array
    {
        $input['request_id'] = (string)($input['request_id'] ?? $requestId . ':' . $code);
        $input['project_id'] = (int)($input['project_id'] ?? $projectId);
        if ($deliveryItemId > 0) $input['delivery_item_id'] = $deliveryItemId;
        if (in_array($code, ['generate_text', 'generate_image', 'generate_video', 'generate_music'], true) && trim((string)($input['prompt'] ?? $input['content'] ?? '')) === '') {
            if (in_array($code, ['generate_image', 'generate_video', 'generate_music'], true)) {
                $input['creative_intent'] = $prompt;
            } else {
                $input['prompt'] = $prompt;
            }
        }
        if ($code === 'generate_text') {
            // Agent tool output is fed back into the loop. Most requests stay
            // concise, while script and storyboard work gets a dedicated budget.
            $profile = self::textProfile($prompt);
            $input['max_tokens'] = max(256, min(3200, (int)($input['max_tokens'] ?? $profile['max_tokens'])));
            $input['enable_thinking'] = array_key_exists('enable_thinking', $input)
                ? (bool)$input['enable_thinking']
                : !empty($profile['enable_thinking']);
            $input['request_timeout_seconds'] = max(10, min(150, (int)($input['request_timeout_seconds'] ?? $profile['timeout_seconds'])));
        }
        if ($code === 'canvas_read') {
            $input['canvas_snapshot'] = $context;
        }
        if ($code === 'canvas_select') {
            $input['selected_elements'] = (array)($context['selected_elements'] ?? $context['selection']['elements'] ?? []);
        }
        if ($code === 'asset_analyze') {
            // A reference uploaded in a conversation is available to that conversation only.
            // Only a selected canvas node represents a reusable project asset.
            $hasSuppliedAssets = !empty($input['assets']) || !empty($input['references']) || !empty($input['uploaded_references']);
            if (!$hasSuppliedAssets) {
                $input['references'] = (array)($context['uploaded_references'] ?? []);
                if ($input['references'] !== []) {
                    $input['reference_scope'] = 'conversation';
                } else {
                    $input['references'] = (array)($context['selected_elements'] ?? $context['selection']['elements'] ?? []);
                    $input['reference_scope'] = $input['references'] === [] ? 'conversation' : 'canvas';
                }
            } else {
                $input['reference_scope'] = 'conversation';
            }
            $input['requires_visual_understanding'] = true;
            $input['request'] = (string)($input['request'] ?? $prompt);
            $input['precomputed_asset_insights'] = (array)($context['enriched_context']['asset_insights'] ?? []);
        }
        if (in_array($code, ['generate_image', 'generate_video', 'generate_music'], true)) {
            $creativeContext = (array)($context['enriched_context']['creative_context'] ?? $context['enriched_context'] ?? []);
            $input['creative_brief'] = $creativeContext;
            $intent = trim((string)($input['creative_intent'] ?? $input['prompt'] ?? $input['content'] ?? $prompt));
            // The submission gateway is the only component permitted to write the final prompt.
            $input['user_request'] = $intent;
            $input['prompt'] = $intent;
            $input['creative_context'] = $creativeContext;
            $input['prompt_mode'] = !empty($input['delivery']) || !empty($input['section']) ? 'planned' : 'direct';
        }
        return $input;
    }

    /** @return array{run:bool,reason:string} */
    private static function preflightEnrichmentDecision(string $request, array $context, array $taskDecision): array
    {
        if (!self::hasReferenceAssets($context)) {
            return ['run' => false, 'reason' => 'no_reference_asset'];
        }
        $policy = (array)($taskDecision['skill_contract']['enrichment_policy'] ?? []);
        if (!empty($policy['preflight_visual_understanding']) || !empty($taskDecision['preflight_visual_understanding'])) {
            return ['run' => true, 'reason' => 'skill_policy'];
        }
        if (!empty($context['requires_visual_understanding'])) {
            return ['run' => true, 'reason' => 'request_context'];
        }
        $text = mb_strtolower($request, 'UTF-8');
        if (preg_match('/分析|识别|辨认|描述|读图|看图|提取|ocr|analy[sz]e|recogniz|describe|inspect/u', $text) === 1) {
            return ['run' => true, 'reason' => 'explicit_analysis_request'];
        }
        return ['run' => false, 'reason' => 'deferred_on_demand'];
    }

    /** @return array<string, mixed> */
    private static function deferredEnrichment(string $request, array $context, string $reason): array
    {
        $hasVerifiedFacts = !empty($context['verified_product_facts']) || !empty($context['brand_memory']['verified_facts']);
        return [
            'status' => 'skipped',
            'context' => $context,
            'asset_insights' => [],
            'copy_plan' => [],
            'visual_prompt' => [],
            'fact_source_distribution' => [],
            'errors' => [],
            'conditional_claim_categories' => $hasVerifiedFacts ? [] : ProductFactPolicy::requestedEvidenceCategories($request),
            'reason' => $reason,
        ];
    }

    private static function hasReferenceAssets(array $context): bool
    {
        $references = array_merge(
            (array)($context['uploaded_references'] ?? []),
            (array)($context['selected_elements'] ?? $context['selection']['elements'] ?? [])
        );
        foreach ($references as $reference) {
            if (is_string($reference) && trim($reference) !== '') {
                return true;
            }
            if (!is_array($reference)) {
                continue;
            }
            foreach (['url', 'image_url', 'file_url', 'src'] as $key) {
                if (trim((string)($reference[$key] ?? '')) !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array{kind:string,max_tokens:int,timeout_seconds:int,enable_thinking:bool} */
    private static function textProfile(string $request, array $taskDecision = []): array
    {
        $text = mb_strtolower($request, 'UTF-8');
        $skillKey = (string)($taskDecision['selected_skill_key'] ?? '');
        $isDrama = $skillKey !== '' && str_contains($skillKey, 'drama');
        $isStoryboard = preg_match('/分镜|镜头脚本|shot\s*list|storyboard/u', $text) === 1;
        $isScript = $isDrama || preg_match('/短剧|剧本|分集|场景剧本|角色小传|故事大纲/u', $text) === 1;

        if ($isStoryboard) {
            return ['kind' => 'storyboard', 'max_tokens' => 2200, 'timeout_seconds' => 120, 'enable_thinking' => true];
        }
        if ($isScript) {
            return ['kind' => 'drama_script', 'max_tokens' => 2800, 'timeout_seconds' => 120, 'enable_thinking' => true];
        }
        return ['kind' => 'quick_creative', 'max_tokens' => 900, 'timeout_seconds' => 60, 'enable_thinking' => false];
    }

    /** @return array<string, mixed> */
    private static function creativeBriefSummary(array $enrichment): array
    {
        $insight = (array)(($enrichment['asset_insights'] ?? [])[0] ?? []);
        $copy = (array)($enrichment['copy_plan'] ?? []);
        return [
            'status' => (string)($enrichment['status'] ?? 'skipped'),
            'reason' => (string)($enrichment['reason'] ?? ''),
            'subject_type' => (string)($insight['subject_type'] ?? ''),
            'visible_facts' => array_slice((array)($insight['visible_facts'] ?? []), 0, 3),
            'visual_style' => array_slice((array)($insight['visual_style'] ?? []), 0, 3),
            'composition' => (array)($insight['composition'] ?? []),
            'confidence' => (float)($insight['confidence'] ?? 0),
            'headline' => (string)($copy['headline'] ?? ''),
            'subheadline' => (string)($copy['subheadline'] ?? ''),
            'cta' => (string)($copy['cta'] ?? ''),
            'fact_source_distribution' => (array)($enrichment['fact_source_distribution'] ?? []),
        ];
    }

    private static function applyEvidenceRequirement(array &$decision, array $categories): void
    {
        if ($categories === [] || empty($decision['skill_contract'])) return;
        $contract = (array)$decision['skill_contract'];
        $contract['missing_hard_slots'] = ['verified_product_claims'];
        $contract['missing_slots'] = ['verified_product_claims'];
        $contract['clarification_question'] = '你希望强调的具体卖点需要可靠商品资料。请提供对应的参数、认证或商品链接。';
        $decision['missing_hard_slots'] = ['verified_product_claims'];
        $decision['clarification_question'] = (string)$contract['clarification_question'];
        $decision['next_action'] = 'clarify';
        $decision['skill_contract'] = $contract;
    }

    private static function assertSkillAllowsTool(array $skillContract, string $code, array $runtimeAllowedTools = []): void
    {
        $allowedTools = $runtimeAllowedTools !== []
            ? $runtimeAllowedTools
            : (array)($skillContract['allowed_tools'] ?? []);
        if ($allowedTools === []) {
            return;
        }
        $always = ['ask_user', 'retrieve_skills', CanvasProtocol::TOOL_QUERY];
        if (!in_array($code, $always, true) && !in_array($code, $allowedTools, true)) {
            throw new Exception('当前 Skill 未授权使用工具：' . $code);
        }
    }

    private static function retrieveSkills(int $tenantId, string $request, int $limit = 3): array
    {
        $needle = mb_strtolower($request, 'UTF-8');
        $scored = [];
        foreach (AigcCanvasSkillService::routerSkills($tenantId, 40) as $skill) {
            $haystack = mb_strtolower(implode(' ', [(string)($skill['skill_key'] ?? ''), (string)($skill['name'] ?? ''), (string)($skill['description'] ?? ''), (string)($skill['trigger_description'] ?? '')]), 'UTF-8');
            $score = 0;
            foreach (preg_split('/[\s,，。！？!?；;]+/u', $needle) ?: [] as $word) {
                if (mb_strlen($word, 'UTF-8') >= 2 && str_contains($haystack, $word)) {
                    $score++;
                }
            }
            $scored[] = ['score' => $score, 'skill' => [
                'skill_key' => (string)($skill['skill_key'] ?? ''),
                'name' => (string)($skill['name'] ?? ''),
                'description' => mb_substr((string)($skill['description'] ?? ''), 0, 300, 'UTF-8'),
                'prompt' => mb_substr((string)($skill['content_markdown'] ?? ''), 0, 700, 'UTF-8'),
                'allowed_tools' => (array)($skill['tool_policy']['allowed_tools'] ?? []),
            ]];
        }
        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_values(array_map(static fn(array $item): array => $item['skill'], array_slice(array_filter($scored, static fn(array $item): bool => $item['score'] > 0), 0, max(1, min(3, $limit)))));
    }

    private static function toolMessage(array $result): array
    {
        return self::sanitize([
            'tool_calls' => array_map(static fn($call): array => [
                'tool_code' => (string)($call['tool_code'] ?? ''),
                'status' => (string)($call['status'] ?? 'success'),
                'output' => $call['output'] ?? [],
                'error' => (string)($call['error'] ?? ''),
            ], (array)($result['tool_calls'] ?? [])),
            'workspace_actions' => array_map(static fn($action): array => [
                'action_type' => (string)($action['action_type'] ?? ''),
                'status' => (string)($action['status'] ?? 'pending'),
            ], (array)($result['workspace_actions'] ?? [])),
            'assets' => array_map(static fn($asset): array => [
                'type' => (string)($asset['type'] ?? $asset['asset_type'] ?? ''),
                'status' => (string)($asset['status'] ?? 'pending'),
            ], (array)($result['assets'] ?? [])),
        ]);
    }

    private static function mediaSubmissionFailureReply(string $toolCode, array $input = [], string $request = ''): string
    {
        $typeLabel = match ($toolCode) {
            'generate_video' => '视频',
            'generate_music' => '音乐',
            default => '图片',
        };
        return "{$typeLabel}生成任务未能创建，请稍后重试。";
    }

    /** A rejected result must never be presented as a submitted media task. */
    private static function mediaSubmissionVerificationFailureReply(string $toolCode): string
    {
        $typeLabel = match ($toolCode) {
            'generate_video' => "\u{89C6}\u{9891}",
            'generate_music' => "\u{97F3}\u{4E50}",
            default => "\u{56FE}\u{7247}",
        };
        return "{$typeLabel}\u{4EFB}\u{52A1}\u{8FD4}\u{56DE}\u{4E0D}\u{5B8C}\u{6574}\u{FF0C}\u{6211}\u{6CA1}\u{6709}\u{628A}\u{5B83}\u{5F53}\u{4F5C}\u{5DF2}\u{63D0}\u{4EA4}\u{7684}\u{4EFB}\u{52A1}\u{3002}\u{8BF7}\u{7A0D}\u{540E}\u{91CD}\u{8BD5}\u{3002}";
    }

    private static function mediaSubmissionReply(string $toolCode, array $result = [], string $request = ''): string
    {
        $hasResult = false;
        foreach (array_merge((array)($result['assets'] ?? []), (array)($result['workspace_actions'] ?? [])) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $asset = is_array($item['input']['asset'] ?? null) ? $item['input']['asset'] : $item;
            if (trim((string)($asset['url'] ?? $asset['image_url'] ?? $asset['video_url'] ?? $asset['audio_url'] ?? '')) !== '') {
                $hasResult = true;
                break;
            }
        }
        $typeLabel = match ($toolCode) {
            'generate_video' => '视频',
            'generate_music' => '音乐',
            default => '图片',
        };
        return $hasResult
            ? "{$typeLabel}已完成渲染，画布节点已更新。"
            : "已在画布中创建{$typeLabel}生成节点，正在渲染。";
    }

    /**
     * Produces a user-facing summary only from the immutable submission input.
     * It intentionally does not infer a style that the user did not provide.
     */
    private static function mediaSubmissionDirection(array $result, string $originalRequest = ''): string
    {
        $direction = self::mediaSubmissionText($originalRequest);
        if ($direction !== '') {
            return $direction;
        }
        $candidates = [];
        foreach (array_merge((array)($result['workspace_actions'] ?? []), (array)($result['tool_calls'] ?? [])) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $input = is_array($item['input'] ?? null) ? $item['input'] : [];
            $output = is_array($item['output'] ?? null) ? $item['output'] : [];
            $submitted = is_array($input['submitted_input'] ?? null)
                ? $input['submitted_input']
                : (is_array($output['submitted_input'] ?? null) ? $output['submitted_input'] : $input);
            // `prompt` may already be the compiler output. Only immutable user
            // wording may be presented back in the conversation.
            foreach (['original_user_request', 'user_request', 'creative_intent'] as $key) {
                $value = trim((string)($submitted[$key] ?? $input[$key] ?? ''));
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }
        $direction = self::mediaSubmissionText((string)($candidates[0] ?? ''));
        if ($direction === '') {
            return '按当前已确认的创作请求生成。';
        }
        return $direction;
    }

    private static function mediaToolCode(array $toolCalls): string
    {
        foreach ($toolCalls as $call) {
            if (!is_array($call)) {
                continue;
            }
            $code = (string)($call['tool_code'] ?? $call['code'] ?? $call['name'] ?? '');
            if (in_array($code, ['generate_image', 'generate_video', 'generate_music'], true)) {
                return $code;
            }
        }
        return 'generate_image';
    }

    private static function mediaSubmissionText(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }
        $limit = 96;
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $limit, 'UTF-8')) . '…';
    }

    private static function sanitize($value)
    {
        if (!is_array($value)) {
            return is_string($value) && mb_strlen($value, 'UTF-8') > 2000 ? mb_substr($value, 0, 2000, 'UTF-8') . '...' : $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string)$key), ['sku', 'sku_id', 'market_sku_id', 'market_input_sku_id', 'market_output_sku_id', 'api_key', 'token', 'secret'], true)) {
                continue;
            }
            $result[$key] = self::sanitize($item);
        }
        return $result;
    }

    private static function systemPrompt(): string
    {
        return '你是无限画布创作 Agent。先理解用户请求、当前选区和画布，再决定回复、追问或调用工具。memory 是已确认的线程短期记忆：连续修改必须优先遵守其中的目标和保留条件，除非用户明确推翻。retrieved_skills 只是候选能力说明，不是关键词触发器；selected_skill_contract 则是用户显式选择的产品交付合同，必须遵守其中的 allowed_tools、missing_slots、确认和画布策略。若 selected_skill_contract 的 missing_slots 非空，优先调用 ask_user，且一次只问一个最高价值问题，不得提交媒体生成。For a contract with output_policy.batch_mode=true or required_deliverables, deliver every planned item. Never substitute one cover image for a multi-item delivery. 画布上下文会给出 reference_resolution：只有 status=resolved 且 confidence 足够时才可作为编辑目标；status=ambiguous 时必须先追问，不能猜测。需要事实或画布目标时先调用查询工具；工具结果会回到你这里继续判断。图片、视频、音乐只能使用提供的生成工具，系统会走算力市场。任何移动、更新、删除、分组等画布改动都必须调用 canvas_mutation 或 selection_action 生成提案，不能声称已直接修改。只有电商详情、多图分镜、脚本到视频等确实复杂的任务才调用 delegate_subagent；简单请求不要拆分子任务。工具失败后根据失败结果改用可行方案或清楚追问。只有来自用户、已解析画布、上传素材识别或工具结果的信息才能作为事实回复；不得把系统默认比例、数量、风格、平台、价格或工具选择表述为已识别到的用户需求。缺少生成主体、场景或关键信息时，调用 ask_user 询问，不要用默认文案填充。最终回复必须面向用户，不得暴露 SKU、密钥、内部参数或系统实现。回复要先直接回应用户，再给出必要建议或下一步；引用画布或已完成结果时只使用已确认信息，避免模板化状态话术。若渠道不支持原生函数调用，严格返回 JSON 对象 {"assistant_reply":"面向用户的自然回复","tool_calls":[{"name":"工具名","arguments":{}}]}，无需工具时 tool_calls 为空。';
    }

    private static function emit(?callable $emit, string $event, array $payload): void
    {
        if (is_callable($emit)) {
            $emit($event, $payload);
        }
    }
}
