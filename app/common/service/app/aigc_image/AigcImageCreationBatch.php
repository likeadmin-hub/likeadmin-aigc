<?php

namespace app\common\service\app\aigc_image;

use InvalidArgumentException;

/** Presentation metadata only; generation and billing remain per task. */
class AigcImageCreationBatch
{
    public const STORAGE_KEY = '_creation_batch';

    public static function takeFromRequest(array &$params): array
    {
        $value = $params['creation_batch'] ?? null;
        unset($params['creation_batch']);
        if ($value === null) {
            return [];
        }
        $batch = self::normalize($value);
        if ($batch === null || (int)($params['quantity'] ?? 1) !== 1) {
            throw new InvalidArgumentException('图片批次参数无效');
        }
        return [self::STORAGE_KEY => $batch];
    }

    public static function normalize($value): ?array
    {
        if (!is_array($value) || !is_string($value['id'] ?? null)
            || !preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', $value['id'])
            || !is_int($value['size'] ?? null) || !is_int($value['index'] ?? null)
            || $value['size'] < 2 || $value['size'] > 4
            || $value['index'] < 1 || $value['index'] > $value['size']) {
            return null;
        }
        return ['id' => $value['id'], 'index' => $value['index'], 'size' => $value['size']];
    }

    public static function fromTask(array $task): ?array
    {
        $params = $task['provider_params_json'] ?? [];
        if (is_string($params)) {
            $params = json_decode($params, true);
        }
        return self::normalize(is_array($params) ? ($params[self::STORAGE_KEY] ?? null) : null);
    }

    public static function providerParams(array $params): array
    {
        unset($params[self::STORAGE_KEY]);
        return $params;
    }
}
