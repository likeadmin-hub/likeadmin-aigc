<?php
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_video\VideoEffectService as Effects;

final class VideoEffectTest extends TestCase
{
    private function catalog(): array {
        $rows=Effects::defaults();
        foreach($rows as &$row){$row['enabled']=true;$row['product_ids']=[7];$row['poster']='/uploads/demo.webp';$row['video']='/uploads/demo.mp4';}
        return Effects::normalize($rows);
    }
    public function testDefaultsAreNotPublicUntilValidated(): void {
        self::assertCount(3,Effects::defaults());
        self::assertSame([],Effects::publicCatalog(Effects::defaults()));
        self::assertArrayNotHasKey('instruction',Effects::publicCatalog($this->catalog())[0]);
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
    public function testEnablingRequiresModelsAndActualPreview(): void {
        $rows=Effects::defaults();$rows[0]['enabled']=true;
        $this->expectException(InvalidArgumentException::class);Effects::normalize($rows);
    }
}
