<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\agent\prompt\PromptSpecCompiler;
use app\common\service\app\aigc_canvas\agent\prompt\VideoPromptSpecCompiler;
use app\common\service\app\aigc_canvas\agent\prompt\VideoPromptSubmissionService;
use app\common\service\app\aigc_canvas\agent\runtime\AgentResponseProtocol;
use app\common\service\app\aigc_canvas\agent\runtime\AgentResultValidator;
use app\common\service\power\CanvasVideoPromptSubmissionGuard;

$failures = [];

$clarify = AgentResponseProtocol::fromResult([
    'next_action' => 'clarify',
    'reply' => 'Please provide a subject.',
    'task_decision' => ['missing_hard_slots' => ['visual_subject']],
]);
if (($clarify['response_kind'] ?? '') !== AgentResponseProtocol::CLARIFY
    || ($clarify['content']['missing_slots'] ?? []) !== ['visual_subject']) {
    $failures[] = 'clarification response is not structured';
}

$plan = AgentResponseProtocol::fromResult([
    'next_action' => 'confirm_initial_batch',
    'batch_id' => 12,
    'planned_sections' => [['section_key' => 'hero']],
]);
if (($plan['response_kind'] ?? '') !== AgentResponseProtocol::PLAN_REVIEW
    || ($plan['quick_actions'][0]['type'] ?? '') !== 'confirm_plan') {
    $failures[] = 'plan review response is not structured';
}

$execution = AgentResponseProtocol::fromResult([
    'next_action' => 'generation_submitted',
    'tool_calls' => [['tool_code' => 'generate_image']],
]);
if (($execution['response_kind'] ?? '') !== AgentResponseProtocol::EXECUTION_STATUS) {
    $failures[] = 'execution response is not structured';
}
$loopSource = file_get_contents($root . '/app/common/service/app/aigc_canvas/agent/runtime/AgentLoopService.php') ?: '';
if (!str_contains($loopSource, "!empty(\$assets) ? 'generation_submitted' : (!empty(\$workspaceActions)")) {
    $failures[] = 'media submission is incorrectly treated as a canvas confirmation';
}

$mediaValidation = AgentResultValidator::validate('generate_image', [
    'tool_calls' => [[
        'tool_code' => 'generate_image',
        'status' => 'success',
        'output' => ['task_id' => 653],
    ]],
    'assets' => [['type' => 'image', 'status' => 'pending']],
    'workspace_actions' => [['action_type' => 'insert_image', 'status' => 'pending']],
]);
if (!AgentResultValidator::isConfirmedMediaSubmission('generate_image', $mediaValidation)) {
    $failures[] = 'accepted media task does not terminate the current Agent turn';
}
if (!str_contains($loopSource, 'AgentResultValidator::isConfirmedMediaSubmission')
    || !str_contains($loopSource, 'if ($mediaSubmitted)')) {
    $failures[] = 'agent loop does not stop after an accepted media task';
}

$evidence = AgentResponseProtocol::fromResult(['next_action' => 'chat'], ['visible_facts' => ['blue product']]);
if (($evidence['response_kind'] ?? '') !== AgentResponseProtocol::EVIDENCE_REVIEW) {
    $failures[] = 'evidence response is not structured';
}

$onboarding = AgentResponseProtocol::onboarding([
    'agent_name' => 'Canvas Agent',
    'quick_prompts' => ['Create a poster'],
]);
if (($onboarding['response_kind'] ?? '') !== AgentResponseProtocol::ONBOARDING
    || ($onboarding['quick_actions'][0]['type'] ?? '') !== 'prompt') {
    $failures[] = 'onboarding response is not structured';
}

$trace = PromptSpecCompiler::compileAdHoc([], 'Create a product poster', 'generate_image', [
    'ratio' => '3:4',
    'negative_prompt' => 'watermark',
]);
try {
    PromptSpecCompiler::assertSubmission([
        'prompt' => $trace['compiled_prompt'],
        'prompt_spec_json' => $trace['prompt_spec_json'],
        'compiler_version' => $trace['compiler_version'],
        'prompt_hash' => $trace['prompt_hash'],
    ]);
} catch (Throwable) {
    $failures[] = 'compiled prompt fails submission validation';
}
if ((string)($trace['prompt_spec_json']['model_options']['negative_prompt'] ?? '') !== 'watermark') {
    $failures[] = 'model negative prompt is absent from the structured model options';
}
if (str_contains((string)$trace['compiled_prompt'], 'Provider negative prompt')) {
    $failures[] = 'internal provider field leaked into compiled output';
}
$musicTrace = PromptSpecCompiler::compileAdHoc([], 'Create a calm ambient soundtrack', 'generate_music', []);
try {
    PromptSpecCompiler::assertSubmission([
        'prompt' => $musicTrace['compiled_prompt'],
        'prompt_spec_json' => $musicTrace['prompt_spec_json'],
        'compiler_version' => $musicTrace['compiler_version'],
        'prompt_hash' => $musicTrace['prompt_hash'],
    ]);
} catch (Throwable) {
    $failures[] = 'compiled music prompt fails submission validation';
}
try {
    PromptSpecCompiler::assertSubmission(['prompt' => 'uncompiled prompt']);
    $failures[] = 'uncompiled prompt was accepted';
} catch (InvalidArgumentException) {
}

$video = VideoPromptSpecCompiler::compile([
    'prompt_mode' => 'direct',
    'user_request' => 'A cat walks toward the camera',
    'ratio' => '16:9',
    'duration' => 5,
    'video_direction' => ['soft daylight', 'shallow depth of field'],
    'camera_movement' => 'slow dolly in',
    'motion' => 'the cat walks naturally and pauses at the end',
]);
if (str_contains((string)$video['compiled_prompt'], 'price') || (array)($video['prompt_spec_json']['constraints'] ?? []) !== []) {
    $failures[] = 'generic video prompt incorrectly contains product-claim controls';
}
try {
    VideoPromptSpecCompiler::assertSubmission([
        'prompt' => $video['compiled_prompt'],
        'prompt_spec_json' => $video['prompt_spec_json'],
        'compiler_version' => $video['compiler_version'],
        'prompt_hash' => $video['prompt_hash'],
    ]);
    CanvasVideoPromptSubmissionGuard::assertPrepared('aigc_canvas', [
        'submission_prepared' => true,
        'prompt' => $video['compiled_prompt'],
        'compiled_prompt' => $video['compiled_prompt'],
        'prompt_spec_json' => $video['prompt_spec_json'],
        'compiler_version' => $video['compiler_version'],
        'prompt_hash' => $video['prompt_hash'],
        'preflight' => ['status' => 'passed'],
    ]);
} catch (Throwable) {
    $failures[] = 'compiled video prompt fails submission validation';
}
try {
    CanvasVideoPromptSubmissionGuard::assertPrepared('aigc_canvas', ['prompt' => 'uncompiled video']);
    $failures[] = 'uncompiled video prompt was accepted';
} catch (Throwable) {
}
$videoDraft = VideoPromptSubmissionService::normalizeEnrichment('{"description":"A cat runs through a sunlit room.","temporal_direction":["start wide, then follow the cat"],"camera_movement":"handheld tracking","motion":"run then stop","continuity":["keep fur color consistent"]}');
if (($videoDraft['description'] ?? '') === '' || count((array)($videoDraft['temporal_direction'] ?? [])) !== 1) {
    $failures[] = 'video prompt enrichment response cannot be normalized';
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
