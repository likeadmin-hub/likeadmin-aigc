<?php
namespace Tests\Feature;

use app\common\service\app\aigc_music\AigcMusicService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MusicHistoryReuseTest extends TestCase
{
    private function restore(array $row): array
    {
        $method=new ReflectionMethod(AigcMusicService::class,'historyGenerationParams');
        $method->setAccessible(true);
        return $method->invoke(null,$row);
    }

    public function testRetryAndRecoveryKeepDeclaredCustomInstrumentalAndVoiceChoices(): void
    {
        $row=['prompt'=>'Synthetic','lyrics'=>'','duration'=>60,'market_product_id'=>7,'market_sku_id'=>9,'reference_asset_id'=>0,
            'provider_payload'=>['generation'=>['custom'=>true,'instrumental'=>false,'vocal_gender'=>'f','language'=>'en','style'=>'ambient'],
                'submit'=>['private_supplier_field'=>'must not replay']]];
        $input=$this->restore($row);
        self::assertTrue($input['custom']);self::assertFalse($input['instrumental']);
        self::assertSame('f',$input['vocal_gender']);self::assertSame('en',$input['language']);self::assertSame('ambient',$input['style']);
        self::assertSame(60,$input['duration']);self::assertSame(9,$input['market_sku_id']);self::assertSame(0,$input['reference_asset_id']);
        self::assertArrayNotHasKey('provider_payload',$input);
        $row['provider_payload']['generation']=['custom'=>false,'instrumental'=>true,'vocal_gender'=>'','language'=>'auto','style'=>''];
        $input=$this->restore($row);
        self::assertFalse($input['custom']);self::assertTrue($input['instrumental']);self::assertSame('',$input['vocal_gender']);self::assertSame('',$input['style']);
    }

    public function testOldRowsWithoutGenerationSnapshotKeepLegacyRecoveryFallback(): void
    {
        self::assertSame(['prompt'=>'Synthetic','lyrics'=>'','custom'=>false,'instrumental'=>true],$this->restore(['prompt'=>'Synthetic','lyrics'=>'']));
        self::assertSame(['prompt'=>'','lyrics'=>'Synthetic lyrics','custom'=>true,'instrumental'=>false],$this->restore(['prompt'=>'','lyrics'=>'Synthetic lyrics']));
    }
}
