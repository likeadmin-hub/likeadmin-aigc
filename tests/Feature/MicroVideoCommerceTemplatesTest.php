<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoCommerceTemplates as Catalog;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchPolicy as Policy;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchRecords as Records;

final class MicroVideoCommerceTemplatesTest extends TestCase
{
    public function testBundledCatalogContains31DistinctCompleteShortTemplatesAnd10NewCategories(): void
    {
        $catalog=Catalog::catalog();
        self::assertSame(1,$catalog['version']);
        self::assertCount(31,$catalog['templates']);
        self::assertCount(10,$catalog['categories']);
        self::assertCount(31,array_unique(array_column($catalog['templates'],'code')));
        self::assertCount(10,array_unique(array_column($catalog['categories'],'code')));
        $categories=array_column($catalog['categories'],'code');
        foreach($catalog['templates'] as $template){
            self::assertContains($template['category'],$categories);
            self::assertContains($template['default_duration'],[10,15]);
            self::assertSame('commerce',$template['workflow_type']);
            self::assertSame('9:16',$template['ratio']);
            self::assertSame('/resource/image/apps/aigc_micro_video/templates/commerce',dirname($template['cover_image']));
            self::assertSame($template['code'],pathinfo($template['cover_image'],PATHINFO_FILENAME));
            self::assertTrue($template['protected_prompt']);
            self::assertNotEmpty($template['internal_prompt']);
            self::assertNotEmpty($template['script']);
            self::assertNotEmpty($template['segments']);
            self::assertSame($template['default_duration'],array_sum(array_column($template['segments'],'duration')));
            foreach($template['segments'] as $index=>$segment){
                self::assertSame($index+1,$segment['sort']);
                foreach(['title','prompt','start_state','end_state','continuity'] as $field)self::assertNotSame('',trim($segment[$field]));
                self::assertSame([],$segment['image_indices']);
            }
            $end=0;
            foreach($template['beats'] as $beat){self::assertSame($end,$beat['start']);self::assertGreaterThan($beat['start'],$beat['end']);$end=$beat['end'];}
            self::assertSame($template['default_duration'],$end);
            self::assertNotEmpty($template['roles']);
            foreach($template['roles'] as $role){self::assertTrue($role['fixed_identity']);self::assertSame([],$role['images']);self::assertContains($role['group'],['lead','support','crowd']);}
        }
    }

    public function testExistingCategoryCodesAndOrderArePreservedBeforeNewCategories(): void
    {
        $categories=Records::categories()['lists'];
        self::assertSame(['story','campus','office','palace','kungfu','twist','commerce','property'],array_column(array_slice($categories,0,8),'code'));
        self::assertSame(Catalog::catalog()['categories'],array_slice($categories,8));
        self::assertCount(18,array_unique(array_column($categories,'code')));
    }

    public function testHttpRoundTripKeepsTemplateDurationSegmentsAndRolesTyped(): void
    {
        $body=Policy::normalizeValues(['default_duration'=>'15','durations'=>['15'],'segments'=>[['sort'=>'1','duration'=>'15','image_indices'=>['0']]],'roles'=>[['fixed_identity'=>'true']]]);
        self::assertSame(15,$body['default_duration']);
        self::assertSame([15],$body['durations']);
        self::assertSame(['sort'=>1,'duration'=>15,'image_indices'=>[0]],$body['segments'][0]);
        self::assertTrue($body['roles'][0]['fixed_identity']);
    }

    /** @dataProvider invalidBundledData */
    public function testMalformedBundledCatalogFailsBeforeImport(string $mutation): void
    {
        $catalog=Catalog::catalog();
        switch($mutation){
            case 'duplicate_code':$catalog['templates'][1]['code']=$catalog['templates'][0]['code'];break;
            case 'duplicate_category':$catalog['categories'][1]['code']=$catalog['categories'][0]['code'];break;
            case 'external_cover':$catalog['templates'][0]['cover_image']='https://example.invalid/cover.jpg';break;
            case 'wrong_duration':$catalog['templates'][0]['segments'][0]['duration']=99;break;
            case 'typed_duration':$catalog['templates'][0]['default_duration']='15';break;
            case 'unbound_asset':$catalog['templates'][0]['roles'][0]['images']=[['uri'=>'uploads/untrusted.png']];break;
            case 'missing_internal_prompt':unset($catalog['templates'][0]['internal_prompt']);break;
        }
        $method=new \ReflectionMethod(Catalog::class,'validate');
        $this->expectException(\RuntimeException::class);$method->invoke(null,$catalog);
    }

    public static function invalidBundledData(): array
    {return array_map(static fn($case)=>[$case],['duplicate_code','duplicate_category','external_cover','wrong_duration','typed_duration','unbound_asset','missing_internal_prompt']);}
}
