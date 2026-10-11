<?php
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_video\VideoEffectService as Effects;

final class VideoEffectTest extends TestCase
{
    private function catalog(): array {
        $rows=array_slice(Effects::defaults(),0,3);
        foreach($rows as &$row){$row['enabled']=true;$row['product_ids']=[7];$row['poster']='/uploads/demo.webp';$row['video']='/uploads/demo.mp4';}
        return Effects::normalize($rows);
    }
    public function testLegacyCategoriesAndConfiguredCategories(): void {
        $rows=array_slice(Effects::defaults(),0,3);
        unset($rows[0]['categories']);
        self::assertSame(['Camera Control'],Effects::normalize($rows)[0]['categories']);
        $rows[0]['categories']=['Action Movement','Viral','Viral'];
        $rows[0]['enabled']=true;$rows[0]['product_ids']=[7];
        self::assertSame(['Action Movement','Viral'],Effects::publicCatalog(Effects::normalize($rows))[0]['categories']);
        $rows[0]['categories']=['unknown'];
        $this->expectException(InvalidArgumentException::class);
        Effects::normalize($rows);
    }
    public function testDefaultsAreNotPublicUntilEnabled(): void {
        self::assertCount(90,Effects::defaults());
        self::assertSame([],Effects::publicCatalog(Effects::defaults()));
        self::assertNotEmpty(Effects::publicCatalog($this->catalog())[0]['instruction']);
    }
    public function testServerInstructionAndOriginalPromptRemainSeparate(): void {
        $params=['prompt'=>'黑色耳机','effect_id'=>'push_in','generation_method'=>'text_to_video','effect_snapshot'=>['instruction'=>'forged']];
        $effect=Effects::resolve($this->catalog(),$params,7);
        $compiled=Effects::compile($params,$effect);
        self::assertSame('黑色耳机',$params['prompt']);
        self::assertStringContainsString($effect['instruction'],$compiled['prompt']);
        self::assertArrayNotHasKey('effect_snapshot',$compiled);
        self::assertSame('黑色耳机',Effects::snapshot($effect,$params['prompt'])['user_prompt']);
        self::assertNotSame(Effects::snapshot($effect,'x')['version'],Effects::snapshot($this->catalog()[1],'x')['version']);
    }
    public function testPlainGenerationIsUnchanged(): void {
        $params=['prompt'=>'主体','ratio'=>'16:9'];
        self::assertNull(Effects::resolve($this->catalog(),$params,7));
        self::assertSame($params,Effects::compile($params,null));
    }
    public function testInvalidEffectsFailBeforeGeneration(): void {
        foreach ([['effect_id'=>'unknown'],['effect_id'=>'push_in','generation_method'=>'omni_reference']] as $params) {
            try{Effects::resolve($this->catalog(),$params,7);self::fail('Invalid effect accepted');}catch(InvalidArgumentException $e){self::assertNotEmpty($e->getMessage());}
        }
        foreach ([Effects::defaults(),$this->catalog()] as $rows) {
            try{Effects::resolve($rows,['effect_id'=>'push_in'],8);self::fail('Unsupported model accepted');}catch(InvalidArgumentException $e){self::assertNotEmpty($e->getMessage());}
        }
    }
    public function testImageRequiredIsEnforced(): void {
        $rows=$this->catalog();$rows[0]['require_image']=true;
        $this->expectException(InvalidArgumentException::class);
        Effects::resolve($rows,['effect_id'=>'push_in'],7);
    }
    public function testPreviewIsOptionalForFrontendTesting(): void {
        $rows=array_slice(Effects::defaults(),0,3);
        foreach($rows as &$row){$row['enabled']=true;$row['product_ids']=[7];$row['poster']='';$row['video']='';}
        $normalized=Effects::normalize($rows);
        self::assertCount(3,Effects::publicCatalog($normalized));
        foreach($normalized as $effect){
            self::assertSame('', $effect['video']);
            self::assertSame($effect['id'],Effects::resolve($normalized,['effect_id'=>$effect['id']],7)['id']);
        }
    }
    public function testFormEncodedModelIdsAreNormalized(): void {
        $rows=array_slice(Effects::defaults(),0,3);$rows[0]['enabled']=true;$rows[0]['product_ids']=['98'];
        self::assertSame([98],Effects::normalize($rows)[0]['product_ids']);
        $rows[0]['enabled']='true';$rows[0]['require_image']='false';
        self::assertTrue(Effects::normalize($rows)[0]['enabled']);
        self::assertFalse(Effects::normalize($rows)[0]['require_image']);
        $rows[0]['product_ids']=['98oops'];
        $this->expectException(InvalidArgumentException::class);Effects::normalize($rows);
    }
    public function testCustomEntriesAndEmptyCatalogAreSupported(): void {
        $rows=$this->catalog();$custom=$rows[0];$custom['id']='custom_dolly';$rows[]=$custom;
        self::assertCount(4,Effects::normalize($rows));self::assertSame([],Effects::normalize([]));
        $rows[3]['id']=$rows[0]['id'];$this->expectException(InvalidArgumentException::class);Effects::normalize($rows);
    }
    public function testAllReviewedEffectsResolveWithTheirRequiredInputs(): void {
        $rows=Effects::defaults();
        foreach($rows as &$row){$row['enabled']=true;$row['product_ids']=[7];}unset($row);
        $rows=Effects::normalize($rows);
        foreach($rows as $row){
            $method=$row['methods'][0];$assets=[];
            if($row['require_image'])$assets[]=['type'=>'image','url'=>'https://example.com/first.png','role'=>$method==='start_end'?'first_frame_image':'reference_image'];
            if($method==='start_end')$assets[]=['type'=>'image','url'=>'https://example.com/last.png','role'=>'last_frame_image'];
            $params=['effect_id'=>$row['id'],'generation_method'=>$method,'reference_assets'=>$assets,'prompt'=>'测试场景'];
            $resolved=Effects::resolve($rows,$params,7);
            self::assertSame($row['id'],$resolved['id']);
            self::assertStringContainsString($row['instruction'],Effects::compile($params,$resolved)['prompt']);
        }
    }
    public function testTransitionsRejectMissingEndFrame(): void {
        $rows=Effects::defaults();foreach($rows as &$row){$row['enabled']=true;$row['product_ids']=[7];}unset($row);
        $this->expectException(InvalidArgumentException::class);
        Effects::resolve($rows,['effect_id'=>'fire_transition','generation_method'=>'start_end','reference_assets'=>[['type'=>'image','url'=>'https://example.com/first.png','role'=>'first_frame_image']]],7);
    }
    public function testEditedInstructionReplacesPresetWithoutDuplicateAppending(): void {
        $params=['effect_id'=>'push_in','prompt'=>'黑色耳机','effect_prompt'=>'Move slowly forward, then stop.'];
        $effect=Effects::resolve($this->catalog(),$params,7);$compiled=Effects::compile($params,$effect);
        self::assertSame(1,substr_count($compiled['prompt'],$params['effect_prompt']));
        self::assertSame($params['effect_prompt'],Effects::snapshot($effect,$params['prompt'])['instruction']);
        self::assertStringNotContainsString($this->catalog()[0]['instruction'],$compiled['prompt']);
    }
    public function testCompleteTemplatesPreserveDefaultsAndRejectIncompleteFrames(): void {
        $rows=$this->catalog();
        $rows[0]['template']=['product_id'=>7,'generation_method'=>'start_end','prompt'=>'A person in a station','duration'=>6,'resolution'=>'768P','ratio'=>'9:16','reference_assets'=>[
            ['url'=>'https://example.com/first.jpg','role'=>'first_frame_image','name'=>'首帧'],
            ['url'=>'https://example.com/last.jpg','role'=>'last_frame_image','name'=>'尾帧'],
        ]];
        $normalized=Effects::normalize($rows);
        self::assertSame(6,$normalized[0]['template']['duration']);
        self::assertSame('image',$normalized[0]['template']['reference_assets'][0]['type']);
        self::assertCount(2,Effects::publicCatalog($normalized)[0]['template']['reference_assets']);
        array_pop($rows[0]['template']['reference_assets']);
        $this->expectException(InvalidArgumentException::class);Effects::normalize($rows);
    }
    public function testTemplateCannotSelectUnboundModelOrUnsafeAsset(): void {
        foreach([['product_id'=>9,'url'=>'https://example.com/a.jpg'],['product_id'=>7,'url'=>'javascript:bad']] as $case){
            $rows=$this->catalog();$rows[0]['template']=['product_id'=>$case['product_id'],'generation_method'=>'image_to_video','prompt'=>'scene','duration'=>5,'resolution'=>'768P','ratio'=>'16:9','reference_assets'=>[['url'=>$case['url']]]];
            try{Effects::normalize($rows);self::fail('Invalid template accepted');}catch(InvalidArgumentException $error){self::assertNotEmpty($error->getMessage());}
        }
    }
    public function testEnablingRequiresModels(): void {
        $rows=array_slice(Effects::defaults(),0,3);$rows[0]['enabled']=true;
        $this->expectException(InvalidArgumentException::class);Effects::normalize($rows);
    }
}
