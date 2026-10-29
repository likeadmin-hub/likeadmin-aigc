<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationCompatibilityAudit as Audit;
use PHPUnit\Framework\TestCase;

class MarketGenerationSkuDiagnosticsTest extends TestCase
{
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
