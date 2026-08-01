<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\agent\delivery\ConversationTaskResolver;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryItemService;
use app\common\service\app\aigc_canvas\agent\delivery\PendingActionProtocol;
use app\common\service\app\aigc_canvas\agent\delivery\ExternalAssetImportService;

$failures = [];
$references = ['uploaded_references' => [['type' => 'image', 'url' => '/storage/product.png']]];
$compound = ConversationTaskResolver::preview('先生成淘宝主图，再生成 3 张卖点图，最后做 5 屏详情页', $references);
$items = (array)($compound['items'] ?? []);
if (count($items) !== 3) {
    $failures[] = 'compound request was not split into three delivery items';
}
$skills = array_map(static fn(array $item): string => (string)($item['skill_key'] ?? ''), $items);
foreach (['ecommerce_main_image', 'ecommerce_selling_point', 'ecommerce_detail_page'] as $expected) {
    if (!in_array($expected, $skills, true)) $failures[] = 'missing delivery skill: ' . $expected;
}
foreach ($items as $item) {
    if (!empty($item['missing_slots'])) $failures[] = 'reference-backed item should not be blocked by soft slots';
    if (($item['pending_action']['type'] ?? '') !== 'confirm_execution') $failures[] = 'ready item must expose generic confirmation action';
}
$selling = array_values(array_filter($items, static fn(array $item): bool => ($item['skill_key'] ?? '') === 'ecommerce_selling_point'));
if ((int)($selling[0]['delivery']['quantity'] ?? 0) !== 3) {
    $failures[] = 'selling-point quantity was not retained on its item';
}
$detail = array_values(array_filter($items, static fn(array $item): bool => ($item['skill_key'] ?? '') === 'ecommerce_detail_page'));
if ((int)($detail[0]['delivery']['section_count'] ?? 0) !== 5) {
    $failures[] = 'detail-page section count was not retained on its item';
}

$missingReference = ConversationTaskResolver::preview('做一张白底商品主图');
$main = (array)(($missingReference['items'] ?? [])[0] ?? []);
if (($main['skill_key'] ?? '') !== 'ecommerce_main_image' || ($main['missing_slots'] ?? []) !== ['product_reference']) {
    $failures[] = 'main-image routing or required reference slot is incorrect';
}
if (($main['pending_action']['type'] ?? '') !== 'fill_slot') {
    $failures[] = 'missing required slot must use generic fill_slot pending action';
}
$fillResolution = PendingActionProtocol::resolve([
    'id' => 1, 'status' => 'clarifying', 'slots' => ['user_request' => 'x'],
    'pending_action' => $main['pending_action'], 'meta' => [], 'delivery' => [], 'creative_context' => [], 'reference_assets' => [],
], ['action' => 'fill_slot', 'action_id' => (string)($main['pending_action']['action_id'] ?? ''), 'structured_value' => ['product_reference' => '/storage/product.png']]);
if (($fillResolution['status'] ?? '') !== 'ready' || (($fillResolution['patch']['slots_json']['product_reference'] ?? '') !== '/storage/product.png')) {
    $failures[] = 'fill_slot pending action did not restore the item slots';
}
$confirm = PendingActionProtocol::confirmation();
$confirmResolution = PendingActionProtocol::resolve([
    'id' => 2, 'status' => 'ready', 'slots' => [], 'pending_action' => $confirm,
    'meta' => [], 'delivery' => [], 'creative_context' => [], 'reference_assets' => [],
], ['action' => 'confirm_execution', 'action_id' => (string)$confirm['action_id'], 'structured_value' => true]);
if (($confirmResolution['status'] ?? '') !== 'ready') {
    $failures[] = 'confirm_execution must leave the item ready for executor claim';
}

$interrupt = ConversationTaskResolver::preview('先做一张白底主图', $references);
if (count((array)($interrupt['items'] ?? [])) !== 1 || (($interrupt['items'][0]['skill_key'] ?? '') !== 'ecommerce_main_image')) {
    $failures[] = 'explicit main-image interruption does not create a distinct main-image item';
}

