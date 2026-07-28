<?php

namespace app\common\service\app\aigc_canvas\agent\canvas;

/**
 * Resolves only deterministic canvas references. Ambiguous language is kept as
 * candidates so the Agent can ask one focused question instead of guessing.
 */
final class CanvasReferenceResolver
{
    public static function resolve(string $request, array $context): array
    {
        $elements = array_values(array_filter((array)($context['elements'] ?? $context['nodes'] ?? []), 'is_array'));
        $selection = array_values(array_filter((array)($context['selected_elements'] ?? $context['selection']['elements'] ?? []), 'is_array'));
        $text = mb_strtolower($request, 'UTF-8');
        if (self::mentionsSelection($text) && count($selection) === 1) {
            return self::resolved($selection[0], 1.0, 'selection');
        }
        if ($elements === []) {
            return ['status' => 'none', 'candidates' => []];
        }
        $spatial = self::spatialReference($text);
        if ($spatial !== []) {
            $ordered = self::order($elements, $spatial['axis'], $spatial['direction']);
            $index = $spatial['index'] - 1;
            if (isset($ordered[$index])) {
                return self::resolved($ordered[$index], 0.95, $spatial['label']);
            }
            return ['status' => 'ambiguous', 'reason' => 'position_out_of_range', 'candidates' => self::candidates($ordered)];
        }
        if (preg_match('/第\s*(\d+)\s*(张|个|项|个元素)?/u', $request, $match)) {
            $ordered = self::order($elements, 'y', 'asc');
            $index = max(1, (int)$match[1]) - 1;
            if (isset($ordered[$index])) {
                return self::resolved($ordered[$index], 0.72, 'ordered_index');
            }
        }
        if (self::mentionsSelection($text) && count($selection) > 1) {
            return ['status' => 'ambiguous', 'reason' => 'multiple_selection', 'candidates' => self::candidates($selection)];
        }
        return ['status' => 'none', 'candidates' => []];
    }

    private static function mentionsSelection(string $text): bool
    {
        foreach (['这张', '这个', '这里', '当前', '选中', '它', '它们', '这一组'] as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }
        return false;
    }

    private static function spatialReference(string $text): array
    {
        $patterns = [
            ['/(左边|左侧|最左)\s*第?\s*(\d+)?/u', 'x', 'asc', 'left'],
            ['/(右边|右侧|最右)\s*第?\s*(\d+)?/u', 'x', 'desc', 'right'],
            ['/(上面|上方|最上)\s*第?\s*(\d+)?/u', 'y', 'asc', 'top'],
            ['/(下面|下方|最下)\s*第?\s*(\d+)?/u', 'y', 'desc', 'bottom'],
        ];
        foreach ($patterns as [$pattern, $axis, $direction, $label]) {
            if (preg_match($pattern, $text, $match)) {
                return ['axis' => $axis, 'direction' => $direction, 'index' => max(1, (int)($match[2] ?? 1)), 'label' => $label];
            }
        }
        return [];
    }

    private static function order(array $elements, string $axis, string $direction): array
    {
        usort($elements, static function (array $a, array $b) use ($axis, $direction): int {
            $aMain = self::coordinate($a, $axis);
            $bMain = self::coordinate($b, $axis);
            $aOther = self::coordinate($a, $axis === 'x' ? 'y' : 'x');
            $bOther = self::coordinate($b, $axis === 'x' ? 'y' : 'x');
            $result = $aMain <=> $bMain;
            if ($result === 0) {
                $result = $aOther <=> $bOther;
            }
            return $direction === 'desc' ? -$result : $result;
        });
        return $elements;
    }

    private static function coordinate(array $element, string $axis): float
    {
        $position = is_array($element['position'] ?? null) ? $element['position'] : [];
        $style = is_array($element['style'] ?? null) ? $element['style'] : [];
        return (float)($element[$axis] ?? $position[$axis] ?? $style[$axis] ?? 0);
    }

    private static function resolved(array $element, float $confidence, string $source): array
    {
        return ['status' => 'resolved', 'confidence' => $confidence, 'source' => $source, 'target' => self::candidate($element), 'candidates' => []];
    }

    private static function candidates(array $elements): array
    {
        return array_slice(array_map([self::class, 'candidate'], $elements), 0, 8);
    }

    private static function candidate(array $element): array
    {
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        return [
            'id' => (string)($element['id'] ?? $element['element_id'] ?? ''),
            'type' => (string)($element['type'] ?? $data['type'] ?? ''),
            'name' => mb_substr((string)($element['name'] ?? $data['name'] ?? $data['title'] ?? $data['text'] ?? ''), 0, 120, 'UTF-8'),
            'x' => self::coordinate($element, 'x'),
            'y' => self::coordinate($element, 'y'),
        ];
    }
}
