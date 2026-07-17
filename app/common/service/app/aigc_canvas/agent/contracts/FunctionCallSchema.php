<?php

namespace app\common\service\app\aigc_canvas\agent\contracts;

final class FunctionCallSchema
{
    public static function function(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => array_values($required),
                ],
            ],
        ];
    }
}
