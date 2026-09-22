<?php

namespace app\common\service\app\aigc_short_drama;

use Exception;
use think\facade\Db;

/**
 * The current formal short-drama target for a free canvas.
 *
 * Binding is deliberately separate from canvas nodes, runs, and assets.  A
 * canvas may gain a formal target later, but historical free-canvas work must
 * keep its original project_id=0 ownership and billing records.
 */
class ShortDramaCanvasBindingService
{
    private const TABLE = 'aigc_short_drama_canvas_binding';
    private const CANVAS_TABLE = 'aigc_short_drama_canvas';
    private const PROJECT_TABLE = 'aigc_short_drama_project';
    private const EPISODE_TABLE = 'aigc_short_drama_episode_task';

    public static function current(int $tenantId, int $userId, int $canvasId): array
    {
        self::ownedCanvas($tenantId, $userId, $canvasId);
        $binding = Db::name(self::TABLE)->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'canvas_id' => $canvasId,
        ])->find();
        return self::format($canvasId, $binding ?: []);
    }

    /**
     * Bind only to an owned story project.  An optional episode is resolved
     * server-side to its owned production project; clients can never select a
     * production project directly.
     */
    public static function bind(int $tenantId, int $userId, array $params): array
    {
        foreach (['production_project_id', 'productionProjectId'] as $forbidden) {
            if (array_key_exists($forbidden, $params)) {
                throw new Exception('制作项目必须由服务端根据剧集解析');
            }
        }
        $canvasId = (int)($params['canvas_id'] ?? $params['id'] ?? 0);
        $projectId = (int)($params['project_id'] ?? 0);
        $episodeId = (int)($params['episode_id'] ?? 0);
        if ($canvasId <= 0) throw new Exception('缺少画布项目');
        if ($projectId <= 0) throw new Exception('请选择要绑定的短剧项目');
        if ($episodeId < 0) throw new Exception('剧集参数无效');

        return Db::transaction(function () use ($tenantId, $userId, $canvasId, $projectId, $episodeId): array {
            self::ownedCanvas($tenantId, $userId, $canvasId, true);
            $project = Db::name(self::PROJECT_TABLE)->where([
                'id' => $projectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$project) throw new Exception('绑定项目不存在或无权访问');

            $productionProjectId = 0;
            if ($episodeId > 0) {
                $episode = Db::name(self::EPISODE_TABLE)->where([
                    'id' => $episodeId, 'tenant_id' => $tenantId, 'user_id' => $userId,
                    'project_id' => $projectId, 'delete_time' => 0,
                ])->lock(true)->find();
                if (!$episode) throw new Exception('剧集不存在或不属于当前短剧项目');
                $productionProjectId = (int)($episode['production_project_id'] ?? 0);
                if ($productionProjectId <= 0) throw new Exception('该剧集尚未创建制作项目，暂不能绑定');
                $production = Db::name(self::PROJECT_TABLE)->where([
                    'id' => $productionProjectId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
                ])->lock(true)->find();
                if (!$production) throw new Exception('剧集制作项目不存在或无权访问');
            }

            $existing = Db::name(self::TABLE)->where([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'canvas_id' => $canvasId,
            ])->lock(true)->find();
            if ($existing
                && (int)$existing['project_id'] === $projectId
                && (int)$existing['episode_id'] === $episodeId
                && (int)$existing['production_project_id'] === $productionProjectId) {
                return self::format($canvasId, $existing);
            }

            $now = time();
            if ($existing) {
                $revision = (int)$existing['binding_revision'] + 1;
                Db::name(self::TABLE)->where('id', (int)$existing['id'])->update([
                    'project_id' => $projectId, 'episode_id' => $episodeId,
                    'production_project_id' => $productionProjectId, 'binding_revision' => $revision,
                    'update_time' => $now,
                ]);
                $existing = array_replace($existing, [
                    'project_id' => $projectId, 'episode_id' => $episodeId,
                    'production_project_id' => $productionProjectId, 'binding_revision' => $revision,
                    'update_time' => $now,
                ]);
                return self::format($canvasId, $existing);
            }

            $id = Db::name(self::TABLE)->insertGetId([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'canvas_id' => $canvasId,
                'project_id' => $projectId, 'episode_id' => $episodeId,
                'production_project_id' => $productionProjectId, 'binding_revision' => 1,
                'create_time' => $now, 'update_time' => $now,
            ]);
            return self::format($canvasId, Db::name(self::TABLE)->where('id', $id)->find() ?: []);
        });
    }

    private static function ownedCanvas(int $tenantId, int $userId, int $canvasId, bool $lock = false): array
    {
        if ($canvasId <= 0) throw new Exception('缺少画布项目');
        $canvas = Db::name(self::CANVAS_TABLE)->where([
            'id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->lock($lock)->find();
        if (!$canvas) throw new Exception('画布项目不存在或无权访问');
        return $canvas;
    }

    private static function format(int $canvasId, array $row): array
    {
        if (!$row) {
            return ['bound' => false, 'canvas_id' => $canvasId, 'project_id' => 0,
                'episode_id' => 0, 'production_project_id' => 0, 'binding_revision' => 0];
        }
        return ['bound' => true, 'canvas_id' => $canvasId,
            'project_id' => (int)$row['project_id'], 'episode_id' => (int)$row['episode_id'],
            'production_project_id' => (int)$row['production_project_id'],
            'binding_revision' => (int)$row['binding_revision']];
    }
}
