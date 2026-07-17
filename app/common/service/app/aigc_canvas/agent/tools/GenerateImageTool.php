<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

final class GenerateImageTool implements ToolInterface
{
    public function code(): string
    {
        return 'generate_image';
    }

    public function schema(): array
    {
        return FunctionCallSchema::function($this->code(), 'Generate image assets using the current canvas image model.', [
            'prompt' => ['type' => 'string'],
            'quantity' => ['type' => 'number'],
            'ratio' => ['type' => 'string'],
            'target_element_id' => ['type' => 'string'],
            'section_key' => ['type' => 'string'],
        ], ['prompt']);
    }

    public function execute(AgentExecutionContext $context, array $arguments): array
    {
        $input = array_merge($context->toolOptions($this->code()), [
            'prompt' => trim((string)($arguments['prompt'] ?? $context->request())),
            'project_id' => $context->projectId(),
            'quantity' => max(1, min(4, (int)($arguments['quantity'] ?? 1))),
            'request_id' => (string)($context->route()['request_id'] ?? ''),
        ]);
        $referenceImages = [];
        $referenceAssets = [];
        foreach ((array)($context->context()['uploaded_references'] ?? []) as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $url = trim((string)($reference['url'] ?? $reference['uri'] ?? ''));
            if ($url === '') {
                continue;
            }
            $type = strtolower((string)($reference['type'] ?? $reference['asset_type'] ?? 'image'));
            $referenceAssets[] = [
                'type' => $type,
                'url' => $url,
                'uri' => $url,
                'name' => (string)($reference['name'] ?? ''),
            ];
            if ($type === 'image' || $type === 'asset') {
                $referenceImages[] = $url;
            }
        }
        if (!empty($referenceImages)) {
            $input['reference_images'] = array_values(array_unique(array_merge(
                (array)($input['reference_images'] ?? []),
                $referenceImages
            )));
        }
        if (!empty($referenceAssets)) {
            $input['reference_assets'] = $referenceAssets;
        }
        if (!empty($arguments['ratio'])) {
            $input['ratio'] = (string)$arguments['ratio'];
        }
        foreach (['target_element_id', 'section_key'] as $key) {
            if (!empty($arguments[$key])) {
                $input[$key] = (string)$arguments[$key];
            }
        }
        return AigcCanvasAgentRuntimeService::executeExternalToolWithActions(
            $context->tenantId(),
            $context->userId(),
            $context->projectId(),
            $context->threadId(),
            $context->messageId(),
            $this->code(),
            $input,
            $input['prompt'],
            $context->context(),
            $context->emit(),
            (int)$input['quantity']
        );
    }
}
