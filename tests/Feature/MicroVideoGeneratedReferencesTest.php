<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoContentPlanning as Planning;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchJobs as Jobs;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchWorker as Worker;
use app\common\service\app\aigc_micro_video\MicroVideoGeneratedReferences as References;
use PHPUnit\Framework\TestCase;

final class MicroVideoGeneratedReferencesTest extends TestCase
{
    private function script(array $indices): array
    {
        return ['text'=>'人物接过商品。','continuity'=>'同一房间', 'segments'=>[
            ['sort'=>1,'title'=>'交接','duration'=>15,'prompt'=>"0-3秒：人物拿起商品。\n3-15秒：配角接过并回应。",'image_indices'=>$indices,'start_state'=>'商品在桌上','end_state'=>'配角拿稳商品','continuity'=>'同一房间']
        ]];
    }

    /** @dataProvider bindings */
    public function testGeneratedBindingsOnlyAddressFrozenGlobalImages(array $images,array $indices,array $expected,bool $contract): void
    {
        $request=['snapshot'=>['target_duration'=>15,'images'=>$images,'roles'=>[['role_id'=>'lead','images'=>[['uri'=>'role.png']]]],'product_snapshot'=>['body'=>['images'=>[['uri'=>'product.png']]]]]];
        if($contract)$request+=['planning_contract'=>Planning::contract(['target_duration'=>15],[15],10,3),'video_reference_limit'=>3];
        $result=Worker::parse(['action'=>'rewrite','request_json'=>json_encode($request)],['content'=>json_encode(['script'=>$this->script($indices)])]);
        self::assertSame($expected,$result['script']['segments'][0]['image_indices']);
        self::assertSame('人物接过商品。',$result['script']['text']);
    }

    public static function bindings(): array
    {
        return [
            [[],[0],[],false], [[],[0],[],true],
            [[['uri'=>'first.png'],['uri'=>'second.png']],[1,8,0,-1],[1,0],false],
            [[['uri'=>'first.png'],['uri'=>'second.png']],[1,8,0,-1],[1,0],true],
            [[['uri'=>'first.png']],[],[],true],
        ];
    }

    public function testOldSuccessfulGeneratedResultIsSafeToReadWithoutChangingReceipt(): void
    {
        $result=['script'=>$this->script([0])];$receipt=json_encode(['content'=>json_encode($result)]);
        $row=['id'=>1,'project_id'=>2,'project_revision'=>1,'action'=>'rewrite','status'=>'success','result_json'=>json_encode($result),'request_json'=>json_encode(['snapshot'=>['images'=>[]]]),'receipt_json'=>$receipt,'error'=>'','user_charge_points'=>1,'consumption_id'=>3,'create_time'=>1,'update_time'=>1];
        self::assertSame([],Jobs::dto($row)['result']['script']['segments'][0]['image_indices']);
        self::assertSame($receipt,$row['receipt_json']);
        self::assertSame([0],json_decode($row['result_json'],true)['script']['segments'][0]['image_indices']);
    }

    public function testChangedGlobalImagesCannotRetargetAnOldGeneratedBinding(): void
    {
        $images=[['uri'=>'first.png'],['uri'=>'second.png']];$script=$this->script([1,0]);
        self::assertSame([1,0],References::script($script,['images'=>$images],$images)['segments'][0]['image_indices']);
        self::assertSame([],References::script($script,['images'=>$images],array_reverse($images))['segments'][0]['image_indices']);
        self::assertSame([0],References::script($script,['images'=>$images],[['uri'=>'first.png']])['segments'][0]['image_indices']);
        self::assertSame([],References::script($script,['images'=>$images],[])['segments'][0]['image_indices']);
    }

    public function testValidGeneratedBindingsStillObeyTheSelectedSkuReferenceLimit(): void
    {
        $request=['snapshot'=>['images'=>[['uri'=>'a'],['uri'=>'b']]],'planning_contract'=>Planning::contract(['target_duration'=>15],[15],10,3),'video_reference_limit'=>1];
        $this->expectException(\InvalidArgumentException::class);
        Worker::parse(['action'=>'rewrite','request_json'=>json_encode($request)],['content'=>json_encode(['script'=>$this->script([0,1])])]);
    }

    public function testDeclaredImageCountCannotAuthorizeAnInventedBinding(): void
    {
        $request=['snapshot'=>['target_duration'=>15,'images'=>[]],'image_reference_contract'=>['image_count'=>15,'allowed_image_indices'=>[0]]];
        $result=Worker::parse(['action'=>'rewrite','request_json'=>json_encode($request)],['content'=>json_encode(['script'=>$this->script([0])])]);
        self::assertSame([],$result['script']['segments'][0]['image_indices']);
    }

    public function testWritingContractCountsGlobalImagesWithoutBorrowingNestedImages(): void
    {
        $contract=References::contract(['images'=>[['uri'=>'a'],['uri'=>'b']],'roles'=>[['images'=>[['uri'=>'role']]]],'product_snapshot'=>['body'=>['images'=>[['uri'=>'product']]]]]);
        self::assertSame(2,$contract['image_count']);self::assertSame([0,1],$contract['allowed_image_indices']);
        self::assertSame([],References::contract(['roles'=>[['images'=>[['uri'=>'role']]]]])['allowed_image_indices']);
    }

    public function testRewriteSchemaDoesNotInventFirstImageWhenThereAreNoGlobalImages(): void
    {
        $system=(new \ReflectionMethod(Jobs::class,'system'))->invoke(null,'rewrite',['images'=>[]]);
        preg_match('/输出 (\{"script".*)。，?$/u',$system,$match);
        // The model-facing JSON example must itself be legal for an image-free draft.
        self::assertSame([],json_decode($match[1]??'',true)['script']['segments'][0]['image_indices']??null);
    }
}
