<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\service\app\aigc_video\AigcVideoPosterService;
use app\common\service\FileService;
use think\facade\Db;

/** Durable, bounded background work for short-drama canvas video posters. */
class ShortDramaCanvasPosterJobService
{
    private const TABLE = 'aigc_short_drama_canvas_poster_job';
    private const MAX_ATTEMPTS = 4;

    public static function enqueue(
        int $tenantId,
        int $userId,
        int $canvasId,
        string $nodeId,
        string $videoUri,
        string $storageScope = '',
        string $storageEngine = '',
        string $storageDomain = ''
    ): void {
        $videoUri = self::canonicalUri($videoUri);
        if ($tenantId <= 0 || $userId <= 0 || $canvasId <= 0 || $nodeId === '' || $videoUri === '') {
            return;
        }
        $now = time();
        $key = sha1(implode('|', [$tenantId, $userId, $canvasId, $nodeId, $videoUri]));
        Db::transaction(function () use ($tenantId, $userId, $canvasId, $nodeId, $videoUri, $storageScope, $storageEngine, $storageDomain, $key, $now): void {
            $job = Db::name(self::TABLE)->where('idempotency_key', $key)->lock(true)->find();
            if (!$job) {
                Db::name(self::TABLE)->insert([
                    'tenant_id' => $tenantId, 'user_id' => $userId, 'canvas_id' => $canvasId,
                    'node_id' => $nodeId, 'video_uri' => $videoUri,
                    'storage_scope' => $storageScope, 'storage_engine' => $storageEngine, 'storage_domain' => $storageDomain,
                    'status' => 'pending', 'attempts' => 0, 'max_attempts' => self::MAX_ATTEMPTS,
                    'next_run_time' => $now, 'lease_token' => '', 'lease_expire_time' => 0,
                    'poster_uri' => '', 'poster_scope' => '', 'poster_engine' => '', 'poster_domain' => '',
                    'last_error' => '', 'idempotency_key' => $key,
                    'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
                ]);
                return;
            }
            if (!in_array((string)$job['status'], ['success', 'dead'], true)) {
                Db::name(self::TABLE)->where('id', (int)$job['id'])->update([
                    'status' => 'pending', 'next_run_time' => $now, 'lease_token' => '', 'lease_expire_time' => 0,
                    'storage_scope' => $storageScope, 'storage_engine' => $storageEngine, 'storage_domain' => $storageDomain,
                    'update_time' => $now,
                ]);
            }
        });
    }

    /** @return array<int,array<string,mixed>> */
    public static function claim(string $worker, int $leaseSeconds, int $limit = 1): array
    {
        $claimed = [];
        for ($index = 0; $index < max(1, min(10, $limit)); $index++) {
            $job = Db::transaction(function () use ($worker, $leaseSeconds) {
                $now = time();
                $row = Db::name(self::TABLE)->where(function ($query) use ($now) {
                    $query->where(function ($pending) use ($now) {
                        $pending->whereIn('status', ['pending', 'retrying'])->where('next_run_time', '<=', $now);
                    })->whereOr(function ($expired) use ($now) {
                        $expired->where('status', 'running')->where('lease_expire_time', '<=', $now);
                    });
                })->whereRaw('attempts < max_attempts')->order(['next_run_time' => 'asc', 'id' => 'asc'])->lock(true)->find();
                if (!$row) {
                    return null;
                }
                $token = $worker . '-' . bin2hex(random_bytes(8));
                Db::name(self::TABLE)->where('id', (int)$row['id'])->update([
                    'status' => 'running', 'attempts' => (int)$row['attempts'] + 1,
                    'lease_token' => $token, 'lease_expire_time' => $now + max(30, $leaseSeconds), 'update_time' => $now,
                ]);
                $row['lease_token'] = $token;
                $row['attempts'] = (int)$row['attempts'] + 1;
                return $row;
            });
            if (!$job) {
                break;
            }
            $claimed[] = $job;
        }
        return $claimed;
    }

    public static function run(array $job): void
    {
        $poster = AigcVideoPosterService::createFirstFrame(
            (int)$job['tenant_id'],
            (string)$job['video_uri'],
            (string)$job['storage_scope'],
            (string)$job['storage_engine'],
            (string)$job['storage_domain']
        );
        self::updateCanvasNodePoster($job, $poster);
        Db::name(self::TABLE)->where([
            'id' => (int)$job['id'], 'lease_token' => (string)$job['lease_token'],
        ])->update([
            'status' => 'success', 'lease_token' => '', 'lease_expire_time' => 0,
            'poster_uri' => (string)$poster['uri'], 'poster_scope' => (string)$poster['storage_scope'],
            'poster_engine' => (string)$poster['storage_engine'], 'poster_domain' => (string)$poster['storage_domain'],
            'last_error' => '', 'finish_time' => time(), 'update_time' => time(),
        ]);
    }

