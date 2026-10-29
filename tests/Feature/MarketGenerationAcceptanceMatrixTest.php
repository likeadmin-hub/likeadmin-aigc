<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationAcceptanceMatrix as Matrix;
use PHPUnit\Framework\TestCase;

class MarketGenerationAcceptanceMatrixTest extends TestCase
{
    private function audit(): array
    {
        return ['tenant_id'=>1,'generated_at'=>'2026-10-30T01:05:00+08:00','models'=>[
            ['generation_type'=>'image','resource_type'=>'model','id'=>'fixture:1','product_id'=>1,'model_code'=>'fixture','channel_code'=>'channel',
                'app_code'=>'','api_code'=>'','declaration_fingerprint'=>str_repeat('a',64),'native_contract'=>'matched',
                'tool_catalog_visibility'=>['standalone_image'=>'listed','canvas'=>'listed','short_drama'=>'listed_in_model_group'],
                'sku_matrix'=>[['market_sku_id'=>2,'meter_status'=>'recognized','request_cases'=>[
                    ['case'=>'text:0','material_mode'=>'text','status'=>'constructed'],['case'=>'reference:1','material_mode'=>'reference','status'=>'rejected']]]]]],
            'unavailable_applications'=>[['market_product_id'=>3,'category_code'=>'image','name'=>'Pending app','unavailable_reason'=>'adapter missing','api_key'=>'secret']]];
    }
    public function testAllEntriesStayPendingDespiteMatchedContractsAndSuccessfulConstruction(): void
    {
        $a=$this->audit();$a['models'][0]['request_payload']=['api_key'=>'secret'];$r=Matrix::build($a);
        self::assertSame(10,$r['summary']['entry_cells']);self::assertSame(0,$r['summary']['accepted_cells']);
        self::assertSame(['constructed'=>1,'rejected'=>1],$r['summary']['local_preparation_observations']);
        foreach($r['resources'][0]['cases']as$c)self::assertSame(array_fill_keys(Matrix::ENTRIES,'not_checked'),$c['entry_acceptance']);
        self::assertSame('not_checked',$r['resources'][0]['catalogue_visibility']['pc_home']);
        self::assertStringNotContainsString('secret',json_encode($r));self::assertCount(1,$r['unavailable_applications']);
    }
    public function testIdentityChannelSkuModeEntryAndScenarioAllSeparateCells(): void
    {
        $identity=$this->audit()['models'][0];unset($identity['sku_matrix']);
        $base=Matrix::cellKey($identity,2,'text','pc_studio','first');$keys=[$base];
        $other=$identity;$other['channel_code']='other';$keys[]=Matrix::cellKey($other,2,'text','pc_studio','first');
        $keys[]=Matrix::cellKey($identity,3,'text','pc_studio','first');$keys[]=Matrix::cellKey($identity,2,'reference','pc_studio','first');
        $keys[]=Matrix::cellKey($identity,2,'text','canvas','first');$keys[]=Matrix::cellKey($identity,2,'text','pc_studio','second');
        self::assertCount(6,array_unique($keys));
    }
    /** @dataProvider invalid */
    public function testIncompleteOrDuplicateEvidenceCannotProduceAnAcceptanceMatrix(string $case): void
    {
        $a=$this->audit();switch($case){
            case 'missing_identity':unset($a['models'][0]['model_code']);break;
            case 'duplicate_resource':$a['models'][]=$a['models'][0];break;
            case 'duplicate_cell':$a['models'][0]['sku_matrix'][0]['request_cases'][]=$a['models'][0]['sku_matrix'][0]['request_cases'][0];break;
            case 'missing_mode':unset($a['models'][0]['sku_matrix'][0]['request_cases'][0]['material_mode']);break;
            case 'no_probes':$a['models'][0]['sku_matrix'][0]['request_cases']=[];break;
            case 'bad_fingerprint':$a['models'][0]['declaration_fingerprint']='partial';break;
        }
        $this->expectException(\InvalidArgumentException::class);Matrix::build($a);
    }
    public static function invalid(): array{return array_map(static fn($v)=>[$v],['missing_identity','duplicate_resource','duplicate_cell','missing_mode','no_probes','bad_fingerprint']);}
}
