<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Prompt-only cuts. No new shot or provider payload structure. */
final class ShortDramaSameSceneCuts
{
    public static function snapshot(array $model): array
    {
        $capabilities = (array)($model['capabilities'] ?? []);
        return ['version' => 1, 'enabled' => ($capabilities['supports_same_scene_cuts'] ?? false) === true,
            'model_id' => (string)($model['id'] ?? ''), 'max_segments' => 3];
    }

    public static function enabled(array $request): bool
    {
        return ShortDramaEpisodeDuration::active($request)
            && ($request['same_scene_cut_policy']['enabled'] ?? false) === true
            && !in_array($request['generation_mode'] ?? $request['video_mode'] ?? '', ['lip_sync', 'lipsync'], true);
    }

    public static function instruction(array $request): string
    {
        if (!ShortDramaEpisodeDuration::active($request)) return '';
        $common = '一张分镜卡对应一次视频生成。visual_description与image_prompt只写开场画面。camera_movement和video_prompt描述片段内完整运动，沿用导演稿格式。'
            . '片段内不得跨场景、跨时间、临时新增人物。所有可见人物必须列入subject_ref_ids。复杂多人对白拆成不同卡片；对口型流程只允许一个说话主体。';
        if (!self::enabled($request)) return $common . '当前生成路径尚未确认支持自动切镜，每张卡默认连续镜头，不能自动加入正反打或转场。';
        return $common . '当前模型已明确支持同场景切镜。默认仍为连续镜头，仅剧情必要时，在同场景连续时间和已绑定人物之间切近景、反应或细节；最多三个画面段落。'
            . '使用相对秒数覆盖当前片段，例如10秒片段：0–3秒甲近景；3–7秒乙反打；7–10秒甲反应。此规则替代旧模板固定一镜到底要求。'
            . 'dialogue仍为字符串；不同说话人逐行标注真实角色名，如“甲：你好。\n乙：你好。”，不得把多人台词全部交给voice_role中的一个人。';
    }

    /** Validate only auto-created segments, never rewrite user-authored prompts. */
    public static function assertShot(array $shot, array $plan, array $request): void
    {
        $camera = (string)($shot['camera_movement'] ?? '');
        preg_match_all('/(\d+(?:\.\d+)?)\s*(?:秒)?\s*[-–—~至到]\s*(\d+(?:\.\d+)?)\s*秒/u', $camera, $ranges, PREG_SET_ORDER);
        if (count($ranges) < 2) return;
        if (!self::enabled($request) || count($ranges) > 3) throw new RuntimeException('当前片段的自动切镜数量或模型能力不符合要求', 422);
        $end = 0.0;
        foreach ($ranges as $range) {
            if (abs((float)$range[1] - $end) > 0.001 || (float)$range[2] <= $end) throw new RuntimeException('片段内部切镜时间必须连续', 422);
            $end = (float)$range[2];
        }
        if (abs($end - (float)$shot['recommended_duration_seconds']) > 0.001) throw new RuntimeException('片段切镜时间必须完整覆盖本卡片时长', 422);
        foreach ((array)($plan['subjects'] ?? []) as $subject) {
            $name = trim((string)($subject['name'] ?? ''));
            if ($name !== '' && mb_strpos($camera, $name) !== false && !in_array($subject['id'], (array)$shot['subject_ref_ids'], true)) {
                throw new RuntimeException('切镜中可见人物缺少主体绑定：' . $name, 422);
            }
        }
    }
}
