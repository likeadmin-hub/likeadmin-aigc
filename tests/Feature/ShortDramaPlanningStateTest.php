<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningState as State;
use PHPUnit\Framework\TestCase;

class ShortDramaPlanningStateTest extends TestCase
{
    /** @dataProvider errors */
    public function testFailureClassificationDistinguishesApplicationAndProvider($message, string $code): void
    {
        self::assertSame($code, State::errorCode($message));
    }
    public static function errors(): array
    {
        return [
            ['场次时长与片段合计不一致','validation_failed'],
            ['正文引用范围无效','validation_failed'],
            ['要求证据必须引用当前窗口真实非空字段','validation_failed'],
            ['SQLSTATE[22001]: Data too long for column','persistence_failed'],
            ['Call to undefined method Example::missing()','internal_failed'],
            ['请先配置短剧文本模型','model_config_invalid'],
            ['model_not_found：上游暂时无法处理该模型','provider_model_failed'],
            ['文本模型网络请求失败：SSL_ERROR_SYSCALL','provider_network_failed'],
            ['模型回复未完整结束，费用待核对','provider_output_incomplete'],
            ['本次输出达到模型长度上限','provider_output_incomplete'],
            ['JSON解析失败','parse_failed'],
            ['本集自动生成预算已达上限','generation_limit_reached'],
            ['租户积分不足','billing_failed'],
            ['请输入故事灵感','input_invalid'],
            ['上次请求在返回前中断，已保留完成部分','execution_interrupted'],
            ['未知程序异常','internal_failed'],
            [State::LEGACY_WAIT_ERROR,'legacy_queue_expired'],
            [new \RuntimeException('字段校验错误',422),'validation_failed'],
            [new \TypeError('bad payload'),'internal_failed'],
        ];
    }
    public function testOnlyNeverStartedOldStandaloneTasksCanExpire(): void
    {
        $row=['status'=>'pending','create_time'=>1,'update_time'=>1,'result_json'=>'{}'];
        self::assertTrue(State::legacyWaitExpired($row,[],100000));
        self::assertFalse(State::legacyWaitExpired($row,[],600));
        foreach (['started_at'=>1,'provider_request_id'=>'accepted','app_task_id'=>1,'result_json'=>'{"storyboard":[{"shot_id":"1"}]}','status'=>'running'] as $key=>$value) {
            self::assertFalse(State::legacyWaitExpired(array_replace($row,[$key=>$value]),[],100000));
        }
        foreach ([['_generation_version'=>3],['multi_episode'=>true],['episode_id'=>2],['source'=>'market_file_qa_parse']] as $request) {
            self::assertFalse(State::legacyWaitExpired($row,$request,100000));
        }
    }
}
