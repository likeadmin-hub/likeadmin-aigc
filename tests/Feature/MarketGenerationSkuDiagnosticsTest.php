<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationCompatibilityAudit as Audit;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketGenerationNativeContract as Native;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;

class MarketGenerationSkuDiagnosticsTest extends TestCase
{
    public static function reviewedSkuCases(): array
    {
        $read=static fn($name)=>json_decode(file_get_contents(dirname(__DIR__).'/fixtures/'.$name),true,512,JSON_THROW_ON_ERROR);
        $review=$read('market-sku-rejection-review-20261029.json');
        $entries=array_column($read('market-structured-public-contracts-20261029.json')['entries'],null,'model_code');
        $skus=array_column($review['skus'],null,'sku_id');$rows=[];
        foreach ($review['cases'] as $observed) {
            $entry=$entries[$observed['model_code']];$sku=$skus[$observed['sku_id']];
            if ($sku['model_code']!==$entry['model_code'] || $sku['channel_code']!==$entry['channel_code']) {
                throw new \LogicException('Reviewed SKU fixture identity mismatch');
            }
            $cases=Audit::cases($entry['generation_type'],$entry['options']+['material_modes'=>Materials::modes($entry['metadata'])],$entry['metadata']);
            $matches=array_values(array_filter($cases,static fn($c)=>$c['case']===$observed['case']));
            if (count($matches)!==1) throw new \LogicException('Reviewed synthetic case missing or ambiguous');
            $rows[$observed['sku_id'].':'.$observed['case']]=[$entry,$sku,$matches[0],$observed];
        }
        if (count($rows)!==124 || count($skus)!==23) throw new \LogicException('Reviewed SKU coverage changed');
        return $rows;
    }

    /** @dataProvider reviewedSkuCases */
    public function testRecordedSkuRejectionWithActualFrozenNativeCompiler(array $entry,array $sku,array $case,array $observed): void
    {
        self::assertNotEmpty(Native::modes($entry['metadata']));
        self::assertSame('constructed',$case['expected']);
        $snapshot=['resource_type'=>'model','model_code'=>$entry['model_code'],'channel_code'=>$entry['channel_code'],
            'market_metadata'=>$entry['metadata'],'locked_params'=>$sku['locked_params'],'sku_id'=>$sku['sku_id']];
        $before=$snapshot;
        $prepare=$entry['generation_type']==='image'
            ? static fn($frozen,$request)=>Images::prepareRequest($frozen,$request)->nativeInput
            : static fn($frozen,$request)=>Videos::prepareRequest($frozen,$request)->nativeInput;
        $result=Audit::probeWithSkuDiagnostics($case,$snapshot,$prepare);
        self::assertSame('rejected',$result['status']);
        self::assertSame($observed['rejection_category'],$result['rejection_category']);
        self::assertSame('constructed',$result['diagnostic_control_status']);
        self::assertSame($before,$snapshot);
    }

    private function case(string $expected='constructed'): array
    {
        return ['case'=>'text#fixture','mode_case'=>'text','expected'=>$expected,'fixture_scope'=>'offline','skip'=>false,
            'request'=>['material_mode'=>'text','provider_params'=>['audio'=>false],'reference_assets'=>[]]];
    }

    private function snapshot(array $locks): array
    {
        return ['locked_params'=>$locks,'market_metadata'=>['params_schema'=>['audio'=>['type'=>'boolean']],
            'material_capabilities'=>['modes'=>[['code'=>'text_to_video','view_key'=>'text','method'=>'text_to_video','fields'=>[]]]]]];
    }

    public function testModeConflictRemainsRejectedAndControlCannotMutateTheSnapshot(): void
    {
        $snapshot=$this->snapshot(['generation_type'=>'image_to_video']);$before=$snapshot;$seen=[];
        $result=Audit::probeWithSkuDiagnostics($this->case(),$snapshot,static function($frozen,$request)use(&$seen){
            $seen[]=$frozen;
            if ($frozen['locked_params']!==[]) throw new \InvalidArgumentException('private rejected value');
            return $request['provider_params'];
        });
        self::assertSame('rejected',$result['status']);
        self::assertSame('sku_generation_mode_conflict',$result['rejection_category']);
        self::assertSame('constructed',$result['diagnostic_control_status']);
        self::assertCount(2,$seen);self::assertSame($snapshot['market_metadata'],$seen[1]['market_metadata']);
        self::assertSame($before,$snapshot);
        self::assertStringNotContainsString('private rejected value',json_encode($result));
    }

    public function testLockedAudioCombinationIsNotReportedAsAnAdapterDefect(): void
    {
        $r=Audit::probeWithSkuDiagnostics($this->case(),$this->snapshot(['audio'=>true]),static function($frozen,$request){
            if ($frozen['locked_params']['audio'] ?? false) throw new \InvalidArgumentException('not disclosed');
            return $request['provider_params'];
        });
        self::assertSame('rejected',$r['status']);
        self::assertSame('sku_locked_parameter_combination',$r['rejection_category']);
        self::assertStringNotContainsString('not disclosed',json_encode($r));
    }

    public function testARejectedControlRemainsUnresolved(): void
    {
        $r=Audit::probeWithSkuDiagnostics($this->case(),$this->snapshot(['audio'=>true]),static function(){throw new \InvalidArgumentException('redact');});
        self::assertSame('rejected',$r['status']);
        self::assertSame('unresolved_local_constraint',$r['rejection_category']);
        self::assertSame('rejected',$r['diagnostic_control_status']);
    }

    public function testExpectedRejectionsDoNotRunUnlockedControls(): void
    {
        $calls=0;
        $r=Audit::probeWithSkuDiagnostics($this->case('rejected'),$this->snapshot(['audio'=>true]),static function()use(&$calls){$calls++;throw new \InvalidArgumentException('redact');});
        self::assertSame('rejected',$r['status']);self::assertSame(1,$calls);
        self::assertArrayNotHasKey('diagnostic_control_status',$r);
    }

    public function testSuccessfulPreparedRequestDoesNotRunAnotherCompiler(): void
    {
        $calls=0;
        $r=Audit::probeWithSkuDiagnostics($this->case(),$this->snapshot(['audio'=>true]),static function($frozen)use(&$calls){$calls++;return ['audio'=>$frozen['locked_params']['audio']];});
        self::assertSame('constructed',$r['status']);self::assertSame(1,$calls);
        self::assertArrayNotHasKey('rejection_category',$r);
    }

    public function testUnregisteredStructureStillSkipsCompilation(): void
    {
        $case=$this->case();$case['skip']=true;
        $r=Audit::probeWithSkuDiagnostics($case,$this->snapshot([]),static function(){throw new \RuntimeException('must never compile');});
        self::assertSame('needs_structured_fixture',$r['status']);
    }
}
