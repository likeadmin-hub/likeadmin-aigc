<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaVisualDependencies as Dependencies;
use PHPUnit\Framework\TestCase;

class ShortDramaVisualDependenciesTest extends TestCase
{
    private function plan(): array
    {
        return ['subjects' => [
            ['id'=>'p1', 'name'=>'主角', 'visual_prompt'=>'蓝色外套', 'raw_image'=>'uploads/blue.png'],
            ['id'=>'p2', 'name'=>'路人', 'visual_prompt'=>'灰色外套'],
        ], 'locations'=>[['id'=>'s1', 'name'=>'街道', 'visual_prompt'=>'白天街道', 'image'=>'uploads/street.png']]];
    }

    public function testCaptureTracksOnlyBoundRolesAndScenesWithoutChangingPlan(): void
    {
        $plan = $this->plan(); $before = serialize($plan);
        $snapshot = Dependencies::capture($plan, ['subject_ref_ids'=>['p1'], 'scene_ref_id'=>'s1']);
        self::assertSame(['p1'], array_keys($snapshot['subjects']));
        self::assertSame(['s1'], array_keys($snapshot['scenes']));
        self::assertFalse($snapshot['unknown']);
        self::assertSame($before, serialize($plan));
    }

    public function testAppearanceAndSelectedImagesChangeFingerprintButOtherRolesDoNot(): void
    {
        $plan=$this->plan(); $before=Dependencies::catalog($plan);
        $plan['subjects'][0]['visual_prompt']='红色外套';
        $plan['locations'][0]['image']='uploads/night.png';
        $after=Dependencies::catalog($plan);
        self::assertNotSame($before['subjects']['p1']['fingerprint'], $after['subjects']['p1']['fingerprint']);
        self::assertNotSame($before['scenes']['s1']['fingerprint'], $after['scenes']['s1']['fingerprint']);
        self::assertSame($before['subjects']['p2'], $after['subjects']['p2']);
        $plan['subjects'][0]['raw_image']='uploads/red.png';
        self::assertNotSame($after['subjects']['p1']['fingerprint'], Dependencies::catalog($plan)['subjects']['p1']['fingerprint']);
    }

    public function testRenameModelAndSignedUrlRenewalDoNotInvalidateMaterial(): void
    {
        $plan=$this->plan(); $plan['subjects'][0]['raw_image']='';
        $plan['subjects'][0]['image']='https://cdn.test/image.png?Expires=1&Signature=old&width=512';
        $before=Dependencies::catalog($plan);
        $plan['subjects'][0]['name']='新名字'; $plan['subjects'][0]['model_id']='new-model';
        $plan['subjects'][0]['image']='https://cdn.test/image.png?width=512&Signature=new&Expires=9';
        self::assertSame($before['subjects']['p1']['fingerprint'], Dependencies::catalog($plan)['subjects']['p1']['fingerprint']);
    }

    public function testExplicitAndActualReferencesAreIncluded(): void
    {
        $snapshot=Dependencies::capture($this->plan(), [], ['params'=>['selected_subject_ids'=>['p2']]], [
            ['asset_type'=>'subject_image', 'meta'=>['subject_id'=>'p1']],
            ['asset_type'=>'scene_image', 'meta'=>['item_id'=>'s1']],
        ]);
        self::assertCount(2,$snapshot['subjects']); self::assertCount(1,$snapshot['scenes']);
    }

    public function testLegacyNamedReferencesResolveOnlyWhenUnambiguous(): void
    {
        $plan=$this->plan();
        self::assertFalse(Dependencies::capture($plan, ['subject_ref_ids'=>['主角'], 'scene_name'=>'街道'])['unknown']);
        $plan['subjects'][1]['name']='主角';
        $snapshot=Dependencies::capture($plan, ['subject_ref_ids'=>['主角','missing']]);
        self::assertTrue($snapshot['unknown']); self::assertSame([],$snapshot['subjects']);
    }

    public function testVideoKeepsOldImageBaselineEvenWhenCreatedAfterAppearanceEdit(): void
    {
        $plan=$this->plan(); $shot=['subject_ref_ids'=>['p1']];
        $image=Dependencies::capture($plan,$shot);
        $plan['subjects'][0]['visual_prompt']='红色外套';
        $video=Dependencies::inherit(Dependencies::capture($plan,$shot),$image);
        self::assertCount(2,$video['subjects']['p1']['fingerprints']);
        self::assertContains($image['subjects']['p1']['fingerprints'][0],$video['subjects']['p1']['fingerprints']);
        self::assertSame($video,Dependencies::inherit($video,$image), 'repeated references do not grow provenance');
    }

    public function testLegacyInputsStayUnknownAndFreshRegenerationGetsNewBaseline(): void
    {
        $plan=$this->plan(); $shot=['subject_ref_ids'=>['p1']];
        $fresh=Dependencies::capture($plan,$shot);
        self::assertTrue(Dependencies::inherit($fresh,null)['unknown']);
        self::assertFalse(Dependencies::inherit($fresh,$fresh)['unknown']);
        self::assertCount(1,Dependencies::inherit($fresh,$fresh)['subjects']['p1']['fingerprints']);
    }
}
