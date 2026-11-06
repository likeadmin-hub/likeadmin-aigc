<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaUploadedScriptApi as Api, ShortDramaFileModelParser as Parser, ShortDramaFileParseFallback as Fallback, ShortDramaStoryWorkflow as Workflow};
use PHPUnit\Framework\TestCase;

final class ShortDramaUploadedScriptApiTest extends TestCase
{
    public function testLegacyParserSelectionCannotOverrideApiAndLaterCreationChoicesAreRetained(): void
    {
        $input = ['source' => 'market_file_qa_parse', 'parse_model_id' => '6',
            'file_urls' => ['https://files.example/script.txt'], 'parse_mode' => 'split_by_ai', 'preserve_original' => true,
            'model_selections' => ['script_plan' => ['id' => '6'], 'image' => ['id' => '9']], 'style_id' => 'style_1',
            '_file_parse_model' => ['model' => ['id' => '6'], 'text' => "甲\n乙"]];
        $request = Api::request($input);
        self::assertArrayNotHasKey('parse_model_id', $request);
        self::assertArrayNotHasKey('_file_parse_model', $request);
        self::assertSame($input['file_urls'], $request['file_urls']);
        self::assertSame($input['model_selections'], $request['model_selections']);
        self::assertSame('style_1', $request['style_id']);
        self::assertSame('split_by_ai', $request['parse_mode']);
        self::assertTrue($request['preserve_original']);
        self::assertSame("甲\n乙", $request['_file_parse_fallback']['text']);
        self::assertFalse(Parser::applies($request));
        self::assertFalse(Workflow::workerOwned($request));
        self::assertFalse(Fallback::eligible($request));
        self::assertArrayHasKey('_file_parse_model', $input, 'Historical input must remain immutable');
    }

    public function testOldRepairCannotScheduleAnotherTextCallOnApiRetry(): void
    {
        foreach (['queued', 'running', 'done', 'failed'] as $state) {
            $request = Api::request(['_file_parse_fallback' => ['version' => 1, 'text' => '完整原文',
                'model' => ['id' => '6'], 'state' => $state, 'result' => ['old' => true]]]);
            self::assertSame(['version' => 1, 'text' => '完整原文'], $request['_file_parse_fallback']);
            self::assertFalse(Fallback::eligible($request));
            self::assertFalse(Fallback::pending($request));
            self::assertFalse(Workflow::workerOwned($request));
        }
    }

    public function testApiDoesNotRequireReadableTextOrAnyTextModel(): void
    {
        $request = Api::request(['file_urls' => ['https://files.example/script.docx'],
            '_file_parse_fallback' => ['version' => 1, 'unavailable_reason' => '本地正文不可读']]);
        self::assertSame('market_file_qa_parse', $request['source']);
        self::assertSame('upload', $request['script_source']);
        self::assertSame('本地正文不可读', $request['_file_parse_fallback']['unavailable_reason']);
        self::assertFalse(Parser::applies($request));
        self::assertFalse(Fallback::eligible($request));
    }
}
