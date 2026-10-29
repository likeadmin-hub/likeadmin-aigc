<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStructuredResponse as Response;
use PHPUnit\Framework\TestCase;

final class ShortDramaStructuredResponseBoundaryTest extends TestCase
{
    /** @dataProvider valid */
    public function testPreservesCompleteUnambiguousObjects($content, array $expected): void
    {
        self::assertSame($expected, Response::decode(['content'=>$content]));
    }
    public static function valid(): array
    {
        $rows=[];
        foreach (['正文','包含 {括号} 和 [列表]','台词："你好"','反斜线\\与换行\n','😀中文'] as $value) {
            $data=['title'=>$value,'subjects'=>[['id'=>'p1','name'=>'人物']]];
            $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            foreach ([$json,"\xEF\xBB\xBF".$json,"```json\n".$json."\n```","\xEF\xBB\xBF```json\n".$json."\n```",'以下是结果：'.$json.'生成完毕。'] as $input) $rows[]=[$input,$data];
        }
        $rows[]=[json_encode(['data'=>['result'=>['plan'=>['payload'=>['title'=>'深层']]]]]),['title'=>'深层']];
        $rows[]=[[['type'=>'text','text'=>'{"title":'],['type'=>'output_text','text'=>'"分块"}']],['title'=>'分块']];
        return $rows;
    }
    /** @dataProvider invalid */
    public function testRejectsAmbiguousOrWrongTypesWithoutPhpWarnings($content): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(422);
        Response::decode(['content'=>$content]);
    }
    public static function invalid(): array
    {
        return array_map(static fn($v)=>[$v],[
            null,42,true,[],['title'=>'结构值'],[['type'=>'reasoning','text'=>'{"title":"推理"}']],
            [['type'=>'text','text'=>['bad']]],'[{"title":"列表"}]',
            '{"title":"一"}{"title":"二"}','{"title":"一"}\n```json\n{"diagnostic":true}',
            '{"title":"重复","title":"覆盖"}','{"subjects":[{"id":"p","id":"q"}]}',
            '{"title":"重复","\u0074itle":"转义重复"}',
            '{"title":"完整"}{"unfinished":', '{"title":"残缺"', '{"title":"错括号"]}',
            '说明[{"title":"列表"}]','"{\\"title\\":\\"字符串\\"}"',
            '没有JSON', '{"data":[{"title":"列表封装"}]}',
            '{"title":"未闭合字符串}', '{"title":NaN}', '{"title":undefined}'
        ]);
    }
    public function testRefusalAndTruncationAreNotFormatRepair(): void
    {
        foreach (['refusal'=>403,'length'=>413] as $reason=>$code) {
            try { Response::decode(['content'=>'{"title":"不应接受"}','finish_reason'=>$reason]); self::fail('Expected failure'); }
            catch (\RuntimeException $e) { self::assertSame($code,$e->getCode()); }
        }
    }
    public function testPartialRecoveryDoesNotCastStructuredContentToString(): void
    {
        self::assertSame([],Response::partialObjectPrefix(['content'=>['bad'=>'object']]));
        self::assertNull(Response::partialStoryboard(['content'=>['bad'=>'object']]));
    }
    public function testHistoricalDiagnosticEnvelopeIsExplicitlyCompatibleButNotNewBusinessJson(): void
    {
        self::assertSame(['title'=>'历史正文'],Response::decode(['content'=>"{\"title\":\"历史正文\"}\n```json\n{\"diagnostic\":true}\n```"],false));
        $this->expectExceptionCode(422);
        Response::decode(['content'=>'{"title":"原文"}{"title":"另一正文"}'],false);
    }
}
