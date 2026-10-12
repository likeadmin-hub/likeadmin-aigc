<?php
namespace app\common\service\open_platform;
final class OpenException extends \RuntimeException
{
    public function __construct(public readonly string $errorType, string $message, public readonly int $httpStatus = 400) { parent::__construct($message); }
}
