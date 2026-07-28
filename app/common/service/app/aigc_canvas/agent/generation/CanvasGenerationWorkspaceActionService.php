<?php

namespace app\common\service\app\aigc_canvas\agent\generation;

use app\common\model\app\aigc_canvas\AigcCanvasAgentWorkspaceAction;
use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use think\facade\Db;

final class CanvasGenerationWorkspaceActionService
{
    public static function create(AgentExecutionContext $context, string $toolCode, string $prompt, array $task, array $asset, array $submittedInput = []): array
    {
        $type = $toolCode === 'generate_video' ? 'video' : ($toolCode === 'generate_music' ? 'audio' : 'image');
        $actionType = 'insert_' . $type;
        $submittedPrompt = trim((string)($submittedInput['compiled_prompt'] ?? $submittedInput['prompt'] ?? $prompt));
        if ($submittedPrompt === '') {
            $submittedPrompt = trim($prompt);
        }
        $compiledPrompt = trim((string)($submittedInput['compiled_prompt'] ?? ''));
        $hasSubmissionSnapshot = $compiledPrompt !== ''
            && trim((string)($submittedInput['prompt_hash'] ?? '')) !== ''
            && trim((string)($submittedInput['compiler_version'] ?? '')) !== '';
        $url = (string)($asset['url'] ?? $asset['image_url'] ?? $asset['video_url'] ?? $asset['audio_url'] ?? '');
        $normalizedAsset = array_merge($asset, [
            'type' => $type,
            'url' => $url,
            'task_id' => (string)($task['task_id'] ?? $asset['task_id'] ?? ''),
            'provider_task_id' => (string)($task['power_task_id'] ?? $asset['provider_task_id'] ?? ''),
        ]);
        $now = time();
        $actionInput = [
            'submitted_input' => $submittedInput,
            'asset' => $normalizedAsset,
            // Keep a top-level copy for historical readers that predate submitted_input.
            // Both values are immutable snapshots of the provider submission, never UI text.
            'prompt' => $submittedPrompt,
            'submitted_prompt' => $submittedPrompt,
            'compiled_prompt' => $compiledPrompt !== '' ? $compiledPrompt : $submittedPrompt,
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
            'placement' => 'viewport_right',
            'requires_confirmation' => false,
            'generation_task' => $task,
        ];
        // Persist a concrete JSON string. Model JSON casting has yielded empty action
        // inputs, which prevents both the pending chat card and node prompt recovery.
        $storedInput = self::jsonString($actionInput);
        $actionId = (int)Db::name('aigc_canvas_agent_workspace_action')->insertGetId([
            'tenant_id' => $context->tenantId(),
            'user_id' => $context->userId(),
            'project_id' => $context->projectId(),
            'thread_id' => $context->threadId(),
            'message_id' => $context->messageId(),
            'tool_call_id' => 0,
            'action_type' => $actionType,
            'status' => 'pending',
            'input_json' => $storedInput,
            'result_json' => '[]',
            'error' => '',
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);
        $formatted = AigcCanvasAgentRuntimeService::formatWorkspaceAction([
            'id' => $actionId,
            'project_id' => $context->projectId(),
            'thread_id' => $context->threadId(),
            'message_id' => $context->messageId(),
            'tool_call_id' => 0,
            'action_type' => $actionType,
            'status' => 'pending',
            'input_json' => $storedInput,
            'result_json' => '[]',
            'error' => '',
            'create_time' => $now,
        ]);
        $emit = $context->emit();
        if (is_callable($emit)) {
            $emit('agent.workspace.action_pending', $formatted);
            $emit('agent.canvas.patch', ['action_type' => $actionType, 'workspace_action' => $formatted]);
        }
        return $formatted;
    }

    private static function jsonString(array $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return is_string($encoded) ? $encoded : '{}';
    }
}
