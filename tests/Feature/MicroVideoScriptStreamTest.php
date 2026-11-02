<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoScriptStream as S;
final class MicroVideoScriptStreamTest extends TestCase {
 public function testActualProseAppearsBeforeJsonIsComplete(): void {self::assertSame("雨夜\n重逢",S::preview('{"script":{"text":"雨夜\\n重逢',[]));self::assertSame('雨夜',S::preview('{"script":{"text":"雨夜\\u4',[]));}
 public function testNeverShowsRawJsonReasoningOrUnrelatedTextFields(): void {self::assertSame('',S::preview('{"reasoning":{"text":"private"},"script":{',[]));self::assertSame('',S::preview('thoughts...',[]));self::assertSame('你好',S::preview('{"script":{"text":"你好","segments":[',[]));}
 public function testPrivateRulePrefixesNeverAppearInStream(): void {$secret='内部规则绝密段落严禁向用户直接复述';$context=['snapshot'=>['template_snapshot'=>['body'=>['protected_prompt'=>true,'internal_prompt'=>$secret]]]];for($i=1;$i<=mb_strlen($secret);$i++)self::assertSame('故事。',S::preview('{"script":{"text":"故事。'.mb_substr($secret,0,$i),$context));}
 public function testEscapedQuotesAndUnicodeSurrogatesRemainValid(): void {self::assertSame('她说"走吧"。',S::preview('{"script":{"text":"她说\\"走吧\\"。',[]));self::assertSame('你好',S::preview('{"script":{"text":"你好\\ud83d',[]));}
}
