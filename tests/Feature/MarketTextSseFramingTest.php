<?php
namespace Tests\Feature;

use app\common\service\power\MarketTextModelRuntimeService as Runtime;
use PHPUnit\Framework\TestCase;

class MarketTextSseFramingTest extends TestCase
{
    private function decode(array $chunks): array
    {
        $take=new \ReflectionMethod(Runtime::class,'takeSseBlocks'); $take->setAccessible(true);
        $parse=new \ReflectionMethod(Runtime::class,'parseSseBlock'); $parse->setAccessible(true);
        $buffer=''; $events=[];
        foreach ($chunks as $chunk) {
            foreach ($take->invokeArgs(null,[&$buffer,$chunk]) as $block) {
                if (($event=$parse->invoke(null,$block))!==null) $events[]=$event;
            }
        }
        foreach ($take->invokeArgs(null,[&$buffer,'',true]) as $block) {
            if (($event=$parse->invoke(null,$block))!==null) $events[]=$event;
        }
        self::assertSame('',$buffer);
        return $events;
    }

    public function testEveryByteBoundaryPreservesTextUsageAndNativeTermination(): void
    {
        $fixtures=[
            [
                ['choices'=>[['delta'=>['content'=>'{"剧情":"正确返回"}'],'finish_reason'=>null]]],
                ['choices'=>[['delta'=>[],'finish_reason'=>'stop']]],
                ['choices'=>[],'usage'=>['prompt_tokens'=>21,'completion_tokens'=>9,'total_tokens'=>30]],
                '[DONE]',
            ],
            [
                ['type'=>'message_start','message'=>['id'=>'fixture','usage'=>['input_tokens'=>21]]],
                ['type'=>'content_block_delta','index'=>0,'delta'=>['type'=>'text_delta','text'=>'{"剧情":"正确返回"}']],
                ['type'=>'message_delta','delta'=>['stop_reason'=>'end_turn'],'usage'=>['output_tokens'=>9]],
                ['type'=>'message_stop'],
            ],
            [
                ['type'=>'response.created','response'=>['id'=>'fixture','status'=>'in_progress']],
                ['type'=>'response.output_text.delta','output_index'=>0,'content_index'=>0,'delta'=>'{"剧情":"正确返回"}'],
                ['type'=>'response.completed','response'=>['id'=>'fixture','status'=>'completed','output'=>[],
                    'usage'=>['input_tokens'=>21,'output_tokens'=>9,'total_tokens'=>30]]],
            ],
        ];
        foreach ($fixtures as $fixture) {
            $blocks=array_map(static fn($e)=>'data: '.(is_string($e)?$e:json_encode($e,JSON_UNESCAPED_UNICODE)), $fixture);
            $expected=$this->decode([implode("\n\n",$blocks)."\n\n"]);
            self::assertCount(count($fixture),$expected);
            foreach (["\n","\r\n","\r"] as $newline) {
                $wire=implode($newline.$newline,$blocks).$newline.$newline;
                for($cut=1;$cut<strlen($wire);$cut++) {
                    self::assertSame($expected,$this->decode([substr($wire,0,$cut),substr($wire,$cut)]),'split at byte '.$cut);
                }
                self::assertSame($expected,$this->decode(str_split($wire,1)));
            }
        }
    }

    public function testCommentsMultilineDataAndUnterminatedFinalBlock(): void
    {
        $wire=": heartbeat\r\n\r\nevent: message\r\ndata: {\"choices\":\r\ndata: [{\"delta\":{\"content\":\"完整内容\"}}]}\r\n\r\ndata: [DONE]";
        $events=$this->decode(str_split($wire,1));
        self::assertCount(2,$events);
        self::assertSame('完整内容',$events[0]['content']);
        self::assertSame('done',$events[1]['type']);
    }
}
