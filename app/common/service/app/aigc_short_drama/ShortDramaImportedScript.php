<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Lossless adapter for raw and previously normalized parser receipts. */
final class ShortDramaImportedScript
{
    public static function sourceContent(array $episode): string
    {
        $values = [];
        foreach (['content', 'source_content'] as $key) {
            if (!array_key_exists($key, $episode) || $episode[$key] === null) continue;
            if (!is_string($episode[$key])) throw new RuntimeException('解析结果的剧本原文必须为文本，原始回包已保留', 422);
            if ($episode[$key] !== '') $values[$key] = $episode[$key];
        }
        if (count($values) === 2 && $values['content'] !== $values['source_content']) {
            throw new RuntimeException('解析结果包含不一致的两份剧本原文，请核对原始回包', 422);
        }
        return $values['content'] ?? $values['source_content'] ?? '';
    }
}
