<?php

namespace app\common\service\power;

/** Local, operator-verified evidence; never populated from upstream descriptions. */
final class MarketModelCapabilityVerification
{
    public const KEY = 'local_capability_verification';

    public static function identity(array $product): array
    {
        return array_intersect_key($product, array_flip([
            'source_code', 'upstream_resource_key', 'upstream_model_code', 'upstream_channel_code',
        ]));
    }

    public static function vision(array $product): bool
    {
        $snapshot = $product['source_payload'] ?? [];
        if (is_string($snapshot)) $snapshot = json_decode($snapshot, true) ?: [];
        $proof = $snapshot[self::KEY] ?? [];
        $identity = self::identity($product);
        return ($product['resource_type'] ?? '') === 'model'
            && count($identity) === 4 && ($identity['upstream_model_code'] ?? '') !== ''
            && ($proof['identity'] ?? null) === $identity
            && ($proof['method'] ?? '') === 'image_probe'
            && ($proof['supports_vision'] ?? false) === true
            && (int)($proof['verified_at'] ?? 0) > 0
            && (int)($proof['app_task_id'] ?? 0) > 0
            && trim((string)($proof['provider_request_id'] ?? '')) !== '';
    }

    /** Preserve evidence only for the same source/model/channel during catalogue sync. */
    public static function carryForward(array $snapshot, array $old, array $new): array
    {
        unset($snapshot[self::KEY]);
        if (self::vision($old) && self::identity($old) === self::identity($new)) {
            $previous = $old['source_payload'];
            if (is_string($previous)) $previous = json_decode($previous, true) ?: [];
            $snapshot[self::KEY] = $previous[self::KEY];
        }
        return $snapshot;
    }
}
