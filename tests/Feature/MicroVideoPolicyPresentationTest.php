<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoPolicy as P;
final class MicroVideoPolicyPresentationTest extends TestCase
{
    /** @dataProvider states */
    public function testFramePresentationPreservesMainLifecycleAndActions(string $status,string $frameStatus,string $label): void
    {
        $legacy=P::publicState($status);$current=P::publicState($status,$frameStatus);self::assertSame($label,$current['status_text']);self::assertSame($legacy['status'],$current['status']);self::assertSame($legacy['can_continue'],$current['can_continue']);self::assertSame($legacy['can_show_error'],$current['can_show_error']);
    }
    public static function states(): array
    {
        return [
            'legacy-off'=>['awaiting_confirmation','off','待确认分段'],
            'frame-idle'=>['awaiting_confirmation','idle','待准备首尾帧'],
            'frame-generating'=>['awaiting_confirmation','generating','正在准备首尾帧'],
            'frame-review'=>['awaiting_confirmation','review','待确认首尾帧'],
            'frame-ready'=>['awaiting_confirmation','ready','待确认生成'],
            'frame-waiting'=>['awaiting_confirmation','waiting_continue','首尾帧待继续'],
            'unknown-frame'=>['awaiting_confirmation','future_state','待确认分段'],
            'running-over-generating'=>['running','generating','正在生成视频'],
            'running-over-review'=>['running','review','正在生成视频'],
            'success-over-generating'=>['success','generating','已完成'],
            'success-over-ready'=>['success','ready','已完成'],
            'main-waiting-over-ready'=>['waiting_continue','ready','待继续'],
            'planning-over-idle'=>['planning','idle','正在构思'],
        ];
    }
}
