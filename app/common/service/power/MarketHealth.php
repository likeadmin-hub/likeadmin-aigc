<?php
declare(strict_types=1);

namespace app\common\service\power;

use think\facade\Cache;
use think\facade\Db;
use Throwable;

final class MarketHealth
{
    private const DAY = 86400;
    private const CACHE_KEY = 'market_health_24h_v1';
    private const CACHE_SECONDS = 30;
    private const EMPTY = ['calls' => 0, 'successes' => 0, 'failures' => 0];

    /** The consumption record represents one marketplace invocation, including any internal retries. */
    public static function aggregate(?int $now = null): array
    {
        $now ??= time();
        $rows = Db::name('ai_consumption_log')
            ->whereBetween('create_time', [$now - self::DAY, $now])
            ->where('product_id', '>', 0)
            ->fieldRaw("product_id, COUNT(*) AS calls, SUM(CASE WHEN run_status = 'success' THEN 1 ELSE 0 END) AS successes, SUM(CASE WHEN run_status = 'failed' THEN 1 ELSE 0 END) AS failures")
            ->group('product_id')->select()->toArray();
        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[(int)$row['product_id']] = [
                'calls' => (int)$row['calls'],
                'successes' => (int)$row['successes'],
                'failures' => (int)$row['failures'],
            ];
        }
        return $snapshot;
    }

    public static function snapshot(): array
    {
        if (Db::connect()->getPdo()->inTransaction()) {
            return self::aggregate();
        }
        try {
            $snapshot = Cache::get(self::CACHE_KEY);
            if (is_array($snapshot)) {
                return $snapshot;
            }
        } catch (Throwable) {
            // A cache failure does not prevent reading the aggregate.
        }
        $snapshot = self::aggregate();
        try {
            Cache::set(self::CACHE_KEY, $snapshot, self::CACHE_SECONDS);
        } catch (Throwable) {
        }
        return $snapshot;
    }

    public static function attachProducts(array $rows, ?array $snapshot = null): array
    {
        if ($rows === []) {
            return [];
        }
        $snapshot ??= self::snapshot();
        foreach ($rows as &$row) {
            $row['health_24h'] = self::format($snapshot[(int)$row['id']] ?? self::EMPTY);
        }
        unset($row);
        return $rows;
    }

    public static function attachGroups(array $groups, array $products): array
    {
        if ($groups === []) {
            return [];
        }
        $snapshot = self::snapshot();
        $byApp = [];
        foreach ($products as $product) {
            $app = trim((string)$product['upstream_app_code']);
            $counts = $snapshot[(int)$product['id']] ?? self::EMPTY;
            foreach ($counts as $kind => $count) {
                $byApp[$app][$kind] = ($byApp[$app][$kind] ?? 0) + $count;
            }
        }
        foreach ($groups as &$group) {
            $group['health_24h'] = self::format($byApp[$group['app_code']] ?? self::EMPTY);
        }
        unset($group);
        return $groups;
    }

    private static function format(array $counts): array
    {
        $decided = $counts['successes'] + $counts['failures'];
        return $counts + [
            'excluded' => $counts['calls'] - $decided,
            'rate' => $decided > 0 ? round(100 * $counts['successes'] / $decided, 2) : ($counts['calls'] === 0 ? 100.0 : null),
        ];
    }

    public static function direction(mixed $order): string
    {
        return in_array($order, ['asc', 'desc'], true) ? $order : '';
    }

    public static function sort(array $rows, string $order): array
    {
        if (self::direction($order) === '') {
            return $rows;
        }
        usort($rows, static function (array $left, array $right) use ($order): int {
            $a = $left['health_24h'];
            $b = $right['health_24h'];
            $aDecided = $a['successes'] + $a['failures'];
            $bDecided = $b['successes'] + $b['failures'];
            $aRank = $aDecided > 0 ? 0 : ($a['calls'] > 0 ? 1 : 2);
            $bRank = $bDecided > 0 ? 0 : ($b['calls'] > 0 ? 1 : 2);
            if ($aRank !== $bRank || $aRank !== 0) {
                return $aRank <=> $bRank;
            }
            $comparison = ($a['successes'] * $bDecided) <=> ($b['successes'] * $aDecided);
            return $order === 'asc' ? $comparison : -$comparison;
        });
        return $rows;
    }
}
