<?php
namespace Tests\Feature;

use app\common\service\app\PromptEnhanceModelService as Enhance;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_image\ImagePromptEnhanceService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\app\aigc_video\VideoPromptEnhanceService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class PromptEnhanceModelConfigTest extends TestCase
{
    public function testLegacyConfigAndInstructionResetRemainCompatible(): void
    {
        self::assertSame(['legacy' => true], Enhance::validateConfig(1, ['legacy' => true]));
        self::assertSame(['prompt_enhance_model_id' => '', 'prompt_enhance_system_prompt' => '自定义规则'],
            Enhance::validateConfig(1, ['prompt_enhance_model_id' => '', 'prompt_enhance_system_prompt' => ' 自定义规则 ']));
        self::assertSame('', Enhance::validateConfig(1, ['prompt_enhance_system_prompt' => '  '])['prompt_enhance_system_prompt']);
        self::assertSame('123', Enhance::normalizeSelection(123));
        self::assertSame('123', Enhance::normalizeSelection('123'));
        self::assertSame('', Enhance::normalizeSelection(null));
        self::assertSame(['model_code'=>'actual', 'model_name'=>'实际模型'], Enhance::resultModel(['model_code'=>'actual', 'model_name'=>'实际模型', 'channel_code'=>'private']));
    }

    /** @dataProvider invalidSelections */
    public function testRejectsMalformedModelSelections($selection): void
    {
        $this->expectException(\Exception::class);
        Enhance::normalizeSelection($selection);
    }
    public static function invalidSelections(): array { return [[[]], [true], [0], [-1], ['1.0'], ['1e2'], ['model'], ['01']]; }

    public function testRejectsOversizedInstructions(): void
    {
        $this->expectException(\Exception::class);
        Enhance::validateConfig(1, ['prompt_enhance_system_prompt' => str_repeat('字', 12001)]);
    }

    public function testTenantAppSettingsRoundTripAndUnavailableModels(): void
    {
        if (getenv('AIGC_ENHANCE_CONFIG_DB_TESTS') !== '1') self::markTestSkipped('Local transactional fixtures only');
        (new \think\App())->initialize();
        self::assertSame('x_cn', Db::connect()->getConfig('database'));
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        Db::startTrans();
        try {
            $tenantId = 2000000720;
            $models = Enhance::options($tenantId);
            self::assertNotEmpty($models);
            $selection = $models[0]['id'];
            AigcImageService::saveConfig($tenantId, ['config_json' => ['prompt_enhance_model_id'=>$selection, 'prompt_enhance_system_prompt'=>'图片自定义规则', 'legacy'=>'keep']]);
            AigcVideoService::saveConfig($tenantId, ['config_json' => ['prompt_enhance_model_id'=>'', 'prompt_enhance_system_prompt'=>'视频自定义规则']]);
            $image = AigcImageService::config($tenantId);
            $video = AigcVideoService::config($tenantId);
            self::assertSame('keep', $image['config_json']['legacy']);
            self::assertSame($selection, $image['prompt_enhance_model']['id']);
            self::assertFalse($image['prompt_enhance_model']['automatic']);
            self::assertTrue($video['prompt_enhance_model']['automatic']);
            self::assertSame(['model_code'=>$selection, '_disable_model_fallback'=>true, 'system_prompt'=>'图片自定义规则'], Enhance::requestParams($tenantId, 'aigc_image', ImagePromptEnhanceService::instructions()));
            self::assertSame(['model_code'=>'', '_disable_model_fallback'=>false, 'system_prompt'=>'视频自定义规则'], Enhance::requestParams($tenantId, 'aigc_video', VideoPromptEnhanceService::instructions()));
            self::assertSame(ImagePromptEnhanceService::instructions(), Enhance::requestParams($tenantId+1, 'aigc_image', ImagePromptEnhanceService::instructions())['system_prompt']);
            self::assertSame('', Enhance::requestParams($tenantId+1, 'aigc_image', '')['model_code']);
            AigcImageService::saveConfig($tenantId, ['config_json'=>['prompt_enhance_model_id'=>'', 'prompt_enhance_system_prompt'=>'']]);
            self::assertSame(ImagePromptEnhanceService::instructions(), Enhance::requestParams($tenantId, 'aigc_image', ImagePromptEnhanceService::instructions())['system_prompt']);
            self::assertSame('视频自定义规则', Enhance::requestParams($tenantId, 'aigc_video', '')['system_prompt']);
            Db::name('power_market_product')->where('id', $selection)->update(['status'=>0]);
            self::assertFalse(Enhance::describe($tenantId, ['prompt_enhance_model_id'=>$selection])['available']);
            try {
                AigcImageService::saveConfig($tenantId, ['status'=>0, 'config_json'=>['prompt_enhance_model_id'=>$selection]]);
                self::fail('Unavailable configured model must be rejected');
            } catch (\Exception $e) {
                self::assertStringContainsString('不可用', $e->getMessage());
            }
            self::assertSame(1, (int)AigcImageService::config($tenantId)['status']);
        } finally { Db::rollback(); }
    }
}
