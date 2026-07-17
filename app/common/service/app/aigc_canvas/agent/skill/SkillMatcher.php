<?php

namespace app\common\service\app\aigc_canvas\agent\skill;

final class SkillMatcher
{
    public static function matchDesignSkill(array $skills, string $content): array
    {
        $text = mb_strtolower($content, 'UTF-8');
        foreach ($skills as $skill) {
            $haystack = mb_strtolower(
                (string)($skill['skill_key'] ?? '') . ' ' .
                (string)($skill['description'] ?? '') . ' ' .
                (string)($skill['trigger_description'] ?? ''),
                'UTF-8'
            );
            if ($haystack !== '' && self::overlaps($text, $haystack)) {
                return $skill;
            }
        }
        return [];
    }

    private static function overlaps(string $content, string $haystack): bool
    {
        foreach (['设计', '页面', '海报', '主视觉', '发布会', '品牌', '详情页', 'design', 'poster'] as $word) {
            if (str_contains($content, $word) && str_contains($haystack, $word)) {
                return true;
            }
        }
        return false;
    }
}
