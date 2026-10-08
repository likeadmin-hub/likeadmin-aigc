<?php

namespace app\common\service\update;

class UpdateProtocolException extends \RuntimeException
{
    public string $errorCode;

    public function __construct(string $errorCode, string $message = '')
    {
        $this->errorCode = $errorCode;
        parent::__construct(($message ?: $errorCode) . ($message ? ' (' . $errorCode . ')' : ''));
    }
}
