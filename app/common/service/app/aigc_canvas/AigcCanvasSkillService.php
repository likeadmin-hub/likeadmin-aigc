<?php

namespace app\common\service\app\aigc_canvas;

use app\common\model\app\aigc_canvas\AigcCanvasSkill;
use app\common\service\app\aigc_canvas\agent\orchestrator\DesignAgentOrchestrator;
use Exception;
use think\facade\Db;

class AigcCanvasSkillService
{
    public const TYPE_AGENT_PROMPT = 'agent_prompt';
    public const TYPE_AGENT_WORKFLOW = 'agent_workflow';
    public const SOURCE_BUILTIN = 'builtin';
    public const SOURCE_TENANT = 'tenant';
    public const RELEASE_DRAFT = 'draft';
    public const RELEASE_TESTING = 'testing';
    public const RELEASE_CANARY = 'canary';
    public const RELEASE_ACTIVE = 'active';
    public const RELEASE_PAUSED = 'paused';
    public const RELEASE_ARCHIVED = 'archived';
    private const CATALOG_REVISION = '20260726.1';
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
        'visibility_policy_json',
        'model_policy_json',
        'execution_policy_json',
        'quality_policy_json',
        'safety_policy_json',
        'analytics_policy_json',
    ];

    public static function lists(int $tenantId, array $params = []): array
    {
        self::ensureSchema();
        $query = AigcCanvasSkill::where('tenant_id', $tenantId)
            ->where('delete_time', 0)
            ->whereIn('skill_type', [self::TYPE_AGENT_PROMPT, self::TYPE_AGENT_WORKFLOW]);
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
            'release_status' => self::RELEASE_ACTIVE,
            'delete_time' => 0,
        ])->whereIn('skill_type', [self::TYPE_AGENT_PROMPT, self::TYPE_AGENT_WORKFLOW]);
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
            'release_status' => self::RELEASE_ACTIVE,
            'delete_time' => 0,
        ])->whereIn('skill_type', [self::TYPE_AGENT_PROMPT, self::TYPE_AGENT_WORKFLOW])
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
        if (empty($skill) || (int)($skill['status'] ?? 0) !== 1 || (string)($skill['release_status'] ?? self::RELEASE_ACTIVE) !== self::RELEASE_ACTIVE) {
            throw new Exception('Skill not found');
        }
        return self::formatSkill($skill, true);
    }

    /**
     * Resolve a user-selected product Skill for the Agent Loop. A selection
     * narrows the Agent's contract; it never switches back to legacy routing.
     */
    public static function selectedForAgent(int $tenantId, array $params): array
    {
        $key = trim((string)($params['skill_key'] ?? $params['skill_code'] ?? ''));
        $id = (int)($params['skill_id'] ?? 0);
        if (($key === '' || $key === 'agent_auto') && $id <= 0) {
            return [];
        }
        $skill = self::resolveSkill($tenantId, $key, $id);
        if (empty($skill) || (int)($skill['status'] ?? 0) !== 1 || (string)($skill['release_status'] ?? self::RELEASE_ACTIVE) !== self::RELEASE_ACTIVE) {
            throw new Exception('所选 Skill 当前不可用');
        }
        return self::formatSkill($skill, true);
    }

    /**
     * Convert tenant-editable product configuration into a bounded runtime
     * contract. Platform gates still own billing, security and provider access.
     */
    public static function compileForAgent(array $skill, string $content, array $context, bool $explicit = false): array
    {
        $toolPolicy = (array)($skill['tool_policy_json'] ?? $skill['tool_policy'] ?? []);
        $clarification = (array)($skill['clarification_policy_json'] ?? []);
        $execution = (array)($skill['execution_policy_json'] ?? []);
        $canvas = (array)($skill['canvas_output_policy_json'] ?? []);
        $required = self::applySlotPolicyDefinitions(
            self::normalizeSlots((array)($skill['required_slots_json'] ?? [])),
            (array)($skill['agent_policy_json']['slot_policy'] ?? [])
        );
        $slotState = self::resolveSlotState($required, (array)($skill['defaults_json'] ?? []), $content, $context);
        $missing = (array)$slotState['missing_hard_slots'];
        $questions = (array)($clarification['questions'] ?? []);
        $question = '';
        if ($missing !== []) {
            $first = (string)$missing[0];
            $question = trim((string)($questions[$first] ?? self::slotQuestion($required, $first)));
        }

        $allowed = array_values(array_unique(array_filter(array_map('strval', (array)($toolPolicy['allowed_tools'] ?? [])))));
        if ($allowed === []) {
            // A tenant-created Skill starts with the smallest useful contract.
            // Media and canvas operations must be explicitly granted in its policy.
            $allowed = ['ask_user', 'generate_text'];
        }
        $capabilityTools = self::capabilityTools($toolPolicy, $allowed);
        $bindingPolicy = self::bindingPolicy($skill);
        return [
            'skill_key' => (string)($skill['skill_key'] ?? ''),
            'name' => (string)($skill['name'] ?? ''),
            'version' => (int)($skill['version'] ?? 1),
            'explicit' => $explicit,
            'allowed_tools' => $allowed,
            'capability_tools' => $capabilityTools,
            'binding_policy' => $bindingPolicy,
            'required_slots' => $required,
            'missing_slots' => $missing,
            'missing_hard_slots' => $missing,
            'missing_soft_slots' => (array)$slotState['missing_soft_slots'],
            'inferred_slots' => (array)$slotState['inferred_slots'],
            'default_slots' => (array)$slotState['default_slots'],
            'default_sources' => (array)$slotState['default_sources'],
            'slot_state' => (array)$slotState['slots'],
            'clarification_question' => $question,
            'defaults' => (array)($skill['defaults_json'] ?? []),
            'optional_slots' => self::normalizeSlots((array)($skill['optional_slots_json'] ?? [])),
            'max_tool_calls' => max(1, min(24, (int)($toolPolicy['max_tool_calls'] ?? $execution['max_tool_calls'] ?? 12))),
            'max_iterations' => max(1, min(6, (int)($execution['max_iterations'] ?? $execution['max_rounds'] ?? ($skill['agent_policy_json']['max_rounds'] ?? 6)))),
            'requires_confirmation' => !empty($toolPolicy['requires_confirmation']) || in_array((string)($canvas['writeback'] ?? ''), ['propose_then_apply', 'confirm_before_apply'], true),
            'model_policy' => (array)($skill['model_policy_json'] ?? []),
            'output_policy' => (array)($skill['output_policy_json'] ?? []),
            'canvas_policy' => $canvas,
            'quality_policy' => (array)($skill['quality_policy_json'] ?? []),
            'enrichment_policy' => (array)($skill['agent_policy_json']['enrichment_policy'] ?? []),
        ];
    }

    public static function release(int $tenantId, int $adminId, array $params): array
    {
        $skill = self::findById($tenantId, (int)($params['id'] ?? 0));
        $status = trim((string)($params['release_status'] ?? self::RELEASE_ACTIVE));
        if (!in_array($status, [self::RELEASE_DRAFT, self::RELEASE_TESTING, self::RELEASE_CANARY, self::RELEASE_ACTIVE, self::RELEASE_PAUSED, self::RELEASE_ARCHIVED], true)) {
            throw new Exception('无效的发布状态');
        }
        $skill->save(['release_status' => $status, 'update_time' => time()]);
        self::snapshot($skill->toArray(), $adminId, $status);
        return self::formatSkill($skill->toArray(), true);
    }

    public static function versions(int $tenantId, int $skillId): array
    {
        self::findById($tenantId, $skillId);
        try {
            return Db::name('aigc_canvas_skill_version')
                ->where(['tenant_id' => $tenantId, 'skill_id' => $skillId])
                ->order(['version' => 'desc'])
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Restore a prior immutable snapshot as a new active version. */
    public static function rollback(int $tenantId, int $adminId, array $params): array
    {
        self::ensureSchema();
        $skill = self::findById($tenantId, (int)($params['id'] ?? 0));
        $query = Db::name('aigc_canvas_skill_version')
            ->where(['tenant_id' => $tenantId, 'skill_id' => (int)$skill['id']]);
        $targetVersion = (int)($params['version'] ?? 0);
        if ($targetVersion > 0) {
            $query->where('version', $targetVersion);
        } else {
            $query->where('version', '<', (int)$skill['version'])->order('version', 'desc');
        }
        $snapshot = $query->find();
        if (!$snapshot) {
            throw new Exception('No prior Skill version is available for rollback');
        }
        $config = self::normalizeJsonPayload($snapshot['config_json'] ?? []);
        if ($config === []) {
            throw new Exception('The selected Skill snapshot is invalid');
        }
        $data = self::payload($config, false);
        $data['version'] = (int)$skill['version'] + 1;
        $data['release_status'] = self::RELEASE_ACTIVE;
        $data['update_time'] = time();
        $skill->save($data);
        self::snapshot($skill->toArray(), $adminId, self::RELEASE_ACTIVE);
        return self::formatSkill($skill->toArray(), true);
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
            'release_status' => self::RELEASE_DRAFT,
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]));
        self::snapshot($skill->toArray(), $adminId);
        return self::formatSkill($skill->toArray(), true);
    }

    public static function update(int $tenantId, array $params): array
    {
        self::ensureSchema();
        if ((string)($params['skill_type'] ?? '') === 'workflow_template') {
            throw new Exception('Workflow Template 已迁移至独立模板库，不能作为 Skill 创建或保存');
        }
        $id = (int)($params['id'] ?? 0);
        $skill = self::findById($tenantId, $id);
        $data = self::payload($params, false);
        if (!empty($data['skill_key']) && $data['skill_key'] !== (string)$skill['skill_key']) {
            self::assertUniqueKey($tenantId, $data['skill_key'], $id);
        }
        $data['version'] = (int)$skill['version'] + 1;
        $data['release_status'] = self::RELEASE_DRAFT;
        $data['update_time'] = time();
        $skill->save($data);
        self::snapshot($skill->toArray(), (int)($skill['user_id'] ?? 0));
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
        $query = AigcCanvasSkill::where('tenant_id', $tenantId)
            ->where('delete_time', 0)
            ->whereIn('skill_type', [self::TYPE_AGENT_PROMPT, self::TYPE_AGENT_WORKFLOW]);
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
        foreach (self::productCatalog() as $index => $item) {
            $key = (string)$item['skill_key'];
            $exists = AigcCanvasSkill::where([
                'tenant_id' => $tenantId,
                'skill_key' => $key,
            ])->findOrEmpty();
            if (!$exists->isEmpty()) {
                if ((string)($exists['source_type'] ?? '') === self::SOURCE_BUILTIN) {
                    $currentPolicy = is_array($exists['agent_policy_json'] ?? null) ? $exists['agent_policy_json'] : [];
                    if ((string)($currentPolicy['catalog_revision'] ?? '') === self::CATALOG_REVISION) {
                        continue;
                    }
                    $item['version'] = max(1, (int)($exists['version'] ?? 1) + 1);
                    $releaseStatus = self::catalogReleaseStatus($item, $exists->toArray());
                    $exists->save(array_merge($item, [
                        'tenant_id' => $tenantId,
                        'user_id' => 0,
                        'source_type' => self::SOURCE_BUILTIN,
                        'status' => 1,
                        'release_status' => $releaseStatus,
                        'sort' => (int)($item['sort'] ?? (100 - $index)),
                        'update_time' => time(),
                        'delete_time' => 0,
                    ]));
                    self::snapshot($exists->toArray(), 0, $releaseStatus);
                }
                continue;
            }
            $now = time();
            $releaseStatus = self::catalogReleaseStatus($item);
            AigcCanvasSkill::create(array_merge($item, [
                'tenant_id' => $tenantId,
                'user_id' => 0,
                'source_type' => self::SOURCE_BUILTIN,
                'status' => 1,
                'release_status' => $releaseStatus,
                'version' => 1,
                'sort' => (int)($item['sort'] ?? (100 - $index)),
                'create_time' => $now,
                'update_time' => $now,
                'delete_time' => 0,
            ]));
            $created = AigcCanvasSkill::where(['tenant_id' => $tenantId, 'skill_key' => $key, 'delete_time' => 0])->findOrEmpty();
            if (!$created->isEmpty()) {
                self::snapshot($created->toArray(), 0, $releaseStatus);
            }
        }
    }

    private static function productCatalog(): array
    {
        $items = array_merge(self::builtinSkills(), self::legacyBuiltinSkills(), [
            [
                'skill_key' => 'image_edit',
                'name' => '图片编辑',
                'description' => '基于选中或上传图片生成保留原图的新版本，用于改背景、改色、局部重绘和风格调整。',
                'category' => 'image',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '先读取用户选中的图片或上传参考素材，确认编辑指令后生成一个并排的新版本；不得覆盖原图。',
                'trigger_description' => '改背景、换颜色、局部重绘、修图、把选中的图改成、图片编辑。',
                'workflow_json' => [],
                'examples_json' => ['把选中的图背景换成蓝色，更高级', '把这张商品图改成白底图'],
                'negative_examples_json' => ['没有引用图片时生成一张新海报', '写一段图片说明'],
                'required_slots_json' => [
                    ['key' => 'reference_asset', 'type' => 'reference_node', 'sources' => ['selected_element', 'uploaded_references'], 'ask' => '请先选中画布中的图片或上传参考图。'],
                    ['key' => 'edit_instruction', 'type' => 'text', 'ask' => '你希望如何修改这张图片？'],
                ],
                'optional_slots_json' => [['key' => 'preserve', 'type' => 'text', 'default' => '主体、构图和商品一致性']],
                'defaults_json' => ['ratio' => 'source'],
                'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1],
                'tool_policy_json' => ['allowed_tools' => ['ask_user', 'canvas_query', 'selection_action', 'asset_analyze', 'generate_image'], 'max_tool_calls' => 5],
                'output_policy_json' => ['format' => 'media', 'workspace_action' => 'insert_new_version'],
                'canvas_output_policy_json' => ['writeback' => 'propose_then_apply', 'preserve_source_assets' => true, 'layout' => 'side_by_side'],
                'sort' => 129,
            ],
            [
                'skill_key' => 'brand_kit',
                'name' => '品牌设定',
                'description' => '整理品牌名称、Logo、色彩、字体、调性和参考链接，经过确认后写入项目品牌记忆。',
                'category' => 'branding',
                'skill_type' => self::TYPE_AGENT_WORKFLOW,
                'content_markdown' => '先收集品牌名称和 Logo 或官网/参考链接，提炼可复用的视觉约束；必须在用户确认后写入品牌记忆。',
                'trigger_description' => '品牌设定、Brand Kit、品牌色、Logo、视觉识别、品牌规范。',
                'workflow_json' => [],
                'examples_json' => ['帮我建立品牌设定，品牌名是极光咖啡', '根据这个 Logo 和官网整理品牌视觉规范'],
                'negative_examples_json' => ['直接生成一个没有品牌信息的海报', '普通聊天'],
                'required_slots_json' => [
                    ['key' => 'brand_name', 'type' => 'text', 'ask' => '请告诉我品牌名称。'],
                    ['key' => 'logo_or_url', 'type' => 'asset_or_text', 'sources' => ['uploaded_references', 'user_text'], 'ask' => '请上传 Logo，或提供品牌官网/参考链接。'],
                ],
                'optional_slots_json' => [['key' => 'industry', 'type' => 'text'], ['key' => 'target_audience', 'type' => 'text']],
                'defaults_json' => [],
                'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1],
                'tool_policy_json' => ['allowed_tools' => ['ask_user', 'asset_analyze', 'web_fetch', 'brand_research', 'generate_text'], 'max_tool_calls' => 6, 'requires_confirmation' => true],
                'output_policy_json' => ['format' => 'brand_profile', 'workspace_action' => 'confirm_brand_memory'],
                'canvas_output_policy_json' => ['writeback' => 'propose_then_apply', 'layout' => 'brand_board'],
                'sort' => 126,
            ],
        ], self::p1Skills(), self::p2Skills());

        return array_map(static fn(array $item): array => self::completeBuiltinDefinition($item), $items);
    }

    private static function catalogReleaseStatus(array $item, array $existing = []): string
    {
        $statuses = [self::RELEASE_DRAFT, self::RELEASE_TESTING, self::RELEASE_CANARY, self::RELEASE_ACTIVE, self::RELEASE_PAUSED, self::RELEASE_ARCHIVED];
        $current = (string)($existing['release_status'] ?? '');
        $requested = (string)($item['release_status'] ?? self::RELEASE_ACTIVE);
        if (
            (string)($item['skill_key'] ?? '') === 'short_drama_preproduction'
            && $current === self::RELEASE_DRAFT
            && $requested === self::RELEASE_ACTIVE
        ) {
            return self::RELEASE_ACTIVE;
        }
        if (in_array($current, $statuses, true)) {
            return $current;
        }
        return in_array($requested, $statuses, true) ? $requested : self::RELEASE_ACTIVE;
    }

    private static function completeBuiltinDefinition(array $item): array
    {
        $key = (string)($item['skill_key'] ?? '');
        $toolDefaults = [
            'ecommerce_detail_page' => ['ask_user', 'canvas_query', 'asset_analyze', 'generate_text', 'generate_image', 'canvas_mutation', 'delegate_subagent'],
            'ecommerce_image' => ['ask_user', 'asset_analyze', 'generate_image'],
            'general_image' => ['ask_user', 'generate_image'],
            'image_edit' => ['ask_user', 'canvas_query', 'selection_action', 'asset_analyze', 'generate_image'],
            'poster_design' => ['ask_user', 'generate_text', 'generate_image', 'canvas_mutation'],
            'video_generation' => ['ask_user', 'asset_analyze', 'generate_video'],
            'music_generation' => ['ask_user', 'generate_music'],
            'script_planning' => ['ask_user', 'generate_text', 'canvas_mutation'],
            'asset_analysis' => ['ask_user', 'canvas_query', 'asset_analyze', 'generate_text'],
            'brand_kit' => ['ask_user', 'asset_analyze', 'web_fetch', 'brand_research', 'generate_text'],
            'general_chat' => ['ask_user', 'generate_text'],
            'creative_plan' => ['ask_user', 'generate_text', 'canvas_query', 'canvas_mutation'],
            'product_image_prompt' => ['ask_user', 'generate_text'],
            'launch_event_plan' => ['ask_user', 'generate_text', 'canvas_mutation'],
        ];
        $capability = str_contains($key, 'video') ? 'video_generation' : (str_contains($key, 'music') ? 'music_generation' : (str_contains($key, 'image') || str_contains($key, 'poster') ? 'image_generation' : 'text_generation'));
        $p0Definitions = self::p0Definitions();
        if (isset($p0Definitions[$key])) {
            $item = array_replace($item, $p0Definitions[$key]);
        }
        $item['required_slots_json'] = self::applyBuiltinSlotPolicy($key, (array)($item['required_slots_json'] ?? []));
        $existingTools = (array)($item['tool_policy_json']['allowed_tools'] ?? []);
        $item['tool_policy_json'] = array_merge((array)($item['tool_policy_json'] ?? []), [
            'allowed_tools' => $toolDefaults[$key] ?? ($existingTools ?: ['ask_user', 'generate_text']),
            'max_tool_calls' => (int)($item['tool_policy_json']['max_tool_calls'] ?? ($item['skill_type'] === self::TYPE_AGENT_WORKFLOW ? 12 : 4)),
            'requires_confirmation' => !empty($item['tool_policy_json']['requires_confirmation']) || ($item['skill_type'] === self::TYPE_AGENT_WORKFLOW),
        ]);
        foreach (self::capabilityTools($item['tool_policy_json'], (array)$item['tool_policy_json']['allowed_tools']) as $level => $tools) {
            $item['tool_policy_json'][$level] = $tools;
        }
        $item['agent_policy_json'] = array_merge((array)($item['agent_policy_json'] ?? []), [
            'binding_policy' => self::builtinBindingPolicy($key),
            'slot_policy' => self::slotPolicyFromSlots((array)($item['required_slots_json'] ?? [])),
        ]);
        $item['agent_policy_json'] = array_merge((array)$item['agent_policy_json'], [
            'enrichment_policy' => array_merge([
                'enabled' => true,
                'on_reference_asset' => ['visual_understanding'],
                'brief_type' => 'reference_visual',
                'auto_copy_when_missing' => false,
                'visible_facts_only' => true,
                'minimum_confidence' => 0.7,
                'cache_ttl_seconds' => 2592000,
                'render_copy_in_image' => false,
            ], (array)($item['agent_policy_json']['enrichment_policy'] ?? []), [
                'render_copy_in_image' => $key === 'ecommerce_detail_page',
            ]),
        ]);
        $item['visibility_policy_json'] = array_merge(['user_visible' => $key !== 'ecommerce_image', 'entry_points' => ['skill_gallery', 'chat_router'], 'tenant_editable' => true], (array)($item['visibility_policy_json'] ?? []));
        $item['model_policy_json'] = array_merge(['capability' => $capability, 'selection_mode' => 'auto', 'budget_mode' => 'balanced'], (array)($item['model_policy_json'] ?? []));
        $item['execution_policy_json'] = array_merge(['mode' => 'agent_loop', 'max_iterations' => $item['skill_type'] === self::TYPE_AGENT_WORKFLOW ? 6 : 4, 'max_tool_calls' => (int)$item['tool_policy_json']['max_tool_calls']], (array)($item['execution_policy_json'] ?? []));
        $item['quality_policy_json'] = array_merge(['checks' => ['task_result', 'canvas_writeback'], 'on_failure' => 'deliver_partial_and_explain'], (array)($item['quality_policy_json'] ?? []));
        $item['safety_policy_json'] = array_merge(['content_policy' => 'platform_default', 'asset_policy' => 'platform_default'], (array)($item['safety_policy_json'] ?? []));
        $item['analytics_policy_json'] = array_merge(['events' => ['skill_selected', 'clarification', 'tool_completed', 'delivery_completed'], 'success_definition' => 'delivery_completed'], (array)($item['analytics_policy_json'] ?? []));
        $item['agent_policy_json'] = array_merge((array)($item['agent_policy_json'] ?? []), ['catalog_revision' => self::CATALOG_REVISION, 'execution_mode' => 'agent_loop']);
        $item['tool_schema_json'] = ['allowed_tools' => $item['tool_policy_json']['allowed_tools']];
        $item['canvas_output_policy_json'] = array_merge((array)($item['canvas_output_policy_json'] ?? []), ['writeback' => (string)($item['canvas_output_policy_json']['writeback'] ?? 'propose_then_apply')]);
        return $item;
    }

    /**
     * The eight P0 Skills intentionally overwrite older builtin definitions.
     * This keeps existing keys stable while replacing partial legacy policies.
     */
    private static function p0Definitions(): array
    {
        return [
            'general_image' => [
                'required_slots_json' => [['key' => 'subject', 'type' => 'text', 'ask' => '你想生成什么主体或场景？']],
                'optional_slots_json' => [['key' => 'style', 'type' => 'text'], ['key' => 'ratio', 'type' => 'enum', 'options' => ['1:1', '3:4', '4:3', '9:16', '16:9'], 'default' => '1:1'], ['key' => 'quantity', 'type' => 'number', 'default' => 1]],
                'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1, 'ask_when_missing_required_slots' => true],
                'tool_policy_json' => ['allowed_tools' => ['ask_user', 'generate_image'], 'max_tool_calls' => 3],
                'output_policy_json' => ['format' => 'media', 'workspace_action' => 'insert_image', 'write_to_canvas' => true],
            ],
            'image_edit' => [
                'required_slots_json' => [
                    ['key' => 'reference_asset', 'type' => 'reference_node', 'sources' => ['selected_element', 'uploaded_references'], 'ask' => '请先选中画布中的图片，或上传一张需要编辑的图片。'],
                    ['key' => 'edit_instruction', 'type' => 'text', 'ask' => '你希望如何修改这张图片？'],
                ],
                'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1, 'ask_when_missing_required_slots' => true],
                'tool_policy_json' => ['allowed_tools' => ['ask_user', 'canvas_query', 'selection_action', 'asset_analyze', 'generate_image'], 'max_tool_calls' => 5],
                'output_policy_json' => ['format' => 'media', 'workspace_action' => 'insert_new_version', 'write_to_canvas' => true],
                'canvas_output_policy_json' => ['writeback' => 'propose_then_apply', 'preserve_source_assets' => true, 'layout' => 'side_by_side'],
            ],
            'poster_design' => [
                'required_slots_json' => [['key' => 'theme', 'type' => 'text', 'ask' => '请补充海报主题或活动内容。']],
                'optional_slots_json' => [['key' => 'headline', 'type' => 'text'], ['key' => 'ratio', 'type' => 'enum', 'options' => ['3:4', '1:1', '9:16', '16:9'], 'default' => '3:4'], ['key' => 'style', 'type' => 'text']],
                'defaults_json' => ['quantity' => 1, 'ratio' => '3:4', 'style' => '高端商业产品广告'],
                'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1, 'ask_when_missing_required_slots' => true],
                'tool_policy_json' => ['allowed_tools' => ['ask_user', 'generate_text', 'generate_image', 'canvas_mutation'], 'max_tool_calls' => 5],
                'output_policy_json' => ['format' => 'media_and_canvas', 'workspace_action' => 'insert_image', 'write_to_canvas' => true],
            ],
            'ecommerce_detail_page' => [
                'required_slots_json' => [
                    ['key' => 'product_reference', 'type' => 'asset_or_text', 'sources' => ['uploaded_references', 'selected_element', 'user_text'], 'ask' => '请上传商品图，或补充商品名称、外观、材质和核心卖点。'],
                    ['key' => 'selling_points', 'type' => 'text', 'ask' => '这款商品最希望突出哪些卖点？'],
                ],
                'optional_slots_json' => [['key' => 'platform', 'type' => 'enum', 'options' => ['taobao', 'tmall', 'jd', 'douyin', 'generic'], 'default' => 'taobao'], ['key' => 'section_count', 'type' => 'number', 'default' => 5], ['key' => 'ratio', 'type' => 'enum', 'options' => ['3:4', '1:1', '9:16'], 'default' => '3:4']],
                'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1, 'max_total_questions' => 3, 'ask_when_missing_required_slots' => true],
                'tool_policy_json' => ['allowed_tools' => ['ask_user', 'canvas_query', 'asset_analyze', 'generate_text', 'generate_image', 'canvas_mutation', 'delegate_subagent'], 'max_tool_calls' => 18, 'requires_confirmation' => true],
                'output_policy_json' => ['format' => 'media_and_canvas', 'required_deliverables' => ['detail_sections', 'image_assets', 'canvas_group'], 'write_to_canvas' => true, 'batch_mode' => true],
                'canvas_output_policy_json' => ['writeback' => 'propose_then_apply', 'preserve_source_assets' => true, 'layout' => 'vertical'],
            ],
            'video_generation' => [
                'required_slots_json' => [
                    ['key' => 'subject_or_reference', 'type' => 'asset_or_text', 'sources' => ['selected_element', 'uploaded_references', 'user_text'], 'ask' => '请说明视频主体，或选中/上传一张参考图片。'],
                    ['key' => 'motion', 'type' => 'text', 'ask' => '希望画面如何运动，例如推进、环绕、人物动作或镜头节奏？'],
                ],
                'optional_slots_json' => [['key' => 'duration', 'type' => 'number', 'default' => 5], ['key' => 'ratio', 'type' => 'enum', 'options' => ['16:9', '9:16', '1:1'], 'default' => '16:9']],
                'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1, 'ask_when_missing_required_slots' => true],
                'tool_policy_json' => ['allowed_tools' => ['ask_user', 'asset_analyze', 'generate_video'], 'max_tool_calls' => 4],
                'output_policy_json' => ['format' => 'media', 'workspace_action' => 'insert_video', 'write_to_canvas' => true],
            ],
            'script_planning' => [
                'required_slots_json' => [
                    ['key' => 'goal', 'type' => 'text', 'ask' => '这次内容希望达成什么目标？'],
                    ['key' => 'deliverable_type', 'type' => 'enum', 'options' => ['script', 'copy', 'storyboard', 'plan'], 'ask' => '你希望我交付脚本、文案、分镜还是完整方案？'],
                ],
                'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1, 'ask_when_missing_required_slots' => true],
                'tool_policy_json' => ['allowed_tools' => ['ask_user', 'generate_text', 'canvas_mutation'], 'max_tool_calls' => 4],
                'output_policy_json' => ['format' => 'text_and_canvas', 'workspace_action' => 'insert_text', 'write_to_canvas' => true],
            ],
            'asset_analysis' => [
                'required_slots_json' => [['key' => 'reference_asset', 'type' => 'reference_node', 'sources' => ['selected_element', 'uploaded_references'], 'ask' => '请先选中画布素材，或上传需要分析的文件。']],
                'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1, 'ask_when_missing_required_slots' => true],
                'tool_policy_json' => ['allowed_tools' => ['ask_user', 'canvas_query', 'asset_analyze', 'generate_text'], 'max_tool_calls' => 4],
                'output_policy_json' => ['format' => 'text', 'workspace_action' => 'none'],
            ],
            'brand_kit' => [
                'required_slots_json' => [
                    ['key' => 'brand_name', 'type' => 'text', 'ask' => '请告诉我品牌名称。'],
                    ['key' => 'logo_or_url', 'type' => 'asset_or_text', 'sources' => ['uploaded_references', 'user_text'], 'ask' => '请上传 Logo，或提供品牌官网/参考链接。'],
                ],
                'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1, 'ask_when_missing_required_slots' => true],
                'tool_policy_json' => ['allowed_tools' => ['ask_user', 'asset_analyze', 'web_fetch', 'brand_research', 'generate_text'], 'max_tool_calls' => 6, 'requires_confirmation' => true],
                'output_policy_json' => ['format' => 'brand_profile', 'workspace_action' => 'confirm_brand_memory'],
            ],
        ];
    }

    /**
     * P1 is fully configured but deliberately starts in testing. A tenant must
     * validate its enabled market channels and then release it to canary/active.
     */
    private static function p1Skills(): array
    {
        return [
            self::catalogProductSkill('ecommerce_main_image', '电商主图', 'ecommerce', self::TYPE_AGENT_PROMPT, '生成平台商品主图，突出主体、卖点和平台规范。', [self::slot('product_reference', 'asset_or_text', '请上传商品图，或补充商品名称、外观和材质。', ['uploaded_references', 'selected_element', 'user_text'])], [['key' => 'platform', 'type' => 'enum', 'options' => ['taobao', 'tmall', 'jd', 'amazon', 'generic'], 'default' => 'taobao']], ['ask_user', 'asset_analyze', 'generate_image'], 'image_generation', '根据商品图生成淘宝白底主图'),
            self::catalogProductSkill('ecommerce_selling_point', '电商卖点图', 'ecommerce', self::TYPE_AGENT_PROMPT, '围绕单一商品卖点生成可投放的商业视觉。', [self::slot('product_reference', 'asset_or_text', '请上传商品图，或说明商品主体。', ['uploaded_references', 'selected_element', 'user_text']), self::slot('selling_point', 'text', '请说明这一张图需要突出什么卖点。')], [['key' => 'ratio', 'type' => 'enum', 'options' => ['1:1', '3:4', '4:3'], 'default' => '1:1']], ['ask_user', 'asset_analyze', 'generate_image'], 'image_generation', '给耳机做一张突出降噪能力的卖点图'),
            self::catalogProductSkill('ecommerce_product_listing', '商品 Listing 套图', 'ecommerce', self::TYPE_AGENT_WORKFLOW, '生成主图、卖点图和场景图的商品套图，并按交付物分组写入画布。', [self::slot('product_reference', 'asset_or_text', '请上传商品图，或补充商品信息。', ['uploaded_references', 'selected_element', 'user_text']), self::slot('platform', 'enum', '请说明投放平台，例如淘宝、京东或亚马逊。')], [['key' => 'quantity', 'type' => 'number', 'default' => 5], ['key' => 'ratio', 'type' => 'enum', 'options' => ['1:1', '3:4'], 'default' => '1:1']], ['ask_user', 'asset_analyze', 'generate_text', 'generate_image', 'canvas_mutation', 'delegate_subagent'], 'image_generation', '给保温杯做一套亚马逊 Listing 图片', ['requires_confirmation' => true, 'batch_mode' => true]),
            self::catalogProductSkill('ecommerce_mockup', '商品场景与 Mockup', 'ecommerce', self::TYPE_AGENT_PROMPT, '把商品置入包装、实物、屏幕或生活场景，保持商品主体一致。', [self::slot('product_reference', 'asset_or_text', '请上传或选中商品图片。', ['uploaded_references', 'selected_element', 'user_text']), self::slot('scene', 'text', '希望商品出现在哪种场景或样机中？')], [['key' => 'style', 'type' => 'text']], ['ask_user', 'asset_analyze', 'generate_image'], 'image_generation', '把这款香水放进高端大理石台面场景'),
            self::catalogProductSkill('social_post', '社媒图文帖', 'social', self::TYPE_AGENT_PROMPT, '为指定社媒平台生成标题、正文和配图方向。', [self::slot('platform', 'enum', '请选择发布平台。'), self::slot('topic', 'text', '请说明帖子的主题或产品。')], [['key' => 'tone', 'type' => 'text'], ['key' => 'ratio', 'type' => 'enum', 'options' => ['1:1', '4:5', '9:16'], 'default' => '4:5']], ['ask_user', 'generate_text', 'generate_image', 'canvas_mutation'], 'image_generation', '为新品咖啡写一篇小红书图文帖'),
            self::catalogProductSkill('rednote_cover', '小红书封面', 'social', self::TYPE_AGENT_PROMPT, '生成适合小红书阅读场景的封面与套图视觉。', [self::slot('topic', 'text', '请说明封面主题。')], [['key' => 'headline', 'type' => 'text'], ['key' => 'ratio', 'type' => 'enum', 'options' => ['3:4', '1:1'], 'default' => '3:4']], ['ask_user', 'generate_text', 'generate_image'], 'image_generation', '做一张咖啡探店小红书封面'),
            self::catalogProductSkill('instagram_post', 'Instagram 帖子', 'social', self::TYPE_AGENT_PROMPT, '输出 Feed、Story 或 Reel 封面的图文创意。', [self::slot('topic', 'text', '请说明帖子主题。'), self::slot('post_type', 'enum', '请选择 Feed、Story 或 Reel。')], [['key' => 'audience', 'type' => 'text']], ['ask_user', 'generate_text', 'generate_image'], 'image_generation', '为运动鞋新品做一张 Instagram Feed'),
            self::catalogProductSkill('youtube_thumbnail', 'YouTube 缩略图', 'social', self::TYPE_AGENT_PROMPT, '生成高辨识度的视频缩略图和标题视觉。', [self::slot('topic', 'text', '请说明视频主题。'), self::slot('headline', 'text', '请提供要突出的视频标题。')], [['key' => 'ratio', 'type' => 'enum', 'options' => ['16:9'], 'default' => '16:9']], ['ask_user', 'generate_image'], 'image_generation', '为 AI 教程视频做一张 YouTube 缩略图'),
            self::catalogProductSkill('ad_creative', '广告创意', 'marketing', self::TYPE_AGENT_WORKFLOW, '按目标、渠道和受众生成多角度广告文案与视觉变体。', [self::slot('product_or_offer', 'text', '请说明产品、优惠或服务。'), self::slot('objective', 'enum', '本次广告目标是拉新、转化还是品牌曝光？'), self::slot('platform', 'enum', '请说明投放渠道。')], [['key' => 'quantity', 'type' => 'number', 'default' => 3]], ['ask_user', 'generate_text', 'generate_image', 'canvas_mutation', 'delegate_subagent'], 'image_generation', '为健身课程做 3 组抖音转化广告', ['requires_confirmation' => true, 'batch_mode' => true]),
            self::catalogProductSkill('product_launch_campaign', '新品发布物料', 'marketing', self::TYPE_AGENT_WORKFLOW, '规划并交付新品发布所需的海报、社媒图、脚本和详情页资产。', [self::slot('product', 'text', '请说明新品是什么。'), self::slot('deliverables', 'array', '需要哪些交付物，例如海报、社媒图、脚本或详情页？')], [['key' => 'launch_date', 'type' => 'text'], ['key' => 'channels', 'type' => 'array']], ['ask_user', 'generate_text', 'generate_image', 'generate_video', 'canvas_mutation', 'delegate_subagent'], 'image_generation', '为无线耳机新品规划一套发布物料', ['requires_confirmation' => true, 'batch_mode' => true]),
            self::catalogProductSkill('campaign_kit', '营销活动套件', 'marketing', self::TYPE_AGENT_WORKFLOW, '为跨平台营销活动拆分资产、批次和交付顺序。', [self::slot('campaign_goal', 'text', '请说明营销活动目标。'), self::slot('asset_types', 'array', '需要哪些素材类型？')], [['key' => 'platforms', 'type' => 'array'], ['key' => 'brand_profile', 'type' => 'text']], ['ask_user', 'generate_text', 'generate_image', 'generate_video', 'canvas_mutation', 'delegate_subagent'], 'image_generation', '为夏季促销做一套跨平台 Campaign', ['requires_confirmation' => true, 'batch_mode' => true]),
            self::catalogProductSkill('logo_design', 'Logo 设计', 'branding', self::TYPE_AGENT_WORKFLOW, '生成多个 Logo 概念方向，并配套品牌含义说明。', [self::slot('brand_name', 'text', '请告诉我品牌名称。'), self::slot('industry', 'text', '品牌属于什么行业？'), self::slot('brand_traits', 'text', '希望品牌呈现哪些气质？')], [['key' => 'color_preference', 'type' => 'text']], ['ask_user', 'generate_text', 'generate_image', 'canvas_mutation'], 'image_generation', '为极光咖啡设计三个极简 Logo 方向', ['requires_confirmation' => true]),
            self::catalogProductSkill('brand_identity', '品牌视觉识别', 'branding', self::TYPE_AGENT_WORKFLOW, '整理色彩、字体、视觉语气和系列化应用建议。', [self::slot('brand_name', 'text', '请告诉我品牌名称。'), self::slot('industry', 'text', '品牌属于什么行业？')], [['key' => 'logo_or_url', 'type' => 'asset_or_text']], ['ask_user', 'asset_analyze', 'generate_text', 'generate_image', 'canvas_mutation'], 'image_generation', '为极光咖啡建立品牌视觉识别', ['requires_confirmation' => true]),
            self::catalogProductSkill('brand_campaign_visual', '品牌 Campaign 视觉', 'branding', self::TYPE_AGENT_WORKFLOW, '生成主 KV 与系列视觉，继承项目 Brand Kit。', [self::slot('brand_profile', 'text', '请提供品牌档案，或先完成品牌设定。'), self::slot('campaign_theme', 'text', '请说明 Campaign 主题。')], [['key' => 'ratio', 'type' => 'enum', 'options' => ['16:9', '3:4', '1:1'], 'default' => '16:9']], ['ask_user', 'generate_image', 'canvas_mutation', 'delegate_subagent'], 'image_generation', '为极光咖啡夏日活动做品牌 KV', ['requires_confirmation' => true]),
            self::catalogProductSkill('storyboard', '分镜脚本', 'video', self::TYPE_AGENT_WORKFLOW, '生成镜头表、旁白和可选的分镜视觉，按镜头写入画布。', [self::slot('script_or_goal', 'text', '请提供脚本，或说明视频目标。')], [['key' => 'shot_count', 'type' => 'number', 'default' => 6], ['key' => 'style', 'type' => 'text']], ['ask_user', 'generate_text', 'generate_image', 'canvas_mutation'], 'image_generation', '为新品耳机广告做 6 镜头分镜', ['requires_confirmation' => true]),
            self::catalogProductSkill('video_ad', '视频广告', 'video', self::TYPE_AGENT_WORKFLOW, '将产品、渠道和目标转化为广告脚本、分镜和视频任务。', [self::slot('product_or_offer', 'text', '请说明产品或优惠。'), self::slot('platform', 'enum', '请说明投放平台。')], [['key' => 'duration', 'type' => 'number', 'default' => 15]], ['ask_user', 'generate_text', 'generate_video', 'canvas_mutation', 'delegate_subagent'], 'video_generation', '为健身课程做一条 15 秒抖音广告', ['requires_confirmation' => true]),
            self::catalogProductSkill('image_to_video', '图生视频', 'video', self::TYPE_AGENT_PROMPT, '基于选中或上传图片生成带有指定运动效果的视频片段。', [self::slot('reference_asset', 'reference_node', '请选中画布图片，或上传参考图。', ['selected_element', 'uploaded_references']), self::slot('motion', 'text', '请说明画面或镜头运动。')], [['key' => 'duration', 'type' => 'number', 'default' => 5]], ['ask_user', 'asset_analyze', 'generate_video'], 'video_generation', '把选中的产品图做成镜头推进的视频'),
        ];
    }

    /** P2 entries remain drafts until their upstream capability is validated. */
    private static function p2Skills(): array
    {
        return [
            self::catalogProductSkill('presentation_design', '演示文稿视觉', 'advanced', self::TYPE_AGENT_WORKFLOW, '输出演示结构、封面和页面视觉方案。', [self::slot('topic', 'text', '请说明演示主题。')], [['key' => 'page_count', 'type' => 'number', 'default' => 8]], ['ask_user', 'generate_text', 'generate_image', 'canvas_mutation'], 'image_generation', '为 AI 产品路演设计 8 页演示视觉', ['release_status' => self::RELEASE_DRAFT, 'requires_confirmation' => true]),
            self::catalogProductSkill('landing_page_visual', '落地页视觉方案', 'advanced', self::TYPE_AGENT_WORKFLOW, '生成页面结构、KV 和模块视觉方案。', [self::slot('product_or_offer', 'text', '请说明产品或服务。')], [['key' => 'objective', 'type' => 'text']], ['ask_user', 'generate_text', 'generate_image', 'canvas_mutation'], 'image_generation', '为在线课程设计一个转化落地页视觉', ['release_status' => self::RELEASE_DRAFT, 'requires_confirmation' => true]),
            self::catalogProductSkill('packaging_design', '包装设计', 'advanced', self::TYPE_AGENT_WORKFLOW, '生成包装视觉概念与可展示 Mockup。', [self::slot('product_reference', 'asset_or_text', '请上传商品图，或说明产品。', ['uploaded_references', 'selected_element', 'user_text'])], [['key' => 'package_type', 'type' => 'text']], ['ask_user', 'asset_analyze', 'generate_image', 'canvas_mutation'], 'image_generation', '为咖啡豆设计礼盒包装', ['release_status' => self::RELEASE_DRAFT, 'requires_confirmation' => true]),
            self::catalogProductSkill('product_photography', '产品摄影', 'advanced', self::TYPE_AGENT_PROMPT, '生成棚拍、场景和模特产品摄影方向。', [self::slot('product_reference', 'asset_or_text', '请上传商品图，或说明产品。', ['uploaded_references', 'selected_element', 'user_text'])], [['key' => 'scene', 'type' => 'text']], ['ask_user', 'asset_analyze', 'generate_image'], 'image_generation', '为护肤品生成高级棚拍图', ['release_status' => self::RELEASE_DRAFT]),
            self::catalogProductSkill('character_design', '角色设计', 'advanced', self::TYPE_AGENT_WORKFLOW, '生成角色设定、三视图与场景视觉。', [self::slot('character_brief', 'text', '请说明角色身份、外观和性格。')], [['key' => 'style', 'type' => 'text']], ['ask_user', 'generate_text', 'generate_image', 'canvas_mutation'], 'image_generation', '设计一位赛博侦探角色并生成三视图', ['release_status' => self::RELEASE_DRAFT, 'requires_confirmation' => true]),
            self::catalogProductSkill('short_drama_preproduction', '短剧剧本与分镜规划', 'advanced', self::TYPE_AGENT_WORKFLOW, '先梳理故事梗概、角色、分集节奏和分镜目标，再创建可追踪的短剧剧本任务并交由短剧工作台继续完成。', [self::slot('story_brief', 'text', '请说明短剧故事梗概。')], [['key' => 'episode_count', 'type' => 'number'], ['key' => 'genre', 'type' => 'text'], ['key' => 'target_duration_seconds', 'type' => 'number'], ['key' => 'ratio', 'type' => 'text']], ['ask_user', 'generate_text', 'create_short_drama_plan'], 'text_generation', '为都市悬疑短剧规划 12 集剧本和竖屏分镜', ['release_status' => self::RELEASE_ACTIVE, 'requires_confirmation' => false]),
            self::catalogProductSkill('voiceover_plan', '配音文案', 'advanced', self::TYPE_AGENT_PROMPT, '生成旁白、角色台词和音色建议，不直接提交 TTS。', [self::slot('script_or_goal', 'text', '请提供脚本，或说明配音目标。')], [['key' => 'voice_style', 'type' => 'text']], ['ask_user', 'generate_text', 'canvas_mutation'], 'text_generation', '为产品广告写一段温暖女声旁白', ['release_status' => self::RELEASE_DRAFT]),
        ];
    }

    private static function catalogProductSkill(string $key, string $name, string $category, string $type, string $description, array $requiredSlots, array $optionalSlots, array $tools, string $capability, string $example, array $options = []): array
    {
        $isWorkflow = $type === self::TYPE_AGENT_WORKFLOW;
        $isVideo = in_array('generate_video', $tools, true);
        $isImage = in_array('generate_image', $tools, true);
        $format = $isVideo || $isImage ? 'media_and_canvas' : 'text_and_canvas';
        return [
            'skill_key' => $key,
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'skill_type' => $type,
            'content_markdown' => "围绕{$name}交付用户可直接使用的结果。先复用本轮输入、选区、上传素材和品牌记忆；缺少必填信息时一次只追问一个问题。涉及批量生成、成本或破坏性画布操作必须先确认。",
            'trigger_description' => $name . '、' . $description,
            'workflow_json' => [],
            'examples_json' => [$example],
            'negative_examples_json' => ['普通闲聊或与本能力无关的请求'],
            'required_slots_json' => $requiredSlots,
            'optional_slots_json' => $optionalSlots,
            'defaults_json' => [],
            'clarification_policy_json' => ['enabled' => true, 'max_questions' => 1, 'max_total_questions' => 3, 'ask_when_missing_required_slots' => true, 'prefer_context_before_asking' => true],
            'tool_policy_json' => ['allowed_tools' => $tools, 'max_tool_calls' => $isWorkflow ? 12 : 5, 'requires_confirmation' => !empty($options['requires_confirmation']) || $isWorkflow],
            'model_policy_json' => ['capability' => $capability, 'selection_mode' => 'auto', 'preferred_profiles' => $isVideo ? ['commercial_video'] : ($isImage ? ['commercial_design', 'general_image'] : ['general_text']), 'budget_mode' => 'balanced'],
            'execution_policy_json' => ['mode' => 'agent_loop', 'max_iterations' => $isWorkflow ? 6 : 4, 'max_parallel_generation_tasks' => $isWorkflow ? 3 : 1],
            'output_policy_json' => ['format' => $format, 'write_to_canvas' => true, 'batch_mode' => !empty($options['batch_mode']), 'partial_delivery' => true],
            'canvas_output_policy_json' => ['writeback' => 'propose_then_apply', 'preserve_source_assets' => true, 'layout' => $isWorkflow ? 'grouped_delivery' : 'side_by_side'],
            'quality_policy_json' => ['checks' => ['task_result', 'quantity', 'canvas_writeback'], 'on_failure' => 'deliver_partial_and_explain'],
            'safety_policy_json' => ['content_policy' => 'platform_default', 'asset_policy' => 'platform_default'],
            'analytics_policy_json' => ['events' => ['skill_selected', 'clarification', 'plan_confirmed', 'tool_completed', 'delivery_completed'], 'success_definition' => 'delivery_completed'],
            'visibility_policy_json' => ['user_visible' => $options['user_visible'] ?? true, 'entry_points' => ['skill_gallery', 'chat_router'], 'tenant_editable' => true],
            'release_status' => (string)($options['release_status'] ?? self::RELEASE_TESTING),
            'cover_url' => '',
            'sort' => (int)($options['sort'] ?? 60),
        ];
    }

    private static function slot(string $key, string $type, string $ask, array $sources = []): array
    {
        $slot = ['key' => $key, 'type' => $type, 'ask' => $ask];
        if ($sources !== []) {
            $slot['sources'] = $sources;
        }
        return $slot;
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

    private static function composePrompt(array $skill, string $content, array $context, array $route = []): string
    {
        $template = trim((string)($skill['content_markdown'] ?? ''));
        $trigger = trim((string)($skill['trigger_description'] ?? ''));
        $summary = trim(\app\common\service\app\aigc_canvas\agent\memory\CanvasSnapshotBuilder::summaryText($context));
        $selected = array_slice((array)($context['selected_elements'] ?? []), 0, 5);
        $slots = is_array($route['slots'] ?? null) ? $route['slots'] : [];
        $brand = self::brandMemoryPrompt($context);
        if ($brand !== '') {
            $content = trim($content . "\n\n品牌记忆约束:\n" . $brand);
        }
        return trim(implode("\n\n", array_filter([
            $template !== '' ? "Skill:\n" . $template : '',
            $trigger !== '' ? "适用说明:\n" . $trigger : '',
            "用户需求:\n" . $content,
            $summary !== '' ? "画布摘要:\n" . $summary : '',
            !empty($selected) ? "用户明确引用的画布元素:\n" . json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            !empty($slots) ? "Agent slots:\n" . json_encode($slots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ])));
    }

    private static function brandMemoryPrompt(array $context): string
    {
        $memory = is_array($context['brand_memory'] ?? null) ? $context['brand_memory'] : [];
        if ($memory !== []) {
            return trim((string)($memory['prompt_context'] ?? ''));
        }
        $delivery = is_array($context['conversation_delivery_context'] ?? null)
            ? $context['conversation_delivery_context']
            : [];
        $projectMemory = is_array($delivery['project_memory'] ?? null) ? $delivery['project_memory'] : [];
        $brand = is_array($projectMemory['brand_profile'] ?? null) ? $projectMemory['brand_profile'] : [];
        if (!empty($brand['summary'])) {
            return trim((string)$brand['summary']);
        }
        return '';
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
        $userRequest = trim((string)($slots['prompt'] ?? $slots['product_info'] ?? $slots['visual_subject'] ?? $content));
        $prompt = $userRequest;
        $brand = self::brandMemoryPrompt($context);
        if ($brand !== '') {
            $prompt = trim($prompt . "\n\n品牌记忆约束:\n" . $brand);
        }
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
        if ($toolCode === 'generate_image') {
            $skillKey = (string)($skill['skill_key'] ?? 'image');
            $isEdit = $skillKey === 'image_edit' || !empty($slots['edit_instruction']);
            $input['user_request'] = $userRequest;
            $input['prompt_mode'] = $isEdit ? 'edit' : 'planned';
            $input['delivery'] = [
                'type' => $skillKey,
                'purpose' => (string)($skill['name'] ?? ''),
                'ratio' => (string)($input['ratio'] ?? $input['size'] ?? ''),
                'evidence_ids' => (array)($slots['evidence_ids'] ?? []),
                'copy_content' => is_array($slots['copy_content'] ?? null) ? $slots['copy_content'] : [],
                'visual_direction' => array_values(array_filter([(string)($slots['style'] ?? ''), $brand])),
            ];
            $input['creative_context'] = is_array($context['creative_context'] ?? null)
                ? $context['creative_context']
                : (is_array($context['enriched_context']['creative_context'] ?? null) ? $context['enriched_context']['creative_context'] : []);
            if ($isEdit) {
                $input['edit_action'] = (string)($slots['edit_instruction'] ?? $userRequest);
                $input['original_identity'] = is_array($input['original_identity'] ?? null)
                    ? $input['original_identity']
                    : (array)($input['product_identity'] ?? []);
            }
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
        return 1;
    }

    private static function payload(array $params, bool $creating): array
    {
        $name = trim((string)($params['name'] ?? ''));
        $key = self::normalizeKey((string)($params['skill_key'] ?? ''));
        $type = (string)($params['skill_type'] ?? self::TYPE_AGENT_PROMPT);
        if ($creating && ($name === '' || $key === '')) {
            throw new Exception('Please enter skill name and key');
        }
        if ($type === 'workflow_template') {
            throw new Exception('Workflow Template 已迁移至独立模板库，不能作为 Skill 创建或保存');
        }
        if (!in_array($type, [self::TYPE_AGENT_PROMPT, self::TYPE_AGENT_WORKFLOW], true)) {
            throw new Exception('Skill 类型仅支持 Agent Prompt 或 Agent Workflow');
        }
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
            'workflow_json' => [],
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
        ])->whereIn('skill_type', [self::TYPE_AGENT_PROMPT, self::TYPE_AGENT_WORKFLOW])->findOrEmpty();
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

    private static function normalizeSlots(array $slots): array
    {
        $result = [];
        foreach ($slots as $slot) {
            $row = is_string($slot) ? ['key' => $slot] : (is_array($slot) ? $slot : []);
            $key = trim((string)($row['key'] ?? $row['name'] ?? ''));
            if ($key !== '') {
                $level = (string)($row['required_level'] ?? 'hard');
                $result[] = array_merge([
                    'key' => $key,
                    'required_level' => in_array($level, ['hard', 'soft', 'inferable', 'optional'], true) ? $level : 'hard',
                    'default_strategy' => 'none',
                    'ask_priority' => 100,
                ], $row, ['key' => $key]);
            }
        }
        return $result;
    }

    private static function resolveSlotState(array $slots, array $defaults, string $content, array $context): array
    {
        $missing = self::missingRequiredSlots($slots, $content, $context);
        $missingKeys = array_fill_keys($missing, true);
        $states = [];
        $missingHard = [];
        $missingSoft = [];
        $inferred = [];
        $defaultSlots = [];
        $defaultSources = [];
        foreach ($slots as $slot) {
            $key = (string)($slot['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $level = (string)($slot['required_level'] ?? 'hard');
            $isMissing = isset($missingKeys[$key]);
            $state = ['required_level' => $level, 'status' => $isMissing ? 'missing' : 'provided'];
            if (!$isMissing) {
                if (!empty($context['selected_elements']) && in_array($key, ['reference_asset', 'product_reference', 'subject_or_reference'], true)) {
                    $inferred[$key] = 'selected_canvas_element';
                    $state['source'] = 'canvas_selection';
                } elseif (!empty($context['uploaded_references']) && in_array($key, ['reference_asset', 'product_reference', 'subject_or_reference', 'logo_or_url'], true)) {
                    $inferred[$key] = 'uploaded_reference';
                    $state['source'] = 'uploaded_reference';
                } else {
                    $state['source'] = 'user_request';
                }
            } elseif (array_key_exists($key, $defaults) || array_key_exists('default', $slot)) {
                $defaultSlots[$key] = $defaults[$key] ?? $slot['default'];
                $defaultSources[$key] = (string)($slot['default_strategy'] ?? 'skill_default');
                $state['status'] = 'defaulted';
                $state['source'] = $defaultSources[$key];
            } elseif ($level === 'hard') {
                $missingHard[] = $key;
            } elseif ($level === 'soft') {
                $missingSoft[] = $key;
            }
            $states[$key] = $state;
        }
        usort($missingHard, static function (string $a, string $b) use ($slots): int {
            $priorities = [];
            foreach ($slots as $slot) {
                $priorities[(string)($slot['key'] ?? '')] = (int)($slot['ask_priority'] ?? 100);
            }
            return ($priorities[$a] ?? 100) <=> ($priorities[$b] ?? 100);
        });
        foreach ($defaults as $key => $value) {
            if (!array_key_exists((string)$key, $defaultSlots)) {
                $defaultSlots[(string)$key] = $value;
                $defaultSources[(string)$key] = 'skill_default';
            }
        }
        return [
            'missing_hard_slots' => array_slice($missingHard, 0, 1),
            'missing_soft_slots' => array_values(array_unique($missingSoft)),
            'inferred_slots' => $inferred,
            'default_slots' => $defaultSlots,
            'default_sources' => $defaultSources,
            'slots' => $states,
        ];
    }

    private static function bindingPolicy(array $skill): array
    {
        $policy = (array)($skill['agent_policy_json']['binding_policy'] ?? []);
        $mode = (string)($policy['default_mode'] ?? 'contract');
        return [
            'default_mode' => in_array($mode, ['none', 'advisory', 'contract'], true) ? $mode : 'contract',
            'upgrade_to_contract_on' => array_values(array_unique(array_intersect(
                array_map('strval', (array)($policy['upgrade_to_contract_on'] ?? ['paid_generation', 'batch', 'canvas_write'])),
                ['paid_generation', 'batch', 'canvas_write']
            ))),
        ];
    }

    private static function builtinBindingPolicy(string $key): array
    {
        if (in_array($key, ['general_image', 'poster_design', 'script_planning'], true)) {
            return ['default_mode' => 'advisory', 'upgrade_to_contract_on' => ['paid_generation', 'batch', 'canvas_write']];
        }
        return ['default_mode' => 'contract', 'upgrade_to_contract_on' => ['paid_generation', 'batch', 'canvas_write']];
    }

    private static function applyBuiltinSlotPolicy(string $skillKey, array $slots): array
    {
        $policies = [
            'general_image' => ['subject' => ['required_level' => 'hard', 'ask_priority' => 1]],
            'poster_design' => ['theme' => ['required_level' => 'hard', 'ask_priority' => 1]],
            'script_planning' => [
                'goal' => ['required_level' => 'soft', 'default_strategy' => 'conversation', 'ask_priority' => 1],
                'deliverable_type' => ['required_level' => 'inferable', 'default_strategy' => 'intent', 'ask_priority' => 2],
            ],
            'ecommerce_detail_page' => [
                'product_info' => ['required_level' => 'hard', 'ask_priority' => 1],
                'product_reference' => ['required_level' => 'hard', 'ask_priority' => 1],
                'selling_points' => ['required_level' => 'soft', 'default_strategy' => 'tenant_default', 'ask_priority' => 2],
            ],
        ];
        $rules = $policies[$skillKey] ?? [];
        foreach ($slots as $index => $slot) {
            if (is_string($slot)) {
                $slot = ['key' => $slot];
            }
            if (!is_array($slot)) {
                continue;
            }
            $key = (string)($slot['key'] ?? '');
            $slots[$index] = array_merge($slot, (array)($rules[$key] ?? []));
        }
        return $slots;
    }

    private static function slotPolicyFromSlots(array $slots): array
    {
        $policy = [];
        foreach (self::normalizeSlots($slots) as $slot) {
            $key = (string)($slot['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $policy[$key] = [
                'required_level' => (string)($slot['required_level'] ?? 'hard'),
                'default_strategy' => (string)($slot['default_strategy'] ?? 'none'),
                'ask_priority' => (int)($slot['ask_priority'] ?? 100),
            ];
        }
        return $policy;
    }

    private static function applySlotPolicyDefinitions(array $slots, array $policy): array
    {
        foreach ($slots as $index => $slot) {
            $key = (string)($slot['key'] ?? '');
            if ($key !== '' && is_array($policy[$key] ?? null)) {
                $slots[$index] = array_merge($slot, $policy[$key]);
            }
        }
        return $slots;
    }

    private static function capabilityTools(array $policy, array $legacyAllowed): array
    {
        $levels = ['read', 'plan', 'write', 'costly'];
        $result = [];
        foreach ($levels as $level) {
            $result[$level] = array_values(array_unique(array_filter(array_map('strval', (array)($policy[$level] ?? [])))));
        }
        $read = ['canvas_query', 'canvas_read', 'canvas_select', 'asset_analyze', 'web_fetch', 'search_web_info', 'search_image', 'brand_research', 'url_to_design_brief'];
        $write = ['canvas_mutation', 'selection_action', 'canvas_patch', 'create_page', 'add_element', 'update_element'];
        $costly = ['generate_image', 'generate_video', 'generate_music'];
        foreach ($legacyAllowed as $tool) {
            if (in_array($tool, $read, true)) $result['read'][] = $tool;
            elseif (in_array($tool, $write, true)) $result['write'][] = $tool;
            elseif (in_array($tool, $costly, true)) $result['costly'][] = $tool;
            else $result['plan'][] = $tool;
        }
        return array_map(static fn(array $tools): array => array_values(array_unique($tools)), $result);
    }

    private static function missingRequiredSlots(array $slots, string $content, array $context): array
    {
        $hasAsset = !empty($context['uploaded_references']) || !empty($context['selected_elements']) || !empty($context['selection']['elements']);
        $hasText = mb_strlen(trim($content), 'UTF-8') >= 3;
        $missing = [];
        foreach ($slots as $slot) {
            $key = (string)($slot['key'] ?? '');
            $sources = (array)($slot['sources'] ?? $slot['any_of_context'] ?? []);
            $type = (string)($slot['type'] ?? '');
            $needsAsset = in_array($type, ['asset', 'reference_node'], true)
                || in_array($key, ['reference_asset', 'product_reference', 'logo_or_url', 'subject_or_reference'], true)
                || in_array('uploaded_references', $sources, true)
                || in_array('selected_element', $sources, true);
            if ($type === 'asset_or_text') {
                $satisfied = $hasAsset || self::hasInferredSlotValue($context, $key) || self::contentSatisfiesSlot($key, $content);
            } elseif ($needsAsset) {
                $satisfied = $hasAsset;
            } else {
                $satisfied = self::hasInferredSlotValue($context, $key) || self::contentSatisfiesSlot($key, $content, $hasText);
            }
            if (!$satisfied) {
                $missing[] = $key;
            }
        }
        return array_values(array_unique($missing));
    }

    private static function hasInferredSlotValue(array $context, string $key): bool
    {
        $values = (array)($context['inferred_slot_values'] ?? []);
        if (!array_key_exists($key, $values)) {
            return false;
        }
        $value = $values[$key];
        return is_array($value) ? $value !== [] : trim((string)$value) !== '';
    }

    private static function contentSatisfiesSlot(string $key, string $content, bool $hasText = false): bool
    {
        $text = trim(mb_strtolower($content, 'UTF-8'));
        if ($text === '') {
            return false;
        }
        return match ($key) {
            // A request such as "生成商品详情图" describes an output, not a product.
            // Product slots may only be inferred from an identifiable product brief.
            'product_info', 'product_reference' => self::hasConcreteProductBrief($text),
            'selling_points' => preg_match('/卖点|突出|特点|优势|续航|材质|功能|新品|便携|降噪/u', $text) === 1,
            'selling_point' => preg_match('/卖点|突出|特点|优势|续航|材质|功能|新品|便携|降噪/u', $text) === 1,
            'subject' => preg_match('/^(?:帮我)?(?:生成|做|画|创建)?(?:一张|一个)?(?:图片|图|图像)?[。！？!?]*$/u', $text) !== 1,
            'subject_or_reference' => preg_match('/主体|产品|人物|场景|画面|这张|参考图|图片|视频/u', $text) === 1,
            'motion' => preg_match('/运动|镜头|推进|拉远|环绕|旋转|移动|动作|节奏/u', $text) === 1,
            'deliverable_type' => preg_match('/脚本|文案|分镜|方案|策划/u', $text) === 1,
            'story_brief' => preg_match('/故事|剧情|梗概|主角|题材|冲突|结局/u', $text) === 1,
            'brand_name' => preg_match('/品牌.{0,20}(叫|是|为)|品牌名|公司名/u', $text) === 1,
            'logo_or_url' => preg_match('/https?:\/\/|logo|标志|官网|网站/u', $text) === 1,
            'edit_instruction' => preg_match('/改|换|调整|移除|添加|增强|修|变/u', $text) === 1,
            default => $hasText || mb_strlen($text, 'UTF-8') >= 3,
        };
    }

    private static function hasConcreteProductBrief(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (preg_match('/(?:产品|商品|这款|本款)(?:是|为|叫|名为|：|:)\s*[\p{Han}A-Za-z0-9_-]{2,}|(?:型号|品牌|材质|规格|颜色|容量|尺寸)\s*(?:是|为|：|:)?\s*[\p{Han}A-Za-z0-9_-]{2,}/u', $text) === 1) {
            return true;
        }
        return preg_match('/无线耳机|蓝牙耳机|保温杯|咖啡机|吹风机|扫地机|护肤品|面霜|口红|香水|运动鞋|连衣裙|双肩包|行李箱|手机壳|键盘|鼠标|宠物用品/u', $text) === 1;
    }

    private static function slotQuestion(array $slots, string $key): string
    {
        foreach ($slots as $slot) {
            if ((string)($slot['key'] ?? '') === $key && trim((string)($slot['ask'] ?? '')) !== '') {
                return trim((string)$slot['ask']);
            }
        }
        return '请补充本次创作所需的“' . $key . '”，我再继续。';
    }

    private static function snapshot(array $skill, int $adminId = 0, string $releaseStatus = ''): void
    {
        try {
            $releaseStatus = $releaseStatus ?: (string)($skill['release_status'] ?? self::RELEASE_DRAFT);
            Db::name('aigc_canvas_skill_version')->insert([
                'tenant_id' => (int)($skill['tenant_id'] ?? 0),
                'skill_id' => (int)($skill['id'] ?? 0),
                'version' => max(1, (int)($skill['version'] ?? 1)),
                'release_status' => $releaseStatus,
                'config_json' => json_encode(self::formatSkill($skill, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_by' => $adminId,
                'published_at' => in_array($releaseStatus, [self::RELEASE_CANARY, self::RELEASE_ACTIVE], true) ? time() : 0,
                'create_time' => time(),
            ]);
        } catch (\Throwable) {
            // The main Skill remains usable while an older installation awaits migration.
        }
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
            'release_status' => (string)($row['release_status'] ?? self::RELEASE_ACTIVE),
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
            'tool_policy_json' => ['allowed_tools' => ['web_fetch', 'brand_research', 'url_to_design_brief', 'asset_analyze', 'canvas_read', 'canvas_select', 'canvas_patch', 'create_page', 'add_element', 'update_element', 'generate_image']],
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
                'tool_policy_json' => ['allowed_tools' => ['web_fetch', 'brand_research', 'url_to_design_brief', 'asset_analyze', 'canvas_read', 'canvas_select', 'canvas_patch', 'generate_image']],
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
                'tool_policy_json' => ['allowed_tools' => ['web_fetch', 'brand_research', 'url_to_design_brief', 'asset_analyze', 'canvas_read', 'canvas_select', 'canvas_patch', 'generate_image']],
                'output_policy_json' => ['workspace_action' => 'insert_image'],
                'cover_url' => '',
                'sort' => 128,
            ],
            [
                'skill_key' => 'poster_design',
                'name' => '海报设计',
                'description' => '生成活动海报、营销海报、课程招生海报、节日海报、品牌海报和横版/竖版视觉主图。',
                'category' => 'image',
                'skill_type' => self::TYPE_AGENT_PROMPT,
                'content_markdown' => '你是无限画布海报设计 Agent。识别主题、主标题、辅助文案、风格、比例、品牌色和用途，并调用图片生成工具生成可插入画布的海报视觉。',
                'trigger_description' => '活动海报、促销海报、招生海报、节日海报、课程海报、品牌海报、横版海报、竖版海报、poster。',
                'workflow_json' => [],
                'examples_json' => ['生成一个科技感海报', '做一张618大促海报，风格要红色热闹', '我想做课程招生海报'],
                'negative_examples_json' => ['写一份营销方案', '生成视频', '普通聊天'],
                'required_slots_json' => ['theme'],
                'optional_slots_json' => ['headline', 'sub_text', 'style', 'ratio', 'brand_colors', 'quantity'],
                'defaults_json' => ['quantity' => 1, 'ratio' => '3:4'],
                'clarification_policy_json' => ['max_questions' => 3, 'questions' => ['theme' => '请补充海报主题或活动内容，例如课程招生、节日促销、品牌发布或新品大促。']],
                'tool_policy_json' => ['allowed_tools' => ['web_fetch', 'brand_research', 'url_to_design_brief', 'asset_analyze', 'canvas_read', 'canvas_select', 'canvas_patch', 'generate_image']],
                'output_policy_json' => ['workspace_action' => 'insert_image'],
                'cover_url' => '',
                'sort' => 127,
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
                'tool_policy_json' => ['allowed_tools' => ['asset_analyze', 'canvas_read', 'canvas_select', 'generate_video']],
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
