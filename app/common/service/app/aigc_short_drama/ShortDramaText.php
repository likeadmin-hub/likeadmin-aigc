<?php
namespace app\common\service\app\aigc_short_drama;

/** Lossless display metadata normalization; never use for IDs or evidence verdicts. */
final class ShortDramaText
{
    public static function value($value): string
    {
        if ($value === null || $value === []) return '';
        return trim(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string)$value);
    }

    public static function genre($value): string
    {
        if (is_array($value)) $value = $value['story_type'] ?? $value['genre'] ?? $value['type'] ?? $value;
        return self::value($value);
    }

    public static function lines($value): array
    {
        if (is_string($value)) $value = preg_split('/\r?\n/u', $value) ?: [];
        elseif (is_array($value) && !array_is_list($value)) $value = [$value];
        return array_values(array_filter(array_map([self::class, 'value'], (array)$value), static fn($text) => $text !== ''));
    }
}
