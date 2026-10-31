<?php
namespace Tests\Feature;
use app\common\service\ai\GenerationSubmission;
use PHPUnit\Framework\TestCase;
final class GenerationSubmissionContractTest extends TestCase
{
    public function testAdmissionDoesNotChangeReadEstimateSaveOrUploadContracts(): void
    {
        foreach ([['App.Aigc_Video.Generate','estimate'],['App.Aigc_Canvas.Project','save'],['App.Aigc_Music.Asset','upload_audio'],['App.Aigc_Music.Export','download'],['App.Aigc_Micro_Video.Frame','confirm']] as [$controller,$action]) self::assertSame('',GenerationSubmission::operation($controller,$action));
        foreach ([['App.Aigc_Product_Suite.Generate','index'],['App.Aigc_Canvas.Generate','video'],['App.Smart_Clip.Generate','index'],['App.Aigc_Micro_Video.Frame','generate'],['App.Aigc_Music.Export','wav']] as [$controller,$action]) self::assertNotSame('',GenerationSubmission::operation($controller,$action));
    }
    public function testFingerprintPreservesFalseZeroMaterialOrderAndNestedFields(): void
    {
        $a=['sound'=>false,'duration'=>0,'refs'=>['a','b'],'nested'=>['quality'=>'4K','seed'=>0]];
        self::assertSame(GenerationSubmission::fingerprint($a),GenerationSubmission::fingerprint(array_reverse($a,true)));
        foreach ([array_replace($a,['sound'=>'']),array_replace($a,['duration'=>'0']),array_replace($a,['refs'=>['b','a']]),array_replace($a,['nested'=>['quality'=>'4K','seed'=>1]])] as $other) self::assertNotSame(GenerationSubmission::fingerprint($a),GenerationSubmission::fingerprint($other));
    }
}
