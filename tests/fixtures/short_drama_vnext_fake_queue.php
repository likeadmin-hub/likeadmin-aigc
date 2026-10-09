<?php
namespace app\common\service\app\aigc_short_drama;
/** Isolate transactional series tests from the shared Redis stream. */
class ShortDramaRedisQueue
{
    public static function enqueue(...$args): ?string { return null; }
    public static function available(): bool { return false; }
    public static function claim(...$args): ?array { return null; }
    public static function release(...$args): void {}
    public static function ack(...$args): void {}
}
