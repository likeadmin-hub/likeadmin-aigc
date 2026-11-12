<?php

namespace Tests\Feature;

use app\common\service\power\MarketOtherModelCategory;
use app\common\service\power\PowerMarketService;
use PHPUnit\Framework\TestCase;

class MarketOtherModelCategoryTest extends TestCase
{
    public function testEmptyOptionalTokenRatesDoNotRejectSecondOrImagePricedSpecifications(): void
    {
        $method = new \ReflectionMethod(PowerMarketService::class, 'skuSnapshot');
        $method->setAccessible(true);
        foreach (['input_second','output_second','image','per_call','business_unit'] as $unit) {
            $snapshot = $method->invoke(null, ['sku_key'=>'native','usage_unit'=>$unit,'effective_rate_config'=>[]]);
            self::assertSame($unit, $snapshot['usage_unit']);
            self::assertArrayNotHasKey('effective_rate_config', $snapshot);
        }
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke(null, ['usage_unit'=>'token','usage_unit_size'=>1000,'effective_rate_config'=>[]]);
    }

    public function testUsesPublishedGroupsAndModelBindingsInsteadOfNamesOrLocalIds(): void
    {
        $tree = [
            ['slug'=>'video','children'=>[['slug'=>'model-motion','ai_model_id'=>8]]],
            ['slug'=>'other','children'=>[
                ['slug'=>'image-tools','title'=>'图像处理','children'=>[['slug'=>'model-image','ai_model_id'=>19]]],
                ['slug'=>'video-tools','title'=>'视频处理','children'=>[
                    ['slug'=>'motion','title'=>'动作控制','children'=>[['slug'=>'model-kling','ai_model_id'=>8]]],
                    ['slug'=>'app','ai_app_api_id'=>4,'children'=>[['ai_model_id'=>99]]],
                ]],
                ['slug'=>'unfiled-model','title'=>'名字带视频也不能推断分类','ai_model_id'=>28],
            ]],
        ];
        $index = MarketOtherModelCategory::index($tree);
        self::assertTrue($index['available']);
        self::assertSame('image-tools', $index['models'][19]['code']);
        self::assertSame('video-tools/motion', $index['models'][8]['code']);
        self::assertSame('视频处理 / 动作控制', $index['models'][8]['name']);
        self::assertSame([], $index['models'][28]);
        self::assertArrayNotHasKey(99, $index['models']);
        self::assertFalse(MarketOtherModelCategory::index([])['available']);
        self::assertTrue(MarketOtherModelCategory::index([['slug'=>'other','children'=>[]]])['available']);
    }

    public function testOptionsDeduplicateAcrossAllPagesAndKeepUnclassifiedLast(): void
    {
        $row = static fn($category) => ['source_payload'=>['market_metadata'=>['other_category'=>$category]]];
        $image = ['code'=>'image-tools','name'=>'图像处理','path'=>[['order'=>0]]];
        $video = ['code'=>'video-tools','name'=>'视频处理','path'=>[['order'=>1]]];
        $options = MarketOtherModelCategory::options([$row([]),$row($video),$row($image),$row($video)]);
        self::assertSame(['image-tools','video-tools','uncategorized'], array_column($options,'code'));
        self::assertSame('未分类', $options[2]['name']);
    }
}
