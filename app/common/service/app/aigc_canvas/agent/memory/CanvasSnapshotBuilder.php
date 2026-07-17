<?php

namespace app\common\service\app\aigc_canvas\agent\memory;

final class CanvasSnapshotBuilder
{
    public static function compact(array $context): array
    {
        return [
            'project' => is_array($context['project'] ?? null) ? $context['project'] : [],
            'canvas_summary' => mb_substr((string)($context['canvas_summary'] ?? ''), 0, 800, 'UTF-8'),
            'selected_elements' => array_slice(is_array($context['selected_elements'] ?? null) ? $context['selected_elements'] : [], 0, 12),
            'uploaded_references' => array_slice(is_array($context['uploaded_references'] ?? null) ? $context['uploaded_references'] : [], 0, 12),
        ];
    }
}
