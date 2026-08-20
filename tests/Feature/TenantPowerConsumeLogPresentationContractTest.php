<?php

namespace Tests\Feature;

use app\tenantapi\lists\power\TenantPowerConsumeLogLists;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TenantPowerConsumeLogPresentationContractTest extends TestCase
{
    public function testConsumeTimeSupportsOrmFormattedDateStrings(): void
    {
        self::assertSame('2026-08-18 10:11:40', $this->formatTime('2026-08-18 10:11:40'));
    }

    public function testConsumeTimeSupportsUnixTimestampsAndDateObjects(): void
    {
        $timestamp = strtotime('2026-08-18 10:11:40');

        self::assertSame('2026-08-18 10:11:40', $this->formatTime($timestamp));
        self::assertSame('2026-08-18 10:11:40', $this->formatTime(new DateTimeImmutable('2026-08-18 10:11:40')));
        self::assertSame('-', $this->formatTime(null));
    }

    public function testAdminBundleShowsAContinuousPagedSequenceColumn(): void
    {
        $bundle = (string)file_get_contents(dirname(__DIR__, 2) . '/public/admin/assets/consume_logs-CJUqGNzb.js');

        self::assertStringContainsString('label:"序号",type:"index"', $bundle);
        self::assertStringContainsString('(Number(t(b).page)-1)*Number(t(b).size)+e+1', $bundle);
    }

    private function formatTime($value): string
    {
        $method = new ReflectionMethod(TenantPowerConsumeLogLists::class, 'formatTime');
        $method->setAccessible(true);

        return $method->invoke(null, $value);
    }
}
