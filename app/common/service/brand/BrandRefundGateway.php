<?php
declare(strict_types=1);
namespace app\common\service\brand;
interface BrandRefundGateway
{
    /** Submission acceptance only; success is established by queryRefund. */
    public function refund(array $payment,array $refund): array;
    /** Null means definitive absence; exceptions mean unknown and must never trigger a new request. */
    public function queryRefund(array $payment,array $refund): ?array;
}
