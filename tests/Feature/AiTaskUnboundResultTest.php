<?php

namespace Tests\Feature;

use app\common\service\ai\AiTaskBusinessResultService;
use app\common\service\ai\AiTaskJobService;
use PHPUnit\Framework\TestCase;

class AiTaskUnboundResultTest extends TestCase
{
    private function invoke(string $class, string $method, ...$args)
    {
        $reflection = new \ReflectionMethod($class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$args);
    }

    public function testOnlyRefundedUnboundFailuresAreFinished(): void
    {
        foreach (['failed', 'canceled', 'cancelled', 'success', 'running', 'pending'] as $run) {
            foreach (['refunded', 'settled', 'reserved', 'pending_usage'] as $billing) {
                foreach ([0, 42] as $businessId) {
                    $context = [
                        'consumption' => ['app_task_id' => 7, 'run_status' => $run, 'billing_status' => $billing],
                        'business_table' => 'aigc_short_drama_script_task', 'business_id' => $businessId,
                    ];
                    $expected = in_array($run, ['failed', 'canceled', 'cancelled'], true)
                        && $billing === 'refunded' && $businessId === 0;
                    self::assertSame($expected, $this->invoke(AiTaskBusinessResultService::class,
                        'refundedWithoutBusinessTarget', $context), "$run/$billing/$businessId");
                }
            }
        }
    }

    public function testMissingLinksAndUnknownAdaptersAreNotSilentlyCompleted(): void
    {
        foreach ([[0, 'aigc_short_drama_script_task'], [7, ''], [7, 'unknown_task']] as [$appTask, $table]) {
            self::assertFalse($this->invoke(AiTaskBusinessResultService::class, 'refundedWithoutBusinessTarget', [
                'consumption' => ['app_task_id' => $appTask, 'run_status' => 'failed', 'billing_status' => 'refunded'],
                'business_table' => $table, 'business_id' => 0,
            ]));
        }
    }

    public function testBackoffBoundsForQueryAndBusinessResultWaits(): void
    {
        foreach (['query_result', 'process_result'] as $type) {
            foreach ([1 => 5, 10 => 5, 11 => 10, 100 => 50, 14825 => 60] as $attempt => $delay) {
                self::assertSame($delay, $this->invoke(AiTaskJobService::class, 'rescheduleDelay',
                    ['job_type' => $type, 'attempts' => $attempt], 5));
            }
        }
        foreach (['transfer_result', 'settle', 'refund', 'admin_action'] as $type) {
            self::assertSame(5, $this->invoke(AiTaskJobService::class, 'rescheduleDelay',
                ['job_type' => $type, 'attempts' => 14825], 5));
        }
    }
}
