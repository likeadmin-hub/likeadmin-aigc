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
    public function testConflictingChildrenAndItemsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Declaration::expand(['urls'=>['type'=>'array','items'=>['type'=>'number'],'children'=>['item'=>['type'=>'string']]]]);
    }
}
