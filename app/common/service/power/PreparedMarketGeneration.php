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
}