$media = ConversationTaskResolver::preview('生成一段 16:9 产品演示视频和一段轻快配乐', $references);
$mediaTools = array_map(static fn(array $item): string => (string)($item['tool_code'] ?? ''), (array)($media['items'] ?? []));
if (!in_array('generate_video', $mediaTools, true) || !in_array('generate_music', $mediaTools, true)) {
    $failures[] = 'video and music requests were not split into media delivery items';
}
try {
    ExternalAssetImportService::assertPublicUrl('http://127.0.0.1/reference.png');
    $failures[] = 'external asset guard accepted a private IP';
} catch (\Exception) {
    // Expected: SSRF guard rejects private destinations before download.
}

if (!in_array('clarifying', DeliveryItemService::STATUSES, true)
    || !in_array('completed', DeliveryItemService::STATUSES, true)
    || count(DeliveryItemService::STATUSES) !== 9) {
    $failures[] = 'delivery item state contract is incomplete';
}

$runtime = file_get_contents($root . '/app/common/service/app/aigc_canvas/AigcCanvasAgentRuntimeService.php') ?: '';
if (!str_contains($runtime, 'AgentTaskDecisionService::decide') || !str_contains($runtime, 'ConversationTaskResolver::resolve') || !str_contains($runtime, "'task_decision'")) {
    $failures[] = 'agent runtime does not bind turns to delivery items';
}
$resolver = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/delivery/ConversationTaskResolver.php') ?: '';
if (str_contains($resolver, 'mb_strlen($text, \'UTF-8\') <= 120') || !str_contains($resolver, 'definitionFromDecision')) {
    $failures[] = 'conversation resolver still uses the short-message continuation fallback';
}
$binder = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/delivery/DeliveryItemContextBinder.php') ?: '';
if (!str_contains($binder, 'mergeEnrichment') || !str_contains($binder, 'ensureExecutableContext')) {
    $failures[] = 'delivery item context binder is incomplete';
}
$loop = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/runtime/AgentLoopService.php') ?: '';
if (!str_contains($loop, '$input[\'delivery_item_id\']') || !str_contains($loop, '$toolRoute[\'delivery_item_id\']')) {
    $failures[] = 'delivery item id is not forwarded to tools';
}
$creativeGraph = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/delivery/CreativeDeliveryGraphService.php') ?: '';
if (!str_contains($resolver, 'CreativeDeliveryGraphService::create')
    || !str_contains($creativeGraph, 'updateEditableNodes')
    || !str_contains($creativeGraph, 'runnableItems')
    || !str_contains($creativeGraph, "'terminal_policy' => 'immutable'")) {
    $failures[] = 'creative delivery graph is not the canonical plan/item orchestration path';
}
if (!str_contains($loop, "'agent.delivery.graph.updated'")
    || !str_contains($loop, 'CreativeDeliveryGraphService::runnableItems')
    || !str_contains($loop, "'runnable_delivery_items'")) {
    $failures[] = 'agent loop does not project creative graph state from the delivery plan';
}
$graph = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/delivery/DeliveryGraphExecutor.php') ?: '';
if (!str_contains($graph, 'CanvasGenerationTaskCenterService') || !str_contains($graph, 'executeFromAgentTool') || !str_contains($graph, 'claimReady') || !str_contains($graph, 'assertDependencies')
    || !str_contains($graph, 'private static function promptMode') || !str_contains($graph, "return 'direct'")) {
    $failures[] = 'delivery graph executor does not retain shared task center and dependency guard';
}
$migration = file_get_contents($root . '/app/apps/aigc_canvas/migrations/zz_20260726_canvas_pending_action_protocol.sql') ?: '';
if (!str_contains($migration, 'pending_action_json')) {
    $failures[] = 'pending action migration is missing';
}
$toolMigration = file_get_contents($root . '/app/apps/aigc_canvas/migrations/zz_20260730_canvas_agent_tool_delivery.sql') ?: '';
if (!str_contains($toolMigration, 'delivery_item_id') || !str_contains($toolMigration, 'attempt_no') || !str_contains($toolMigration, 'idx_provider_task')) {
    $failures[] = 'agent tool delivery migration is incomplete';
}

echo json_encode(['passed' => $failures === [], 'failures' => $failures], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failures === [] ? 0 : 1);
