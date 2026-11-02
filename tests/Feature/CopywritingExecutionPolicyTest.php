<?php
namespace Tests\Feature;
use app\common\service\power\{TextModelExecutionPolicy as Policy, MarketTextModelRuntimeService as Runtime};
use PHPUnit\Framework\TestCase;

final class CopywritingExecutionPolicyTest extends TestCase
{
    private function wire(string $code, string $protocol, array $defaults): array
    {
        $model=['id'=>1,'model_code'=>$code,'protocol'=>$protocol];
        $policy=new Policy('aigc_copywriting',Policy::fingerprint($model));
        $params=$policy->generationParams($model,$defaults);
        $method=new \ReflectionMethod(Runtime::class,'requestPayload');$method->setAccessible(true);
        return $method->invoke(null,$protocol,$model,[['role'=>'user','content'=>'正文']], '只生成正文',256,$params);
    }
    public function testQwenOptOutReachesTheActualChatPayload(): void
    {
        $p=$this->wire('qwen3.6-plus','openai_chat',['enable_thinking'=>true,'thinking_budget'=>8000,'top_p'=>0.8]);
        self::assertFalse($p['enable_thinking']);self::assertArrayNotHasKey('thinking_budget',$p);self::assertSame(0.8,$p['top_p']);
    }
    public function testGptResponsesAndChatUseTheirOwnNonReasoningFields(): void
    {
        $p=$this->wire('gpt-5.6-luna','openai_responses',['reasoning'=>['effort'=>'high','summary'=>'auto'],'enable_thinking'=>true]);
        self::assertSame(['effort'=>'none'],$p['reasoning']);self::assertArrayNotHasKey('thinking',$p);self::assertArrayNotHasKey('enable_thinking',$p);
        $chat=$this->wire('gpt-5.6-terra','openai_chat',['reasoning_effort'=>'high','enable_thinking'=>true]);self::assertSame('none',$chat['reasoning_effort']);self::assertArrayNotHasKey('enable_thinking',$chat);
    }
    public function testDeepSeekAndClaudeDisableTypedThinkingWithoutBudgets(): void
    {
        foreach ([['deepseek-v4-pro','openai_chat'],['claude-sonnet-4-6','anthropic_messages']] as [$code,$protocol]) {
            $p=$this->wire($code,$protocol,['thinking'=>['type'=>'enabled','budget_tokens'=>5000],'enable_thinking'=>true]);
            self::assertSame(['type'=>'disabled'],$p['thinking']);
        }
    }
    public function testDeepSeekResponsesPreservesTheExistingOptOut(): void
    {
        $p=$this->wire('deepseek-v4-pro','openai_responses',[]);
        self::assertSame(['effort'=>'none'],$p['reasoning']);
    }
    public function testAnOrdinaryNonReasoningModelDoesNotReceiveUnsupportedThinkingFields(): void
    {
        foreach (['openai_chat','openai_responses'] as $protocol) {
        $p=$this->wire('gpt-4o-mini',$protocol,['temperature'=>0.7]);
        foreach (['thinking','enable_thinking','reasoning','reasoning_effort'] as $key) self::assertArrayNotHasKey($key,$p);
        self::assertSame(0.7,$p['temperature']);
        }
    }
}
