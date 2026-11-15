<?php
namespace Tests\Feature;

use app\common\service\power\MarketPicLipsyncAppRuntimeService as Runtime;
use PHPUnit\Framework\TestCase;

final class PicLipsyncOutputFactsTest extends TestCase
{
    private function facts(array $response,string $url='https://fixture.invalid/result.mp4'): array
    {
        return (new \ReflectionMethod(Runtime::class,'outputFacts'))->invoke(null,$response,$url);
    }
    public function testCompletedQueryMetadataRetainsFractionalSecondsAndParsesResolution(): void
    {
        foreach(['720x1280','720X1280','720 × 1280','720*1280'] as $resolution){
            self::assertSame(['duration'=>10.125,'width'=>720,'height'=>1280],$this->facts(['result'=>['data'=>['output_url'=>'https://fixture.invalid/result.mp4','duration'=>'10.125','resolution'=>$resolution]]]));
        }
    }
    public function testMetadataMustBelongToTheExactOutputAndNeverToTheReferenceVoice(): void
    {
        $response=['duration'=>999,'request'=>['output_url'=>'https://fixture.invalid/result.mp4','duration'=>1.25],
            'result'=>['outputs'=>[
                ['video_url'=>'https://fixture.invalid/other.mp4','duration'=>55],
                ['video_url'=>'https://fixture.invalid/result.mp4','duration'=>10.125,'width'=>1920,'height'=>1080,'resolution'=>'720x1280'],
            ]]];
        self::assertSame(['duration'=>10.125,'width'=>1920,'height'=>1080],$this->facts($response));
        self::assertSame([],$this->facts(['request'=>$response['request']]));
        self::assertSame([],$this->facts($response,'https://fixture.invalid/missing.mp4'));
    }
    public function testInvalidDurationsAndDimensionlessResolutionCannotBecomeBillingUsage(): void
    {
        foreach([0,-1,null,'NaN','Infinity',INF,NAN,[],true] as $duration){
            $facts=$this->facts(['output_url'=>'https://fixture.invalid/result.mp4','duration'=>$duration,'resolution'=>'1080P']);
            self::assertSame(0.0,$facts['duration']);self::assertSame(0,$facts['width']);self::assertSame(0,$facts['height']);
        }
        self::assertSame(0.0,Runtime::inputSeconds(['input_second'=>INF]));
    }
}
