<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationReplyStream;
use PHPUnit\Framework\TestCase;

class CanvasAgentReplyStreamTest extends TestCase
{
    public function testOnlyTopLevelNarrationCanStream(): void
    {
        self::assertSame('你好',ConversationReplyStream::visible('{"intent":"chat","reply_markdown":"你好',true));
        self::assertSame('',ConversationReplyStream::visible('{"workflow_output":{"reply_markdown":"内部产物"},"canvas_actions":{}}',true));
        self::assertSame('公开',ConversationReplyStream::visible('{"intake":{"reply_markdown":"内部"},"reply_markdown":"公开","canvas_actions":{"nodes":[]}}',true));
        self::assertSame('',ConversationReplyStream::visible('不是结构化 JSON',true));
        self::assertSame('',ConversationReplyStream::visible('[{"reply_markdown":"内部"}]',false));
    }

    public function testEveryByteBoundaryHandlesChineseEscapesAndSurrogatePairs(): void
    {
        $reply="你好\n引号\"和反斜线\\与😀";
        $json=json_encode(['intent'=>'chat','reply_markdown'=>$reply,'intake'=>[]]);
        $previous='';
        for ($i=1;$i<=strlen($json);$i++) {
            $text=ConversationReplyStream::visible(substr($json,0,$i),true);
            self::assertTrue(str_starts_with($reply,$text));
            self::assertTrue(str_starts_with($text,$previous));
            $previous=$text;
        }
        self::assertSame($reply,$previous);
        $raw=json_encode(['reply_markdown'=>$reply],JSON_UNESCAPED_UNICODE);
        for ($i=1;$i<=strlen($raw);$i++) self::assertTrue(str_starts_with($reply,ConversationReplyStream::visible(substr($raw,0,$i),true)));
    }

    public function testActionTagsAndFencedJsonNeverFlashIntoPlainReplies(): void
    {
        $text='公开回复<canvas-actions>{"nodes":[{"prompt":"内部"}]}</canvas-actions>';
        for ($i=strlen('公开回复');$i<=strlen($text);$i++) self::assertSame('公开回复',ConversationReplyStream::visible(substr($text,0,$i),false));
        self::assertSame('公开',ConversationReplyStream::visible("```json\n{\"reply_markdown\":\"公开\"}",true));
        self::assertSame('',ConversationReplyStream::visible('```j',false));
    }

    public function testThrottleSnapshotsAndIndependentHeartbeatsWithoutProviderMetadata(): void
    {
        $time=0.0;$events=[];
        $stream=new ConversationReplyStream(static function ($kind,$payload) use (&$events) {$events[]=[$kind,$payload];},true,true,static function () use (&$time) {return $time;});
        $stream->receive('provider_request',['provider_request_id'=>'secret']);
        $stream->receive('delta',['delta'=>'{"reply_markdown":"你']);
        $time=0.1;$stream->receive('delta',['delta'=>'好']);
        self::assertCount(2,$events);
        $time=0.6;$stream->receive('delta',['delta'=>'。"}']);
        self::assertSame(['reply.progress',['text'=>'你好。']],$events[2]);
        $time=5.0;$stream->receive('heartbeat',[]);
        self::assertSame(['provider.heartbeat',['elapsed_ms'=>5000]],$events[3]);
        self::assertCount(4,$events);
    }

    public function testWorkflowArtifactsOnlySendHeartbeatUntilValidatedFinalReply(): void
    {
        $events=[];
        $stream=new ConversationReplyStream(static function ($kind,$payload) use (&$events) {$events[]=[$kind,$payload];},true,false);
        $stream->receive('delta',['delta'=>'{"reply_markdown":"节点已写入","canvas_actions":{"nodes":[]}}']);
        self::assertCount(1,$events);
        self::assertSame('provider.heartbeat',$events[0][0]);
    }

    public function testBlockedOrFailedPublicationCannotMarkTextDelivered(): void
    {
        $stream=new ConversationReplyStream(static function () {throw new \RuntimeException('blocked');},false);
        $this->expectExceptionMessage('blocked');
        $stream->receive('delta',['delta'=>'被拦截的文字']);
    }
}
