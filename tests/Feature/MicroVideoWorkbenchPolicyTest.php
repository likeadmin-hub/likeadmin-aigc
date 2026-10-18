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
    { $old=['script'=>[], 'roles'=>[['role_id'=>'a','images'=>[['uri'=>'old']]],['role_id'=>'b','images'=>[]]]];$b=$old;$b['roles'][0]['images']=[['uri'=>'new']];$b['scenes']=[['scene_id'=>'1','role_ids'=>['a'],'confirmed'=>true],['scene_id'=>'2','role_ids'=>['b'],'confirmed'=>true]];$b=P::dependencies($b,$old);self::assertFalse($b['scenes'][0]['confirmed']);self::assertTrue($b['scenes'][1]['confirmed']); }
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
}
