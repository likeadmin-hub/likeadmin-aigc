<?php
namespace app\common\service\app\aigc_short_drama;

/** One upstream creative response. No editorial validation or repair calls. */
final class ShortDramaDirectGeneration
{
    public static function enabled(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? 0) >= 10;
    }

    public static function messages(string $prompt, array $request, string $title): array
    {
        $context = array_intersect_key($request, array_flip([
            'ratio', 'target_duration_seconds', 'episode_count', 'episode_number', 'multi_episode',
            'multi_episode_stage', 'series_context', 'confirmed_story_snapshot', 'revision_message',
            'revision_target', 'revision_base_result', 'subject_mentions', 'selected_style_name',
            'source_content', 'source_text', 'document_content', 'skill_inputs',
        ]));
        // System-derived defaults are not user requirements.
        if (($request['duration_source'] ?? $request['episode_duration_policy']['source'] ?? '') === 'default') unset($context['target_duration_seconds']);
        return ['system_prompt' => '你是短剧创作助手。根据用户需求完成当前创作阶段，剧情、分镜拆分、镜头数量、时长、对白和视听设计由你自主安排。'
            . '用户明确提供的设定、总时长、时间码和修改范围属于创作需求。'
            . '为便于页面展示，优先返回JSON对象：title、story_outline、script_lines（字符串数组）、subjects和locations（id/name/description对象数组）、art_style、series_bible、episodes（分集大纲）、storyboard（分镜数组）。'
            . '分镜可包含shot_id、scene_ref_id、subject_ref_ids、visual_description、composition、camera_movement、dialogue、voice_role、recommended_duration_seconds、image_prompt、video_prompt。只输出当前阶段需要的内容。',
            'content' => json_encode(['title'=>$title, 'user_request'=>$prompt, 'creative_context'=>$context], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }

    public static function result(array $response, array $request, string $title): array
    {
        $raw = (string)($response['content'] ?? '');
        if (trim($raw) === '') throw new \RuntimeException('模型未返回内容');
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($raw)) ?? $raw;
        $payload = json_decode($json, true);
        if (!is_array($payload) || isset($payload[0])) $payload = ['story_outline'=>$raw, 'script_lines'=>[$raw]];
        // Keep the original response even when it cannot be projected into editor fields.
        $result = $payload + ['title'=>$title, 'story_outline'=>'', 'script_lines'=>[], 'subjects'=>[],
            'locations'=>[], 'storyboard'=>[], 'episodes'=>[], 'series_bible'=>[], 'art_style'=>[],
            'music_plan'=>[], 'type_judgement'=>'', 'core_theme'=>'', 'opening_feedback'=>'', 'planning_steps'=>[]];
        foreach (['title','story_outline','type_judgement','core_theme','opening_feedback'] as $key) {
            if (!is_string($result[$key])) $result[$key] = ShortDramaText::value($result[$key]);
        }
        foreach (['subjects','locations','storyboard','episodes'] as $key) {
            $result[$key] = array_values(array_filter((array)$result[$key], 'is_array'));
        }
        if (!is_array($result['script_lines'])) $result['script_lines'] = [ShortDramaText::value($result['script_lines'])];
        $result['raw_content'] = $raw;
        $result['multi_episode'] = !empty($request['multi_episode']);
        $result['episode_count'] = (int)($request['episode_count'] ?? 1);
        $result['multi_episode_stage'] = $request['multi_episode_stage'] ?? 'production';
        $result['generation_settings'] = array_replace((array)($result['generation_settings'] ?? []), [
            'quality_review_enabled'=>false, 'creative_control'=>'upstream', 'aspect_ratio'=>$request['ratio'] ?? '',
        ]);
        $result['review_report'] = ['status'=>'skipped','blocking_count'=>0,'issues'=>[]];
        if (!empty($request['series_context'])) {
            $result['_continuity'] = ShortDramaContinuity::ledger(['summary'=>$result['story_outline'] ?: $raw,
                'changes'=>[], 'hooks'=>[], 'warnings'=>[]], $result, $request['series_context'], (int)($request['episode_number'] ?? 1));
            $result['_continuity']['audit_status'] = 'skipped';
        }
        return $result;
    }
}
