<?php
namespace app\common\service\redeem;

final class RedeemCardPolicy
{
    public const MAX_QUANTITY = 200;
    public static function normalizeCode(string $code): string { return strtoupper(preg_replace('/[\s-]+/', '', trim($code))); }
    public static function hash(string $code): string { return hash('sha256', self::normalizeCode($code)); }
    public static function newCode(): string { return implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 8)); }
    public static function mask(string $code): string { $v=self::normalizeCode($code); return substr($v,0,4).'-****-****-'.substr($v,-4); }
    /** Used by the existing operation-log listener, never by HTTP serialization. */
    public static function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if (in_array((string)$key, ['code', 'code_hash'], true) && is_string($item)) $value[$key] = '[REDACTED]';
            elseif (is_array($item)) $value[$key] = self::redact($item);
        }
        return $value;
    }
    /** Module-local fail-closed check; unknown global menu routes never imply access. */
    public static function allowsAdminAction(array $admin, string $action, mixed $permissions): bool
    {
        $action = strtolower($action);
        if (!in_array($action, ['options', 'batches', 'lists', 'records', 'generate', 'disable'], true)) return false;
        if (($admin['root'] ?? null) === 1) return true;
        if (!is_array($permissions) || !$permissions) return false;
        $expected = strtolower(\think\helper\Str::camel('finance.redeem_card/' . $action));
        foreach ($permissions as $uri) {
            if (is_string($uri) && strtolower(\think\helper\Str::camel($uri)) === $expected) return true;
        }
        return false;
    }
    public static function state(array $card, array $batch, int $now): string
    {
        if (($card['status'] ?? '') === 'used') return 'used';
        if (($card['status'] ?? '') === 'disabled' || ($batch['status'] ?? '') === 'disabled') return 'disabled';
        if ((int)($batch['expire_time'] ?? 0) > 0 && (int)$batch['expire_time'] <= $now) return 'expired';
        return 'unused';
    }
}
