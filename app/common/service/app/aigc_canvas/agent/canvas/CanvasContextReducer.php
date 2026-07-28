<?php

namespace app\common\service\app\aigc_canvas\agent\canvas;

use app\common\service\app\aigc_canvas\agent\memory\CanvasSnapshotBuilder;

/**
 * Reduces browser canvas state to planning facts. The full browser document is
 * never necessary for a first Agent decision and makes token streaming slower.
 */
final class CanvasContextReducer
{
    public static function reduce(array $context): array
    {
        $compact = CanvasSnapshotBuilder::compact($context);
        $elements = array_values(array_filter((array)($compact['elements'] ?? []), 'is_array'));
        $selected = array_values(array_filter((array)($compact['selected_elements'] ?? []), 'is_array'));
        return [
            'canvas_summary' => self::summary($compact['canvas_summary'] ?? [], $elements),
            'elements' => array_slice(array_map([self::class, 'element'], $elements), 0, 48),
            'selection' => self::selection($compact['selection'] ?? [], $selected),
            'selected_ids' => array_slice(array_values(array_filter(array_map('strval', (array)($compact['selected_ids'] ?? [])))), 0, 12),
            'viewport' => self::numbers((array)($compact['viewport'] ?? []), ['x', 'y', 'zoom']),
            'visible_nodes' => array_slice(array_map([self::class, 'element'], array_values(array_filter((array)($compact['visible_nodes'] ?? []), 'is_array'))), 0, 24),
            'layer_tree' => array_slice(array_map([self::class, 'layer'], array_values(array_filter((array)($compact['layer_tree'] ?? []), 'is_array'))), 0, 48),
            'connections' => array_slice(array_map([self::class, 'connection'], array_values(array_filter((array)($compact['connections'] ?? []), 'is_array'))), 0, 80),
            'uploaded_reference_count' => count((array)($compact['uploaded_references'] ?? [])),
        ];
    }

    private static function summary($summary, array $elements): array
    {
        if (is_array($summary)) {
            return self::filterScalar($summary, 20);
        }
        return ['text' => mb_substr((string)$summary, 0, 500, 'UTF-8'), 'node_count' => count($elements)];
    }

    private static function element(array $element): array
    {
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        $position = is_array($element['position'] ?? null) ? $element['position'] : [];
        $style = is_array($element['style'] ?? null) ? $element['style'] : [];
        return [
            'id' => (string)($element['id'] ?? $element['element_id'] ?? ''),
            'type' => (string)($element['type'] ?? $data['type'] ?? ''),
            'name' => mb_substr((string)($element['name'] ?? $element['title'] ?? $data['name'] ?? $data['title'] ?? ''), 0, 100, 'UTF-8'),
            'text' => mb_substr((string)($element['text'] ?? $element['content'] ?? $data['text'] ?? $data['content'] ?? ''), 0, 240, 'UTF-8'),
            'x' => (float)($element['x'] ?? $position['x'] ?? $style['x'] ?? 0),
            'y' => (float)($element['y'] ?? $position['y'] ?? $style['y'] ?? 0),
            'width' => (float)($element['width'] ?? $style['width'] ?? 0),
            'height' => (float)($element['height'] ?? $style['height'] ?? 0),
        ];
    }

    private static function selection($selection, array $selected): array
    {
        $selection = is_array($selection) ? $selection : [];
        return [
            'ids' => array_slice(array_values(array_filter(array_map('strval', (array)($selection['ids'] ?? [])))), 0, 12),
            'count' => count($selected),
            'elements' => array_slice(array_map([self::class, 'element'], $selected), 0, 8),
        ];
    }

    private static function layer(array $layer): array
    {
        return self::filterScalar($layer, 8);
    }

    private static function connection(array $connection): array
    {
        return self::filterScalar($connection, 8);
    }

    private static function numbers(array $values, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (isset($values[$key]) && is_numeric($values[$key])) {
                $result[$key] = (float)$values[$key];
            }
        }
        return $result;
    }

    private static function filterScalar(array $values, int $limit): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (count($result) >= $limit) {
                break;
            }
            if (is_scalar($value) || $value === null) {
                $result[(string)$key] = is_string($value) ? mb_substr($value, 0, 240, 'UTF-8') : $value;
            }
        }
        return $result;
    }
}