    public static function retry(array $job, \Throwable $error): void
    {
        $attempts = (int)($job['attempts'] ?? 1);
        $terminal = $attempts >= max(1, (int)($job['max_attempts'] ?? self::MAX_ATTEMPTS));
        Db::name(self::TABLE)->where([
            'id' => (int)$job['id'], 'lease_token' => (string)$job['lease_token'],
        ])->update([
            'status' => $terminal ? 'dead' : 'retrying', 'lease_token' => '', 'lease_expire_time' => 0,
            'next_run_time' => $terminal ? 0 : time() + min(300, max(5, 2 ** min(8, $attempts))),
            'last_error' => mb_substr($error->getMessage(), 0, 500), 'finish_time' => $terminal ? time() : 0,
            'update_time' => time(),
        ]);
        if ($terminal) {
            self::markCanvasNodePosterFailed($job);
        }
    }

    public static function recoverExpired(): int
    {
        $expired = Db::name(self::TABLE)->where('status', 'running')->where('lease_expire_time', '<=', time())
            ->whereRaw('attempts >= max_attempts')->select()->toArray();
        foreach ($expired as $job) {
            Db::name(self::TABLE)->where('id', (int)$job['id'])->update([
                'status' => 'dead', 'lease_token' => '', 'lease_expire_time' => 0,
                'last_error' => 'Video poster worker lease expired', 'finish_time' => time(), 'update_time' => time(),
            ]);
            self::markCanvasNodePosterFailed($job);
        }
        return count($expired);
    }

    private static function updateCanvasNodePoster(array $job, array $poster): void
    {
        Db::transaction(function () use ($job, $poster): void {
            $document = Db::name('aigc_short_drama_canvas')->where([
                'id' => (int)$job['canvas_id'], 'tenant_id' => (int)$job['tenant_id'],
                'user_id' => (int)$job['user_id'], 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$document) {
                return;
            }
            $nodes = json_decode((string)$document['nodes_json'], true) ?: [];
            $changed = false;
            foreach ($nodes as &$node) {
                if ((string)($node['id'] ?? '') !== (string)$job['node_id']) {
                    continue;
                }
                $metadata = (array)($node['metadata'] ?? []);
                $currentUri = self::canonicalUri((string)($metadata['video_url'] ?? $metadata['url'] ?? ''));
                if ($currentUri !== (string)$job['video_uri']) {
                    continue;
                }
                $metadata['poster_url'] = FileService::getFileUrlByStorage(
                    (string)$poster['uri'], (string)$poster['storage_scope'],
                    (string)$poster['storage_engine'], (string)$poster['storage_domain']
                );
                $metadata['poster_uri'] = (string)$poster['uri'];
                $metadata['poster_status'] = 'ready';
                $node['metadata'] = $metadata;
                $changed = true;
                break;
            }
            unset($node);
            if ($changed) {
                Db::name('aigc_short_drama_canvas')->where('id', (int)$document['id'])->update([
                    'nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'update_time' => time(),
                ]);
            }
        });
    }

    private static function markCanvasNodePosterFailed(array $job): void
    {
        Db::transaction(function () use ($job): void {
            $document = Db::name('aigc_short_drama_canvas')->where([
                'id' => (int)$job['canvas_id'], 'tenant_id' => (int)$job['tenant_id'],
                'user_id' => (int)$job['user_id'], 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$document) {
                return;
            }
            $nodes = json_decode((string)$document['nodes_json'], true) ?: [];
            $changed = false;
            foreach ($nodes as &$node) {
                if ((string)($node['id'] ?? '') !== (string)$job['node_id']) {
                    continue;
                }
                $metadata = (array)($node['metadata'] ?? []);
                if (self::canonicalUri((string)($metadata['video_url'] ?? $metadata['url'] ?? '')) !== (string)$job['video_uri']) {
                    continue;
                }
                $metadata['poster_status'] = 'failed';
                $node['metadata'] = $metadata;
                $changed = true;
                break;
            }
            unset($node);
            if ($changed) {
                Db::name('aigc_short_drama_canvas')->where('id', (int)$document['id'])->update([
                    'nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'update_time' => time(),
                ]);
            }
        });
    }

    private static function canonicalUri(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^https?://#i', $value) === 1) {
            $value = ltrim(rawurldecode((string)(parse_url($value, PHP_URL_PATH) ?: '')), '/');
        }
        $value = ltrim($value, '/');
        return str_starts_with($value, 'uploads/') || str_starts_with($value, 'resource/') ? $value : '';
    }
}
