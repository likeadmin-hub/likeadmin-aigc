<?php

declare(strict_types=1);

use app\common\model\app\aigc_canvas\AigcCanvasAgentToolCall;
use app\common\model\app\aigc_canvas\AigcCanvasDeliveryItem;
use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryGraphExecutor;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryItemContextBinder;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryItemService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryItemTaskSyncService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryPlanService;
use app\common\service\app\aigc_canvas\agent\delivery\ConversationTaskResolver;
use app\common\service\app\aigc_canvas\agent\delivery\PendingActionProtocol;
use app\common\service\app\aigc_canvas\agent\runtime\AgentTaskDecisionService;
use think\App;
use think\facade\Db;

const PREFIX = 'it_canvas_delivery_';
const TENANT_ID = 990071;
const USER_ID = 990071;

function failE2e(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertE2e(bool $condition, string $message): void
{
    if (!$condition) failE2e($message);
}

function bootE2e(string $root): void
{
    require_once $root . '/vendor/autoload.php';
    $app = new App($root);
    \think\Container::setInstance($app);
    $app->initialize();
    $database = $app->config->get('database');
    $database['connections']['mysql']['prefix'] = PREFIX;
    $app->config->set($database, 'database');
    $app->db->connect('mysql', true);

    // This test owns isolated prefixed tables. Avoid DeliveryPlanService's
    // legacy literal-table compatibility repair against a shared database.
    $property = new ReflectionProperty(DeliveryPlanService::class, 'schemaChecked');
    $property->setAccessible(true);
    $property->setValue(true);
}

function table(string $name): string
{
    return PREFIX . $name;
}

function createTables(): void
{
    Db::execute("CREATE TABLE IF NOT EXISTS `" . table('aigc_canvas_delivery_plan') . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
        `user_id` int unsigned NOT NULL DEFAULT 0, `project_id` int unsigned NOT NULL DEFAULT 0,
        `thread_id` int unsigned NOT NULL DEFAULT 0, `source_message_id` int unsigned NOT NULL DEFAULT 0,
        `title` varchar(160) NOT NULL DEFAULT '', `intent` varchar(80) NOT NULL DEFAULT '',
        `status` varchar(40) NOT NULL DEFAULT 'draft', `item_count` int unsigned NOT NULL DEFAULT 0,
        `meta_json` longtext, `create_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`), KEY `idx_delivery_plan_thread` (`tenant_id`,`user_id`,`thread_id`,`status`,`delete_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    Db::execute("CREATE TABLE IF NOT EXISTS `" . table('aigc_canvas_delivery_item') . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
        `user_id` int unsigned NOT NULL DEFAULT 0, `project_id` int unsigned NOT NULL DEFAULT 0,
        `thread_id` int unsigned NOT NULL DEFAULT 0, `plan_id` int unsigned NOT NULL DEFAULT 0,
        `parent_item_id` int unsigned NOT NULL DEFAULT 0, `source_message_id` int unsigned NOT NULL DEFAULT 0,
        `item_key` varchar(96) NOT NULL DEFAULT '', `objective` text, `skill_key` varchar(120) NOT NULL DEFAULT '',
        `tool_code` varchar(80) NOT NULL DEFAULT '', `status` varchar(40) NOT NULL DEFAULT 'draft',
        `sort_order` int unsigned NOT NULL DEFAULT 0, `retry_count` int unsigned NOT NULL DEFAULT 0,
        `depends_on_json` longtext, `required_slots_json` longtext, `soft_slots_json` longtext, `slots_json` longtext,
        `delivery_json` longtext, `creative_context_json` longtext, `reference_assets_json` longtext,
        `pending_action_json` longtext, `skill_snapshot_json` longtext, `task_snapshot_json` longtext,
        `cost_json` longtext, `result_json` longtext, `provider_request_id` varchar(160) NOT NULL DEFAULT '',
        `provider_error_code` varchar(120) NOT NULL DEFAULT '', `provider_error_message` text, `error` text,
        `meta_json` longtext, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
        `delete_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`),
        KEY `idx_delivery_item_thread` (`tenant_id`,`user_id`,`thread_id`,`status`,`delete_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    Db::execute("CREATE TABLE IF NOT EXISTS `" . table('aigc_canvas_agent_tool_call') . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
        `user_id` int unsigned NOT NULL DEFAULT 0, `project_id` int unsigned NOT NULL DEFAULT 0,
        `thread_id` int unsigned NOT NULL DEFAULT 0, `message_id` int unsigned NOT NULL DEFAULT 0,
        `request_id` varchar(96) NOT NULL DEFAULT '', `tool_code` varchar(80) NOT NULL DEFAULT '',
        `delivery_item_id` bigint unsigned NOT NULL DEFAULT 0, `attempt_no` int unsigned NOT NULL DEFAULT 1,
        `idempotency_key` varchar(128) NOT NULL DEFAULT '', `provider_task_id` varchar(128) NOT NULL DEFAULT '',
        `retry_count` int unsigned NOT NULL DEFAULT 0, `error_code` varchar(80) NOT NULL DEFAULT '',
        `status` varchar(30) NOT NULL DEFAULT 'running', `input_json` longtext, `output_json` longtext,
        `error` text, `started_at` int unsigned NOT NULL DEFAULT 0, `finished_at` int unsigned NOT NULL DEFAULT 0,
        `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
        `delete_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`),
        KEY `idx_delivery_item` (`tenant_id`,`user_id`,`delivery_item_id`),
        KEY `idx_provider_task` (`tenant_id`,`provider_task_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    Db::execute("CREATE TABLE IF NOT EXISTS `" . table('aigc_canvas_agent_workspace_action') . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
        `user_id` int unsigned NOT NULL DEFAULT 0, `project_id` int unsigned NOT NULL DEFAULT 0,
        `thread_id` int unsigned NOT NULL DEFAULT 0, `message_id` int unsigned NOT NULL DEFAULT 0,
        `tool_call_id` int unsigned NOT NULL DEFAULT 0, `action_type` varchar(80) NOT NULL DEFAULT '',
        `status` varchar(30) NOT NULL DEFAULT 'pending', `input_json` longtext, `result_json` longtext,
        `error` text, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
        `delete_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`),
        KEY `idx_workspace_action_message` (`tenant_id`,`user_id`,`message_id`,`delete_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    Db::execute("CREATE TABLE IF NOT EXISTS `" . table('aigc_canvas_agent_message') . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
        `user_id` int unsigned NOT NULL DEFAULT 0, `project_id` int unsigned NOT NULL DEFAULT 0,
        `thread_id` int unsigned NOT NULL DEFAULT 0, `role` varchar(30) NOT NULL DEFAULT '',
        `content` longtext, `content_json` longtext, `status` varchar(30) NOT NULL DEFAULT 'success',
        `meta_json` longtext, `create_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`), KEY `idx_agent_message_thread` (`tenant_id`,`user_id`,`thread_id`,`delete_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    Db::execute("CREATE TABLE IF NOT EXISTS `" . table('aigc_canvas_skill') . "` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
        `user_id` int unsigned NOT NULL DEFAULT 0, `skill_key` varchar(120) NOT NULL DEFAULT '',
        `name` varchar(160) NOT NULL DEFAULT '', `description` text, `skill_type` varchar(40) NOT NULL DEFAULT 'agent_prompt',
        `status` tinyint NOT NULL DEFAULT 1, `release_status` varchar(30) NOT NULL DEFAULT 'active', `sort` int NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`), KEY `idx_skill_key` (`tenant_id`,`skill_key`,`delete_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $skillFields = Db::name('aigc_canvas_skill')->getFields();
    foreach (['tool_policy_json', 'required_slots_json'] as $field) {
        if (!isset($skillFields[$field])) {
            Db::execute("ALTER TABLE `" . table('aigc_canvas_skill') . "` ADD COLUMN `{$field}` longtext NULL");
        }
    }
}

function item(array $override = []): array
{
    $now = time();
    $defaults = [
        'tenant_id' => TENANT_ID, 'user_id' => USER_ID, 'project_id' => 71, 'thread_id' => 81,
        'plan_id' => 1, 'parent_item_id' => 0, 'source_message_id' => 91,
        'item_key' => 'item_' . bin2hex(random_bytes(4)), 'objective' => 'Create product visual',
        'skill_key' => 'ecommerce_main_image', 'tool_code' => 'generate_image', 'status' => 'ready',
        'sort_order' => 1, 'retry_count' => 0, 'depends_on_json' => [], 'required_slots_json' => ['product_reference'],
        'soft_slots_json' => [], 'slots_json' => ['user_request' => 'Create a clean product hero image'],
        'delivery_json' => ['type' => 'ecommerce_main_image'], 'creative_context_json' => [],
        'reference_assets_json' => [['url' => 'https://cdn.example.test/product.jpg', 'source' => 'upload']],
        'pending_action_json' => [], 'skill_snapshot_json' => [], 'task_snapshot_json' => [], 'cost_json' => [],
        'result_json' => [], 'provider_request_id' => '', 'provider_error_code' => '',
        'provider_error_message' => '', 'error' => '', 'meta_json' => [], 'create_time' => $now,
        'update_time' => $now, 'delete_time' => 0,
    ];
    $row = AigcCanvasDeliveryItem::create(array_merge($defaults, $override));
    return DeliveryItemService::find(TENANT_ID, USER_ID, (int)$row['id']);
}

function getItem(int $id): array
{
    return DeliveryItemService::find(TENANT_ID, USER_ID, $id);
}

function decision(string $relation, array $specs, int $targetItemId = 0): array
{
    return [
        'turn_relation' => $relation,
        'target_delivery_item_id' => $targetItemId,
        'confidence' => 0.95,
        'intent' => 'generation',
        'execution_mode' => $relation === 'new' ? 'plan' : 'execute',
        'delivery_specs' => $specs,
    ];
}

function spec(string $key, string $skillKey, string $objective, array $delivery, array $references = [], string $status = 'ready'): array
{
    $requiresReference = str_starts_with($skillKey, 'ecommerce_');
    return [
        'item_key' => $key,
        'objective' => $objective,
        'skill_key' => $skillKey,
        'tool_code' => 'generate_image',
        'status' => $status,
        'required_slots' => $requiresReference ? ['product_reference'] : [],
        'soft_slots' => [],
        'slots' => ['user_request' => $objective],
        'delivery' => $delivery,
        'reference_assets' => $references,
        'pending_action' => PendingActionProtocol::confirmation(),
    ];
}

$root = dirname(__DIR__, 4);
$tables = [
    table('aigc_canvas_agent_workspace_action'),
    table('aigc_canvas_agent_message'),
    table('aigc_canvas_agent_tool_call'),
    table('aigc_canvas_delivery_item'),
    table('aigc_canvas_delivery_plan'),
    table('aigc_canvas_skill'),
];
$checked = [];

try {
    bootE2e($root);
    createTables();
    foreach ($tables as $name) Db::execute("TRUNCATE TABLE `{$name}`");
    foreach (['ecommerce_main_image', 'ecommerce_detail_page', 'general_image', 'logo_design', 'script_planning', 'video_generation'] as $skillKey) {
        $isGeneralImage = $skillKey === 'general_image';
        Db::name('aigc_canvas_skill')->insert([
            'tenant_id' => TENANT_ID, 'user_id' => 0, 'skill_key' => $skillKey,
            'name' => $skillKey, 'description' => '', 'skill_type' => 'agent_prompt', 'status' => 1, 'release_status' => 'active', 'sort' => 0, 'delete_time' => 0,
            'tool_policy_json' => json_encode(['allowed_tools' => $isGeneralImage ? ['ask_user', 'generate_image'] : ['ask_user', 'generate_text']]),
            'required_slots_json' => json_encode($isGeneralImage ? [['key' => 'subject', 'type' => 'text']] : []),
        ]);
    }

    $submitted = 0;
    DeliveryGraphExecutor::setSubmissionAdapterForTesting(static function (array $request) use (&$submitted): array {
        $submitted++;
        $now = time();
        $providerTaskId = 'fake-provider-' . $submitted;
        $tool = AigcCanvasAgentToolCall::create([
            'tenant_id' => TENANT_ID, 'user_id' => USER_ID, 'project_id' => $request['project_id'],
            'thread_id' => $request['thread_id'], 'message_id' => $request['message_id'],
            'request_id' => (string)$request['input']['request_id'], 'tool_code' => $request['tool_code'],
            'delivery_item_id' => (int)$request['input']['delivery_item_id'],
            'attempt_no' => (int)$request['input']['attempt_no'],
            'idempotency_key' => 'fake:' . (string)$request['input']['request_id'],
            'provider_task_id' => $providerTaskId, 'retry_count' => 0, 'error_code' => '', 'status' => 'running',
            'input_json' => $request['input'], 'output_json' => ['status' => 'running', 'task_id' => $providerTaskId],
            'error' => '', 'started_at' => $now, 'finished_at' => 0, 'create_time' => $now,
            'update_time' => $now, 'delete_time' => 0,
        ]);
        return [
            'tool_calls' => [[
                'id' => (int)$tool['id'], 'input' => $request['input'], 'provider_task_id' => $providerTaskId,
                'output' => ['status' => 'running', 'task_id' => $providerTaskId, 'request_id' => $request['input']['request_id']],
            ]],
            'assets' => [],
            'workspace_actions' => [['type' => 'add_image', 'target_element_id' => $request['input']['target_element_id']]],
        ];
    });

    // 1. Uploaded product reference survives binding, graph submission and callback projection.
    $main = item();
    $submittedResult = DeliveryGraphExecutor::execute(TENANT_ID, USER_ID, (int)$main['id']);
    $main = getItem((int)$main['id']);
    assertE2e($main['status'] === 'running' && count($main['reference_assets']) === 1, 'reference-backed item was not submitted');
    $toolCallId = (int)$main['task_snapshot']['tool_call_id'];
    assertE2e($toolCallId > 0 && $submitted === 1, 'graph did not create exactly one associated tool call');
    $main = DeliveryItemTaskSyncService::syncAgentToolCall(TENANT_ID, USER_ID, $toolCallId, [
        'status' => 'success', 'task_id' => 'fake-provider-1', 'request_id' => 'callback-1',
        'assets' => [['url' => 'https://cdn.example.test/result.jpg']],
        'workspace_actions' => $main['result']['workspace_actions'] ?? [],
    ]);
    assertE2e($main['status'] === 'completed' && !empty($main['result']['assets']) && !empty($main['result']['workspace_actions']), 'callback did not complete item and retain canvas action');
    $checked['upload_reference_submit_callback_canvas'] = true;

    // 2-3. The actual resolver creates independent plans for a logo and a new
    // main image instead of continuing an awaiting main/detail-page item.
    $mainPlan = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 82, 201, 'Generate a marketplace main image', [
        'uploaded_references' => [['url' => 'https://cdn.example.test/main.jpg', 'source' => 'upload']],
    ], ['task_decision' => decision('new', [
        spec('main-awaiting', 'ecommerce_main_image', 'Marketplace main image', ['ratio' => '1:1'], [], 'awaiting_confirmation'),
    ])]);
    $awaiting = (array)$mainPlan['item'];
    $logoPlan = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 82, 202, 'Create a new logo', [], ['task_decision' => decision('new', [
        spec('logo-new', 'logo_design', 'New logo', ['ratio' => '1:1']),
    ])]);
    $logo = (array)$logoPlan['item'];
    $detailPlan = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 82, 203, 'Keep the existing detail page', [
        'uploaded_references' => [['url' => 'https://cdn.example.test/detail.jpg', 'source' => 'upload']],
    ], ['task_decision' => decision('new', [
        spec('detail-existing', 'ecommerce_detail_page', 'Existing detail page', ['section_count' => 5], [], 'awaiting_confirmation'),
    ])]);
    $detail = (array)$detailPlan['item'];
    $newMainPlan = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 82, 204, 'Create a 16:9 main image', [
        'uploaded_references' => [['url' => 'https://cdn.example.test/new-main.jpg', 'source' => 'upload']],
    ], ['task_decision' => decision('new', [
        spec('main-16-9', 'ecommerce_main_image', '16:9 main image', ['ratio' => '16:9']),
    ])]);
    $ratioMain = (array)$newMainPlan['item'];
    assertE2e((int)$logo['id'] !== (int)$awaiting['id'] && $logo['reference_assets'] === [], 'new logo contaminated an awaiting main image');
    assertE2e((int)$ratioMain['id'] !== (int)$detail['id'] && getItem((int)$detail['id'])['status'] === 'awaiting_confirmation', 'new main image changed detail-page delivery');
    $checked['new_item_does_not_continue_awaiting'] = true;
    $checked['detail_page_keeps_existing_item'] = true;

    // 4. A natural revision reaches the resolver's item-scoped revision path
    // and retains the persisted product reference.
    $revisionResult = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 82, 205, '改成 9:16，不要文案', [], [
        'task_decision' => decision('revise', [], (int)$ratioMain['id']),
    ]);
    $ratioMain = (array)$revisionResult['item'];
    assertE2e($ratioMain['delivery']['ratio'] === '9:16' && count($ratioMain['reference_assets']) === 1, 'revision replaced product reference unexpectedly');
    $checked['revision_preserves_reference'] = true;

    // 5-6. Natural and structured confirmation resolve the same item contract,
    // then the graph's compare-and-swap permits exactly one fake provider call.
    $confirm = item(['item_key' => 'confirm-once', 'status' => 'awaiting_confirmation', 'pending_action_json' => PendingActionProtocol::confirmation()]);
    $naturalConfirmation = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 81, 206, '确认', [], [
        'task_decision' => decision('confirm', [], (int)$confirm['id']),
    ]);
    $confirm = (array)$naturalConfirmation['item'];
    assertE2e($confirm['status'] === 'ready', 'natural confirmation did not resolve the targeted item');
    $firstSubmission = DeliveryGraphExecutor::execute(TENANT_ID, USER_ID, (int)$confirm['id']);
    $secondClaimRejected = false;
    try { DeliveryGraphExecutor::execute(TENANT_ID, USER_ID, (int)$confirm['id']); } catch (Throwable) { $secondClaimRejected = true; }
    $structured = item(['item_key' => 'confirm-structured', 'status' => 'awaiting_confirmation', 'pending_action_json' => PendingActionProtocol::confirmation()]);
    $structuredConfirmation = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 81, 207, '确认', [], [
        'action' => 'confirm_execution', 'structured_value' => true,
        'task_decision' => decision('confirm', [], (int)$structured['id']),
    ]);
    $structured = (array)$structuredConfirmation['item'];
    assertE2e($firstSubmission['status'] === 'running' && $secondClaimRejected, 'double confirmation created a second provider submission');
    assertE2e($structured['status'] === 'ready', 'structured confirmation did not share the item state contract');
    $checked['double_confirmation_idempotent'] = true;
    $checked['natural_and_structured_confirmation_share_executor'] = true;

    // 7. The shared projection records a completed provider callback on the same item.
    assertE2e($main['task_snapshot']['attempt_no'] === 1 && $main['task_snapshot']['provider_task_id'] === 'fake-provider-1', 'callback snapshot is incomplete');
    $checked['provider_callback_syncs_item_asset_canvas'] = true;

    // 8. Failure creates a new attempt without overwriting the first snapshot entry.
    $retry = item(['item_key' => 'retry-item']);
    DeliveryItemService::claimReady(TENANT_ID, USER_ID, (int)$retry['id']);
    $retry = DeliveryItemService::transition(TENANT_ID, USER_ID, (int)$retry['id'], 'running', ['task_snapshot_json' => ['attempt_no' => 1, 'attempts' => [['attempt_no' => 1, 'provider_task_id' => 'old']]]]);
    $retry = DeliveryItemService::transition(TENANT_ID, USER_ID, (int)$retry['id'], 'failed', ['error' => 'fake failure']);
    DeliveryGraphExecutor::retry(TENANT_ID, USER_ID, (int)$retry['id']);
    $retry = getItem((int)$retry['id']);
    assertE2e(
        $retry['status'] === 'running'
        && $retry['retry_count'] === 1
        && $retry['task_snapshot']['attempts'][0]['provider_task_id'] === 'old'
        && (int)$retry['task_snapshot']['attempts'][1]['attempt_no'] === 2,
        'retry overwrote the earlier attempt snapshot'
    );
    $checked['retry_creates_new_attempt'] = true;

    // 9. A reference-only product request remains executable without invented claims.
    $referenceOnly = item(['item_key' => 'reference-only', 'slots_json' => ['user_request' => 'Create a safe main image'], 'creative_context_json' => []]);
    $referenceOnly = DeliveryItemContextBinder::bindBase(TENANT_ID, USER_ID, $referenceOnly, ['uploaded_references' => $referenceOnly['reference_assets']]);
    DeliveryItemContextBinder::ensureExecutableContext(TENANT_ID, USER_ID, $referenceOnly);
    assertE2e(empty($referenceOnly['creative_context']['claims']), 'reference-only request invented selling points');
    $checked['reference_only_product_is_safe'] = true;

    // 10. Direct mode has no delivery-item association while using the same durable tool-call table.
    $now = time();
    AigcCanvasAgentToolCall::create([
        'tenant_id' => TENANT_ID, 'user_id' => USER_ID, 'project_id' => 71, 'thread_id' => 81, 'message_id' => 92,
        'request_id' => 'direct-image', 'tool_code' => 'generate_image', 'delivery_item_id' => 0, 'attempt_no' => 1,
        'idempotency_key' => 'direct-image', 'provider_task_id' => 'direct-task', 'retry_count' => 0, 'error_code' => '',
        'status' => 'running', 'input_json' => ['prompt_mode' => 'direct'], 'output_json' => [], 'error' => '',
        'started_at' => $now, 'finished_at' => 0, 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
    ]);
    assertE2e((int)Db::name('aigc_canvas_agent_tool_call')->where('request_id', 'direct-image')->value('delivery_item_id') === 0, 'direct image mode created a delivery association');
    $checked['direct_image_mode_has_no_delivery_item'] = true;

    // 11. The brand-planning pilot compiles the reusable stage chain, persists
    // ID dependencies, and exposes visual generation only after upstream text.
    $workflow = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 133, 301, 'Create a complete brand planning workflow', [
        'brand_name' => 'Atlas',
    ], ['task_decision' => [
        'turn_relation' => 'new', 'intent' => 'creative_plan', 'execution_mode' => 'plan',
        'confidence' => 0.96, 'workflow_template' => 'brand_planning', 'delivery_specs' => [],
    ]]);
    $workflowItems = (array)($workflow['plan']['items'] ?? []);
    assertE2e(count($workflowItems) === 7, 'brand workflow did not compile all reusable stages');
    $byStage = [];
    foreach ($workflowItems as $workflowItem) $byStage[(string)($workflowItem['meta']['workflow_stage'] ?? '')] = $workflowItem;
    assertE2e((string)($byStage['brief']['status'] ?? '') === 'ready', 'workflow brief is not ready');
    assertE2e((int)(($byStage['research']['depends_on'] ?? [0])[0] ?? 0) === (int)($byStage['brief']['id'] ?? 0), 'research dependency was not compiled to an item id');
    foreach (['brief', 'research', 'strategy', 'copy', 'visual_direction'] as $stage) {
        $stageItem = DeliveryItemTaskSyncService::syncTextStage(TENANT_ID, USER_ID, (int)$byStage[$stage]['id'], [
            'reply' => 'Actual ' . $stage . ' result', 'tool_calls' => [],
        ]);
        assertE2e((string)$stageItem['status'] === 'completed', $stage . ' text stage did not complete');
        $restoredTextPlan = DeliveryPlanService::detail(TENANT_ID, USER_ID, (int)$workflow['plan']['id']);
        $restoredTextItem = array_values(array_filter((array)$restoredTextPlan['items'], static fn(array $row): bool => (string)($row['id'] ?? '') === (string)$stageItem['id']));
        assertE2e((string)($restoredTextItem[0]['status'] ?? '') === 'completed', $stage . ' completion was not durable for restored history');
    }
    $visualAssets = DeliveryItemService::find(TENANT_ID, USER_ID, (int)$byStage['visual_assets']['id']);
    assertE2e((string)$visualAssets['status'] === 'awaiting_confirmation'
        && (string)($visualAssets['pending_action']['type'] ?? '') === 'approve_visual_generation', 'visual assets were exposed before the required approval');
    $approval = PendingActionProtocol::resolve($visualAssets, [
        'action' => 'approve_visual_generation', 'action_id' => (string)$visualAssets['pending_action']['action_id'], 'structured_value' => true,
    ]);
    $visualAssets = DeliveryItemService::transition(TENANT_ID, USER_ID, (int)$visualAssets['id'], (string)$approval['status'], (array)$approval['patch']);
    assertE2e((string)$visualAssets['status'] === 'ready', 'visual approval did not leave item ready for executor claim');
    $checked['brand_strategy_then_visual_approval'] = true;

    // 12. An insufficient-balance failure affects the media stage only; all
    // completed text work stays durable and the downstream application waits.
    DeliveryGraphExecutor::setSubmissionAdapterForTesting(static function (): array {
        throw new Exception('Insufficient points');
    });
    $blockedVisual = DeliveryGraphExecutor::execute(TENANT_ID, USER_ID, (int)$visualAssets['id']);
    $applications = DeliveryItemService::find(TENANT_ID, USER_ID, (int)$byStage['applications']['id']);
    assertE2e((string)$blockedVisual['status'] === 'failed', 'insufficient points did not block the media stage');
    assertE2e((string)DeliveryItemService::find(TENANT_ID, USER_ID, (int)$byStage['strategy']['id'])['status'] === 'completed'
        && (string)$applications['status'] === 'draft', 'insufficient points changed completed text work or unlocked downstream work');
    $restoredPlan = DeliveryPlanService::detail(TENANT_ID, USER_ID, (int)$workflow['plan']['id']);
    $restoredVisual = array_values(array_filter((array)$restoredPlan['items'], static fn(array $row): bool => (string)($row['item_key'] ?? '') === 'visual_assets'));
    assertE2e(count($restoredVisual) === 1 && (string)$restoredVisual[0]['status'] === 'failed', 'workflow history did not restore the blocked media stage');
    $historyDecision = AgentTaskDecisionService::decide(TENANT_ID, '继续', [], [], ['user_id' => USER_ID, 'thread_id' => 133]);
    assertE2e((int)($historyDecision['target_delivery_item_id'] ?? 0) === (int)$visualAssets['id'], 'restored workflow could not continue its pending media action');
    $checked['insufficient_points_only_blocks_media_and_history_continues'] = true;

    // 13. Incomplete strong-workflow briefs ask for input and create no tool call.
    $beforeToolCalls = (int)Db::name('aigc_canvas_agent_tool_call')->count();
    $incomplete = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 134, 302, 'Create a complete brand planning workflow', [], [
        'task_decision' => ['turn_relation' => 'new', 'intent' => 'creative_plan', 'execution_mode' => 'clarify', 'confidence' => 0.96, 'workflow_template' => 'brand_planning'],
    ]);
    $incompleteBrief = (array)($incomplete['item'] ?? []);
    assertE2e((string)$incompleteBrief['status'] === 'clarifying' && (string)($incompleteBrief['pending_action']['type'] ?? '') === 'fill_slot', 'incomplete workflow brief did not ask for required information');
    assertE2e((int)Db::name('aigc_canvas_agent_tool_call')->count() === $beforeToolCalls, 'clarification consumed a generation tool call');
    $checked['workflow_missing_information_no_tool_call'] = true;

    // 14. Imperative continuation targets the only pending item; an explicit
    // new logo request remains a new task instead of mutating that item.
    $continuable = item(['thread_id' => 135, 'status' => 'awaiting_confirmation', 'pending_action_json' => PendingActionProtocol::confirmation()]);
    $continueDecision = AgentTaskDecisionService::decide(TENANT_ID, '先生成', [], [], ['user_id' => USER_ID, 'thread_id' => 135]);
    assertE2e((int)($continueDecision['target_delivery_item_id'] ?? 0) === (int)$continuable['id']
        && in_array((string)($continueDecision['turn_relation'] ?? ''), ['continue', 'confirm'], true), 'imperative continuation was routed away from its pending item');
    $newDecision = AgentTaskDecisionService::decide(TENANT_ID, '再做一个 Logo', [], [], ['user_id' => USER_ID, 'thread_id' => 135]);
    assertE2e((string)($newDecision['turn_relation'] ?? '') === 'new' && (int)($newDecision['target_delivery_item_id'] ?? 0) === 0, 'explicit new logo request modified the pending item');
    $checked['continuation_and_explicit_new_task_routing'] = true;

    $advisoryDecision = AgentTaskDecisionService::decide(TENANT_ID, 'Plan a short script outline', [], [], ['user_id' => USER_ID, 'thread_id' => 136]);
    assertE2e(empty($advisoryDecision['workflow_template']), 'ordinary script planning was upgraded into a strong workflow');
    $checked['ordinary_script_planning_stays_advisory'] = true;

    // 15. Semantic intent distinguishes a video script from rendering media.
    // Direct media stays on the established submission path and never creates
    // a delivery item; only a compiled professional workflow may do so.
    $scriptDecision = AgentTaskDecisionService::decide(TENANT_ID, 'Write a 15-second smartwatch video script', [], [], [
        'user_id' => USER_ID, 'thread_id' => 138,
        'semantic_decision' => [
            'turn_relation' => 'new', 'intent' => 'text_generation', 'execution_mode' => 'clarify',
            'selected_skill_key' => 'script_planning', 'confidence' => 0.98,
        ],
    ]);
    assertE2e((string)($scriptDecision['intent'] ?? '') === 'text_generation'
        && (array)($scriptDecision['delivery_specs'] ?? []) === [], 'video script semantic decision created media delivery');
    $scriptResolution = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 138, 303, 'Write a 15-second smartwatch video script', [], [
        'task_decision' => $scriptDecision,
    ]);
    assertE2e($scriptResolution === [], 'text-only script request created a delivery item');
    $videoDecision = AgentTaskDecisionService::decide(TENANT_ID, 'Render a 15-second smartwatch video from this script', [], [], [
        'user_id' => USER_ID, 'thread_id' => 139,
        'semantic_decision' => [
            'turn_relation' => 'new', 'intent' => 'generation', 'execution_mode' => 'plan',
            'selected_skill_key' => 'video_generation', 'confidence' => 0.98,
        ],
    ]);
    assertE2e((array)($videoDecision['delivery_specs'] ?? []) === [], 'direct video render created a delivery spec');
    $videoResolution = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 139, 304, 'Render a 15-second smartwatch video from this script', [], [
        'task_decision' => $videoDecision,
    ]);
    assertE2e($videoResolution === [], 'direct video render created a delivery item');
    $checked['semantic_text_script_and_direct_video_render'] = true;

    // 15a. A concrete, high-confidence direct media request must expose only
    // its approved costly tool to the Agent Loop. This prevents a completed
    // brief or a continuation value such as "1" from being classified as an
    // advisory reply and then rejected after the model selects generate_image.
    $readyImageDecision = AgentTaskDecisionService::decide(TENANT_ID, 'Create a cinematic portrait of a red fox in snowfall', [], [], [
        'user_id' => USER_ID, 'thread_id' => 140,
        'semantic_decision' => [
            'turn_relation' => 'new', 'intent' => 'generation', 'execution_mode' => 'execute',
            'selected_skill_key' => 'general_image', 'confidence' => 0.98,
        ],
    ]);
    assertE2e((string)($readyImageDecision['execution_mode'] ?? '') === 'execute'
        && in_array('generate_image', (array)($readyImageDecision['runtime_allowed_tools'] ?? []), true)
        && in_array('generate_image', (array)($readyImageDecision['skill_contract']['allowed_tools'] ?? []), true),
        'concrete direct image request was not authorized for controlled media execution');
    assertE2e((array)($readyImageDecision['delivery_specs'] ?? []) === [], 'direct image request created a delivery item');
    $checked['direct_media_execution_is_authorized_without_delivery_item'] = true;

    // 15b. A media request without a subject is a clarification, not a
    // ready delivery card or an implicit tool submission.
    $bareImageDecision = AgentTaskDecisionService::decide(TENANT_ID, '我想生成一张图片', [], [], [
        'user_id' => USER_ID, 'thread_id' => 141,
        'semantic_decision' => [
            'turn_relation' => 'new', 'intent' => 'generation', 'execution_mode' => 'execute',
            'selected_skill_key' => 'general_image', 'confidence' => 0.98,
        ],
    ]);
    assertE2e((string)($bareImageDecision['execution_mode'] ?? '') === 'clarify'
        && in_array('prompt_or_brief', (array)($bareImageDecision['missing_hard_slots'] ?? []), true)
        && (array)($bareImageDecision['delivery_specs'] ?? []) === [], 'bare image request was not converted to clarification');
    assertE2e(str_contains((string)($bareImageDecision['clarification_question'] ?? ''), '想生成什么图片'), 'bare image clarification is not user-facing Chinese');
    $beforeBareImageItems = (int)Db::name('aigc_canvas_delivery_item')->count();
    $bareImageResolution = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 141, 305, '我想生成一张图片', [], [
        'task_decision' => $bareImageDecision,
    ]);
    assertE2e($bareImageResolution === [] && (int)Db::name('aigc_canvas_delivery_item')->count() === $beforeBareImageItems,
        'bare image clarification created a delivery item');
    $checked['bare_media_request_clarifies_without_delivery_item'] = true;

    // 16. A bare regeneration after an actual script response must reuse the
    // text task, even if a stale media proposal exists in the same thread.
    $staleMedia = item([
        'thread_id' => 142, 'item_key' => 'stale-media-proposal', 'status' => 'awaiting_confirmation',
        'pending_action_json' => PendingActionProtocol::confirmation(),
    ]);
    $now = time();
    Db::name('aigc_canvas_agent_message')->insertAll([
        [
            'tenant_id' => TENANT_ID, 'user_id' => USER_ID, 'project_id' => 71, 'thread_id' => 142,
            'role' => 'user', 'content' => 'Write a 10-second rooftop duel video script', 'content_json' => '{}',
            'status' => 'success', 'meta_json' => '{}', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ],
        [
            'tenant_id' => TENANT_ID, 'user_id' => USER_ID, 'project_id' => 71, 'thread_id' => 142,
            'role' => 'assistant', 'content' => "10-second script:\nScene: two people face off on a rooftop.",
            // Simulates the historical bad route: the visible result is text,
            // but its internal decision incorrectly claimed media generation.
            'content_json' => json_encode(['task_decision' => ['intent' => 'generation'], 'tool_calls' => [], 'workspace_actions' => [], 'assets' => []]),
            'status' => 'success', 'meta_json' => '{}', 'create_time' => $now + 1, 'update_time' => $now + 1, 'delete_time' => 0,
        ],
    ]);
    $scriptUserMessageId = (int)Db::name('aigc_canvas_agent_message')->where([
        'tenant_id' => TENANT_ID, 'user_id' => USER_ID, 'thread_id' => 142, 'role' => 'user',
    ])->value('id');
    $scriptAssistantMessageId = (int)Db::name('aigc_canvas_agent_message')->where([
        'tenant_id' => TENANT_ID, 'user_id' => USER_ID, 'thread_id' => 142, 'role' => 'assistant',
    ])->value('id');
    Db::name('aigc_canvas_agent_workspace_action')->insert([
        'tenant_id' => TENANT_ID, 'user_id' => USER_ID, 'project_id' => 71, 'thread_id' => 142,
        'message_id' => $scriptAssistantMessageId, 'tool_call_id' => 0, 'action_type' => 'insert_text',
        'status' => 'applied', 'input_json' => json_encode(['content' => '10-second script']), 'result_json' => '{}',
        'error' => '', 'create_time' => $now + 1, 'update_time' => $now + 1, 'delete_time' => 0,
    ]);
    $textRetryDecision = AgentTaskDecisionService::decide(TENANT_ID, 'Regenerate', [], [], [
        'user_id' => USER_ID, 'thread_id' => 142, 'agent_intent_router_enabled' => false,
        'agent_generic_capability_fallback_enabled' => false,
    ]);
    assertE2e((string)($textRetryDecision['intent'] ?? '') === 'text_generation'
        && (string)($textRetryDecision['turn_relation'] ?? '') === 'retry'
        && (int)($textRetryDecision['target_delivery_item_id'] ?? 0) === 0
        && (array)($textRetryDecision['delivery_specs'] ?? []) === [], 'text regeneration was routed to stale media work');
    $textRetryResolution = ConversationTaskResolver::resolve(TENANT_ID, USER_ID, 71, 142, 306, 'Regenerate', [], [
        'task_decision' => $textRetryDecision,
    ]);
    assertE2e($textRetryResolution === [] && (string)getItem((int)$staleMedia['id'])['status'] === 'awaiting_confirmation', 'text regeneration changed the stale media item');
    $retrySourceId = AgentTaskDecisionService::retrySourceMessageId(TENANT_ID, USER_ID, 142, $scriptUserMessageId);
    assertE2e($retrySourceId === $scriptAssistantMessageId, 'retry did not identify the actual completed text delivery');
    $buttonRetryDecision = AgentTaskDecisionService::decide(TENANT_ID, 'Write a 10-second rooftop duel video script', [], [], [
        'user_id' => USER_ID, 'thread_id' => 142, 'retry_source_message_id' => $retrySourceId,
        'retry_source_request' => 'Write a 10-second rooftop duel video script',
        'agent_intent_router_enabled' => false, 'agent_generic_capability_fallback_enabled' => false,
    ]);
    assertE2e((string)($buttonRetryDecision['intent'] ?? '') === 'text_generation'
        && (int)($buttonRetryDecision['target_delivery_item_id'] ?? 0) === 0,
        'retry button did not revalidate the original text task type');
    $checked['text_regeneration_does_not_submit_media'] = true;

    // 16b. Routing receives recent history from its own thread only. A blank
    // thread is also isolated from implicit project, brand, and canvas memory.
    Db::name('aigc_canvas_agent_message')->insert([
        'tenant_id' => TENANT_ID, 'user_id' => USER_ID, 'project_id' => 71, 'thread_id' => 143,
        'role' => 'user', 'content' => 'unrelated product detail page request', 'content_json' => '{}',
        'status' => 'success', 'meta_json' => '{}', 'create_time' => $now + 2, 'update_time' => $now + 2, 'delete_time' => 0,
    ]);
    $semanticContextMethod = new ReflectionMethod(AgentTaskDecisionService::class, 'semanticTaskContext');
    $semanticContextMethod->setAccessible(true);
    $threadSemanticContext = $semanticContextMethod->invoke(null, TENANT_ID, [
        'user_id' => USER_ID, 'thread_id' => 142,
    ]);
    $threadHistory = implode("\n", array_column((array)($threadSemanticContext['recent_messages'] ?? []), 'content'));
    assertE2e(str_contains($threadHistory, 'rooftop duel video script')
        && !str_contains($threadHistory, 'unrelated product detail page request'), 'semantic router context leaked another thread history');

    $contextMethod = new ReflectionMethod(AigcCanvasAgentRuntimeService::class, 'contextForUserRequest');
    $contextMethod->setAccessible(true);
    $canvasContext = [
        'project' => ['title' => 'shared canvas'],
        'elements' => [['id' => 'old-brand-node', 'type' => 'text']],
        'selected_elements' => [],
        'brand_memory' => ['profile' => ['name' => 'other conversation brand']],
    ];
    $freshContext = $contextMethod->invoke(null, 'product promotion', $canvasContext, true);
    assertE2e(empty($freshContext['elements']) && empty($freshContext['brand_memory']) && empty($freshContext['context_used']),
        'fresh conversation inherited implicit canvas or brand memory');
    $threadIsolationMethod = new ReflectionMethod(AigcCanvasAgentRuntimeService::class, 'threadUsesIsolatedContext');
    $threadIsolationMethod->setAccessible(true);
    $isolatedThreadContext = $threadIsolationMethod->invoke(null, ['meta' => ['context_isolated' => true]]);
    assertE2e($isolatedThreadContext === true, 'new thread did not retain context isolation after its first turn');
    $isolatedFollowUpContext = $contextMethod->invoke(null, 'what can you do', $canvasContext, $isolatedThreadContext);
    assertE2e(empty($isolatedFollowUpContext['elements']) && empty($isolatedFollowUpContext['brand_memory']),
        'isolated thread follow-up inherited another conversation context');
    $explicitCanvasContext = $contextMethod->invoke(null, 'based on the current canvas', $canvasContext, true);
    assertE2e(!empty($explicitCanvasContext['elements']) && !empty($explicitCanvasContext['context_used']),
        'fresh conversation ignored an explicit canvas reference');
    $freshDeliveryContext = \app\common\service\app\aigc_canvas\agent\memory\ConversationDeliveryContext::build(
        TENANT_ID,
        USER_ID,
        71,
        0,
        $canvasContext,
        false
    );
    assertE2e((array)($freshDeliveryContext['project_memory'] ?? []) === [], 'fresh conversation inherited project memory');
    $checked['thread_memory_and_new_conversation_isolation'] = true;

    $noSkillWorkflow = AgentTaskDecisionService::decide(TENANT_ID + 1, 'Create a complete brand planning workflow', [], [], [
        'user_id' => USER_ID, 'thread_id' => 137,
        'semantic_decision' => [
            'turn_relation' => 'new', 'intent' => 'creative_plan', 'execution_mode' => 'plan',
            'selected_skill_key' => '', 'workflow_template' => 'brand_planning', 'confidence' => 0.96,
        ],
    ]);
    assertE2e((string)($noSkillWorkflow['workflow_template'] ?? '') === 'brand_planning', 'strong workflow incorrectly depended on a retrieved skill');
    $checked['strong_workflow_without_skill_candidate'] = true;

    // 17. Finished text is kept in the assistant reply and persisted as a
    // single auto-applied canvas text action for refresh-safe insertion.
    $textActionMethod = new ReflectionMethod(AigcCanvasAgentRuntimeService::class, 'projectCompletedTextAction');
    $textActionMethod->setAccessible(true);
    $textResult = [
        'reply' => '15-second smartwatch script: the watch wakes before the day begins.',
        'next_action' => 'chat',
        'assets' => [],
        'workspace_actions' => [],
        'task_decision' => ['intent' => 'text_generation'],
        'selected_skill' => ['output_policy' => ['write_to_canvas' => true, 'workspace_action' => 'insert_text']],
    ];
    $textAction = $textActionMethod->invoke(null, TENANT_ID, USER_ID, 71, 140, 305, $textResult);
    assertE2e((string)($textAction['action_type'] ?? '') === 'insert_text', 'completed text did not create a canvas insertion action');
    assertE2e((string)($textAction['input']['content'] ?? '') === $textResult['reply'], 'canvas action did not reuse the visible assistant reply');
    $repeatTextAction = $textActionMethod->invoke(null, TENANT_ID, USER_ID, 71, 140, 305, $textResult);
    assertE2e((int)($repeatTextAction['id'] ?? 0) === (int)($textAction['id'] ?? 0), 'text canvas action was duplicated on recovery');
    assertE2e((int)Db::name('aigc_canvas_agent_workspace_action')->where('message_id', 305)->count() === 1, 'text canvas action was not persisted exactly once');
    $checked['text_result_is_chat_and_canvas_safe'] = true;

    echo json_encode(['passed' => true, 'checked' => $checked], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    DeliveryGraphExecutor::setSubmissionAdapterForTesting(null);
    foreach (array_reverse($tables) as $name) {
        try { Db::execute("DROP TABLE IF EXISTS `{$name}`"); } catch (Throwable) { }
    }
}
