<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchWorker as Worker;
use PHPUnit\Framework\TestCase;

final class MicroVideoWorkbenchJsonTest extends TestCase
{
    private function decode(string $content): array
    {
        return (new \ReflectionMethod(Worker::class, 'decodeContent'))->invoke(null, $content);
    }

    public function testRecoversOneExtraClosingBraceWithoutChangingAnyValues(): void
    {
        $valid = '{"script":{"text":"洋芋上桌","segments":[{"continuity":"同一小院"}]}}';
        $malformed = str_replace('}]}}', '}}]}}', $valid);
        self::assertSame(json_decode($valid, true), $this->decode($malformed));
    }

    public function testValidQuotedBracketsAndEscapedQuotesRemainLiteral(): void
    {
        $value = ['script'=>['text'=>'说：“看这个 } ] { [”，引号 " 和反斜线 \\ 都是正文。','segments'=>[]]];
        self::assertSame($value, $this->decode(json_encode($value, JSON_UNESCAPED_UNICODE)));
        $valid = json_encode(['roles'=>[['name'=>'literal } ] \\ "']]], JSON_UNESCAPED_UNICODE);
        self::assertSame(json_decode($valid, true), $this->decode(substr($valid, 0, -2).'}'.substr($valid, -2)));
    }

    /** @dataProvider invalidContent */
    public function testDoesNotInventMissingContentOrRepairOtherSyntax(string $content): void
    {
        $this->expectException(\JsonException::class);
        $this->decode($content);
    }

    public static function invalidContent(): array
    {
        return [
            ['{"script":{"text":"unfinished"'],
            ['{"roles":[{"name":"a"},]}'],
            ['{"roles":[{"name":}]}'],
            ['{"roles":[]}}}'],
            ['{"roles":[]} prose'],
            ['{"roles":[{"name":"unescaped " quote"}]}'],
        ];
    }

    public function testRecoveredContentStillPassesThroughPrivatePromptGuard(): void
    {
        $secret = 'Private server-only camera instruction alpha bravo charlie.';
        $valid = json_encode(['script'=>['text'=>$secret,'segments'=>[['continuity'=>'same']]]]);
        $malformed = str_replace('}]}}', '}}]}}', $valid);
        $row = ['action'=>'rewrite','request_json'=>json_encode(['snapshot'=>['template_snapshot'=>['body'=>['protected_prompt'=>true,'internal_prompt'=>$secret]]]])];
        $this->expectException(\InvalidArgumentException::class);
        Worker::parse($row, ['content'=>$malformed]);
    }
    public function testPreparationCanSaveTextBeforeImageReferencesAreBound(): void
    {
        $segment = ['sort'=>1,'title'=>'洋芋上桌','duration'=>15,'prompt'=>"0-3秒：掌勺人拿起洋芋。\n3-15秒：时间跳切后食客接过熟制洋芋。",'image_indices'=>[],'start_state'=>'洋芋在篮中','end_state'=>'熟制洋芋在桌上','continuity'=>'同一小院'];
        $row = ['action'=>'rewrite','request_json'=>json_encode(['snapshot'=>['target_duration'=>15],'continuity'=>'同一小院'])];
        $result = Worker::parse($row, ['content'=>json_encode(['script'=>['text'=>'洋芋上桌','segments'=>[$segment]]])]);
        self::assertSame([], $result['script']['segments'][0]['image_indices']);
        // The actual execution/legacy validator must still require a real binding.
        $this->expectException(\InvalidArgumentException::class);
        \app\common\service\app\aigc_micro_video\MicroVideoPolicy::segments([$segment],[15],1,1,10,true);
    }

    public function testPreparationKeepsTheSkuTimingContractWithUnboundImages(): void
    {
        $segment = ['sort'=>1,'title'=>'洋芋上桌','duration'=>15,'prompt'=>"0-3秒：掌勺人拿起洋芋。\n3-15秒：时间跳切后食客接过熟制洋芋。",'image_indices'=>[],'start_state'=>'洋芋在篮中','end_state'=>'熟制洋芋在桌上','continuity'=>'同一小院'];
        $contract = \app\common\service\app\aigc_micro_video\MicroVideoContentPlanning::contract(['target_duration'=>15,'mode'=>'theme'],[10,15],10,3);
        $row = ['action'=>'rewrite','request_json'=>json_encode(['snapshot'=>['target_duration'=>15],'planning_contract'=>$contract,'video_reference_limit'=>3,'continuity'=>'同一小院'])];
        $result = Worker::parse($row, ['content'=>json_encode(['script'=>['text'=>'洋芋上桌','segments'=>[$segment]]])]);
        self::assertSame(15, $result['script']['segments'][0]['duration']);
        $segment['duration'] = 10;
        $this->expectException(\InvalidArgumentException::class);
        Worker::parse($row, ['content'=>json_encode(['script'=>['text'=>'洋芋上桌','segments'=>[$segment]]])]);
    }
}
