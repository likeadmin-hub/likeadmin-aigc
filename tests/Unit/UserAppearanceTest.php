<?php
use app\common\service\UserAppearanceService;
use PHPUnit\Framework\TestCase;

final class UserAppearanceTest extends TestCase
{
    private function skin(): array
    {
        return ['id'=>'custom-12345678','name'=>'我的皮肤','mode'=>'dark','primary'=>'#AbC123','secondary'=>'#456789','background_id'=>7];
    }
    public function testEveryPresetRoundTripsWithoutCustomData(): void
    {
        foreach (UserAppearanceService::PRESETS as $id) {
            $p = UserAppearanceService::defaults(); $p['selected'] = $id;
            self::assertSame($p, UserAppearanceService::normalize($p, fn() => false));
        }
    }
    public function testOwnedAssetIsStoredByIdAndClientUrlIsDiscarded(): void
    {
        $s=$this->skin(); $s['background_url']='https://untrusted.test/image.png';
        $result=UserAppearanceService::normalize(['selected'=>$s['id'],'mode'=>'system','custom'=>[$s]], fn($id)=>$id===7);
        self::assertSame(7,$result['custom'][0]['background_id']);
        self::assertSame('#abc123',$result['custom'][0]['primary']);
        self::assertArrayNotHasKey('background_url',$result['custom'][0]);
    }
    public function testForeignAssetCannotBeAttached(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserAppearanceService::normalize(['custom'=>[$this->skin()]],fn()=>false);
    }
    public function testUnsupportedCustomizationAndCssInjectionAreRejected(): void
    {
        $cases=[['font'=>'external'],['cursor'=>'external'],['sound'=>'external'],['primary'=>'url(https://untrusted.test)'],['secondary'=>'red'],['mode'=>'system'],['id'=>'neon-sunset-drive'],['name'=>''],['background_id'=>-1]];
        foreach($cases as $change){
            try { UserAppearanceService::normalize(['custom'=>[array_replace($this->skin(),$change)]],fn()=>true); self::fail('Accepted invalid skin'); }
            catch(InvalidArgumentException $e){self::assertNotSame('',$e->getMessage());}
        }
    }
    public function testRemovedSelectionAndDuplicateIdsAreRejected(): void
    {
        foreach([['selected'=>'custom-missing1'],['custom'=>[$this->skin(),$this->skin()]],['custom'=>array_fill(0,21,$this->skin())],['mode'=>'unknown']] as $p){
            try { UserAppearanceService::normalize($p,fn()=>true);self::fail('Accepted inconsistent document'); }
            catch(InvalidArgumentException $e){self::assertNotSame('',$e->getMessage());}
        }
    }
}
