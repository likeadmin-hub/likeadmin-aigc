<?php
namespace app\common\service\redeem;

final class RedeemCardException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message) { parent::__construct($message); }
}
