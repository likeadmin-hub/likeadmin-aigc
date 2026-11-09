<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;

final class ShortDramaFileQaErrorTest extends TestCase
{
    private function error(string $message, array $request): string
    {
        return (new \ReflectionMethod(AigcShortDramaService::class, 'scriptTaskError'))->invoke(null, $message, $request);
    }

    public function testRejectedFileParseOutputDoesNotBlameTextModelConfiguration(): void
    {
        $request = ['source' => 'market_file_qa_parse', '_file_parse_fallback' => ['version' => 1, 'text' => '原文']];
        foreach (['result_invalid：上游暂时无法处理该模型，请稍后重试或切换模型', 'result_invalid: 任务处理失败，请稍后重试'] as $message) {
            $safe = $this->error($message, $request);
            self::assertStringContainsString('剧本解析服务返回的结果未通过校验', $safe);
            self::assertStringNotContainsString('文本模型', $safe);
        }
        self::assertSame('解析原文与上传文件不一致', $this->error('解析原文与上传文件不一致', $request));
        self::assertSame('用户取消任务', $this->error('用户取消任务', $request));
        self::assertStringNotContainsString('secret', $this->error('result_invalid: secret=hidden', $request));
    }

    public function testTextModelAndHistoricalModelParseErrorsRetainTheirOwnHandling(): void
    {
        foreach ([[], ['source' => 'script_prompt'], ['source' => 'market_file_qa_parse', '_file_parse_model' => ['model' => ['id' => 1]]],
            ['source' => 'market_file_qa_parse', '_file_parse_fallback' => ['model' => ['id' => 1], 'state' => 'failed']]] as $request) {
            self::assertSame('文本模型调用异常，请检查算力市场模型状态', $this->error('invalid_api_key', $request));
        }
    }
}
