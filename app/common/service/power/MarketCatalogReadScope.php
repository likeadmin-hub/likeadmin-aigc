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
    /** Reuse a pure derivation while its complete inputs remain unchanged.
     * Keep only the latest input per operation; avoid repeatedly serializing large schemas.
     */
    public static function derive(string $key, array $input, callable $read): array
    {
        // Pure contract readers also run without a bootstrapped HTTP application.
        if (!function_exists('request')) return $read();
        $scope = request()->marketCatalogReadScope;
        if (!$scope instanceof self) return $read();
        $previous = $scope->derived[$key] ?? null;
        if ($previous !== null && $previous['input'] === $input) return $previous['value'];
        $value = $read();
        $scope->derived[$key] = ['input' => $input, 'value' => $value];
        return $value;
    }

}
