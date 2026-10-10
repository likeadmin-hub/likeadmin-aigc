<?php
namespace app\common\service\app\aigc_short_drama;

/** Display-only accepted history, never an editable or billable result. */
final class ShortDramaScriptPreview
{
    public static function project(array $plan): array
    {
        return array_intersect_key($plan, array_flip(['title', 'type_judgement', 'core_theme',
            'story_outline', 'script_lines', 'art_style', 'subjects', 'locations', 'storyboard']));
    }
}
