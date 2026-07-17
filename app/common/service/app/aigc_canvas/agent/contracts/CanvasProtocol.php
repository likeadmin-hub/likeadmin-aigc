<?php

namespace app\common\service\app\aigc_canvas\agent\contracts;

final class CanvasProtocol
{
    public const VERSION = '1.1';

    public const CREATE_PAGE = 'create_page';
    public const ADD_ELEMENT = 'add_element';
    public const UPDATE_ELEMENT = 'update_element';

    public static function document(array $actions, array $metadata = []): array
    {
        return [
            'version' => self::VERSION,
            'actions' => array_values($actions),
            'metadata' => $metadata,
        ];
    }
}
