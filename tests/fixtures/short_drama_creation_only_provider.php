<?php
namespace app\common\service\power;

/** Task setup may resolve a model, but this fixture forbids paid generation. */
class MarketTextModelRuntimeService
{
    public static function resolveModel(int $tenantId, $selection, bool $vision = false): array
    {
        return ['id'=>'fixture-text', 'name'=>'Fixture text', 'model_code'=>'fixture-text', 'provider'=>'fixture'];
    }

    public static function modelGroups(int $tenantId): array
    {
        return [];
    }

    public static function generate(...$arguments): array
    {
        throw new \LogicException('Task creation must not submit a paid request');
    }
}
