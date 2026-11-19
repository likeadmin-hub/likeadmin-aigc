<?php
namespace app\common\service\power;

/** Keeps routing metadata separate from the sanitized user-facing message. */
class MarketTextModelProviderException extends \Exception
{
    public bool $allowsModelFallback;

    public function __construct(
        string $message,
        bool $allowsModelFallback,
        public readonly ?array $partialResult = null,
        public readonly array $transportInfo = [],
        public readonly string $protocolCode = '',
        public readonly bool $gatewayRefunded = false,
    )
    {
        parent::__construct($message);
        $this->allowsModelFallback = $allowsModelFallback;
    }
    /** Missing transfer evidence must never permit a paid replay. */
    public function requestNotSent(): bool
    {
        if ($this->partialResult !== null || !in_array($this->transportInfo['errno'] ?? 0, [6,7,28,35], true)) return false;
        foreach (['request_size','size_upload','size_download','pretransfer_time','http_code','header_size'] as $key) {
            if (!isset($this->transportInfo[$key]) || !is_numeric($this->transportInfo[$key]) || (float)$this->transportInfo[$key] !== 0.0) return false;
        }
        return true;
    }
}
