<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationContractReview as Review;
use PHPUnit\Framework\TestCase;

class MarketGenerationContractReviewTest extends TestCase
{
    public function testLedgerBindsAll18IdentitiesWithoutInventingNativeContracts(): void
    {
        $read=static fn($name)=>json_decode(file_get_contents(dirname(__DIR__).'/fixtures/'.$name),true,512,JSON_THROW_ON_ERROR);
        $ledger=Review::ledger($read('canvas-generation-market-contracts.json'),$read('market-legacy-declaration-review-20261029.json'));
        self::assertCount(18,$ledger['entries']);
        self::assertCount(7,array_unique(array_column($ledger['entries'],'protocol_family')));
        foreach ($ledger['entries'] as $row) {
            self::assertTrue($row['recorded_observation_matches']);
            self::assertFalse($row['native_contract_importable']);
            self::assertSame('public_declaration_only',$row['evidence_status']);
            self::assertContains('sku_binding_and_pricing',$row['missing_evidence']);
        }
        $sameName=array_filter($ledger['entries'],static fn($r)=>$r['model_code']==='gpt-image-2');
        self::assertCount(2,$sameName);
        self::assertCount(2,array_unique(array_column($sameName,'channel_code')));
    }

    public function testOpaqueBranchesAndReferencesCannotBeFilledByExamples(): void
    {
        $schema=['properties'=>['items'=>['type'=>'array','items'=>['oneOf'=>[
            ['type'=>'object','example'=>['credential'=>'do not print']],['$ref'=>'private-ref']]]]]];
        $missing=Review::incomplete($schema);
        self::assertSame(['items[]/oneOf/0','items[]/oneOf/1/$ref'],$missing);
        self::assertStringNotContainsString('private-ref',json_encode($missing));
        self::assertStringNotContainsString('do not print',json_encode($missing));
        self::assertSame(['urls[]'],Review::incomplete(['urls'=>['type'=>'array','items'=>['example'=>'https://fixtures.invalid/a.png']]]));
    }

    private static function bundle(): array
    {
        $schema=['type'=>'object','properties'=>['prompt'=>['type'=>'string'],'image_urls'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>2]],'required'=>['prompt']];
        return ['version'=>1,'source'=>['kind'=>'gateway_validator','reference'=>'gateway/contracts/fixture.json','revision'=>'fixture-v1',
            'reviewed_at'=>'2026-10-29T20:00:00+08:00','artifact_sha256'=>hash('sha256','offline source artifact')],
            'entries'=>[['generation_type'=>'image','model_code'=>'fixture-image','channel_code'=>'fixture-channel','public_model_code'=>'fixture-public','generation_protocol'=>'flat_image_v1',
                'declaration'=>['params_schema'=>$schema,'material_capabilities'=>['modes'=>[['code'=>'','fields'=>[['field'=>'image_urls','kind'=>'image','role'=>'reference','maximum'=>2]]]]]],
                'modes'=>[''=>['schema'=>$schema,'rules'=>[]]]]]];
    }

    public function testReviewedImportProducesInactiveCandidatesAndPreservesChannelIdentity(): void
    {
        $bundle=self::bundle();$bundle['source']['api_key']='must not copy';
        $second=$bundle['entries'][0];$second['channel_code']='another-channel';$bundle['entries'][]=$second;
        $out=Review::candidates($bundle,'offline source artifact');
        self::assertCount(2,$out['candidates']);
        foreach ($out['candidates'] as $candidate) {
            self::assertSame('review_candidate_only',$candidate['activation']);
            self::assertSame('unverified',$candidate['sku_pricing']);
            self::assertSame('unverified',$candidate['measured_media']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$candidate['fingerprint']);
        }
        self::assertArrayNotHasKey('models',$out); // Cannot masquerade as the active contract catalogue.
        self::assertStringNotContainsString('must not copy',json_encode($out));
    }

    /** @dataProvider invalidBundles */
    public function testIncompleteOrUnsupportedImportsAreRejected(string $kind): void
    {
        $b=self::bundle();
        switch ($kind) {
            case 'source': $b['source']['kind']='public_example';break;
            case 'hash': $b['source']['artifact_sha256']=str_repeat('0',64);break;
            case 'credential_url': $b['source']['reference']='https://user:secret@fixtures.invalid/source';break;
            case 'query_url': $b['source']['reference']='https://fixtures.invalid/source?key=secret';break;
            case 'identity': unset($b['entries'][0]['channel_code']);break;
            case 'duplicate': $b['entries'][]=array_reverse($b['entries'][0],true);break;
            case 'protocol': $b['entries'][0]['generation_protocol']='h3_content_v1';break;
            case 'opaque': unset($b['entries'][0]['declaration']['params_schema']['properties']['image_urls']['items']);break;
            case 'modes': $b['entries'][0]['modes']=[];break;
            case 'material': $b['entries'][0]['declaration']['material_capabilities']['modes'][0]['fields'][0]['field']='unknown';break;
            case 'rule': $b['entries'][0]['modes']['']['rules']=[['kind'=>'evaluate_php']];break;
            case 'condition': $b['entries'][0]['modes']['']['schema']['if']=['required'=>['prompt']];break;
            case 'keyword': $b['entries'][0]['modes']['']['schema']['properties']['image_urls']['uniqueItems']=true;break;
            case 'type': $b['entries'][0]['modes']['']['schema']['properties']['prompt']['type']='unimplemented';break;
        }
        $this->expectException(\InvalidArgumentException::class);
        Review::candidates($b,'offline source artifact');
    }

    public static function invalidBundles(): array
    {
        return array_map(static fn($s)=>[$s],['source','hash','credential_url','query_url','identity','duplicate','protocol','opaque','modes','material','rule','condition','keyword','type']);
    }
}
