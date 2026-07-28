<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\model\app\aigc_canvas\AigcCanvasAgentWorkspaceAction;
use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\agent\contracts\CanvasProtocol;
use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

/**
 * Canvas actions are proposals. The browser owns the live document and applies
 * a proposal only after the user confirms it.
 */
final class CanvasCapabilityTool implements ToolInterface
{
    private string $code;

    public function __construct(string $code)
    {
        $this->code = $code;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function schema(): array
    {
        return match ($this->code) {
            CanvasProtocol::TOOL_QUERY => FunctionCallSchema::function($this->code, 'Read elements, selection, viewport, layers, or visible nodes from the current canvas snapshot.', [
                'query' => ['type' => 'string'],
                'element_id' => ['type' => 'string'],
            ], ['query']),
            CanvasProtocol::TOOL_SELECTION_ACTION => FunctionCallSchema::function($this->code, 'Create a safe action proposal for the currently selected canvas elements.', [
                'operation' => ['type' => 'string'],
                'payload' => ['type' => 'object'],
            ], ['operation']),
            default => FunctionCallSchema::function($this->code, 'Create a safe canvas mutation proposal. Destructive changes require user confirmation.', [
                'operation' => ['type' => 'string'],
                'element_ids' => ['type' => 'array'],
                'payload' => ['type' => 'object'],
                'requires_confirmation' => ['type' => 'boolean'],
            ], ['operation']),
        };
    }

    public function execute(AgentExecutionContext $context, array $arguments): array
    {
        $snapshot = $context->context();
        if ($this->code === CanvasProtocol::TOOL_QUERY) {
            return $this->query($snapshot, $arguments);
        }

        $selection = CanvasProtocol::selectionSummary((array)($snapshot['selection'] ?? [
            'ids' => (array)($snapshot['selected_ids'] ?? []),
            'elements' => (array)($snapshot['selected_elements'] ?? []),
        ]));
        $operation = trim((string)($arguments['operation'] ?? ''));
        $ids = $this->code === CanvasProtocol::TOOL_SELECTION_ACTION
            ? (array)$selection['ids']
            : (array)($arguments['element_ids'] ?? $selection['ids']);
        $payload = is_array($arguments['payload'] ?? null) ? $arguments['payload'] : [];
        $requiresConfirmation = array_key_exists('requires_confirmation', $arguments)
            ? (bool)$arguments['requires_confirmation']
            : !in_array($operation, [CanvasProtocol::MUTATION_SELECT, CanvasProtocol::MUTATION_FOCUS], true);
        $proposal = CanvasProtocol::mutationProposal($operation, $ids, $payload, $requiresConfirmation);
        $now = time();
        $action = AigcCanvasAgentWorkspaceAction::create([
            'tenant_id' => $context->tenantId(),
            'user_id' => $context->userId(),
            'project_id' => $context->projectId(),
            'thread_id' => $context->threadId(),
            'message_id' => $context->messageId(),
            'tool_call_id' => 0,
            'action_type' => CanvasProtocol::TOOL_MUTATION,
            'status' => 'pending',
            'input_json' => ['proposal' => $proposal, 'selection' => $selection],
            'result_json' => [],
            'error' => '',
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);
        $formatted = AigcCanvasAgentRuntimeService::formatWorkspaceAction($action->toArray());
        $emit = $context->emit();
        if (is_callable($emit)) {
            $emit('agent.canvas.mutation.proposed', $formatted);
            $emit('agent.workspace.action_pending', $formatted);
        }

        return [
            'workspace_actions' => [$formatted],
            'canvas_actions' => [],
        ];
    }

    private function query(array $snapshot, array $arguments): array
    {
        $query = trim((string)($arguments['query'] ?? ''));
        $elements = array_values(array_filter((array)($snapshot['elements'] ?? []), 'is_array'));
        $data = match ($query) {
            CanvasProtocol::QUERY_ELEMENT => $this->element($elements, (string)($arguments['element_id'] ?? '')),
            CanvasProtocol::QUERY_SELECTION => CanvasProtocol::selectionSummary((array)($snapshot['selection'] ?? [
                'ids' => (array)($snapshot['selected_ids'] ?? []),
                'elements' => (array)($snapshot['selected_elements'] ?? []),
            ])),
            CanvasProtocol::QUERY_VIEWPORT => is_array($snapshot['viewport'] ?? null) ? $snapshot['viewport'] : [],
            CanvasProtocol::QUERY_LAYER_TREE => array_values(array_filter((array)($snapshot['layer_tree'] ?? []), 'is_array')),
            CanvasProtocol::QUERY_VISIBLE_NODES => array_values(array_filter((array)($snapshot['visible_nodes'] ?? []), 'is_array')),
            default => $elements,
        };
        return ['tool_calls' => [CanvasProtocol::queryResult($query ?: CanvasProtocol::QUERY_ELEMENTS, ['result' => $data])]];
    }

    private function element(array $elements, string $elementId): array
    {
        foreach ($elements as $element) {
            if ((string)($element['id'] ?? '') === $elementId) {
                return $element;
            }
        }
        return [];
    }
}
