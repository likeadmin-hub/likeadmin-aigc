<?php
namespace app\common\service\app\aigc_short_drama;

/** Stable event ownership is computed once from the scene, never from batch size. */
final class ShortDramaSceneProgress
{
    public static function window(array $beat, int $total, int $offset, int $size, array $completed): array
    {
        $events = array_values(array_filter((array)($beat['key_events'] ?? []), static fn($event) => is_string($event) && trim($event) !== ''));
        if (!$events) $events = [ShortDramaText::value($beat['goal'] ?? '')];
        $windows = []; $active = [];
        foreach ($events as $i => $event) {
            $first = (int)floor($i * $total / count($events));
            $last = max($first, (int)floor(($i + 1) * $total / count($events)) - 1);
            $record = ['event_id'=>'event_' . ($i + 1), 'text'=>$event, 'first_shot'=>$first + 1, 'last_shot'=>$last + 1];
            $windows[] = $record;
            if ($first < $offset + $size && $last >= $offset) $active[] = $record + ['phase'=>$first < $offset ? 'continue' : 'start'];
        }
        return ['first_shot'=>$offset + 1, 'last_shot'=>$offset + $size, 'scene_shots'=>$total,
            'at_scene_start'=>$offset === 0, 'at_scene_end'=>$offset + $size === $total,
            'event_windows'=>$windows, 'current_events'=>$active,
            'completed_narrative'=>array_map(static fn($shot) => array_intersect_key($shot, array_flip([
                'shot_id','scene_ref_id','subject_ref_ids','visual_description','dialogue','voice_role','speech_type'])), $completed),
            'instruction'=>'event_windows规定事件首次发生及延续区间，与请求分块大小无关。仅展开current_events；phase=continue承接已发生动作，不重演取出、交接、进入、发现等起始动作，也不重说已完成台词。长台词按原文顺序接续，不删改。completed_narrative是已经完成的事实，不得再次生成。entry只在场景首段表现，exit只在末段达成；中间段不要提前收尾或从开头重演。全场goal和其他事件只作参考。'];
    }
}
