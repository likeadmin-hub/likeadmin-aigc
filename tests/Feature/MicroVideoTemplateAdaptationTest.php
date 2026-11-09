<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoTemplate as T;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbench as W;
final class MicroVideoTemplateAdaptationTest extends TestCase
{
    private function body(): array{return ['script'=>['text'=>'0-3秒：开场。' . "\n" . '3-15秒：收尾。','segments'=>[['sort'=>1,'duration'=>15,'prompt'=>'0-3秒：开场。' . "\n" . '3-15秒：收尾。','image_indices'=>[]]]]];}
    public function testProseOnlyEditsCannotClaimTheirOldGenerationPromptIsSynchronized(): void
    {$old=$this->body();$next=$old;$next['script']['text']='全新的剧情';$next['script']['requires_sync']=false;self::assertTrue(T::synchronize($next,$old)['script']['requires_sync']);}
    public function testSegmentOnlyEditUpdatesTheFullScriptAndReferenceOnlyEditsCannotBypassAStaleProse(): void
    {$old=$this->body();$next=$old;$next['script']['segments'][0]['prompt']=str_replace('开场','新开场',$next['script']['segments'][0]['prompt']);self::assertStringContainsString('新开场',T::synchronize($next,$old)['script']['text']);$old['script']['requires_sync']=true;$next=$old;$next['script']['requires_sync']=false;$next['script']['segments'][0]['image_indices']=[0];self::assertTrue(T::synchronize($next,$old)['script']['requires_sync']);}
    public function testLegacyTemplateReadGainsSegmentsButKeepsPrivateAndExtensionFields(): void
    {$old=['script'=>$this->body()['script']['text'],'internal_prompt'=>'preserved private value','custom'=>1];$next=T::normalize($old);self::assertSame(15,$next['default_duration']);self::assertCount(1,$next['segments']);self::assertSame($old['internal_prompt'],$next['internal_prompt']);self::assertSame(1,$next['custom']);}
    public function testUnsynchronizedProseIsRejectedBeforeExecutionReferencesOrAnyPaidAction(): void
    {$this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('正文与分镜尚未同步');W::executionReferences(['script'=>['requires_sync'=>true]]);}
}
