<?php
namespace Tests\Feature;
use app\common\service\power\MarketPriceWriteInput as Input;
use PHPUnit\Framework\TestCase;
final class MarketPriceWriteInputTest extends TestCase
{
    public function testRowsUseDeterministicLockOrderAndShelfDoesNotRequirePrice(): void
    {
        $rows=Input::rows([['sku_id'=>8,'sale_points'=>'2.100000'],['sku_id'=>2,'state_only'=>true]]);
        self::assertSame([2,8],array_column($rows,'sku_id'));
        self::assertSame(2.1,$rows[1]['sale_points']);
    }
    /** @dataProvider invalid */
    public function testBadPricesAndDuplicateSkusAreRejected(array $rows): void
    { $this->expectException(\RuntimeException::class);Input::rows($rows); }
    public static function invalid():array
    { return [[[['sku_id'=>1,'sale_points'=>-1]]],[[['sku_id'=>1,'sale_points'=>'NaN']]],[[['sku_id'=>1,'sale_points'=>INF]]],[[['sku_id'=>1,'sale_points'=>[]]]],[[['sku_id'=>1,'sale_points'=>2],['sku_id'=>1,'sale_points'=>3]]]]; }
}
