<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use Exception;

final class JsonCanvasValidator
{
    private const MAX_ACTIONS = 200;

    public static function assertValid(array $canvasJson): void
    {
        $version = (string)($canvasJson['version'] ?? '');
        if (!in_array($version, ['1.0', '1.1'], true)) {
            throw new Exception('Unsupported JSON Canvas version');
        }
        $actions = $canvasJson['actions'] ?? [];
        if (!is_array($actions)) {
            throw new Exception('Invalid JSON Canvas actions');
        }
        if (count($actions) > self::MAX_ACTIONS) {
            throw new Exception('JSON Canvas action limit exceeded');
        }
        $elementIds = [];
        foreach ($actions as $action) {
            if (!is_array($action) || empty($action['type'])) {
                throw new Exception('Invalid JSON Canvas action');
            }
            $type = (string)$action['type'];
            if (!in_array($type, ['create_page', 'add_element', 'update_element'], true)) {
                throw new Exception('Unsupported JSON Canvas action: ' . $type);
            }
            if ($type === 'create_page') {
                $page = is_array($action['page'] ?? null) ? $action['page'] : [];
                if (empty($page['id']) || (float)($page['width'] ?? 0) < 320 || (float)($page['height'] ?? 0) < 320) {
                    throw new Exception('Invalid JSON Canvas page');
                }
            }
            if ($type === 'add_element') {
                $element = is_array($action['element'] ?? null) ? $action['element'] : [];
                $id = (string)($element['id'] ?? '');
                if ($id === '' || isset($elementIds[$id])) {
                    throw new Exception('Invalid or duplicate JSON Canvas element ID');
                }
                $elementIds[$id] = true;
            }
            if ($type === 'update_element' && trim((string)($action['element_id'] ?? '')) === '') {
                throw new Exception('Invalid JSON Canvas update target');
            }
        }
    }
}
