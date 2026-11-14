<?php

namespace app\common\service\power;

/** A snapshot only while assembling a display catalogue, owned by this request. */
final class MarketCatalogReadScope
{
    private array $values = [];
    private array $derived = [];

    public static function run(callable $read): array
    {
        $request = request();
        if ($request->marketCatalogReadScope instanceof self) return $read();
        $request->marketCatalogReadScope = new self();
        try {
            return $read();
        } finally {
            // Admission, quotes, billing and the next request always read fresh data.
            $request->marketCatalogReadScope = null;
        }
    }

    public static function remember(string $key, callable $read): array
    {
        $scope = request()->marketCatalogReadScope;
        if (!$scope instanceof self) return $read();
        if (!array_key_exists($key, $scope->values)) $scope->values[$key] = $read();
        return $scope->values[$key];
    }
    public static function forget(string $key): void
    {
        $scope = request()->marketCatalogReadScope;
        if ($scope instanceof self) unset($scope->values[$key]);
    }

    /** Reuse a pure derivation while its complete inputs remain unchanged.
     * Keep the latest complete input per model/API and operation. Discovery often
     * revisits models after rendering another family; avoid fingerprinting them again.
     */
    public static function derive(string $key, array $input, callable $read): array
    {
        // Pure contract readers also run without a bootstrapped HTTP application.
        if (!function_exists('request')) return $read();
        $scope = request()->marketCatalogReadScope;
        if (!$scope instanceof self) return $read();
        // Identity only chooses a slot. The entire input still has to match,
        // including schema changes under the same upstream model/API identity.
        $upstream = is_array($input['upstream_metadata'] ?? null) ? $input['upstream_metadata'] : [];
        $identity = json_encode([
            $upstream['model_id'] ?? '', $upstream['model_code'] ?? '', $upstream['channel_code'] ?? '',
            $input['upstream_app_metadata']['code'] ?? '', $input['upstream_api_metadata']['id'] ?? '',
            $input['upstream_api_metadata']['code'] ?? '',
        ], JSON_THROW_ON_ERROR);
        $previous = $scope->derived[$key][$identity] ?? null;
        if ($previous !== null && $previous['input'] === $input) return $previous['value'];
        $value = $read();
        if (!isset($scope->derived[$key][$identity]) && count($scope->derived[$key] ?? []) >= 128) array_shift($scope->derived[$key]);
        $scope->derived[$key][$identity] = ['input' => $input, 'value' => $value];
        return $value;
    }

}
