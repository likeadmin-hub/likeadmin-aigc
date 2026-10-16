<?php
namespace Tests\Feature;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use PHPUnit\Framework\TestCase;

class CanvasProjectPagesTest extends TestCase
{
    private function normalize(array $value): array
    {
        $method = new \ReflectionMethod(AigcCanvasService::class, 'normalizeViewport');
        $method->setAccessible(true);
        return $method->invoke(null, $value);
    }

    public function testSingleCanvasViewportRemainsCompatible(): void
    {
        self::assertSame(['x' => 12.0, 'y' => 34.0, 'zoom' => 0.75], $this->normalize(['x' => 12, 'y' => 34, 'k' => 0.75]));
    }

    public function testPageMembershipNamesAndIndependentViewportsSurviveRoundTrip(): void
    {
        $viewport = $this->normalize(['currentCanvasId' => 'second', 'pages' => [
            ['id' => 'first', 'name' => '原始画布', 'nodeIds' => ['a', 'b', 'a'], 'viewport' => ['x' => 10, 'y' => 20, 'k' => 0.5]],
            ['id' => 'second', 'name' => '空白画布', 'nodeIds' => [], 'viewport' => ['x' => 30, 'y' => 40, 'k' => 2]],
        ]]);
        self::assertSame('second', $viewport['currentCanvasId']);
        self::assertSame(['a', 'b'], $viewport['pages'][0]['nodeIds']);
        self::assertSame([], $viewport['pages'][1]['nodeIds']);
        self::assertSame('空白画布', $viewport['pages'][1]['name']);
        self::assertSame(2.0, $viewport['pages'][1]['viewport']['k']);
        self::assertSame($viewport, $this->normalize($viewport));
    }

    public function testInvalidActivePageFallsBackWithoutDroppingPages(): void
    {
        $viewport = $this->normalize(['currentCanvasId' => 'missing', 'pages' => [['id' => 'a'], ['id' => 'b']]]);
        self::assertSame('a', $viewport['currentCanvasId']);
        self::assertCount(2, $viewport['pages']);
    }

    public function testDuplicatePageIdsAreRejectedInsteadOfOverwritingData(): void
    {
        $this->expectException(\Exception::class);
        $this->normalize(['pages' => [['id' => 'a'], ['id' => 'a']]]);
    }
}
