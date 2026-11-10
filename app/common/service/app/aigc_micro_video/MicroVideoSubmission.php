<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\ai\MarketVideoSubmissionService as Shared;
/** Compatibility facade for existing micro-video and product-video callers. */
final class MicroVideoSubmission
{
    public static function applies(array $c): bool { return Shared::applies($c); }
    public static function ambiguous(string $m): bool { return Shared::ambiguous($m); }
    public static function begin(int $id): bool { return Shared::begin($id); }
    public static function capture(int $id,array $r,string $task,string $request): void { Shared::capture($id,$r,$task,$request); }
    public static function hold(int $id,string $m): bool { return Shared::hold($id,$m); }
    public static function receipt(array $c): ?array { return Shared::receipt($c); }
    public static function pending(array $c): bool { return Shared::pending($c); }
}
