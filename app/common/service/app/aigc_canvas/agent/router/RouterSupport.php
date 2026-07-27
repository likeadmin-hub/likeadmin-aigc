<?php

namespace app\common\service\app\aigc_canvas\agent\router;

trait RouterSupport
{
    protected static function containsAny(string $text, array $keywords): bool
    {
        $lower = mb_strtolower($text, 'UTF-8');
        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower((string)$keyword, 'UTF-8');
            if ($keyword !== '' && str_contains($lower, $keyword)) {
                return true;
            }
        }
        return false;
    }

    protected static function parseJson(string $content): array
    {
        $text = trim($content);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/s', $text, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    protected static function slotKeys($slots): array
    {
        $result = [];
        foreach ((array)$slots as $key => $slot) {
            $name = is_array($slot) ? (string)($slot['key'] ?? $slot['name'] ?? '') : (is_string($key) ? (string)$key : (string)$slot);
            $name = trim($name);
            if ($name !== '') {
                $result[] = $name;
            }
        }
        return array_values(array_unique($result));
    }

    protected static function referenceSummaryForPrompt(array $references): array
    {
        $items = [];
        foreach (array_slice($references, 0, 8) as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $items[] = [
                'type' => (string)($reference['type'] ?? 'image'),
                'role' => (string)($reference['role'] ?? ''),
                'name' => (string)($reference['name'] ?? $reference['title'] ?? ''),
                'url_present' => trim((string)($reference['url'] ?? $reference['uri'] ?? '')) !== '',
            ];
        }
        return $items;
    }

    protected static function isExecutionConfirmation(string $content): bool
    {
        $text = mb_strtolower(trim($content), 'UTF-8');
        return in_array($text, ['确认', '确认生成', '开始生成', '按这个生成', '可以', '执行', 'confirm'], true)
            || preg_match('/^(?:确认|可以|开始|按这个).{0,12}(?:生成|执行)?$/u', $text) === 1;
    }
}
