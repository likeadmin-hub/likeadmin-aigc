<?php
namespace Tests\Feature;

use app\common\service\power\MarketOwnedPlatformContractBuilder as Builder;
use app\common\service\power\MarketOwnedPlatformPartialFacts as Facts;
use app\common\service\power\MarketOwnedPlatformPreparationVerification as Verification;
use PHPUnit\Framework\TestCase;

/** Synthetic public declarations and literal-parser fixtures; actual authenticated source is verified by the offline CLI. */
class MarketOwnedPlatformPartialFactsTest extends TestCase
{
    private static function source(): array
    {
        $ratios=['1:1','16:9','9:16','4:3','3:4','3:2','2:3','5:4','4:5','21:9','9:21','1:3','3:1','2:1','1:2'];
        $php='';
        foreach(['dakkaGptImageSize','gptImage2FastSize']as$method){
            $php.='function '.$method.'(string $size, array $params): string { $matrix = ['."\n";
            foreach($ratios as$ratio){
                $size=$method==='dakkaGptImageSize'?'2048x688':'2016x672';
                $php.="'".$ratio."' => ['1k' => '".$size."', '2k' => '3840x1280'";
                if($method!=='dakkaGptImageSize'||!in_array($ratio,['1:3','3:1'],true))$php.=", '4k' => '3840x2160'";
                $php.="],\n";
            }
            $php.="\n]; return 'not executed'; }\n";
        }
        return ['server/app/common/service/ai/driver/DrawDriver.php'=>$php];
    }

    /** @dataProvider exactModels */
    public function testEveryExactIdentityHasMachineReadableFactsWithoutBecomingAnActiveContract(string $model,string $channel): void
    {
        $profile=array_values(array_filter(Builder::profiles(),static fn($p)=>$p['model_code']===$model&&$p['channel_code']===$channel))[0];
        $metadata=['params_schema'=>['prompt'=>['type'=>'string','example'=>'Never a default'],
            'quality'=>['type'=>'string','options'=>'low / high']],'max_reference_images'=>12];
        $facts=Facts::extract($profile,$metadata,self::source());
        self::assertSame('partial_facts_not_admission',$facts['status']);self::assertFalse($facts['deployment_verified']);
        self::assertNotEmpty($facts['source_constraints']);self::assertSame(12,$facts['saved_public_capabilities']['max_reference_images']);
        self::assertArrayNotHasKey('default',$facts['parameters']['prompt']);self::assertArrayNotHasKey('enum',$facts['parameters']['quality']);
        self::assertSame(['low','high'],$facts['parameters']['quality']['display_options']);
        $state=$profile+['facts'=>$facts];$cases=Verification::cases($state,null);
        self::assertNotEmpty($cases);self::assertSame('prepare',$cases[0]['expectation']);
    }

    public static function exactModels(): array
    {return array_map(static fn($p)=>[$p['model_code'],$p['channel_code']],Builder::profiles());}

    public function testNativeSchemaChildrenFalseAndZeroAreKeptWithoutCopyingExamples(): void
    {
        $facts=Facts::declarations(['image'=>['type'=>'array','children'=>['item'=>['type'=>'string','example'=>'private URL']]],
            'audio'=>['type'=>'boolean','default'=>false],'seed'=>['type'=>'integer','default'=>0],
            'quality'=>['type'=>'string','enum'=>'["low","high"]']]);
        self::assertSame('string',$facts['image']['children']['item']['type']);
        self::assertFalse($facts['audio']['default']);self::assertSame(0,$facts['seed']['default']);
        self::assertSame(['low','high'],$facts['quality']['declared_enum']);self::assertStringNotContainsString('private URL',json_encode($facts));
    }

