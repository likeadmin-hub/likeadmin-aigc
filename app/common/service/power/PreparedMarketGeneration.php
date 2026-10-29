<?php

namespace app\common\service\power;

/** Internal, immutable preparation result. Never accepts client-supplied pricing. */
final class PreparedMarketGeneration
{
    private function __construct(
        public readonly array $nativeInput,
        public readonly ?array $billingMeter,
        public readonly array $meterContext
    ) {}

    public static function model(array $payload, ?array $billingMeter = null): self
    {
        $native = MarketModelTaskRequest::payload($payload);
        return new self($native, $billingMeter, $billingMeter === null ? [] : MarketGenerationMeter::context($billingMeter, $native));
    }

    public static function application(array $payload, ?array $billingMeter = null): self
    {
        return new self($payload, $billingMeter, $billingMeter === null ? [] : MarketGenerationMeter::context($billingMeter, $payload));
    }

    /** Supplier uploads replace URLs with asset IDs without changing reserved metering. */
    public function withApplicationInput(array $payload): self
    {
        return new self($payload, $this->billingMeter, $this->meterContext);
    }
}
