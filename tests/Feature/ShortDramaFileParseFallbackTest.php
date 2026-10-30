<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\ShortDramaFileParseFallback as Fallback;

final class ShortDramaFileParseFallbackTest extends TestCase
{
    private function request(): array { return ['source'=>'market_file_qa_parse','prompt'=>'保留两集','_file_parse_fallback'=>[
        'version'=>1,'text'=>"第一集 地址\n0-5秒 甲拿起信。\n第二集 来客\n0-5秒 乙敲门。",'model'=>['id'=>'65','context_window'=>32768,'max_output_tokens'=>8192]]]; }
    private function plan(): array { return ['episodes'=>[
        ['episode_no'=>1,'source_range'=>['start_line'=>1,'end_line'=>2]],
        ['episode_no'=>2,'source_range'=>['start_line'=>3,'end_line'=>4]]]]; }
    public function testOneModelPassRepairsUsingOriginalRangesAndKeepsOriginalReceiptAsData(): void
    {
        $request=$this->request();$request['_file_parse_fallback']+=['original_result'=>['bad'=>'primary'],'validation_error'=>'缺少分集'];$calls=0;
        $result=Fallback::repair(1,3,['id'=>0,'task_id'=>''],$request,function($input,$event)use(&$calls){
            $calls++;self::assertStringContainsString('缺少分集',$input['content']);self::assertStringContainsString('L000001 第一集 地址',$input['content']);
            self::assertTrue($input['_disable_model_fallback']);self::assertTrue($input['_disable_transient_retry']);self::assertTrue($input['_disable_compatibility_retry']);return ['content'=>json_encode($this->plan())];
        },static fn($plan)=>$plan);
        self::assertSame(1,$calls);self::assertSame($request['_file_parse_fallback']['text'],implode('',array_column($result['parsed']['episodes'],'source_content')));
    }
    public function testInvalidRepairStopsAfterOneCall(): void
    {
        $calls=0;
        try {Fallback::repair(1,3,['id'=>0,'task_id'=>''],$this->request(),function()use(&$calls){$calls++;return ['content'=>'{}'];},static fn($p)=>$p);self::fail('Invalid repair must fail');}
        catch(\RuntimeException $e){self::assertSame(422,$e->getCode());self::assertSame(1,$calls);}
    }
    public function testOriginalSourceCannotBeRewrittenByThePrimaryParser(): void
    {
        $p=$this->plan();$p['episodes'][0]['source_content']='甲离开了。';$p['episodes'][1]['source_content']="第二集 来客\n0-5秒 乙敲门。";
        $this->expectExceptionCode(422);Fallback::assertSources($p,$this->request());
    }
    public function testMissingBodyOrCrossEpisodeRangesAreNotAccepted(): void
    {
        $p=$this->plan();$p['episodes'][0]['source_content']="第一集 地址\n0-5秒 甲拿起信。";$p['episodes'][1]['source_content']='';
        $this->expectExceptionCode(422);Fallback::assertSources($p,$this->request());
    }
    public function testInvalidOriginalTimingCannotBeHiddenByACompleteJsonRepair(): void
    {
        $r=$this->request();$r['_file_parse_fallback']['text']="第一集 地址\n0-5秒 甲拿信。\n3-8秒 甲看信。\n第二集 来客\n乙敲门。";
        $p=['episodes'=>[['episode_no'=>1,'source_range'=>['start_line'=>1,'end_line'=>3]],['episode_no'=>2,'source_range'=>['start_line'=>4,'end_line'=>5]]]];
        $this->expectExceptionCode(422);Fallback::repair(1,3,['id'=>0,'task_id'=>''],$r,static fn()=>['content'=>json_encode($p)],static fn($p)=>$p);
    }
    public function testEmptyOrPreviouslyAttemptedSourceCannotScheduleAnotherFallback(): void
    {
        self::assertTrue(Fallback::eligible($this->request()));$r=$this->request();$r['_file_parse_fallback']['state']='failed';self::assertFalse(Fallback::eligible($r));
        $r=$this->request();$r['_file_parse_fallback']['text']='';self::assertFalse(Fallback::eligible($r));
    }
    public function testMalformedEpisodeTypeIsAValidationErrorAndPrivateSourceIsNotPublicDiagnostics(): void
    {
        $request=$this->request();$method=new \ReflectionMethod(\app\common\service\app\aigc_short_drama\AigcShortDramaService::class,'stripPromptDiagnostics');$method->setAccessible(true);
        self::assertArrayNotHasKey('_file_parse_fallback',$method->invoke(null,$request));
        $this->expectExceptionCode(422);Fallback::repair(1,3,['id'=>0,'task_id'=>''],$request,static fn()=>['content'=>'{"episodes":"bad"}'],static fn($p)=>$p);
    }
    public function testSourceOrderCannotBeReversedWhenHeadingsAreEnglish():void
    {
        $r=$this->request();$r['_file_parse_fallback']['text']="Episode one\n甲拿信。\nEpisode two\n乙敲门。";
        $p=['episodes'=>[['episode_no'=>1,'source_range'=>['start_line'=>3,'end_line'=>4],'source_content'=>"Episode two\n乙敲门。"],['episode_no'=>2,'source_range'=>['start_line'=>1,'end_line'=>2],'source_content'=>"Episode one\n甲拿信。"]]];
        $this->expectExceptionCode(422);Fallback::assertSources($p,$r);
    }
    public function testPartialSeriesKeepsOriginalHeadingNumbersAndSourceOrder():void
    {
        $r=$this->request();$r['_file_parse_fallback']['text']="第三集 地址\n甲拿信。\n第四集 来客\n乙敲门。";
        $result=Fallback::repair(1,3,['id'=>0,'task_id'=>''],$r,fn()=>['content'=>json_encode($this->plan())],static fn($p)=>$p);
        self::assertStringStartsWith('第三集',$result['parsed']['episodes'][0]['source_content']);
        self::assertStringStartsWith('第四集',$result['parsed']['episodes'][1]['source_content']);
    }
}
