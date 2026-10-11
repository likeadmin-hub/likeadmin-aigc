<?php
namespace app\common\service\open_platform;
final class OpenStreamResponse extends \think\Response
{
    public function __construct(private \Closure $stream,array $headers=[]) {parent::__construct('',200,$headers+['Content-Type'=>'text/event-stream','Cache-Control'=>'no-cache','X-Accel-Buffering'=>'no']);}
    protected function sendData(string $data): void {ignore_user_abort(true);@ini_set('zlib.output_compression','0');($this->stream)();}
}
