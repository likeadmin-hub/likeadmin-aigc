<?php
namespace app\common\service\app\aigc_short_drama;

/** Source-addressed summaries/endings, separate from verified state and hook ledgers. */
final class ShortDramaSourceMemory
{
    public static function enabled(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? 0) >= 11;
    }

    public static function capture(array $plan): array
    {
        $records = [];
        if (is_string($plan['story_outline'] ?? null) && trim($plan['story_outline']) !== '') {
            $records['story_outline'] = ['field'=>'story_outline', 'text'=>$plan['story_outline']];
        }
        foreach (array_slice((array)($plan['storyboard'] ?? []), -3) as $index => $shot) {
            foreach (['visual_description','dialogue'] as $field) {
                if (!is_string($shot[$field] ?? null) || trim($shot[$field]) === '') continue;
                $records['ending_' . $index . '_' . $field] = ['shot_id'=>(string)($shot['shot_id'] ?? ''),
                    'field'=>$field, 'text'=>$shot[$field]];
            }
        }
        return ['version'=>1, 'digest'=>ShortDramaContinuity::fingerprint($plan), 'records'=>$records];
    }

    public static function attach(array $plan, array $request): array
    {
        if (self::enabled($request) && !empty($request['series_context']) && isset($plan['_continuity'])) {
            $plan['_continuity']['source_memory'] = self::capture($plan);
        }
        return $plan;
    }

    public static function excerpts(array $history, int $length = 600): array
    {
        $records = [];
        foreach ($history as $number => $entry) {
            $source = $entry['source_memory'] ?? [];
            $items = $source['records'] ?? ['summary'=>['field'=>'legacy_summary','text'=>(string)($entry['summary'] ?? '')]];
            foreach ($items as $key => $item) {
                $text = (string)($item['text'] ?? '');
                for ($start=0, $total=mb_strlen($text); $start<$total; $start+=max(1, (int)($length * .8))) {
                    $records[$number . ':' . $key . ':' . $start] = [
                        'episode_number'=>(int)$number, 'source_digest'=>$source['digest'] ?? $entry['digest'] ?? '',
                        'verification'=>'source_text_not_audited', 'audit_status'=>$entry['audit_status'] ?? 'unknown',
                        'field'=>$item['field'] ?? $key, 'shot_id'=>$item['shot_id'] ?? '',
                        'start_character'=>$start, 'excerpt'=>mb_substr($text,$start,$length),
                    ];
                }
            }
        }
        return $records;
    }

    public static function outline(array $previous, array $roadmap, int $start, string $query): array
    {
        $records=[];
        foreach ($previous as $episode) {
            if ((int)($episode['episode_number'] ?? 0) >= $start) continue;
            $records[(int)$episode['episode_number']] = array_intersect_key($episode,
                array_flip(['episode_number','title','story_outline','conflict_point','ending_hook']));
        }
        foreach ($roadmap as $segment) if ($segment['start'] <= $start && $segment['end'] >= $start) {
            $query .= ' ' . json_encode($segment, JSON_UNESCAPED_UNICODE);
        }
        return ['instruction'=>'以下为已保存的前集大纲摘选，不代表已生成正文或完整历史。保留其事件、伏笔与揭露顺序；未选入不代表不存在。',
            'episodes'=>ShortDramaNarrativeMemory::select($records,$query,4000)];
    }
}
