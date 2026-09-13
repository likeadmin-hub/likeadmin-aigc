<?php

/**
 * Deterministic in-process text provider for script-plan contract tests.
 * It never opens a network connection, creates a provider task or bills.
 */
namespace app\common\service\power {
    class MarketTextModelRuntimeService
    {
        public static array $requests = [];
        public static bool $returnIncompleteFirstOutline = false;
        public static bool $returnIncompleteEveryOutline = false;
        public static bool $missingSpeaker = false;
        private static bool $incompleteWasReturned = false;
        private static int $lastOutlineCount = 0;

        public static function reset(): void
        {
            self::$requests = [];
            self::$missingSpeaker = false;
            self::$returnIncompleteFirstOutline = false;
            self::$returnIncompleteEveryOutline = false;
            self::$incompleteWasReturned = false;
            self::$lastOutlineCount = 0;
        }

        public static function modelGroups(int $tenantId): array
        {
            return [];
        }

        public static function generate(int $tenantId, int $userId, array $params, ?callable $callback = null): array
        {
            self::$requests[] = $params;
            $content = (string)($params['content'] ?? '');
            $isRepair = (string)($params['action_code'] ?? '') === 'script_plan_repair';
            $count = self::outlineCount($content);
            if ($isRepair && self::$lastOutlineCount > 0) {
                $count = self::$lastOutlineCount;
            }
            if ($count >= 2) {
                self::$lastOutlineCount = $count;
                $episodeItems = $count;
                if (self::$returnIncompleteEveryOutline || (self::$returnIncompleteFirstOutline && !self::$incompleteWasReturned && !$isRepair)) {
                    $episodeItems = 1;
                    self::$incompleteWasReturned = true;
                }
                return ['content' => json_encode(self::outlinePayload($episodeItems), JSON_UNESCAPED_UNICODE)];
            }
            $payload = self::singlePayload();
            if (self::$missingSpeaker) {
                $payload['storyboard'][0]['dialogue'] = '我找到线索了。';
                if ($isRepair) $payload['storyboard'][0]['voice_role'] = $payload['subjects'][0]['name'];
            }
            return ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE)];
        }

        private static function outlineCount(string $content): int
        {
            if (preg_match('/exactly\s+(\d+)\s+outline items/i', $content, $match)) {
                return (int)$match[1];
            }
            if (preg_match('/"episode_count"\s*:\s*(\d+)/', $content, $match)) {
                return (int)$match[1];
            }
            return 0;
        }

        private static function outlinePayload(int $count): array
        {
            $episodes = [];
            for ($number = 1; $number <= $count; $number++) {
                $episodes[] = [
                    'episode_number' => $number,
                    'title' => '第' . $number . '集',
                    'story_outline' => '第' . $number . '集推进主线并揭示新线索。',
                    'conflict_point' => '第' . $number . '集的核心冲突。',
                    'ending_hook' => '第' . $number . '集留下新的悬念。',
                ];
            }
            return [
                'title' => '模拟长篇悬疑',
                'type_judgement' => '悬疑短剧',
                'core_theme' => '真相与信任',
                'story_outline' => '主角逐步追查旧案，最终揭开真相。',
                'subjects' => [['id' => 'subject_1', 'name' => '林岚', 'description' => '追查旧案的调查员']],
                'locations' => [['id' => 'location_1', 'name' => '旧宅', 'description' => '线索不断浮现的旧宅']],
                'episodes' => $episodes,
                'storyboard' => [],
            ];
        }

        private static function singlePayload(): array
        {
            $storyboard = [];
            // The single-plan default duration is one minute; return enough
            // provider shots to exercise the exact-duration guard as well.
            for ($number = 1; $number <= 12; $number++) {
                $storyboard[] = [
                    'shot_id' => (string)$number, 'scene_ref_id' => 'location_1', 'subject_ref_ids' => ['subject_1'],
                    'visual_description' => '林岚在旧宅书房推进调查，第' . $number . '个镜头聚焦关键录音笔。',
                    'composition' => '中近景，录音笔位于画面中央。', 'camera_movement' => '缓慢推进',
                    'image_prompt' => '雨夜旧宅书房中的林岚举起录音笔。',
                    'video_prompt' => '镜头缓慢推进到林岚手中的录音笔。', 'recommended_duration_seconds' => 5,
                ];
            }
            return [
                'title' => '模拟单集悬疑',
                'type_judgement' => '悬疑短剧',
                'core_theme' => '真相与信任',
                'story_outline' => '林岚在旧宅发现关键录音，决定继续追查。',
                'script_lines' => ['林岚在旧宅发现关键录音。'],
                'subjects' => [['id' => 'subject_1', 'name' => '林岚', 'description' => '调查旧案的年轻记者']],
                'locations' => [['id' => 'location_1', 'name' => '旧宅', 'description' => '雨夜中的旧宅']],
                'storyboard' => $storyboard,
            ];
        }
    }
}
