<?php
namespace Tests\Feature;

use app\common\service\power\MarketCatalogReadScope;
use app\common\service\power\MarketGenerationNativeContract;
use PHPUnit\Framework\TestCase;

final class NativeContractCatalogPerformanceTest extends TestCase
{
    /** @runInSeparateProcess */
    public function testRepeatedContractVerificationIsReusedOnlyWithinTheReadScope(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();
        $schema=[]; for($i=0;$i<2000;$i++) $schema['field_'.$i]=['type'=>'string','description'=>str_repeat('fixture',10)];
        $metadata=['upstream_metadata'=>['model_code'=>'fixture'], 'params_schema'=>$schema, 'material_capabilities'=>['modes'=>[]]];
        $canonical=static function($value) use (&$canonical) { if(!is_array($value))return $value; foreach($value as &$v)$v=$canonical($v);unset($v);if(!array_is_list($value))ksort($value);return $value; };
        $fingerprint=hash('sha256',json_encode($canonical([$schema,['modes'=>[]]]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $file=tempnam(sys_get_temp_dir(),'contract-test-');
        file_put_contents($file,json_encode(['models'=>['fixture'=>['fingerprint'=>$fingerprint,'modes'=>['test'=>['schema'=>[]]]]]]));
        putenv('MARKET_GENERATION_CONTRACTS_PATH='.$file);
        try {
            $t=microtime(true);for($i=0;$i<100;$i++) $baseline=MarketGenerationNativeContract::modes($metadata);$slow=microtime(true)-$t;
            $t=microtime(true);$result=MarketCatalogReadScope::run(static function()use($metadata){for($i=0;$i<100;$i++)$out=MarketGenerationNativeContract::modes($metadata);$changed=$metadata;$changed['params_schema']['field_0']['type']='number';return ['out'=>$out,'changed'=>MarketGenerationNativeContract::modes($changed)];});$fast=microtime(true)-$t;
            self::assertNotEmpty($baseline);self::assertSame($baseline,$result['out']);self::assertSame([],$result['changed']);self::assertNull(request()->marketCatalogReadScope);
            self::assertLessThan($slow*0.7,$fast,'Repeated identical native declarations should avoid recalculating the fingerprint');
            self::assertSame([],MarketGenerationNativeContract::modes(array_replace_recursive($metadata,['params_schema'=>['field_0'=>['type'=>'number']]])));
        } finally { unlink($file); putenv('MARKET_GENERATION_CONTRACTS_PATH'); }
    }
}
