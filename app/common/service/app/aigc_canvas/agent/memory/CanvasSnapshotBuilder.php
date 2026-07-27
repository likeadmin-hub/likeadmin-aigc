<?php

namespace app\common\service\app\aigc_canvas\agent\memory;

final class CanvasSnapshotBuilder
{
    public static function summaryText(array $context): string
    {
        $summary = $context['canvas_summary'] ?? '';
        if (!is_array($summary)) {
            return mb_substr((string)$summary, 0, 800, 'UTF-8');
        }
        return sprintf(
            '当前画布有 %d 个节点，文本 %d 个，图片 %d 个，视频 %d 个，音频 %d 个。',
            (int)($summary['node_count'] ?? 0),
            (int)($summary['text_count'] ?? 0),
            (int)($summary['image_count'] ?? 0),
            (int)($summary['video_count'] ?? 0),
            (int)($summary['audio_count'] ?? 0)
        );
    }

    public static function compact(array $context): array
    {
        return [
            'project' => is_array($context['project'] ?? null) ? $context['project'] : [],
            'canvas_summary' => is_array($context['canvas_summary'] ?? null)
                ? $context['canvas_summary']
                : mb_substr((string)($context['canvas_summary'] ?? ''), 0, 800, 'UTF-8'),
            'elements' => array_slice(is_array($context['elements'] ?? null) ? $context['elements'] : [], 0, 120),
            'selection' => is_array($context['selection'] ?? null) ? $context['selection'] : [],
            'selected_ids' => array_slice(is_array($context['selected_ids'] ?? null) ? $context['selected_ids'] : [], 0, 24),
            'viewport' => is_array($context['viewport'] ?? null) ? $context['viewport'] : [],
            'visible_nodes' => array_slice(is_array($context['visible_nodes'] ?? null) ? $context['visible_nodes'] : [], 0, 60),
            'layer_tree' => array_slice(is_array($context['layer_tree'] ?? null) ? $context['layer_tree'] : [], 0, 120),
            'connections' => array_slice(is_array($context['connections'] ?? null) ? $context['connections'] : [], 0, 160),
            'selected_elements' => array_slice(is_array($context['selected_elements'] ?? null) ? $context['selected_elements'] : [], 0, 12),
            'uploaded_references' => array_slice(is_array($context['uploaded_references'] ?? null) ? $context['uploaded_references'] : [], 0, 12),
        ];
    }
}
