<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationContractReview as Review;
use PHPUnit\Framework\TestCase;

class MarketGenerationContractReviewTest extends TestCase
{
    /** @dataProvider declarationChanges */
    public function testSameSchemaAndModeCountCannotHideOtherDeclarationChanges(string $field,$before,$after): void
    {
        $schema=['prompt'=>['type'=>'string']];$saved=['params_schema'=>$schema,$field=>$before];$remote=array_replace($saved,[$field=>$after]);
        $r=Review::platformData(['params_schema'=>$schema,'upstream_metadata'=>$saved],['model_pricing'=>$remote]);
        self::assertTrue($r['platform_schema_matches_saved']);self::assertFalse($r['platform_declaration_matches_saved']);
        self::assertNotSame($r['saved_declaration_fingerprint'],$r['platform_declaration_fingerprint']);self::assertSame('unverified',$r['runtime_readiness']);
    }
    public static function declarationChanges(): array
    {
        return [['default_params',['audio'=>false],['audio'=>true]],['default_params',['seed'=>0],['seed'=>null]],
            ['material_capabilities',['modes'=>[['code'=>'reference','minimum'=>1]]],['modes'=>[['code'=>'reference','minimum'=>2]]]],
            ['validation_rules',[['kind'=>'max','limit'=>1]],[['kind'=>'max','limit'=>2]]],
            ['generation_contract',['rules'=>[]],['rules'=>[['kind'=>'requires']]]],['native_contract',['version'=>1],['version'=>2]],
            ['request_schema',['type'=>'object'],['type'=>'string']],['generation_protocol','native_v1','native_v2'],
            ['contract_version','one','two'],['gateway_version','one','two']];
    }
    public function testDeclarationFingerprintsPreserveTypedPresenceAndRedactOtherFields(): void
    {
        $saved=['default_params'=>['example'=>false,'seed'=>0],'params_schema'=>['prompt'=>['type'=>'string']]];
        $remote=['params_schema'=>['prompt'=>['type'=>'string']],'default_params'=>['seed'=>0,'example'=>false],'api_key'=>'never expose','price'=>123.45];
        $r=Review::catalogueData(['upstream_metadata'=>$saved],$remote);self::assertTrue($r['platform_declaration_matches_saved']);
        self::assertStringNotContainsString('never expose',json_encode($r));self::assertStringNotContainsString('123.45',json_encode($r));
        unset($remote['default_params']['example']);self::assertFalse(Review::catalogueData(['upstream_metadata'=>$saved],$remote)['platform_declaration_matches_saved']);
        $missing=Review::applicationData(['upstream_metadata'=>$saved],[]);self::assertNull($missing['platform_declaration_matches_saved']);self::assertNull($missing['platform_declaration_fingerprint']);
        $application=Review::applicationData(['upstream_metadata'=>$saved],['api_pricing'=>$saved,'model_pricing'=>['default_params'=>['seed'=>999]]]);self::assertTrue($application['platform_declaration_matches_saved']);
    }
    public function testPlatformParametersAndLocalNativeSnapshotAreDifferentEvidenceLayers(): void
    {
        $schema=['image_urls'=>['type'=>'array']];
        $metadata=['params_schema'=>$schema,'upstream_metadata'=>['params_schema'=>$schema]];
        $detail=['model_pricing'=>['params_schema'=>$schema,'tenant_price'=>999,'api_key'=>'never print'],
            'doc'=>['content'=>'Published prose can be richer; do not parse it as a machine contract']];
        $r=Review::platformData($metadata,$detail);
        self::assertTrue($r['platform_detail_available']);
        self::assertTrue($r['platform_schema_matches_saved']);
        self::assertSame(['image_urls[]'],$r['platform_schema_opaque_paths']);
        self::assertSame(0,$r['platform_material_mode_count']);
        self::assertSame('unverified',$r['runtime_readiness']);
        self::assertStringNotContainsString('never print',json_encode($r));
        self::assertStringNotContainsString('999',json_encode($r));
        self::assertNull(Review::platformData($metadata,[])['platform_schema_matches_saved']);
        $detail['model_pricing']['params_schema']['image_urls']['items']=['type'=>'string'];
        self::assertFalse(Review::platformData($metadata,$detail)['platform_schema_matches_saved']);
        self::assertSame([],Review::platformData($metadata,$detail)['platform_schema_opaque_paths']);
    }

    public function testCatalogueProjectionCannotClaimDocumentOrRuntimeReadiness(): void
    {
        $schema=['images'=>['type'=>'array','items'=>['type'=>'string']]];
        $r=Review::catalogueData(['params_schema'=>$schema],['params_schema'=>$schema]);
        self::assertTrue($r['catalogue_declaration_available']);
        self::assertSame('model_catalogue_detail',$r['source_projection']);
        self::assertSame('unverified',$r['runtime_readiness']);
        self::assertArrayNotHasKey('published_document_available',$r);
        self::assertArrayNotHasKey('platform_detail_available',$r);
        self::assertFalse(Review::catalogueData([],[])['catalogue_declaration_available']);
    }

