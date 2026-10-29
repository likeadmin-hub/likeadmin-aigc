<?php
namespace app\common\service\app\aigc_short_drama;

/** Display-only accepted history, never an editable or billable result. */
final class ShortDramaScriptPreview
{
    /** Old streams may be offered as drafts only when the entire JSON object is intact. */
    public static function legacyCheckpoint(array $state): array
    {
        $content=trim((string)($state['__stream_content'] ?? ''));
        $content=preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content) ?? $content;
        if (!str_starts_with($content,'{') || !is_array(json_decode($content,true)) || json_last_error() !== JSON_ERROR_NONE) return [];
        try { $plan=ShortDramaStructuredResponse::decode(['content'=>$content]); }
        catch (\Throwable) { return []; }
        return self::project($plan);
    }

    /** User-facing hints describe only saved content and a coarse recent stage. */
    public static function recoveryInfo(array $state, string $status, string $error = ''): array
    {
        if (!in_array($status, ['failed','canceled'], true)) return [];
        $draft = self::project((array)($state['__script_checkpoint'] ?? []));
        $shots = is_array($draft['storyboard'] ?? null) ? count($draft['storyboard']) : 0;
        $hasDraft = $shots > 0 || trim((string)($draft['story_outline'] ?? '')) !== ''
            || !empty($draft['script_lines']) || !empty($draft['subjects']) || !empty($draft['locations']);
        $unit = (string)($state['__stream_unit'] ?? ''); $stage = '';
        if (preg_match('/^v3_(?:policy2_)?(?:timed_)?scene_(\d+)_(\d+)(?:_|$)/D', $unit, $match)) $stage = '第' . (int)$match[1] . '场，从第' . (int)$match[2] . '个分镜开始';
        elseif (preg_match('/^v3_(?:policy2_)?timed_batch_(\d+)_(\d+)$/D', $unit, $match)) $stage = '第' . (int)$match[1] . '至' . (int)$match[2] . '场';
        elseif (preg_match('/^v3_(?:policy2_)?(?:timed_)?skeleton(?:_|$)/D', $unit)) $stage = '场次规划';
        $info=['has_draft'=>$hasDraft, 'saved_shots'=>$shots, 'recent_stage'=>$stage];
        if ($error !== '') {
            $code=ShortDramaPlanningState::errorCode($error);
            $messages=[
                'provider_output_incomplete'=>['模型输出未完成','请查看已保留内容，再尝试继续生成。'],
                'parse_failed'=>['输出格式无法解析','请查看已保留内容，重试后仍失败可联系管理员。'],
                'generation_limit_reached'=>['本次处理范围达到上限','可尝试继续生成，或缩小剧本范围。'],
                'validation_failed'=>['内容校验未通过','请结合提示检查场景、对白或时长。'],
                'input_invalid'=>['输入内容不符合要求','请检查剧本原文和时间要求后重试。'],
            ];
            if (isset($messages[$code])) [$info['issue'],$info['advice']]=$messages[$code];
            if (preg_match('/(?:分镜|镜头)\s*([a-zA-Z0-9_-]{1,40})(?:\s|的|，|：|:|$)/u',$error,$match)) $info['shot_id']=$match[1];
            foreach (['对白'=>'对白或说话人','说话人'=>'对白或说话人','时间码'=>'时长或时间码','时长'=>'时长或时间码',
                '场景'=>'场景','主体'=>'主体','画面'=>'画面描述','骨架'=>'场次规划','分镜数量'=>'分镜数量','JSON'=>'输出格式'] as $term=>$label) {
                if (str_contains($error, $term)) { $info['field_label']=$label; break; }
            }
        }
        return $info;
    }

    public static function project(array $plan): array
    {
        return array_intersect_key($plan, array_flip(['title', 'type_judgement', 'core_theme',
            'story_outline', 'script_lines', 'art_style', 'subjects', 'locations', 'scene_screenplays', 'storyboard']));
    }

    /** Read-only compatibility for workers that predate timed checkpoints. */
    public static function fromReceipts(array $rows): array
    {
        $plan = []; $shots = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'received' || !empty($row['error'])) continue;
            if (!preg_match('/^v3_(?:policy2_)?(?:timed_)?(?:skeleton|scene_)/', (string)($row['unit_key'] ?? ''))) continue;
            try {
                $receipt = json_decode((string)$row['result_json'], true, 512, JSON_THROW_ON_ERROR);
                $value = ShortDramaStructuredResponse::decode((array)($receipt['result'] ?? []));
            } catch (\Throwable) { continue; }
            if (!empty($value['locations']) && !empty($value['story_outline'])) {
                $plan = self::project($value); $shots = [];
            }
            if (!$plan) continue;
            foreach (($value['storyboard'] ?? []) as $shot) {
                if (is_array($shot) && !empty($shot['shot_id'])) $shots[(string)$shot['shot_id']] = $shot;
            }
        }
        if ($plan) $plan['storyboard'] = array_values($shots);
        return $plan;
    }
}
