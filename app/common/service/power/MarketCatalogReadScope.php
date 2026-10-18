<?php

namespace app\common\service\power;

/** A snapshot only while assembling a display catalogue, owned by this request. */
final class MarketCatalogReadScope
{
    private array $values = [];

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
}
