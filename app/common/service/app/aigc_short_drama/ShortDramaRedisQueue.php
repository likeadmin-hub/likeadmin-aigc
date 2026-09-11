<?php

namespace app\common\service\app\aigc_short_drama;

/** Small Redis Streams adapter. MySQL remains the source of truth. */
class ShortDramaRedisQueue
{
    private const STREAM = 'short_drama:jobs';
    private const GROUP = 'short_drama_workers';
    private static ?\Redis $redis = null;

    private static function connection(): ?\Redis
    {
        if (self::$redis) return self::$redis;
        if (!class_exists('Redis')) return null;
        $redis = new \Redis();
        $host = (string)env('short_drama.redis_host', env('cache.host', 'like-redis'));
        $port = (int)env('short_drama.redis_port', env('cache.port', 6379));
        try {
            if (!$redis->connect($host, $port, 2.0)) return null;
            $password = (string)env('short_drama.redis_password', env('cache.password', ''));
            if ($password !== '') $redis->auth($password);
            $prefix = (string)env('short_drama.redis_prefix', 'la:');
            $redis->setOption(\Redis::OPT_PREFIX, $prefix);
            // Local all-in-one deployments expose Redis on loopback while the
            // shared cache default may still say like-redis.
            try { $redis->ping(); } catch (\Throwable $e) {
                $redis->close();
                if (!$redis->connect('127.0.0.1', $port, 2.0)) return null;
                if ($password !== '') $redis->auth($password);
                $redis->setOption(\Redis::OPT_PREFIX, $prefix);
            }
            self::$redis = $redis;
            return $redis;
        } catch (\Throwable $e) { return null; }
    }

    public static function available(): bool { return self::connection() !== null; }

    private static function ensureGroup(\Redis $redis): void
    {
        try { $redis->xGroup('CREATE', self::STREAM, self::GROUP, '0', true); } catch (\Throwable $e) {
            if (!str_contains(strtolower($e->getMessage()), 'busygroup')) throw $e;
        }
    }

    public static function enqueue(int $tenantId, int $projectId, int $episodeId, int $attempt = 0): ?string
    {
        $redis = self::connection(); if (!$redis) return null;
        try {
            self::ensureGroup($redis);
            return (string)$redis->xAdd(self::STREAM, '*', ['tenant_id' => $tenantId, 'project_id' => $projectId, 'episode_id' => $episodeId, 'attempt' => $attempt]);
        } catch (\Throwable $e) { return null; }
    }

    public static function claim(string $consumer, int $blockMs = 1000): ?array
    {
        $redis = self::connection(); if (!$redis) return null;
        try {
            self::ensureGroup($redis);
            // Redis BLOCK 0 means wait forever; workers use a short poll so the
            // MySQL recovery scan remains available when Redis is empty.
            $rows = $redis->xReadGroup(self::GROUP, $consumer, [self::STREAM => '>'], 1, max(1, $blockMs));
            if (!$rows || empty($rows[self::STREAM])) return null;
            $item = reset($rows[self::STREAM]);
            return ['id' => (string)key($rows[self::STREAM]), 'data' => $item];
        } catch (\Throwable $e) { return null; }
    }

    public static function ack(string $id): void
    { $redis = self::connection(); if ($redis && $id !== '') try { $redis->xAck(self::STREAM, self::GROUP, [$id]); } catch (\Throwable $e) {} }

    public static function lease(int $episodeId, string $token, int $seconds = 180): bool
    { $redis = self::connection(); if (!$redis) return true; try { return (bool)$redis->set('short_drama:lease:' . $episodeId, $token, ['nx', 'ex' => $seconds]); } catch (\Throwable $e) { self::$redis = null; return true; } }

    public static function renew(int $episodeId, string $token, int $seconds = 180): bool
    { $redis = self::connection(); if (!$redis) return true; try { return (bool)$redis->set('short_drama:lease:' . $episodeId, $token, ['xx', 'ex' => $seconds]); } catch (\Throwable $e) { self::$redis = null; return true; } }

    public static function release(int $episodeId, string $token): void
    { $redis = self::connection(); if ($redis) { $key = 'short_drama:lease:' . $episodeId; if ($redis->get($key) === $token) $redis->del($key); } }
}
