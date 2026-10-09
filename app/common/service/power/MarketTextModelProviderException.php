<?php
namespace app\common\service\power;

/** Keeps routing metadata separate from the sanitized user-facing message. */
class MarketTextModelProviderException extends \Exception
{
    public bool $allowsModelFallback;

    public function __construct(string $message, bool $allowsModelFallback)
    {
        parent::__construct($message);
        $this->allowsModelFallback = $allowsModelFallback;
    }
}