    public function testFastAndDakkaMatricesRemainSeparateAndMissingFourKIsNotInvented(): void
    {
        $profiles=Builder::profiles();
        $get=static fn($model)=>array_values(array_filter($profiles,static fn($p)=>$p['model_code']===$model))[0];
        $fast=Facts::extract($get('gpt-image-2-fast'),['params_schema'=>[]],self::source());
        $dakka=Facts::extract($get('gpt-image-2.5-flare'),['params_schema'=>[]],self::source());
        self::assertSame('2016x672',Facts::pixelCell($fast,'3:1','1K'));
        self::assertSame('2048x688',Facts::pixelCell($dakka,'3:1','1k'));
        self::assertNull(Facts::pixelCell($dakka,'3:1','4k'));self::assertNotNull(Facts::pixelCell($fast,'3:1','4k'));
        self::assertCount(2,$fast['conflicts']);self::assertFalse($fast['source_constraints']['prompt_limit_enforced_by_generic_driver']);
        self::assertArrayNotHasKey('pixel_matrix',Facts::extract($get('gpt-image-2.5'),['params_schema'=>[]],[])['source_constraints']);
    }

    public function testSavedReferenceCountAndDocumentedCountAreNotCollapsedOrPromoted(): void
    {
        $profile=array_values(array_filter(Builder::profiles(),static fn($p)=>$p['model_code']==='gpt-image-2'&&$p['channel_code']==='OpenaiM'))[0];
        $facts=Facts::extract($profile,['params_schema'=>[],'max_reference_images'=>12],[]);
        self::assertSame(12,$facts['saved_public_capabilities']['max_reference_images']);
        self::assertSame(16,$facts['source_constraints']['documented_reference_images']);
        self::assertSame('keep_saved_cap_do_not_expand_from_document',$facts['conflicts'][0]['resolution']);
    }

    public function testMatrixReaderRejectsMissingOrExecutableExpressionsInsteadOfEvaluatingPhp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Facts::matrix('function gptImage2FastSize() { $matrix = system("should never execute"); }','gptImage2FastSize');
    }

    public function testRuntimeAugmentationBindsAndCompilesAgainstItsOwnViewWithoutRewritingRawSavedQ4Evidence(): void
    {
        $original=['params_schema'=>['images'=>['type'=>'array','example'=>['synthetic-example']]],
            'upstream_metadata'=>['model_code'=>'q4-preview-video','channel_code'=>'q4_video']];
        $runtime=$original;$runtime['params_schema']['duration']=['type'=>'integer','default'=>5];
        $row=['metadata'=>$runtime,'original_saved_metadata'=>$original,
            'declaration_fingerprint'=>\app\common\service\power\MarketGenerationReviewedContract::declarationFingerprint($runtime),
            'original_saved_declaration_fingerprint'=>\app\common\service\power\MarketGenerationReviewedContract::declarationFingerprint($original)];
        $before=$row;$metadata=Builder::declarationMetadata($row);self::assertSame($runtime,$metadata);self::assertSame($before,$row);
        $profile=array_values(array_filter(Builder::profiles(),static fn($p)=>$p['model_code']==='q4-preview-video'))[0];
        $entry=(new \ReflectionMethod(Builder::class,'entry'))->invoke(null,$profile,
            ['params_schema'=>$metadata['params_schema'],'material_capabilities'=>[]],['upstream_model'=>'synthetic-q4-deployed']);
        $artifact='Synthetic view augmentation fixture';$source=['kind'=>'gateway_validator','reference'=>'synthetic/q4-view',
            'revision'=>\app\common\service\power\MarketOwnedPlatformContractRules::REVISION,'reviewed_at'=>'2026-11-01T00:00:00+08:00','artifact_sha256'=>hash('sha256',$artifact)];
        $contract=\app\common\service\power\MarketGenerationContractReview::reviewedCatalogue(['version'=>1,'source'=>$source,'entries'=>[$entry]],$artifact)['contracts'][0];
        $metadata[\app\common\service\power\MarketGenerationReviewedContract::PIN]=$contract;
        self::assertSame($row['declaration_fingerprint'],\app\common\service\power\MarketGenerationReviewedContract::selected($metadata)['declaration_fingerprint']);
        self::assertNotSame($row['original_saved_declaration_fingerprint'],$contract['declaration_fingerprint']);
        self::assertSame($original,$row['original_saved_metadata']);
    }
}
