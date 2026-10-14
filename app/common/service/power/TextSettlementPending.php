<?php
declare(strict_types=1);
namespace app\common\service\power;

/** Provider output exists. Do not retry generation or classify this as a provider refund. */
final class TextSettlementPending extends \RuntimeException
{
    public function __construct(public readonly int $consumptionId,public readonly array $result,public readonly array $usage,\Throwable $previous)
    {
        parent::__construct('回复已保留，费用结算待核对，请勿重复提交',0,$previous);
    }
}
