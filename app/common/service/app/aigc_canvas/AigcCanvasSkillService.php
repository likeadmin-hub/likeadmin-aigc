<?php

namespace app\common\service\app\aigc_canvas;

use app\common\model\app\aigc_canvas\AigcCanvasAgentWorkspaceAction;
use app\common\model\app\aigc_canvas\AigcCanvasSkill;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_canvas\agent\orchestrator\DesignAgentOrchestrator;
use Exception;
use think\facade\Db;

class AigcCanvasSkillService
{
    public const TYPE_AGENT_PROMPT = 'agent_prompt';
    public const TYPE_WORKFLOW_TEMPLATE = 'workflow_template';
    public const TYPE_AGENT_WORKFLOW = 'agent_workflow';
    public const SOURCE_BUILTIN = 'builtin';
    public const SOURCE_TENANT = 'tenant';
    private const JSON_POLICY_FIELDS = [
        'examples_json',
        'negative_examples_json',
        'required_slots_json',
        'optional_slots_json',
        'defaults_json',
        'clarification_policy_json',
        'tool_policy_json',
        'output_policy_json',
        'agent_policy_json',
        'tool_schema_json',
        'canvas_output_policy_json',
    ];

    public static function lists(int $tenantId, array $params = []): array
    {
        self::ensureSchema();
        $query = AigcCanvasSkill::where('tenant_id', $tenantId)->where('delete_time', 0);
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword) {
                $query->whereLike('name', '%' . $keyword . '%')
                    ->whereOrLike('skill_key', '%' . $keyword . '%')
                    ->whereOrLike('description', '%' . $keyword . '%');
            });
        }
        $type = trim((string)($params['skill_type'] ?? ''));
        if ($type !== '') {
            $query->where('skill_type', $type);
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', (int)$status);
        }

        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->limit(($pageNo - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        return [
            'lists' => array_map([self::class, 'formatSkill'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    public static function usable(int $tenantId, array $params = []): array
    {
        self::ensureSchema();
        self::seedBuiltinSkills($tenantId);
        $query = AigcCanvasSkill::where([
            'tenant_id' => $tenantId,
            'status' => 1,
            'delete_time' => 0,
        ]);
        $type = trim((string)($params['skill_type'] ?? ''));
        if ($type !== '') {
            $query->where('skill_type', $type);
        }
        $rows = $query
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->limit(max(1, min(100, (int)($params['limit'] ?? 80))))
            ->select()
            ->toArray();
        return array_map([self::class, 'formatSkill'], $rows);
    }

    public static function routerSkills(int $tenantId, int $limit = 80): array
    {
        self::ensureSchema();
        self::seedBuiltinSkills($tenantId);
        $rows = AigcCanvasSkill::where([
            'tenant_id' => $tenantId,
            'status' => 1,
            'delete_time' => 0,
        ])
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->limit(max(1, min(100, $limit)))
            ->select()
            ->toArray();

        return array_map(static fn(array $row): array => self::formatSkill($row, true), $rows);
    }

    public static function detail(int $tenantId, int $id): array
    {
        self::ensureSchema();
        $skill = self::findById($tenantId, $id);
        return self::formatSkill($skill->toArray(), true);
    }

    public static function userDetail(int $tenantId, array $params): array
    {
        self::ensureSchema();
        $skill = self::resolveSkill($tenantId, (string)($params['skill_key'] ?? ''), (int)($params['id'] ?? 0));
        if (empty($skill) || (int)($skill['status'] ?? 0) !== 1) {
            throw new Exception('Skill not found');
        }
        return self::formatSkill($skill, true);
    }

    public static function create(int $tenantId, int $adminId, array $params): array
    {
        self::ensureSchema();
        $data = self::payload($params, true);
        self::assertUniqueKey($tenantId, $data['skill_key']);
        $now = time();
        $skill = AigcCanvasSkill::create(array_merge($data, [
            'tenant_id' => $tenantId,
            'user_id' => $adminId,
            'source_type' => self::SOURCE_TENANT,
            'version' => 1,
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]));
        return self::formatSkill($skill->toArray(), true);
    }

    public static function update(int $tenantId, array $params): array
    {
        self::ensureSchema();
        $id = (int)($params['id'] ?? 0);
        $skill = self::findById($tenantId, $id);
        $data = self::payload($params, false);
        if (!empty($data['skill_key']) && $data['skill_key'] !== (string)$skill['skill_key']) {
            self::assertUniqueKey($tenantId, $data['skill_key'], $id);
        }
        $data['version'] = (int)$skill['version'] + 1;
        $data['update_time'] = time();
        $skill->save($data);
        return self::formatSkill($skill->toArray(), true);
    }

    public static function status(int $tenantId, array $params): array
    {
        self::ensureSchema();
        $skill = self::findById($tenantId, (int)($params['id'] ?? 0));
        $skill->save([
            'status' => (int)!empty($params['status']),
            'update_time' => time(),
        ]);
        return self::formatSkill($skill->toArray(), true);
    }

    public static function delete(int $tenantId, int $id): void
    {
        self::ensureSchema();
        $skill = self::findById($tenantId, $id);
        $skill->save([
            'delete_time' => time(),
            'update_time' => time(),
        ]);
    }

    public static function resolveSkill(int $tenantId, string $skillKey, int $id = 0): array
    {
        self::ensureSchema();
        $query = AigcCanvasSkill::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($id > 0) {
            $query->where('id', $id);
        } else {
            $skillKey = self::normalizeKey($skillKey);
            if ($skillKey === '') {
                return [];
            }
            $query->where('skill_key', $skillKey);
        }
        $row = $query->findOrEmpty();
        return $row->isEmpty() ? [] : $row->toArray();
    }

    public static function runDbSkill(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, array $skill, string $content, array $context, ?callable $emit = null, array $route = []): array
    {
        $type = (string)($skill['skill_type'] ?? '');
        if ($type === self::TYPE_AGENT_WORKFLOW) {
            return (new DesignAgentOrchestrator())->run(
                $tenantId,
                $userId,
                $projectId,
                $threadId,
                $messageId,
                $content,
                $context,
                array_merge($route, [
                    'skill_key' => (string)($skill['skill_key'] ?? ''),
                    'skill_code' => (string)($skill['skill_key'] ?? ''),
                    'skill' => self::formatSkill($skill, true),
                ]),
                $emit
            );
        }
        if ($type === self::TYPE_WORKFLOW_TEMPLATE) {
            $action = self::createWorkflowAction($tenantId, $userId, $projectId, $threadId, $messageId, $skill, $content, $context, $emit);
            return [
                'reply' => '已为你准备好工作流模板，可插入到当前画布。',
                'tool_calls' => [],
                'workspace_actions' => [$action],
                'assets' => [],
                'next_action' => 'insert_workflow',
            ];
        }

        $toolCode = self::toolCodeForSkill($skill, (string)($route['intent'] ?? ''));
        if (in_array($toolCode, ['generate_image', 'generate_video', 'generate_music'], true)) {
            $input = self::toolInputForSkill($tenantId, $toolCode, $skill, $content, $context, $route);
            $input['project_id'] = $projectId;
            if ($toolCode === 'generate_image') {
                return self::executeImageToolBatches(
                    $tenantId,
                    $userId,
                    $projectId,
                    $threadId,
                    $messageId,
                    $input,
                    $content,
                    $context,
                    $emit
                );
            }
            return AigcCanvasAgentRuntimeService::executeExternalToolWithActions(
                $tenantId,
                $userId,
                $projectId,
                $threadId,
                $messageId,
                $toolCode,
                $input,
                $content,
                $context,
                $emit
            );
        }

        $tool = self::executePromptSkill($tenantId, $userId, $projectId, $threadId, $messageId, $skill, $content, $context, $emit, $route);
        $reply = (string)($tool['output']['content'] ?? $tool['output']['text'] ?? '');
        return [
            'reply' => $reply !== '' ? $reply : '已完成。',
            'tool_calls' => [$tool],
            'workspace_actions' => [],
            'assets' => [],
            'next_action' => 'chat',
            'reply_streamed' => !empty($tool['streamed']),
        ];
    }

    public static function seedBuiltinSkills(int $tenantId): void
    {
        self::ensureSchema();
        foreach (self::builtinSkills() as $index => $item) {
            $key = (string)$item['skill_key'];
            $exists = AigcCanvasSkill::where([
                'tenant_id' => $tenantId,
                'skill_key' => $key,
            ])->findOrEmpty();
            if (!$exists->isEmpty()) {
                continue;
            }
            $now = time();
            AigcCanvasSkill::create(array_merge($item, [
                'tenant_id' => $tenantId,
                'user_id' => 0,
                'source_type' => self::SOURCE_BUILTIN,
                'status' => 1,
                'version' => 1,
                'sort' => (int)($item['sort'] ?? (100 - $index)),
                'create_time' => $now,
                'update_time' => $now,
                'delete_time' => 0,
            ]));
        }
    }

    private static function executePromptSkill(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, array $skill, string $content, array $context, ?callable $emit, array $route = []): array
    {
        $input = [
            'prompt' => self::composePrompt($skill, $content, $context, $route),
            'project_id' => $projectId,
            'canvas_snapshot' => $context,
            'request_id' => (string)($route['request_id'] ?? ''),
        ];
        $runtime = new class {
            public function call(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, array $input, ?callable $emit): array
            {
                return AigcCanvasAgentRuntimeService::executeExternalTool($tenantId, $userId, $projectId, $threadId, $messageId, 'generate_text', $input, $emit);
            }
        };
        return $runtime->call($tenantId, $userId, $projectId, $threadId, $messageId, $input, $emit);
    }

    private static function createWorkflowAction(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, array $skill, string $prompt, array $context, ?callable $emit): array
    {
        $now = time();
        $workflow = is_array($skill['workflow_json'] ?? null) ? $skill['workflow_json'] : [];
        $action = AigcCanvasAgentWorkspaceAction::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'tool_call_id' => 0,
            'action_type' => 'insert_workflow',
            'status' => 'pending',
            'input_json' => [
                'skill' => self::formatSkill($skill),
                'workflow' => [
                    'nodes' => array_values((array)($workflow['nodes'] ?? [])),
                    'connections' => array_values((array)($workflow['connections'] ?? $workflow['edges'] ?? [])),
                    'viewport' => is_array($workflow['viewport'] ?? null) ? $workflow['viewport'] : [],
                ],
                'prompt' => $prompt,
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

    private static function composePrompt(array $skill, string $content, array $context, array $route = []): string
    {
        $template = trim((string)($skill['content_markdown'] ?? ''));
        $trigger = trim((string)($skill['trigger_description'] ?? ''));
        $summary = trim((string)($context['canvas_summary'] ?? ''));
        $selected = array_slice((array)($context['selected_elements'] ?? []), 0, 5);
        $slots = is_array($route['slots'] ?? null) ? $route['slots'] : [];
        return trim(implode("\n\n", array_filter([
            $template !== '' ? "Skill:\n" . $template : '',
            $trigger !== '' ? "适用说明:\n" . $trigger : '',
            "用户需求:\n" . $content,
            $summary !== '' ? "画布摘要:\n" . $summary : '',
            !empty($selected) ? "用户明确引用的画布元素:\n" . json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            !empty($slots) ? "Agent slots:\n" . json_encode($slots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ])));
    }

    private static function toolCodeForSkill(array $skill, string $intent = ''): string
    {
        $policy = self::normalizeJsonPayload($skill['tool_policy_json'] ?? []);
        $allowed = array_values(array_filter(array_map('strval', (array)($policy['allowed_tools'] ?? []))));
        foreach ($allowed as $tool) {
            if (in_array($tool, ['generate_text', 'generate_image', 'generate_video', 'generate_music'], true)) {
                return $tool;
            }
        }
        if (in_array($intent, ['generate_image', 'generate_video', 'generate_music'], true)) {
            return $intent;
        }
        return 'generate_text';
    }

    private static function toolInputForSkill(int $tenantId, string $toolCode, array $skill, string $content, array $context, array $route): array
    {
        $slots = is_array($route['slots'] ?? null) ? $route['slots'] : [];
        $defaults = self::normalizeJsonPayload($skill['defaults_json'] ?? []);
        $toolOptions = is_array($route['tool_options'][$toolCode] ?? null) ? $route['tool_options'][$toolCode] : [];
        $prompt = trim((string)($slots['prompt'] ?? $slots['product_info'] ?? $slots['visual_subject'] ?? $content));
        $selectedMentions = AigcCanvasAgentRuntimeService::selectedMentionsForSkill($context);
        $input = array_merge($defaults, $toolOptions, $slots, [
            'prompt' => $prompt,
            'project_id' => (int)($context['project']['id'] ?? $context['project_id'] ?? 0),
            'selected_mentions' => $selectedMentions,
            'request_id' => (string)($route['request_id'] ?? ''),
        ]);
        $referenceAssets = self::referenceAssetsFromMentions($selectedMentions);
        if (!empty($referenceAssets)) {
            $input['reference_assets'] = array_values($referenceAssets);
            $input['reference_images'] = array_values(array_unique(array_filter(array_map(
                static fn(array $asset): string => (string)($asset['type'] ?? '') === 'image' ? (string)($asset['url'] ?? $asset['uri'] ?? '') : '',
                $referenceAssets
            ))));
        }
        if ($toolCode === 'generate_music') {
            $input['content'] = (string)($input['content'] ?? $input['prompt'] ?? $content);
            $input['duration'] = (int)($input['duration'] ?? 30);
        }
        return $input;
    }

    private static function referenceAssetsFromMentions(array $mentions): array
    {
        $assets = [];
        foreach ($mentions as $mention) {
            if (!is_array($mention)) {
                continue;
            }
            $url = trim((string)($mention['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $rawType = strtolower((string)($mention['type'] ?? ''));
            $assetType = strtolower((string)($mention['asset_type'] ?? $mention['role'] ?? ''));
            $mimeType = strtolower((string)($mention['mime_type'] ?? ''));
            if ($rawType === 'video' || str_contains($assetType, 'video') || str_starts_with($mimeType, 'video/')) {
                $type = 'video';
            } elseif ($rawType === 'audio' || str_contains($assetType, 'audio') || str_starts_with($mimeType, 'audio/')) {
                $type = 'audio';
            } elseif ($rawType === 'image' || $rawType === 'asset') {
                $type = 'image';
            } else {
                continue;
            }
            $key = $type . ':' . $url;
            $assets[$key] = [
                'type' => $type,
                'uri' => $url,
                'url' => $url,
                'name' => (string)($mention['name'] ?? ''),
            ];
        }
        return array_values($assets);
    }

    private static function executeImageToolBatches(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, array $input, string $content, array $context, ?callable $emit): array
    {
        $requestedQuantity = max(1, (int)($input['quantity'] ?? 1));
        $perTaskQuantity = self::agentImagePerTaskQuantity($tenantId, $input);
        $remaining = $requestedQuantity;
        $results = [
            'reply' => '',
            'tool_calls' => [],
            'workspace_actions' => [],
            'assets' => [],
            'next_action' => 'execute_tool',
        ];

        while ($remaining > 0) {
            $currentQuantity = min($perTaskQuantity, $remaining);
            $taskInput = array_merge($input, ['quantity' => $currentQuantity]);
            $result = AigcCanvasAgentRuntimeService::executeExternalToolWithActions(
                $tenantId,
                $userId,
                $projectId,
                $threadId,
                $messageId,
                'generate_image',
                $taskInput,
                $content,
                $context,
                $emit,
                $currentQuantity
            );
            $results['tool_calls'] = array_merge($results['tool_calls'], (array)($result['tool_calls'] ?? []));
            $results['workspace_actions'] = array_merge(
                $results['workspace_actions'],
                array_slice((array)($result['workspace_actions'] ?? []), 0, $currentQuantity)
            );
            $results['assets'] = array_merge(
                $results['assets'],
                array_slice((array)($result['assets'] ?? []), 0, $currentQuantity)
            );
            $remaining -= $currentQuantity;
        }

        $taskCount = count($results['tool_calls']);
        $hasResolvedAsset = !empty(array_filter($results['assets'], fn($asset) => !empty($asset['url'])));
        if ($taskCount > 1) {
            $results['reply'] = $hasResolvedAsset
                ? "已生成 {$requestedQuantity} 张结果。"
                : "已拆分提交 {$taskCount} 个生图任务。";
        } else {
            $results['reply'] = $hasResolvedAsset ? '已生成结果。' : '已提交生成任务。';
        }
        return $results;
    }

    private static function agentImagePerTaskQuantity(int $tenantId, array $input): int
    {
        try {
            $selection = AigcImageChannelService::resolveSelection($tenantId, $input);
            $options = array_values(array_filter(array_map('intval', (array)($selection['channel']['quantity_options'] ?? [1]))));
            if (empty($options)) {
                $options = [1];
            }
            sort($options);
            return in_array(1, $options, true) ? 1 : max(1, (int)$options[0]);
        } catch (Exception) {
            return 1;
        }
    }

    private static function payload(array $params, bool $creating): array
    {
        $name = trim((string)($params['name'] ?? ''));
        $key = self::normalizeKey((string)($params['skill_key'] ?? ''));
        $type = (string)($params['skill_type'] ?? self::TYPE_AGENT_PROMPT);
        if ($creating && ($name === '' || $key === '')) {
            throw new Exception('Please enter skill name and key');
        }
        if (!in_array($type, [self::TYPE_AGENT_PROMPT, self::TYPE_WORKFLOW_TEMPLATE, self::TYPE_AGENT_WORKFLOW], true)) {
            $type = self::TYPE_AGENT_PROMPT;
        }
        $workflow = self::normalizeJsonPayload($params['workflow_json'] ?? []);
        $jsonPolicies = [];
        foreach (self::JSON_POLICY_FIELDS as $field) {
            $jsonPolicies[$field] = self::normalizeJsonPayload($params[$field] ?? []);
        }
        return array_filter(array_merge([
            'skill_key' => $key,
            'name' => $name,
            'description' => mb_substr(trim((string)($params['description'] ?? '')), 0, 500, 'UTF-8'),
            'category' => mb_substr(trim((string)($params['category'] ?? 'general')), 0, 80, 'UTF-8'),
            'skill_type' => $type,
            'content_markdown' => trim((string)($params['content_markdown'] ?? '')),
            'trigger_description' => trim((string)($params['trigger_description'] ?? '')),
            'workflow_json' => $workflow,
            'cover_url' => mb_substr(trim((string)($params['cover_url'] ?? '')), 0, 500, 'UTF-8'),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
        ], $jsonPolicies), static fn($value) => $value !== '' && $value !== null);
    }

    private static function findById(int $tenantId, int $id): AigcCanvasSkill
    {
        if ($id <= 0) {
            throw new Exception('Skill not found');
        }
        $skill = AigcCanvasSkill::where([
            'tenant_id' => $tenantId,
            'id' => $id,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($skill->isEmpty()) {
            throw new Exception('Skill not found');
        }
        return $skill;
    }

    private static function assertUniqueKey(int $tenantId, string $key, int $exceptId = 0): void
    {
        $query = AigcCanvasSkill::where([
            'tenant_id' => $tenantId,
            'skill_key' => $key,
            'delete_time' => 0,
        ]);
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        if (!$query->findOrEmpty()->isEmpty()) {
            throw new Exception('Skill key already exists');
        }
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_\\-]/', '_', $key) ?? '';
        return trim($key, '_-');
    }

    private static function normalizeJsonPayload($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    public static function formatSkill(array $row, bool $detail = false): array
    {
        $data = [
            'id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'skill_key' => (string)($row['skill_key'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'category' => (string)($row['category'] ?? 'general'),
            'skill_type' => (string)($row['skill_type'] ?? self::TYPE_AGENT_PROMPT),
            'source_type' => (string)($row['source_type'] ?? self::SOURCE_TENANT),
            'trigger_description' => (string)($row['trigger_description'] ?? ''),
            'cover_url' => (string)($row['cover_url'] ?? ''),
            'status' => (int)($row['status'] ?? 1),
            'version' => (int)($row['version'] ?? 1),
            'sort' => (int)($row['sort'] ?? 0),
            'create_time' => (int)($row['create_time'] ?? 0),
            'update_time' => (int)($row['update_time'] ?? 0),
        ];
        if ($detail) {
            $data['content_markdown'] = (string)($row['content_markdown'] ?? '');
            $data['workflow_json'] = is_array($row['workflow_json'] ?? null) ? $row['workflow_json'] : [];
            foreach (self::JSON_POLICY_FIELDS as $field) {
                $data[$field] = is_array($row[$field] ?? null) ? $row[$field] : [];
            }
        }
        return $data;
    }

    private static function builtinSkills(): array
    {
        return [[
            'skill_key' => 'ecommerce_detail_page',
            'name' => '电商详情页设计',
            'description' => '根据商品资料和核心卖点规划完整电商详情页，生成分区视觉并组装到无限画布。',
            'category' => 'ecommerce',
            'skill_type' => self::TYPE_AGENT_WORKFLOW,
            'content_markdown' => implode("\n", [
                '# 电商详情页设计',
                '先确认商品来源和核心卖点。信息不足时只追问，不创建任务。',
                '信息完整后规划首屏主视觉、核心卖点、使用场景、材质细节、规格与信任等区块。',
                '每个区块只提交一个图像任务，并以 JSON Canvas 纵向组装。',
            ]),
            'trigger_description' => '淘宝详情页、天猫详情页、商品详情长图、电商详情页、整套商品详情视觉。',
            'workflow_json' => [],
            'examples_json' => ['给这款蓝牙耳机做完整淘宝详情页', '根据上传的商品图生成 5 个详情页区块'],
            'negative_examples_json' => ['写一段商品介绍文案', '生成一张普通商品主图', '分析这张图片'],
            'required_slots_json' => [
                ['key' => 'product_info', 'any_of_context' => ['uploaded_references'], 'label' => '商品信息或商品参考图'],
                ['key' => 'selling_points', 'label' => '核心卖点'],
            ],
            'optional_slots_json' => ['platform', 'target_audience', 'style', 'brand_colors', 'section_count', 'ratio', 'detail_sections'],
            'defaults_json' => ['platform' => 'taobao', 'section_count' => 5, 'style' => '高级、简洁、真实商业摄影'],
            'clarification_policy_json' => [
                'max_questions' => 3,
                'questions' => [
                    'product_info' => '请告诉我商品是什么，或上传一张清晰的商品图。',
                    'selling_points' => '请补充至少一个核心卖点，例如降噪、长续航、轻便或材质优势。',
                ],
            ],
            'tool_policy_json' => ['allowed_tools' => ['create_page', 'add_element', 'update_element', 'generate_image']],
            'output_policy_json' => ['format' => 'json_canvas', 'workspace_action' => 'apply_json_canvas'],
            'agent_policy_json' => ['agents' => ['master', 'planner', 'copy', 'visual', 'canvas'], 'max_rounds' => 6, 'max_tool_calls' => 24],
            'tool_schema_json' => ['required_tools' => ['create_page', 'add_element', 'generate_image']],
            'canvas_output_policy_json' => ['version' => '1.1', 'page_width' => 750, 'auto_apply' => true, 'layout' => 'vertical'],
            'cover_url' => '',
            'sort' => 1000,
        ]];
    }

    private static function legacyBuiltinSkills(): array
    {
        return [
            [
                'skill_key' => 'ecommerce_image',
                'name' => '电商商品图',
                'description' => '生成商品主图、详情图、卖点图和平台营销视觉。',
                'category' => 'ecommerce',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是电商视觉 Agent。根据商品信息、平台、图片类型和风格，直接组织可用于生成的商业图片提示词，并调用图像生成工具。',
                'trigger_description' => '淘宝详情图、商品主图、电商海报、卖点图、产品图、带货视觉。',
                'workflow_json' => [],
                'examples_json' => ['帮我生成两张淘宝详情图', '给蓝牙耳机做一张商品主图', '做一张小红书风格产品海报'],
                'negative_examples_json' => ['写一段商品详情文案', '帮我策划发布会流程'],
                'required_slots_json' => ['product_info'],
                'optional_slots_json' => ['platform', 'image_type', 'style', 'quantity', 'ratio', 'selling_points', 'content_sections', 'reference_style', 'target_audience'],
                'defaults_json' => ['platform' => 'taobao', 'image_type' => 'detail', 'quantity' => 1, 'ratio' => '1:1'],
                'clarification_policy_json' => ['max_questions' => 3, 'questions' => ['product_info' => '请告诉我商品是什么，或上传一张商品图。我会按默认电商尺寸继续生成。']],
                'tool_policy_json' => ['allowed_tools' => ['generate_image']],
                'output_policy_json' => ['workspace_action' => 'insert_image'],
                'cover_url' => '',
                'sort' => 130,
            ],
            [
                'skill_key' => 'general_image',
                'name' => '通用生图',
                'description' => '根据自然语言生成海报、插画、照片、视觉概念图。',
                'category' => 'image',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是通用图像生成 Agent。识别画面主体、风格、比例和数量，并调用图像生成工具。',
                'trigger_description' => '生成图片、海报、插画、照片、封面、视觉设计。',
                'workflow_json' => [],
                'examples_json' => ['生成一张赛博朋克海报', '画一张高级感咖啡产品图'],
                'negative_examples_json' => ['写方案', '生成视频', '写音乐'],
                'required_slots_json' => ['visual_subject'],
                'optional_slots_json' => ['style', 'ratio', 'quantity'],
                'defaults_json' => ['quantity' => 1, 'ratio' => '1:1'],
                'clarification_policy_json' => ['max_questions' => 3, 'questions' => ['visual_subject' => '你想生成什么画面？请补充主体或场景。']],
                'tool_policy_json' => ['allowed_tools' => ['generate_image']],
                'output_policy_json' => ['workspace_action' => 'insert_image'],
                'cover_url' => '',
                'sort' => 128,
            ],
            [
                'skill_key' => 'video_generation',
                'name' => '视频生成',
                'description' => '把提示词或引用图片扩展为短视频、镜头和动态画面。',
                'category' => 'video',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是视频生成 Agent。识别视频主体、时长、比例、运动方式和参考素材，并调用视频生成工具。',
                'trigger_description' => '生成视频、图生视频、短片、动画、镜头、运镜。',
                'workflow_json' => [],
                'examples_json' => ['把这张图做成 5 秒视频', '生成一个产品旋转展示视频'],
                'negative_examples_json' => ['生成一张图片', '写一份策划案'],
                'required_slots_json' => ['video_subject'],
                'optional_slots_json' => ['duration', 'ratio', 'motion', 'quality'],
                'defaults_json' => ['duration' => 5, 'ratio' => '16:9'],
                'clarification_policy_json' => ['max_questions' => 3, 'questions' => ['video_subject' => '你想生成什么视频画面？如果是图生视频，请先选择或上传参考图。']],
                'tool_policy_json' => ['allowed_tools' => ['generate_video']],
                'output_policy_json' => ['workspace_action' => 'insert_video'],
                'cover_url' => '',
                'sort' => 126,
            ],
            [
                'skill_key' => 'music_generation',
                'name' => '音乐生成',
                'description' => '生成配乐、歌曲、音频氛围和旁白方向。',
                'category' => 'audio',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是音乐生成 Agent。识别风格、情绪、时长和用途，并调用音乐生成工具。',
                'trigger_description' => '生成音乐、配乐、歌曲、音频、BGM、旁白。',
                'workflow_json' => [],
                'examples_json' => ['生成一段科技感发布会开场音乐', '做一段 30 秒轻快 BGM'],
                'negative_examples_json' => ['生成图片', '写营销策划'],
                'required_slots_json' => ['music_subject'],
                'optional_slots_json' => ['style', 'duration', 'mood'],
                'defaults_json' => ['duration' => 30],
                'clarification_policy_json' => ['max_questions' => 3, 'questions' => ['music_subject' => '你想生成什么类型的音乐？请补充用途、情绪或风格。']],
                'tool_policy_json' => ['allowed_tools' => ['generate_music']],
                'output_policy_json' => ['workspace_action' => 'insert_audio'],
                'cover_url' => '',
                'sort' => 124,
            ],
            [
                'skill_key' => 'script_planning',
                'name' => '文案策划',
                'description' => '生成策划案、流程、脚本、营销文案和创意拆解。',
                'category' => 'planning',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是无限画布的策划与文案 Agent。只根据用户明确提供的信息输出结构化方案，不要默认引用画布内容。',
                'trigger_description' => '策划案、流程方案、发布会、商品文案、脚本、创意方案、分析建议。',
                'workflow_json' => [],
                'examples_json' => ['帮我生成一份新品发布会流程策划案', '写一段商品详情文案', '拆解一个短视频脚本'],
                'negative_examples_json' => ['生成一张图', '把图做成视频'],
                'required_slots_json' => [],
                'optional_slots_json' => ['topic', 'tone', 'length'],
                'defaults_json' => [],
                'clarification_policy_json' => ['max_questions' => 3],
                'tool_policy_json' => ['allowed_tools' => ['generate_text']],
                'output_policy_json' => ['format' => 'markdown'],
                'cover_url' => '',
                'sort' => 122,
            ],
            [
                'skill_key' => 'asset_analysis',
                'name' => '素材分析',
                'description' => '分析用户明确引用或上传的图片、视频、文本素材。',
                'category' => 'analysis',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是素材分析 Agent。只分析用户明确引用、上传或选择的素材，输出可执行建议。',
                'trigger_description' => '分析这张图、参考这个素材、根据选中节点总结、优化当前素材。',
                'workflow_json' => [],
                'examples_json' => ['分析这张图适合怎么做海报', '根据选中节点给我优化建议'],
                'negative_examples_json' => ['没有引用素材时的普通聊天', '直接生成图片'],
                'required_slots_json' => ['reference_asset'],
                'optional_slots_json' => ['analysis_goal'],
                'defaults_json' => [],
                'clarification_policy_json' => ['max_questions' => 3, 'questions' => ['reference_asset' => '请先选择画布节点或上传参考素材，我再帮你分析。']],
                'tool_policy_json' => ['allowed_tools' => ['generate_text']],
                'output_policy_json' => ['format' => 'markdown'],
                'cover_url' => '',
                'sort' => 120,
            ],
            [
                'skill_key' => 'general_chat',
                'name' => '通用对话',
                'description' => '无法匹配具体生成工具时，作为普通创作助手回答。',
                'category' => 'general',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是无限画布创作助手。回答用户问题，必要时给出下一步建议；不要默认调用生成工具。',
                'trigger_description' => '普通问答、解释、建议、不确定意图。',
                'workflow_json' => [],
                'examples_json' => ['你能做什么', '帮我想几个方向'],
                'negative_examples_json' => [],
                'required_slots_json' => [],
                'optional_slots_json' => [],
                'defaults_json' => [],
                'clarification_policy_json' => ['max_questions' => 3],
                'tool_policy_json' => ['allowed_tools' => ['generate_text']],
                'output_policy_json' => ['format' => 'markdown'],
                'cover_url' => '',
                'sort' => 118,
            ],
            [
                'skill_key' => 'creative_plan',
                'name' => '创作方案',
                'description' => '根据用户目标生成可执行的创意方案、流程和交付清单。',
                'category' => 'general',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是无限画布的创作策划 Agent。请输出目标、核心策略、执行步骤、素材清单、风险提示和交付物。',
                'trigger_description' => '方案、策划、文案、活动流程、创意拆解。',
                'workflow_json' => [],
                'tool_policy_json' => ['allowed_tools' => ['generate_text']],
                'cover_url' => '',
                'sort' => 100,
            ],
            [
                'skill_key' => 'product_image_prompt',
                'name' => '商品图',
                'description' => '为商品主图、模特图、卖点图生成结构化提示词。',
                'category' => 'ecommerce',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是商品视觉提示词专家。请围绕商品卖点、目标人群、画面构图、光线、材质和电商转化输出可直接用于生图的提示词。',
                'trigger_description' => '商品图、主图、模特图、电商视觉、卖点图。',
                'workflow_json' => [],
                'tool_policy_json' => ['allowed_tools' => ['generate_text']],
                'cover_url' => '',
                'sort' => 92,
            ],
            [
                'skill_key' => 'launch_event_plan',
                'name' => '发布会策划',
                'description' => '生成新品发布会流程、视觉、传播和执行排期。',
                'category' => 'marketing',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是品牌发布会策划 Agent。请输出发布会主题、流程安排、舞美视觉、嘉宾/媒体安排、传播节奏、物料清单和执行排期。',
                'trigger_description' => '新品发布会、活动流程、品牌发布、营销策划。',
                'workflow_json' => [],
                'tool_policy_json' => ['allowed_tools' => ['generate_text']],
                'cover_url' => '',
                'sort' => 90,
            ],
            [
                'skill_key' => 'wf_product_ecommerce_set',
                'name' => '商品图工作流',
                'description' => '插入商品信息、参考图、主图生成和卖点图生成节点。',
                'category' => 'workflow',
                'skill_type' => self::TYPE_WORKFLOW_TEMPLATE,
                'content_markdown' => '',
                'trigger_description' => '商品图批量工作流、主图和卖点图生成。',
                'workflow_json' => self::workflowProductSet(),
                'tool_policy_json' => [],
                'cover_url' => '',
                'sort' => 80,
            ],
            [
                'skill_key' => 'wf_short_drama_character',
                'name' => '短剧角色工作流',
                'description' => '插入角色设定、正面图和三视图扩展节点。',
                'category' => 'workflow',
                'skill_type' => self::TYPE_WORKFLOW_TEMPLATE,
                'content_markdown' => '',
                'trigger_description' => '短剧角色、人物设定、三视图。',
                'workflow_json' => self::workflowCharacterSet(),
                'tool_policy_json' => [],
                'cover_url' => '',
                'sort' => 78,
            ],
        ];
    }

    private static function workflowProductSet(): array
    {
        return [
            'nodes' => [
                ['id' => 'product_info', 'type' => 'text', 'position' => ['x' => 0, 'y' => 0], 'metadata' => ['title' => '商品信息', 'content' => '填写商品名称、核心卖点、材质、颜色、目标人群和使用场景。']],
                ['id' => 'product_ref', 'type' => 'image', 'position' => ['x' => 0, 'y' => 240], 'metadata' => ['title' => '商品参考图', 'image' => '', 'url' => '', 'status' => 'idle']],
                ['id' => 'main_prompt', 'type' => 'text', 'position' => ['x' => 420, 'y' => 0], 'metadata' => ['title' => '主图提示词', 'content' => '生成高级电商主图，突出商品卖点，干净背景，商业摄影质感。']],
                ['id' => 'main_image', 'type' => 'image', 'position' => ['x' => 840, 'y' => 0], 'metadata' => ['title' => '生成商品主图', 'prompt' => '', 'status' => 'idle']],
                ['id' => 'selling_prompt', 'type' => 'text', 'position' => ['x' => 420, 'y' => 260], 'metadata' => ['title' => '卖点图提示词', 'content' => '生成突出功能结构、材质细节和核心卖点的电商卖点图。']],
                ['id' => 'selling_image', 'type' => 'image', 'position' => ['x' => 840, 'y' => 260], 'metadata' => ['title' => '生成卖点图', 'prompt' => '', 'status' => 'idle']],
            ],
            'connections' => [
                ['fromNodeId' => 'product_info', 'toNodeId' => 'main_image'],
                ['fromNodeId' => 'product_ref', 'toNodeId' => 'main_image'],
                ['fromNodeId' => 'main_prompt', 'toNodeId' => 'main_image'],
                ['fromNodeId' => 'product_info', 'toNodeId' => 'selling_image'],
                ['fromNodeId' => 'product_ref', 'toNodeId' => 'selling_image'],
                ['fromNodeId' => 'selling_prompt', 'toNodeId' => 'selling_image'],
            ],
        ];
    }

    private static function workflowCharacterSet(): array
    {
        return [
            'nodes' => [
                ['id' => 'character_desc', 'type' => 'text', 'position' => ['x' => 0, 'y' => 0], 'metadata' => ['title' => '角色设定', 'content' => '填写角色姓名、年龄、外貌、服装、性格、世界观和表演气质。']],
                ['id' => 'front_prompt', 'type' => 'text', 'position' => ['x' => 420, 'y' => 0], 'metadata' => ['title' => '正面全身提示词', 'content' => '根据角色设定生成正面全身角色图，白色简洁背景，高清写实，电影级质感。']],
                ['id' => 'front_image', 'type' => 'image', 'position' => ['x' => 840, 'y' => 0], 'metadata' => ['title' => '正面角色参考', 'image' => '', 'url' => '', 'status' => 'idle']],
                ['id' => 'side_prompt', 'type' => 'text', 'position' => ['x' => 420, 'y' => 260], 'metadata' => ['title' => '侧面角色提示词', 'content' => '基于正面参考生成侧面角色图，保持五官、服装和气质一致。']],
                ['id' => 'side_image', 'type' => 'image', 'position' => ['x' => 840, 'y' => 260], 'metadata' => ['title' => '侧面角色图', 'image' => '', 'url' => '', 'status' => 'idle']],
                ['id' => 'scene_prompt', 'type' => 'text', 'position' => ['x' => 420, 'y' => 520], 'metadata' => ['title' => '生活场景提示词', 'content' => '基于角色参考生成适合短剧宣传的生活场景图。']],
                ['id' => 'scene_image', 'type' => 'image', 'position' => ['x' => 840, 'y' => 520], 'metadata' => ['title' => '生活场景图', 'image' => '', 'url' => '', 'status' => 'idle']],
            ],
            'connections' => [
                ['fromNodeId' => 'character_desc', 'toNodeId' => 'front_image'],
                ['fromNodeId' => 'front_prompt', 'toNodeId' => 'front_image'],
                ['fromNodeId' => 'front_image', 'toNodeId' => 'side_image'],
                ['fromNodeId' => 'side_prompt', 'toNodeId' => 'side_image'],
                ['fromNodeId' => 'front_image', 'toNodeId' => 'scene_image'],
                ['fromNodeId' => 'scene_prompt', 'toNodeId' => 'scene_image'],
            ],
        ];
    }

    public static function ensureSchema(): void
    {
        return;
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        Db::execute(<<<SQL
CREATE TABLE IF NOT EXISTS `la_aigc_canvas_skill` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `skill_key` varchar(120) NOT NULL DEFAULT '',
  `name` varchar(120) NOT NULL DEFAULT '',
  `description` varchar(500) NOT NULL DEFAULT '',
  `category` varchar(80) NOT NULL DEFAULT 'general',
  `skill_type` varchar(40) NOT NULL DEFAULT 'agent_prompt',
  `source_type` varchar(40) NOT NULL DEFAULT 'tenant',
  `content_markdown` longtext,
  `trigger_description` text,
  `workflow_json` longtext,
  `examples_json` text,
  `negative_examples_json` text,
  `required_slots_json` text,
  `optional_slots_json` text,
  `defaults_json` text,
  `clarification_policy_json` text,
  `tool_policy_json` text,
  `output_policy_json` text,
  `agent_policy_json` text,
  `tool_schema_json` text,
  `canvas_output_policy_json` text,
  `cover_url` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `version` int unsigned NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_skill_key` (`tenant_id`,`skill_key`,`delete_time`),
  KEY `idx_tenant_type_status` (`tenant_id`,`skill_type`,`status`,`delete_time`),
  KEY `idx_tenant_sort` (`tenant_id`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas skills'
SQL);
        self::ensureJsonPolicyColumns();
    }

    private static function ensureJsonPolicyColumns(): void
    {
        $columns = [
            'examples_json' => 'text',
            'negative_examples_json' => 'text',
            'required_slots_json' => 'text',
            'optional_slots_json' => 'text',
            'defaults_json' => 'text',
            'clarification_policy_json' => 'text',
            'tool_policy_json' => 'text',
            'output_policy_json' => 'text',
            'agent_policy_json' => 'text',
            'tool_schema_json' => 'text',
            'canvas_output_policy_json' => 'text',
        ];
        foreach ($columns as $column => $definition) {
            try {
                $exists = Db::query("SHOW COLUMNS FROM `la_aigc_canvas_skill` LIKE '{$column}'");
                if (empty($exists)) {
                    Db::execute("ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `{$column}` {$definition} NULL AFTER `workflow_json`");
                }
            } catch (Exception) {
                // App migrations create these fields; runtime repair is best-effort for upgraded installs.
            }
        }
    }
}
