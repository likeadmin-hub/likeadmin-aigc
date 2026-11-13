<?php
namespace Tests\Feature;
use app\common\service\app\aigc_store_visit\{StoreVisitPolicy as P,StoreVisitPrompts};
use app\common\service\app\StudioBuiltinTemplates;
use PHPUnit\Framework\TestCase;
final class StoreVisitMaterialsTest extends TestCase
{
    private function body(): array {return P::body(['images'=>[['uri'=>'uploads/store.png','role'=>'store','reference_slot_id'=>'store'],['uri'=>'uploads/person.png','role'=>'person','reference_slot_id'=>'person']],'person_info'=>'人物穿灰色外套','product_info'=>'已确认的咖啡店','video_selection'=>['duration'=>15],'script'=>'人物走入 @图片1 的门店，保持 @图片2 的外貌。']);}
    public function testRolesAndPersonTextInvalidateConfirmedScripts(): void {
        $b=$this->body();P::assertMaterials($b['images']);$key=P::inputKey($b);$b['person_info']='蓝色外套';self::assertNotSame($key,P::inputKey($b));$b=$this->body();$b['images'][0]['role']='person';self::assertNotSame($key,P::inputKey($b));
    }
    public function testBothMaterialCategoriesAreRequired(): void {$this->expectException(\InvalidArgumentException::class);P::assertMaterials([['role'=>'store']]);}
    public function testUnknownRolesCannotMasqueradeAsStore(): void {$this->expectException(\InvalidArgumentException::class);P::assertMaterials([['role'=>'store'],['role'=>'person'],['role'=>'reference_image']]);}
    public function testTheActualGenerationPromptBindsEveryRoleToItsStableImageOrdinal(): void {
        $b=$this->body();$b['script_input_key']=P::inputKey($b);$prompt=P::prompt($b);self::assertStringContainsString('@图片1 是门店参考图',$prompt);self::assertStringContainsString('@图片2 是人物参考图',$prompt);self::assertStringContainsString('人物穿灰色外套',$prompt);self::assertStringContainsString('不编造地址',$prompt);
    }
    public function testAnalysisReceivesTheMaterialRolesAndFactualConstraints(): void {self::assertStringContainsString('material_roles',StoreVisitPrompts::system());self::assertStringContainsString('person_info',StoreVisitPrompts::system());self::assertStringContainsString('门店布局',StoreVisitPrompts::system());}
    public function testReviewedPortableTemplatesPreserveRolesWithoutGrantingForeignMedia(): void {
        $b=$this->body();foreach($b['images'] as &$image)$image=array_replace($image,['uri'=>'https://cdn.example.test/'.basename($image['uri']),'storage_scope'=>'platform','storage_engine'=>'qcloud','storage_domain'=>'https://cdn.example.test']);unset($image);$b['genre_snapshot']=['code'=>'host_tour','name'=>'人物带逛','instruction'=>'人物走进门店'];
        $copy=StudioBuiltinTemplates::portableBody(P::APP,$b);self::assertSame(['store','person'],array_column($copy['images'],'role'));self::assertSame($b['person_info'],$copy['person_info']);self::assertSame('', $copy['video_selection']['model_id']);self::assertNotEmpty($copy['script_input_key']);
    }
}
