<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchPolicy as P;
final class MicroVideoWorkbenchPolicyTest extends TestCase
{
    public function testRevisionMustMatchEvenWhenOlderEditorTriesToRestore(): void
    { $this->expectException(\InvalidArgumentException::class); P::revision(['revision'=>3],2); }
    public function testStableRoleIdentityAndDuplicateRoleIdsAreChecked(): void
    { $this->expectException(\InvalidArgumentException::class); P::body('project',['roles'=>[['role_id'=>'lead'],['role_id'=>'lead']]]); }
    public function testScriptEditInvalidatesSceneConfirmations(): void
    { $b=P::dependencies(['script'=>['text'=>'new'],'scenes'=>[['scene_id'=>'s','confirmed'=>true,'images'=>[['uri'=>'x']]]]],['script'=>['text'=>'old']]);self::assertFalse($b['scenes'][0]['confirmed']);self::assertSame('stale',$b['scenes'][0]['status']); }
    public function testRoleEditInvalidatesOnlyItsScenes(): void
    { $old=['script'=>[], 'roles'=>[['role_id'=>'a','images'=>[['uri'=>'old']]],['role_id'=>'b','images'=>[]]]];$b=$old;$b['roles'][0]['images']=[['uri'=>'new']];$b['scenes']=[['scene_id'=>'1','role_ids'=>['a'],'confirmed'=>true],['scene_id'=>'2','role_ids'=>['b'],'confirmed'=>true]];$old['scenes']=$b['scenes'];$b=P::dependencies($b,$old);self::assertFalse($b['scenes'][0]['confirmed']);self::assertTrue($b['scenes'][1]['confirmed']); }
    public function testCanonicalFingerprintIgnoresObjectKeyOrderingButRetainsArrayOrder(): void
    {self::assertSame(P::hash(['x'=>1,'y'=>2]),P::hash(['y'=>2,'x'=>1]));self::assertNotSame(P::hash(['x'=>[1,2]]),P::hash(['x'=>[2,1]]));}
    public function testPureBriefParsingDoesNotInventProductFacts(): void
    {$b=P::brief(['goal'=>'介绍商品','target_duration'=>60]);self::assertSame('介绍商品',$b['brief']['goal']);self::assertSame(60,$b['brief']['target_duration']);}
    public function testUnknownActionsCannotBecomePaidCalls(): void
    {$this->expectException(\InvalidArgumentException::class);P::action('video');}
    public function testQuoteBindsSelectionAndCurrentRevision(): void
    {$a=['project_revision'=>1,'selection'=>['model_id'=>'a']];$b=$a;$b['selection']['model_id']='b';self::assertNotSame(P::hash($a),P::hash($b));}
    public function testUnknownSubmissionIsNeverRetryable(): void
    {$this->expectException(\InvalidArgumentException::class);P::retryable(['status'=>'unknown','receipt_json'=>'{}','consumption_id'=>1]);}
    public function testDuplicateSceneSortCannotSilentlyDiscardReferenceGraph(): void
    {$this->expectException(\InvalidArgumentException::class);P::body('project',['roles'=>[],'scenes'=>[['scene_id'=>'a','sort'=>1],['scene_id'=>'b','sort'=>1]]]);}
    public function testSceneSortMustReferenceAnExistingSegment(): void
    {$this->expectException(\InvalidArgumentException::class);P::body('project',['script'=>['segments'=>[['duration'=>15]]],'roles'=>[],'scenes'=>[['scene_id'=>'outside','sort'=>2]]]);}
    public function testPublicRecordImagesNeverExposeSourceOwnerOrFileIdentity(): void
    {$dto=\app\common\service\app\aigc_micro_video\MicroVideoWorkbenchRecords::dto(['id'=>1,'kind'=>'role','title'=>'role','user_id'=>1,'revision'=>1,'status'=>1,'visibility'=>'private','create_time'=>1,'update_time'=>1,'body_json'=>json_encode(['images'=>[['uri'=>'uploads/a.png','url'=>'/uploads/a.png','tenant_id'=>77,'user_id'=>88,'file_id'=>99,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']]])]);$image=$dto['body']['images'][0];self::assertArrayNotHasKey('tenant_id',$image);self::assertArrayNotHasKey('user_id',$image);self::assertArrayNotHasKey('file_id',$image);self::assertSame('tenant',$image['storage_scope']);}
    public function testExplicitCurrentSceneReviewClearsStaleButAnotherEditInvalidatesAgain(): void
    {
        $old=['script'=>['text'=>'current','segments'=>[['duration'=>15]]],'roles'=>[['role_id'=>'lead','images'=>[['uri'=>'image1']]]],'scenes'=>[['scene_id'=>'s1','sort'=>1,'role_ids'=>['lead'],'mode'=>'reference','images'=>[],'confirmed'=>true]]];
        $edited=$old;$edited['roles'][0]['images']=[['uri'=>'image2']];$stale=P::dependencies($edited,$old);self::assertSame('stale',$stale['scenes'][0]['status']);self::assertFalse($stale['scenes'][0]['confirmed']);
        $reviewed=$stale;$reviewed['scenes'][0]['confirmed']=true;$ready=P::dependencies($reviewed,$stale);self::assertSame('ready',$ready['scenes'][0]['status']);self::assertNotEmpty($ready['scenes'][0]['dependency_hash']);[$segments,$images]=\app\common\service\app\aigc_micro_video\MicroVideoWorkbench::executionReferences($ready);self::assertSame('image2',$images[0]['uri']);
        $editedAgain=$ready;$editedAgain['roles'][0]['images']=[['uri'=>'image3']];$again=P::dependencies($editedAgain,$ready);self::assertFalse($again['scenes'][0]['confirmed']);self::assertSame('stale',$again['scenes'][0]['status']);
    }
    public function testEditingSceneAndConfirmingInSameSaveStillRequiresAReview(): void
    {$old=['script'=>['text'=>'current'],'roles'=>[],'scenes'=>[['scene_id'=>'s','sort'=>1,'role_ids'=>[],'description'=>'old','confirmed'=>false,'status'=>'stale']]];$new=$old;$new['scenes'][0]['description']='new';$new['scenes'][0]['confirmed']=true;$r=P::dependencies($new,$old);self::assertFalse($r['scenes'][0]['confirmed']);self::assertSame('stale',$r['scenes'][0]['status']);}
    public function testRefreshingASignedPreviewUrlDoesNotInvalidateTheSameOwnedImage(): void
    {$old=['script'=>['text'=>'same'],'roles'=>[['role_id'=>'a','images'=>[['uri'=>'uploads/a.png','url'=>'https://store.invalid/a?old','storage_scope'=>'tenant','storage_engine'=>'qcloud','storage_domain'=>'https://store.invalid']]]],'scenes'=>[['scene_id'=>'s','sort'=>1,'role_ids'=>['a'],'images'=>[],'confirmed'=>true]]];$new=$old;$new['roles'][0]['images'][0]['url']='https://store.invalid/a?fresh';$r=P::dependencies($new,$old);self::assertTrue($r['scenes'][0]['confirmed']);self::assertSame('ready',$r['scenes'][0]['status']);}
    public function testRealDefaultRequestFilterShapeIsCanonicalizedWithoutChangingText(): void
    {
        $raw=['script_stale'=>false,'target_duration'=>30,'brief'=>['target_duration'=>30,'requirements'=>'0'],'script'=>['text'=>'0','segments'=>[['sort'=>1,'duration'=>15,'image_indices'=>[0,1]]]],'roles'=>[['role_id'=>'lead','name'=>'0','fixed_identity'=>true]],'scenes'=>[['scene_id'=>'s1','sort'=>1,'role_ids'=>['lead'],'confirmed'=>false,'product_visible'=>false]],'facts'=>['duration'=>'0','confirmed'=>'0','fixed_identity'=>'0']];
        $request=(new \app\Request())->withPost(['body'=>$raw]);$filtered=$request->post()['body'];self::assertSame('1',$filtered['roles'][0]['fixed_identity']);self::assertSame('',$filtered['scenes'][0]['confirmed']);self::assertSame(['0','1'],$filtered['script']['segments'][0]['image_indices']);
        $body=P::body('project',$filtered);self::assertTrue($body['roles'][0]['fixed_identity']);self::assertFalse($body['scenes'][0]['confirmed']);self::assertFalse($body['scenes'][0]['product_visible']);self::assertFalse($body['script_stale']);self::assertSame(30,$body['brief']['target_duration']);self::assertSame(15,$body['script']['segments'][0]['duration']);self::assertSame(1,$body['script']['segments'][0]['sort']);self::assertSame([0,1],$body['script']['segments'][0]['image_indices']);self::assertSame(1,$body['scenes'][0]['sort']);self::assertSame('0',$body['script']['text']);self::assertSame($raw['facts'],$body['facts']);
    }
    /** @dataProvider booleanRepresentations */
    public function testWorkbenchBooleanRepresentationsAreExplicitAndTemplateRolesAreRecursive(mixed $input,bool $expected): void
    {$body=P::body('template',['description'=>'0','durations'=>['15','30'],'roles'=>[['role_id'=>'lead','fixed_identity'=>$input]],'beats'=>[['start'=>'0','end'=>'2.5','description'=>'0']]]);self::assertSame($expected,$body['roles'][0]['fixed_identity']);self::assertSame([15,30],$body['durations']);self::assertSame(0,$body['beats'][0]['start']);self::assertSame(2.5,$body['beats'][0]['end']);self::assertSame('0',$body['beats'][0]['description']);}
    public static function booleanRepresentations(): array
    {return [[true,true],[false,false],[1,true],[0,false],['1',true],['0',false],['',false],['true',true],['false',false],[' FALSE ',false]];}
    public function testLegacyStoredDtosAndNestedSnapshotsReturnTheSameAuthoritativeTypes(): void
    {
        $raw=['script_stale'=>'false','template'=>['id'=>'9','revision'=>'2'],'brief'=>['target_duration'=>'30'],'script'=>['text'=>'0','segments'=>[['duration'=>'15','sort'=>'1','image_indices'=>['0']]]],'roles'=>[['role_id'=>'lead','fixed_identity'=>'0']],'scenes'=>[['scene_id'=>'s1','sort'=>'1','role_ids'=>['lead'],'confirmed'=>'1','product_visible'=>'false']],'template_snapshot'=>['body'=>['roles'=>[['role_id'=>'tlead','fixed_identity'=>'1']],'durations'=>['15']]],'product_snapshot'=>['body'=>['facts'=>['confirmed'=>'0','duration'=>'0']]]];
        $dto=\app\common\service\app\aigc_micro_video\MicroVideoWorkbenchRecords::dto(['id'=>1,'kind'=>'project','title'=>'project','user_id'=>1,'revision'=>1,'status'=>1,'visibility'=>'private','create_time'=>1,'update_time'=>1,'body_json'=>json_encode($raw)]);$body=$dto['body'];self::assertFalse($body['script_stale']);self::assertFalse($body['roles'][0]['fixed_identity']);self::assertTrue($body['scenes'][0]['confirmed']);self::assertFalse($body['scenes'][0]['product_visible']);self::assertSame([0],$body['script']['segments'][0]['image_indices']);self::assertSame(15,$body['script']['segments'][0]['duration']);self::assertSame(['id'=>9,'revision'=>2],$body['template']);self::assertTrue($body['template_snapshot']['body']['roles'][0]['fixed_identity']);self::assertSame(['confirmed'=>'0','duration'=>'0'],$body['product_snapshot']['body']['facts']);
    }
    public function testLegacyFalseFlagsDoNotBecomeTruthyExecutionRequirements(): void
    {$body=['script_stale'=>'false','script'=>['segments'=>[['duration'=>'15','sort'=>'1']]],'roles'=>[['role_id'=>'lead','fixed_identity'=>'false','images'=>[]]],'scenes'=>[['scene_id'=>'s1','sort'=>'1','role_ids'=>['lead'],'mode'=>'reference','images'=>[['uri'=>'frame']],'confirmed'=>'1','product_visible'=>'0']]];[$segments,$images]=\app\common\service\app\aigc_micro_video\MicroVideoWorkbench::executionReferences($body);self::assertSame('frame',$images[0]['uri']);self::assertSame(15,$segments[0]['duration']);}
    public function testMalformedBooleanIsRejectedInsteadOfBecomingAnImplicitRequirement(): void
    {$this->expectException(\InvalidArgumentException::class);P::body('role',['fixed_identity'=>'maybe']);}
    public function testUnchangedLegacyHttpScalarsDoNotInvalidateSceneDependencies(): void
    {$original=['script'=>['text'=>'0','segments'=>[['sort'=>1,'duration'=>15,'image_indices'=>[0]]]],'roles'=>[['role_id'=>'lead','fixed_identity'=>false]],'scenes'=>[['scene_id'=>'s1','sort'=>1,'role_ids'=>['lead'],'confirmed'=>true,'product_visible'=>false,'status'=>'ready']]];$legacy=(new \app\Request())->withPost(['body'=>$original])->post()['body'];$canonical=P::body('project',$legacy);$saved=P::dependencies($canonical,$legacy);self::assertTrue($saved['scenes'][0]['confirmed']);self::assertSame('ready',$saved['scenes'][0]['status']);self::assertFalse($saved['roles'][0]['fixed_identity']);}
}
