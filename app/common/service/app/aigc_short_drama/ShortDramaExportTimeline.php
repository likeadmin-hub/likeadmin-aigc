<?php

namespace app\common\service\app\aigc_short_drama;

use Exception;

/** Export only server-owned assets; freeze the editor's choices before queueing. */
final class ShortDramaExportTimeline
{
    /** $shots and $assets must already be scoped to the tenant, user and project. */
    public static function capture(array $shots, array $assets, array $options): array
    {
        if (!$shots) throw new Exception('暂无可导出的分镜');
        $assets = self::readyAssets($assets);
        $explicit = array_key_exists('timeline', $options);
        $timeline = $options['timeline'] ?? [];
        if ($explicit && (!is_array($timeline) || count($timeline) !== count($shots))) {
            throw new Exception('分镜数量已变化，请刷新并检查时间线后重新导出');
        }
        $timeline = is_array($timeline) ? array_values($timeline) : [];
        $clips = [];
        foreach (array_values($shots) as $index => $shot) {
            $shotId = (string)$shot['shot_id'];
            $entry = $explicit ? (array)($timeline[$index] ?? []) : [];
            if ($explicit && (string)($entry['shot_id'] ?? '') !== $shotId) {
                throw new Exception('分镜顺序已变化，请刷新并检查时间线后重新导出');
            }
            $selectedIds = [(int)($shot['selected_video_asset_id'] ?? 0), (int)($shot['selected_image_asset_id'] ?? 0)];
            $asset = null;
            if ($explicit) {
                $asset = $assets[(int)($entry['asset_id'] ?? 0)] ?? null;
            } else {
                foreach ($selectedIds as $id) {
                    if (isset($assets[$id])) { $asset = $assets[$id]; break; }
                }
                // Compatibility for older clients and records without a selection.
                foreach (['shot_video', 'shot_image'] as $type) {
                    if ($asset) break;
                    foreach ($assets as $candidate) {
                        if ($candidate['asset_type'] === $type && (string)$candidate['shot_id'] === $shotId) {
                            $asset = $candidate;
                            break;
                        }
                    }
                }
            }
            if (!$asset || !in_array($asset['asset_type'], ['shot_image', 'shot_video'], true)
                || ((string)$asset['shot_id'] !== $shotId && !in_array((int)$asset['id'], $selectedIds, true))) {
                throw new Exception('分镜 ' . ($index + 1) . ' 缺少可用素材，请重新选择图片或视频');
            }
            $planned = (float)($shot['recommended_duration_seconds'] ?? 0);
            $duration = $explicit ? (float)($entry['duration'] ?? 0) : max(1, $planned > 0 ? $planned : ((float)($asset['duration'] ?? 0) ?: ShortDramaShotDuration::DEFAULT));
            if (!is_finite($duration) || $duration < 1 || $duration > 3600) {
                throw new Exception('分镜 ' . ($index + 1) . ' 的导出时长无效');
            }
            $clips[] = ['shot_id' => $shotId, 'asset_id' => (int)$asset['id'], 'duration' => $duration];
        }
        $bgmId = 0;
        if (!array_key_exists('bgm_enabled', $options) || filter_var($options['bgm_enabled'], FILTER_VALIDATE_BOOLEAN)) {
            $requestedBgm = (int)($options['bgm_asset_id'] ?? 0);
            if ($requestedBgm > 0) {
                if (($assets[$requestedBgm]['asset_type'] ?? '') !== 'bgm_audio') throw new Exception('所选背景音乐不可用，请重新选择');
                $bgmId = $requestedBgm;
            } else {
                foreach ($assets as $asset) {
                    if ($asset['asset_type'] === 'bgm_audio') { $bgmId = (int)$asset['id']; break; }
                }
            }
        }
        return ['version' => 1, 'clips' => $clips, 'bgm_asset_id' => $bgmId];
    }

    /** Never fall back to a newer asset when a frozen input has been removed. */
    public static function resolve(array $snapshot, array $assets): array
    {
        $assets = self::readyAssets($assets);
        $clips = [];
        foreach ($snapshot['clips'] ?? [] as $entry) {
            $asset = $assets[(int)$entry['asset_id']] ?? null;
            if (!$asset || !in_array($asset['asset_type'], ['shot_image', 'shot_video'], true)) {
                throw new Exception('导出使用的素材已不可用，请检查时间线后重新导出');
            }
            $asset['duration'] = (float)$entry['duration'];
            $asset['timeline_shot_id'] = (string)$entry['shot_id'];
            $clips[] = $asset;
        }
        if (!$clips) throw new Exception('暂无可导出的分镜');
        $bgmId = (int)($snapshot['bgm_asset_id'] ?? 0);
        if ($bgmId && ($assets[$bgmId]['asset_type'] ?? '') !== 'bgm_audio') {
            throw new Exception('导出使用的背景音乐已不可用，请重新选择后导出');
        }
        return ['assets' => $clips, 'bgm' => $bgmId ? $assets[$bgmId] : []];
    }

    private static function readyAssets(array $rows): array
    {
        usort($rows, static fn(array $a, array $b): int => (int)$b['id'] <=> (int)$a['id']);
        $assets = [];
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'ready' && empty($row['delete_time'])) $assets[(int)$row['id']] = $row;
        }
        return $assets;
    }
}
