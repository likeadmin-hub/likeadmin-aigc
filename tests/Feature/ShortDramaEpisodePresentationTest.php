<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService as Episodes;
use PHPUnit\Framework\TestCase;

class ShortDramaEpisodePresentationTest extends TestCase
{
    public function testCompletedEpisodeRemainsSuccessfulWhenItsSeriesWasPaused(): void
    {
        $summary = Episodes::summary([
            'id' => 1,
            'status' => 'canceled',
            'completed_once' => 1,
            'error' => '本集已完成，后续生成已暂停，可点击继续队列',
            'outline_json' => '{}',
            'result_json' => '{}',
        ]);

        self::assertTrue($summary['ready']);
        self::assertSame('success', $summary['status']);
        self::assertSame('canceled', $summary['queue_state']);
        self::assertSame('', $summary['error']);
        self::assertFalse($summary['can_retry']);
    }

    public function testCanceledEpisodeWithoutAResultStillRequiresRegeneration(): void
    {
        $summary = Episodes::summary([
            'id' => 2,
            'status' => 'canceled',
            'completed_once' => 0,
            'error' => '已取消自动生成，可按需单独重新生成',
        ]);

        self::assertFalse($summary['ready']);
        self::assertSame('canceled', $summary['status']);
        self::assertSame('canceled', $summary['queue_state']);
        self::assertTrue($summary['can_retry']);
    }
}
