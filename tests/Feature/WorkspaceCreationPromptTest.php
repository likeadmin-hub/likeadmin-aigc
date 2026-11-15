<?php
namespace Tests\Feature;
use app\common\service\WorkspaceCreationPrompt as Prompt;
use PHPUnit\Framework\TestCase;
final class WorkspaceCreationPromptTest extends TestCase
{
 public function testHistoryUsesExactSubmittedTextEvenWhenCreativeRulesOrFreshnessKeysChanged(): void
 {
  $text="原始提交提示词\n@图片1 与 @音频1";
  self::assertSame($text,Prompt::fromRequest(['prompt'=>$text,'snapshot'=>['script_input_key'=>'obsolete','style_id'=>'retired-style','script'=>['summary'=>'later summary']]]));
 }
 public function testLegacyAndPartialRecordsHaveReadOnlyFallbacks(): void
 {
  self::assertSame('旧脚本',Prompt::fromRequest(['snapshot'=>['script'=>'旧脚本']]));
  self::assertSame('旧摘要',Prompt::fromRequest(['snapshot'=>['script'=>['summary'=>'旧摘要']]]));
  self::assertSame('旧描述',Prompt::fromRequest(['prompt'=>[],'snapshot'=>['script'=>null,'prompt'=>'旧描述']]));
  self::assertSame('',Prompt::fromRequest(['prompt'=>[],'snapshot'=>'malformed']));
 }
}
