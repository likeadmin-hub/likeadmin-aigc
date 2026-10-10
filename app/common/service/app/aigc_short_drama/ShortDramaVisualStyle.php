<?php
namespace app\common\service\app\aigc_short_drama;

/** Read legacy/string styles without rewriting the saved model response. */
final class ShortDramaVisualStyle
{
    public static function plan(array $plan, array $confirmed = []): array
    {
        $style = $plan['art_style'] ?? [];
        if (is_string($style)) $style = ['visual_description' => $style];
        if (!is_array($style)) $style = [];
        $name = trim((string)($confirmed['style_name'] ?? ''));
        $prompt = trim((string)($confirmed['style_prompt'] ?? ''));
        if ($name !== '' || $prompt !== '') {
            // The confirmed choice is authoritative over a model's conflicting style.
            $style['base_style'] = $name;
            $style['visual_description'] = trim($name . "\n" . $prompt);
        }
        $plan['art_style'] = $style;
        return $plan;
    }

    public static function append(string $prompt, array $plan): string
    {
        $style = self::plan($plan)['art_style'];
        $text = trim((string)($style['visual_description'] ?? ''));
        if ($text === '') $text = trim((string)($style['base_style'] ?? ''));
        return $text === '' || str_contains($prompt, $text) ? $prompt : rtrim($prompt) . "\n视觉风格：" . $text;
    }
}
