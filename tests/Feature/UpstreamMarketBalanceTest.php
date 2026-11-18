<?php

namespace Tests\Feature;

use app\common\service\app\UpstreamPricingService;
use PHPUnit\Framework\TestCase;

final class UpstreamMarketBalanceTest extends TestCase
{
    public function testBalanceUsesTheActiveChannelAndKeepsZeroAndNegativeValues(): void
    {
        foreach (['1234.125' => 1234.125, 0 => 0.0, '-0.25' => -0.25] as $value => $expected) {
            $balance = $this->readBalance(['available_points' => $value, 'api_key' => 'private'], true);
            self::assertSame('available', $balance['status']);
            self::assertSame($expected, $balance['available_points']);
            self::assertSame('https://api.example.test', $balance['origin']);
            self::assertSame('development', $balance['environment']);
            self::assertGreaterThan(0, $balance['checked_at']);
            self::assertArrayNotHasKey('api_key', $balance);
        }
    }

    public function testInvalidBalanceCannotBePresentedAsZero(): void
    {
        foreach ([[], ['available_points' => false], ['available_points' => '1e999'],
            new \RuntimeException('upstream-rejected-private-key')] as $response) {
            $balance = $this->readBalance($response, true);
            self::assertSame('unavailable', $balance['status']);
            self::assertNull($balance['available_points']);
            self::assertNull($balance['checked_at']);
            self::assertStringNotContainsString('private-key', json_encode($balance));
        }
    }

    public function testMissingChannelConfigurationIsReportedBeforeTheRead(): void
    {
        $balance = $this->readBalance(['available_points' => 20], false);
        self::assertSame('unconfigured', $balance['status']);
        self::assertSame('online', $balance['environment']);
        self::assertNull($balance['available_points']);
        self::assertNull($balance['checked_at']);
    }

    private function readBalance(array|\Throwable $response, bool $configured): array
    {
        (new \think\App())->initialize();
        return UpstreamPricingService::withCatalogBatch(static function () use ($response, $configured): array {
            $state = request()->upstreamCatalogBatch;
            $state['source'] = [
                'active_base_url' => 'https://api.example.test/base',
                'active_api_key' => $configured ? 'fixture-key' : '',
                'dev_mode' => $configured ? 1 : 0,
            ];
            $key = hash('sha256', json_encode(['GET', '/api/v1/user/balance', []], JSON_THROW_ON_ERROR));
            $state['responses'][$key] = $response;
            request()->upstreamCatalogBatch = $state;
            return UpstreamPricingService::queryAccountBalance();
        });
    }
}