    public function testApplicationEvidenceReadsApiPricingWithoutBorrowingModelDeclarations(): void
    {
        $r=Review::applicationData([],['api_pricing'=>['params_schema'=>['media'=>['type'=>'array']]],
            'model_pricing'=>['params_schema'=>['wrong'=>'never borrow']]]);
        self::assertTrue($r['api_declaration_available']);
        self::assertSame('application_doc_detail',$r['source_projection']);
        self::assertSame(['media[]'],$r['platform_schema_opaque_paths']);
        self::assertSame('unverified',$r['sku_pricing']);
        self::assertFalse(Review::applicationData([],['model_pricing'=>['params_schema'=>['wrong'=>[]]]])['api_declaration_available']);
    }

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

    public function testPublishedGuidanceAddsEvidenceWithoutCompletingMachineContracts(): void
    {
        $read=static fn($name)=>json_decode(file_get_contents(dirname(__DIR__).'/fixtures/'.$name),true,512,JSON_THROW_ON_ERROR);
        $guidance=$read('market-legacy-published-guidance-review-20261029.json');
        $ledger=Review::ledger($read('canvas-generation-market-contracts.json'),$read('market-legacy-declaration-review-20261029.json'),$guidance);
        $reviewed=array_filter($ledger['entries'],static fn($r)=>$r['evidence_status']==='published_guidance_reviewed');
        self::assertCount(5,$reviewed);
        self::assertCount(3,$ledger['application_guidance']);
        self::assertSame(['wan','happy_horse','full_video'],array_column($ledger['application_guidance'],'app_code'));
        self::assertSame(['create','submit','submit'],array_column($ledger['application_guidance'],'api_code'));
        foreach ($reviewed as $row) {
            self::assertFalse($row['native_contract_importable']);
            self::assertNotEmpty($row['published_guidance'][0]['unresolved']);
        }
        $h3=array_values(array_filter($reviewed,static fn($r)=>$r['model_code']==='h3-video'))[0];
        self::assertContains('introduction_mentions_tail_only_but_detailed_rules_require_first_frame',$h3['published_guidance'][0]['unresolved']);
        self::assertSame('model_code_matches_but_channel_not_explicit_in_document',$h3['published_guidance'][0]['channel_binding']);
    }

    public function testMachineDetailReviewIsPrimaryEvidenceWithoutImplyingRuntimeReadiness(): void
    {
        $read=static fn($name)=>json_decode(file_get_contents(dirname(__DIR__).'/fixtures/'.$name),true,512,JSON_THROW_ON_ERROR);
        $ledger=Review::ledger($read('canvas-generation-market-contracts.json'),$read('market-legacy-declaration-review-20261029.json'),
            $read('market-legacy-published-guidance-review-20261029.json'),$read('market-platform-contract-flow-review-20261029.json'));
        self::assertCount(17,array_filter($ledger['entries'],static fn($r)=>$r['evidence_status']==='platform_detail_reviewed'));
        foreach ($ledger['entries'] as $entry) {
            self::assertFalse($entry['native_contract_importable']);
            self::assertNotEmpty($entry['platform_observed_at']);
        }
    }

    public function testFullCatalogueEvidenceDoesNotReplaceDocumentHistoryOrGrantNativeContracts(): void
    {
        $read=static fn($name)=>json_decode(file_get_contents(dirname(__DIR__).'/fixtures/'.$name),true,512,JSON_THROW_ON_ERROR);
        $captured=$read('canvas-generation-market-contracts.json');$observations=$read('market-legacy-declaration-review-20261029.json');
        $docs=$read('market-platform-contract-flow-review-20261029.json');$catalogue=$read('market-platform-catalogue-review-20261029.json');
        $ledger=Review::ledger($captured,$observations,[],$docs,$catalogue);
        self::assertCount(18,$ledger['entries']);
        foreach ($ledger['entries'] as $entry) {
            self::assertSame('platform_catalogue_reviewed',$entry['evidence_status']);
            self::assertSame($catalogue['observed_at'],$entry['catalogue_observed_at']);
            self::assertFalse($entry['native_contract_importable']);
            self::assertSame('unverified',$entry['live_generation']);
            self::assertSame(0,$entry['platform_catalogue_observation']['platform_material_mode_count']);
        }
        $gpt=array_values(array_filter($ledger['entries'],static fn($e)=>$e['model_code']==='gpt-image-2' && $e['channel_code']==='OpenAIYI'))[0];
        self::assertFalse($gpt['platform_detail_observation']['platform_identity_verified']);
        self::assertTrue($gpt['platform_catalogue_observation']['platform_identity_verified']);
        $catalogue['models'][]=$catalogue['models'][0];
        $ambiguous=Review::ledger($captured,$observations,[],$docs,$catalogue)['entries'];
        self::assertCount(17,array_filter($ambiguous,static fn($e)=>$e['evidence_status']==='platform_catalogue_reviewed'));
    }

    public function testRecordedGptOpenaiyiIdentityCannotBeReplacedWithVip(): void
    {
        $record=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/market-gpt-image-openaiyi-identity-20261029.json'),true,512,JSON_THROW_ON_ERROR);
        $identity=\app\common\service\power\MarketGenerationSourceIdentity::class;
        $model=array_intersect_key($record['local_resource'],array_flip(['model_code','channel_code']));
        $bound=$identity::bind($model,$record['platform_resource']);
        self::assertSame(96,$bound['model_id']);
        self::assertTrue($identity::matchesDetail($bound,['model_pricing'=>$record['platform_resource']]));
        $vip=$record['platform_resource'];$vip['model_id']=103;$vip['model_code']='gpt-image-2-vip';
        self::assertFalse($identity::matchesDetail($bound,['model_pricing'=>$vip]));
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
