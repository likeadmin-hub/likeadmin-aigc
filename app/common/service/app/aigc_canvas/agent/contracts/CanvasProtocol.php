<?php

namespace app\common\service\app\aigc_canvas\agent\contracts;

final class CanvasProtocol
{
    public const VERSION = '1.2';

    public const CREATE_PAGE = 'create_page';
    public const ADD_ELEMENT = 'add_element';
    public const UPDATE_ELEMENT = 'update_element';

    public const TOOL_QUERY = 'canvas_query';
    public const TOOL_MUTATION = 'canvas_mutation';
    public const TOOL_SELECTION_ACTION = 'selection_action';

    public const QUERY_ELEMENTS = 'elements';
    public const QUERY_ELEMENT = 'element';
    public const QUERY_SELECTION = 'selection';
    public const QUERY_VIEWPORT = 'viewport';
    public const QUERY_LAYER_TREE = 'layer_tree';
    public const QUERY_VISIBLE_NODES = 'visible_nodes';

    public const MUTATION_SELECT = 'select';
    public const MUTATION_UPDATE = 'update';
    public const MUTATION_MOVE = 'move';
    public const MUTATION_RESIZE = 'resize';
    public const MUTATION_DELETE = 'delete';
    public const MUTATION_GROUP = 'group';
    public const MUTATION_UNGROUP = 'ungroup';
    public const MUTATION_FOCUS = 'focus';
    public const MUTATION_UNDO = 'undo';
    public const MUTATION_REDO = 'redo';

    public static function document(array $actions, array $metadata = []): array
    {
        return [
            'version' => self::VERSION,
            'actions' => array_values($actions),
            'metadata' => $metadata,
        ];
    }

    public static function queryResult(string $query, array $data, array $metadata = []): array
    {
        return [
            'protocol_version' => self::VERSION,
            'status' => 'success',
            'query' => $query,
            'data' => $data,
            'metadata' => $metadata,
        ];
    }

    public static function mutationProposal(string $operation, array $elementIds, array $payload = [], bool $requiresConfirmation = true): array
    {
        return [
            'protocol_version' => self::VERSION,
            'operation' => $operation,
            'element_ids' => array_values(array_unique(array_filter(array_map('strval', $elementIds)))),
            'payload' => $payload,
            'requires_confirmation' => $requiresConfirmation,
        ];
    }

    public static function selectionSummary(array $selection): array
    {
        return [
            'ids' => array_values(array_unique(array_filter(array_map('strval', (array)($selection['ids'] ?? []))))),
            'bounds' => is_array($selection['bounds'] ?? null) ? $selection['bounds'] : [],
            'elements' => array_values(array_filter((array)($selection['elements'] ?? []), 'is_array')),
        ];
    }
}
