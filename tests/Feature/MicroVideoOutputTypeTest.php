<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchPolicy as P;
final class MicroVideoOutputTypeTest extends TestCase
{
    public function testExplicitSelectionWinsOverKeywordInference(): void
    {
        foreach (['story','commerce','product','property','brand','knowledge','lifestyle','food','travel','local','非遗文化宣传'] as $type) {
            $r=P::brief(['goal'=>'房产产品介绍','output_type'=>$type]);
            self::assertSame($type,$r['brief']['output_type']);
            self::assertSame($type,$r['suggested_workflow']);
        }
    }
    public function testLegacyAliasesAndAbsentSelectionRemainUsable(): void
    {
        self::assertSame('property',P::brief(['goal'=>'介绍故事','output_type'=>'estate'])['brief']['output_type']);
        self::assertSame('commerce',P::brief(['goal'=>'介绍故事','output_type'=>'剧情带货'])['suggested_workflow']);
        self::assertSame('commerce',P::brief(['goal'=>'商品介绍'])['suggested_workflow']);
    }
    public function testSaveKeepsBothProjectFieldsConsistentAndPreservesCustomType(): void
    {
        $b=P::body('project',['workflow_type'=>'story','brief'=>['output_type'=>'非遗文化宣传']]);
        self::assertSame('非遗文化宣传',$b['workflow_type']);
        self::assertSame('非遗文化宣传',$b['brief']['output_type']);
        $legacy=P::body('project',['workflow_type'=>'property','brief'=>['output_type'=>'短视频']]);
        self::assertSame('property',$legacy['brief']['output_type']);
    }
    public function testWorkflowOnlyLegacyProjectGetsConsistentFieldsOnSave(): void
    {
        $b=P::body('project',['workflow_type'=>'estate','brief'=>['output_type'=>null]]);
        self::assertSame('property',$b['brief']['output_type']);
        self::assertSame('property',$b['workflow_type']);
        $this->expectException(\InvalidArgumentException::class);
        P::body('project',['workflow_type'=>str_repeat('长',41)]);
    }
    public function testOverlongTypeIsRejectedWithoutSilentTruncation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        P::brief(['goal'=>'测试','output_type'=>str_repeat('长',41)]);
    }
}
