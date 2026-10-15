<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

/** Frozen per run: historical authorizations keep their original finite limits. */
final class DecorationAgentExecutionPolicy
{
    public const UNLIMITED = 'unlimited_v1';

    public static function unlimited(array $input): bool
    {
        return ($input['execution_policy']??null)===self::UNLIMITED;
    }

    public static function allowsModel(array $input,int $used): bool
    {
        return self::unlimited($input)||$used<6;
    }

    public static function allowsImage(array $input,int $used): bool
    {
        return self::unlimited($input)||$used<5;
    }

    public static function allowsCost(array $input,float $spent,float $cost): bool
    {
        return self::unlimited($input)||$spent+$cost<=(float)($input['budget_points']??0);
    }

    public static function remaining(array $input,float $spent): ?float
    {
        return self::unlimited($input)?null:max(0.0,(float)($input['budget_points']??0)-$spent);
    }
}
