<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ResourceBudget as Budget;
use app\common\service\power\MarketTextModelRuntimeService as Market;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextResourceBudgetTest extends TestCase
{
    private function amount(int $tokens = 100, float $tenant = 1, float $user = 2): array
    { return ['total_tokens' => $tokens, 'tenant_cost_points' => $tenant, 'user_charge_points' => $user]; }
    private function settled(): array
    { return ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'user_points' => 99999], 'billing' => ['billing_status' => 'settled', 'tenant_cost_points' => .1, 'user_charge_points' => .2]]; }
    public function testActualSettlementReleasesOnlyUnusedHoldAndUsesTrustedBilling(): void
    {
        $state = Budget::admit(Budget::create($this->amount()), 'a', $this->amount(), '');
        $state = Budget::receive($state, 'a', $this->settled());
        self::assertSame(['total_tokens' => 30, 'tenant_cost_points' => 100000, 'user_charge_points' => 200000], $state['spent']);
        self::assertSame([], $state['holds']);
        $state = Budget::admit($state, 'b', $this->amount(70, .9, 1.8), '');
        self::assertArrayHasKey('b', $state['holds']);
        $this->expectExceptionCode(429); Budget::admit($state, 'c', $this->amount(1, 0, 0), '');
    }
    public function testEachLimitRejectsBeforeAdmittingAnotherCall(): void
    {
        foreach ([$this->amount(101, 1, 2), $this->amount(100, 1.000001, 2), $this->amount(100, 1, 2.000001)] as $quote) {
            try { Budget::admit(Budget::create($this->amount()), 'a', $quote, ''); self::fail('Limit bypassed'); }
            catch (\RuntimeException $e) { self::assertSame(429, $e->getCode()); }
        }
    }
    public function testWholeRepairRoundProtectsLaterReviewFromOtherCalls(): void
    {
        $state = Budget::admit(Budget::create($this->amount(330, 3.1, 6.2)), 'a', $this->amount(), '');
        $state = Budget::receive($state, 'a', $this->settled());
        $state = Budget::reserveRound($state, 'repair', 3);
        self::assertSame($state, Budget::reserveRound($state, 'repair', 3));
        try { Budget::admit($state, 'format', $this->amount(1, 0, 0), ''); self::fail('Repair budget stolen'); }
        catch (\RuntimeException $e) { self::assertSame(429, $e->getCode()); }
        $state = Budget::admit($state, 'patch', $this->amount(), 'repair');
        self::assertSame(2, $state['rounds']['repair']['remaining']);
        try { Budget::admit($state, 'shots', $this->amount(100, 1, 2.000001), 'repair'); self::fail('Price increase exceeded cap'); }
        catch (\RuntimeException $e) { self::assertSame(429, $e->getCode()); }
    }
    public function testInsufficientFullRoundDoesNotPartiallyReserve(): void
    {
        $state = Budget::admit(Budget::create($this->amount(329, 9, 9)), 'a', $this->amount(), '');
        $state = Budget::receive($state, 'a', $this->settled());
        $this->expectExceptionCode(429); Budget::reserveRound($state, 'repair', 3);
    }
    public function testMissingUsageAndUnsettledBillsKeepQuotaOccupied(): void
    {
        foreach ([[], ['usage' => ['total_tokens' => 30]], array_replace($this->settled(), ['usage' => []])] as $receipt) {
            $state = Budget::admit(Budget::create($this->amount()), 'a', $this->amount(), '');
            $state = Budget::receive($state, 'a', $receipt);
            self::assertSame('resource_usage_unverified', $state['blocked']); self::assertCount(1, $state['holds']);
            try { Budget::assertVerified($state); self::fail('Unknown usage accepted'); }
            catch (\RuntimeException $e) { self::assertSame(429, $e->getCode()); }
        }
    }
    public function testProviderBoundViolationRecordsActualUsageAndStopsFurtherWork(): void
    {
        $state = Budget::admit(Budget::create($this->amount(1000, 10, 20)), 'a', $this->amount(), '');
        $receipt = $this->settled(); $receipt['usage']['total_tokens'] = 101;
        $state = Budget::receive($state, 'a', $receipt);
        self::assertSame(101, $state['spent']['total_tokens']); self::assertSame('resource_bound_exceeded', $state['blocked']);
        $this->expectExceptionCode(429); Budget::admit($state, 'b', $this->amount(), '');
    }
    public function testMalformedConfigurationCannotBecomeUnlimited(): void
    {
        foreach ([[], $this->amount(-1), ['total_tokens' => 'bad', 'tenant_cost_points' => 1, 'user_charge_points' => 2], $this->amount(0)] as $limits) {
            try { Budget::create($limits); self::fail('Invalid configuration accepted'); }
            catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        }
    }
    public function testOnlyKnownUnsubmittedAttemptReleasesItsOwnHold(): void
    {
        $state = Budget::admit(Budget::create($this->amount(200, 2, 4)), 'a', $this->amount(), '');
        $state = Budget::admit($state, 'b', $this->amount(), '');
        $state = Budget::releaseUnsubmitted($state, 'b');
        self::assertSame(['a'], array_keys($state['holds'])); self::assertSame(0, $state['spent']['total_tokens']);
    }
    public function testPublishedCapacityAndCurrentPriceBoundDoesNotUsePromptGuess(): void
    {
        $method = new \ReflectionMethod(Market::class, 'resourceUpperBound'); $method->setAccessible(true);
        $sku = ['platform_price' => 2, 'tenant_price' => 3, 'usage_unit_size' => 1000];
        $model = ['context_window' => 10000, 'max_input_tokens' => 8000, 'max_output_tokens' => 2000, 'input' => $sku, 'output' => $sku];
        $bound = $method->invoke(null, $model);
        self::assertSame(10000, $bound['total_tokens']); self::assertSame(20.0, $bound['tenant_cost_points']); self::assertSame(30.0, $bound['user_charge_points']);
        unset($model['max_input_tokens']); self::assertSame(12000, $method->invoke(null, $model)['total_tokens']);
        unset($model['context_window']);
        $this->expectExceptionCode(422); $method->invoke(null, $model);
    }
}
