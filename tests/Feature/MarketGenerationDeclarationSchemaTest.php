<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationDeclarationSchema as Declaration;
use app\common\service\power\MarketGenerationContractReview as Review;
use PHPUnit\Framework\TestCase;

class MarketGenerationDeclarationSchemaTest extends TestCase
{
    public function testExplicitArrayChildrenCanBeReadWithoutChangingTheRawDeclaration(): void
    {
        $raw=['urls'=>['type'=>'array','children'=>['item'=>['type'=>'string']]]];$before=$raw;
        $expanded=Declaration::expand($raw);
        self::assertSame(['type'=>'string'],$expanded['urls']['items']);
        self::assertSame([] ,Review::incomplete($expanded));self::assertSame($before,$raw);
    }
    public function testNestedObjectsPreserveBusinessFieldsNamedDescriptionAndItems(): void
    {
        $raw=['type'=>'object','children'=>['description'=>['type'=>'string'],'items'=>['type'=>'array','children'=>['item'=>[
            'type'=>'object','children'=>['enabled'=>['type'=>'boolean']]]]]]];
        $expanded=Declaration::expand($raw);
        self::assertSame('string',$expanded['properties']['description']['type']);
        self::assertSame('boolean',$expanded['properties']['items']['items']['properties']['enabled']['type']);
        self::assertSame([],Review::incomplete($expanded));
    }
    public function testExamplesAndUntypedChildrenCannotFillUnknownTypes(): void
    {
        $raw=['urls'=>['type'=>'array','example'=>['not a schema'],'children'=>['item'=>['example'=>'not a type']]]];
        self::assertSame(['urls[]'],Review::incomplete(Declaration::expand($raw)));
        self::assertSame(['urls[]'],Review::incomplete(Declaration::expand(['urls'=>['type'=>'array','example'=>['text']]])));
    }
    public function testEquivalentChildFlagsUseTheParentRequiredList(): void
    {
        $field = ['type'=>'string'];
        $schema = ['type'=>'object', 'required'=>['name'], 'properties'=>['name'=>$field,'tag'=>$field],
            'children'=>['name'=>$field + ['required'=>true], 'tag'=>$field + ['required'=>false]]];
        $expanded = Declaration::expand($schema);
        self::assertSame($schema['properties'], $expanded['properties']);
        self::assertSame(['name'], $expanded['required']);
        $schema['children']['tag']['required'] = true;
        $this->expectException(\InvalidArgumentException::class);
        Declaration::expand($schema);
    }

    public function testConflictingChildrenAndItemsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Declaration::expand(['urls'=>['type'=>'array','items'=>['type'=>'number'],'children'=>['item'=>['type'=>'string']]]]);
    }

    public function testCapturedImageEvidenceKeepsPartialTypesSeparateFromCompleteContracts(): void
    {
        $read=static fn($name)=>json_decode(file_get_contents(dirname(__DIR__).'/fixtures/'.$name),true,512,JSON_THROW_ON_ERROR);
        $structures=$read('market-image-structure-review-20261029.json');
        $ledger=Review::ledger($read('canvas-generation-market-contracts.json'),$read('market-legacy-declaration-review-20261029.json'),[],[],[],$structures);
        $partial=array_values(array_filter($ledger['entries'],static fn($r)=>$r['partial_structure_review']!==null));
        self::assertCount(9,$partial);
        foreach($partial as $row){
            self::assertFalse($row['native_contract_importable']);
            self::assertSame('incomplete_not_activated',$row['partial_structure_review']['native_contract_status']);
            self::assertSame('string',$row['partial_structure_review']['fields'][0]['reviewed_item_type']);
        }
        self::assertCount(14,array_filter($ledger['entries'],static fn($r)=>$r['expanded_opaque_fields']!==[]));
        $explicit=array_values(array_filter($partial,static fn($r)=>$r['model_code']==='nano-banana-2.1'))[0];
        self::assertSame([],$explicit['expanded_opaque_fields']);
        $unbound=array_values(array_filter($partial,static fn($r)=>$r['model_code']==='gpt-image-2'&&$r['channel_code']==='OpenAIYI'))[0];
        self::assertFalse($unbound['partial_structure_review']['platform_detail_identity_verified']);
    }
}
