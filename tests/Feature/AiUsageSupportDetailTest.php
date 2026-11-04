<?php

declare(strict_types=1);

use app\common\enum\AdminTerminalEnum;
use app\common\service\ai\AiUsageService;
use app\common\service\ai\AiUsageSupportService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * Disposable SQLite fixtures only: no business database, provider, job or balance mutation.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AiUsageSupportDetailTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'support_fixture', 'connections' => ['support_fixture' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        request()->source = AdminTerminalEnum::PLATFORM;
        request()->tenantId = 99;
        request()->setHost('support.test');
        foreach ([
            'config' => 'id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT',
            'tenant' => 'id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER DEFAULT 0, delete_time INTEGER DEFAULT NULL',
            'tenant_file' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, source_id INTEGER, source INTEGER, uri TEXT, storage_scope TEXT, storage_engine TEXT, storage_domain TEXT, delete_time INTEGER DEFAULT NULL',
            'tenant_config' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT',
            'user' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, nickname TEXT, account TEXT, mobile TEXT',
            'app' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT',
            'power_market_product' => 'id INTEGER PRIMARY KEY, name TEXT',
            'ai_app_task' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, task_no TEXT, app_code TEXT, action_code TEXT, business_table TEXT, business_id INTEGER, status TEXT, estimated_user_price NUMERIC DEFAULT 0, actual_user_price NUMERIC DEFAULT 0, estimated_tenant_cost NUMERIC DEFAULT 0, actual_tenant_cost NUMERIC DEFAULT 0, request_summary TEXT DEFAULT "{}", result_summary TEXT DEFAULT "{}", create_time INTEGER DEFAULT 0, finish_time INTEGER DEFAULT 0',
            'ai_consumption_log' => 'id INTEGER PRIMARY KEY, app_task_id INTEGER, tenant_id INTEGER, user_id INTEGER, app_code TEXT, consume_no TEXT, model_code TEXT, api_code TEXT DEFAULT "", price_snapshot TEXT DEFAULT "{}", usage_snapshot TEXT DEFAULT "{}", request_summary TEXT DEFAULT "{}", response_summary TEXT DEFAULT "{}", billing_status TEXT DEFAULT "settled", run_status TEXT DEFAULT "success", quantity NUMERIC DEFAULT 1, usage_unit TEXT DEFAULT "image", product_id INTEGER DEFAULT 0, sku_id INTEGER DEFAULT 0, actual_tenant_cost NUMERIC DEFAULT 0, actual_user_price NUMERIC DEFAULT 0, reserved_tenant_cost NUMERIC DEFAULT 0, reserved_user_price NUMERIC DEFAULT 0, error_message TEXT DEFAULT "", upstream_task_id TEXT DEFAULT "", upstream_request_id TEXT DEFAULT "", create_time INTEGER DEFAULT 0, finish_time INTEGER DEFAULT 0',
            'ai_consumption_event' => 'id INTEGER PRIMARY KEY, consumption_id INTEGER, event_type TEXT, event_status TEXT, payload_summary TEXT, payload_ciphertext TEXT, create_time INTEGER',
            'ai_task_result_asset' => 'id INTEGER PRIMARY KEY, app_task_id INTEGER, consumption_id INTEGER, tenant_id INTEGER, user_id INTEGER, asset_type TEXT, local_uri TEXT, external_url TEXT, storage_scope TEXT, storage_engine TEXT, storage_domain TEXT, transfer_status TEXT DEFAULT "stored", delete_time INTEGER DEFAULT 0, external_expire_time INTEGER DEFAULT 0',
        ] as $name => $columns) Db::execute("CREATE TABLE la_{$name} ({$columns})");
        foreach (['aigc_image', 'aigc_video', 'aigc_music'] as $app) {
            Db::execute("CREATE TABLE la_{$app}_task (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, prompt TEXT, model TEXT, model_json TEXT, status TEXT, reference_images TEXT, reference_assets TEXT, provider_params_json TEXT, provider_payload TEXT, app_task_id INTEGER DEFAULT 0, consumption_id INTEGER DEFAULT 0, delete_time INTEGER DEFAULT 0)");
            Db::execute("CREATE TABLE la_{$app}_result (id INTEGER PRIMARY KEY, task_id INTEGER, tenant_id INTEGER, user_id INTEGER, image_uri TEXT, video_uri TEXT, audio_uri TEXT, cover_uri TEXT, storage_scope TEXT, storage_engine TEXT, storage_domain TEXT, width INTEGER, height INTEGER, duration NUMERIC, provider_task_id TEXT, create_time INTEGER, delete_time INTEGER DEFAULT 0)");
        }
        Db::execute('CREATE TABLE la_aigc_llm_message (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, session_id INTEGER, parent_user_message_id INTEGER, role TEXT, content TEXT, status TEXT, delete_time INTEGER DEFAULT 0)');
        Db::execute('CREATE TABLE la_aigc_copywriting_task (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, prompt_snapshot TEXT, input_snapshot TEXT, model_snapshot TEXT, result_text TEXT, status TEXT)');
        Db::name('tenant')->insertAll([['id' => 11, 'sn' => 'tenant11', 'tactics' => 0], ['id' => 99, 'sn' => 'tenant99', 'tactics' => 0]]);
        Db::name('user')->insert(['id' => 7, 'tenant_id' => 11, 'nickname' => '客服测试用户']);
    }

    private function binding(string $app = 'aigc_image', string $table = ''): array
    {
        $task = ['id' => 1, 'tenant_id' => 11, 'user_id' => 7, 'task_no' => 'AT-fixture', 'app_code' => $app, 'action_code' => 'generate', 'business_table' => $table ?: $app . '_task', 'business_id' => 20, 'status' => 'success'];
        $usage = ['id' => 2, 'app_task_id' => 1, 'tenant_id' => 11, 'user_id' => 7, 'app_code' => $app, 'consume_no' => 'C-fixture', 'model_code' => 'model-code', 'run_status' => 'success', 'price_snapshot' => json_encode(['model_name' => '创建时模型名称'])];
        Db::name('ai_app_task')->insert($task);
        Db::name('ai_consumption_log')->insert($usage);
        return [$task, $usage];
    }

    private function source(string $app = 'aigc_image', array $extra = []): void
    {
        Db::name($app . '_task')->insert($extra + ['id' => 20, 'tenant_id' => 11, 'user_id' => 7, 'prompt' => '保留完整的客服提示词', 'model' => 'model-code', 'model_json' => json_encode(['model_name' => '来源冻结模型名称']), 'status' => 'success', 'reference_images' => json_encode([['url' => 'https://input.test/photo.png', 'api_key' => 'DO_NOT_LEAK']]), 'provider_payload' => '{"authorization":"DO_NOT_LEAK"}', 'provider_params_json' => '{"secret_key":"DO_NOT_LEAK"}']);
    }

    private function media(string $app, int $id, array $extra = []): void
    {
        Db::name($app . '_result')->insert($extra + ['id' => $id, 'task_id' => 20, 'tenant_id' => 11, 'user_id' => 7, 'image_uri' => 'image.png', 'video_uri' => 'video.mp4', 'audio_uri' => 'audio.mp3', 'cover_uri' => 'cover.png', 'storage_scope' => 'tenant', 'storage_engine' => 'local', 'storage_domain' => 'https://stored.test', 'width' => 800, 'height' => 600]);
    }

    public function testImageConsumptionAndAppTaskDetailsContainOnlyAuthorizedSourceContent(): void
    {
        $this->binding(); $this->source(); $this->media('aigc_image', 30);
        $this->media('aigc_image', 31, ['user_id' => 8, 'image_uri' => 'OTHER-USER.png']);
        $this->media('aigc_image', 32, ['tenant_id' => 12, 'image_uri' => 'OTHER-TENANT.png']);
        $this->media('aigc_image', 33, ['delete_time' => 1, 'image_uri' => 'DELETED.png']);
        Db::name('ai_consumption_event')->insert(['id' => 1, 'consumption_id' => 2, 'event_type' => 'submit', 'payload_summary' => '{"image_count":1,"provider_payload":{"secret_key":"DO_NOT_LEAK"}}', 'payload_ciphertext' => 'DO_NOT_LEAK']);
        $detail = AiUsageService::consumptionDetail(2, 11);
        self::assertSame('保留完整的客服提示词', $detail['support_detail']['prompt']);
        self::assertSame('创建时模型名称', $detail['support_detail']['display_model']);
        self::assertCount(1, $detail['support_detail']['media_results']);
        self::assertCount(1, $detail['support_detail']['input_media']);
        self::assertStringNotContainsString('DO_NOT_LEAK', json_encode($detail));
        self::assertStringNotContainsString('OTHER-', json_encode($detail));
        self::assertStringNotContainsString('DELETED', json_encode($detail));
        self::assertSame(99, request()->tenantId);
        self::assertSame([], AiUsageService::consumptionDetail(2, 12));
        $app = AiUsageService::appTaskDetail(1, 11);
        self::assertSame($detail['support_detail']['prompt'], $app['prompt']);
        self::assertCount(1, $app['media_results']);
    }

    public function testVideoAudioAndTextUseTheirOwnScopedResults(): void
    {
        foreach (['aigc_video' => 'video', 'aigc_music' => 'audio'] as $app => $type) {
            Db::name('ai_app_task')->delete(true); Db::name('ai_consumption_log')->delete(true);
            $this->binding($app); $this->source($app); $this->media($app, 30);
            $support = AiUsageService::consumptionDetail(2, 11)['support_detail'];
            self::assertSame($type, $support['media_results'][0]['type']);
            self::assertStringContainsString($type === 'audio' ? 'audio.mp3' : 'video.mp4', $support['media_results'][0]['url']);
        }
        Db::name('ai_app_task')->delete(true); Db::name('ai_consumption_log')->delete(true);
        $this->binding('aigc_llm', 'aigc_llm_message');
        foreach ([
            ['id' => 19, 'tenant_id' => 11, 'user_id' => 7, 'session_id' => 10, 'parent_user_message_id' => 0, 'status' => 'done', 'role' => 'user', 'content' => '输入问题'],
            ['id' => 20, 'tenant_id' => 11, 'user_id' => 7, 'session_id' => 10, 'status' => 'done', 'role' => 'assistant', 'content' => '<script>安全文本输出</script>', 'parent_user_message_id' => 19],
        ] as $message) Db::name('aigc_llm_message')->insert($message);
        $support = AiUsageService::consumptionDetail(2, 11)['support_detail'];
        self::assertSame('输入问题', $support['prompt']);
        self::assertSame('<script>安全文本输出</script>', $support['text_result']);
        Db::name('aigc_llm_message')->where('id', 19)->update(['user_id' => 8]);
        self::assertSame('', AiUsageService::consumptionDetail(2, 11)['support_detail']['prompt']);
    }

    public function testCorruptedAppAndSourceBindingsDoNotCrossTenantOrUser(): void
    {
        $this->binding(); $this->source();
        foreach ([['tenant_id' => 12, 'user_id' => 7], ['tenant_id' => 11, 'user_id' => 8]] as $scope) {
            Db::name('ai_app_task')->where('id', 1)->update($scope);
            $detail = AiUsageService::consumptionDetail(2, 11);
            self::assertNull($detail['task_no']);
            self::assertFalse($detail['support_detail']['source_available']);
            self::assertSame([], AiUsageService::appTaskDetail(1, $scope['tenant_id'])['consumptions']);
        }
        Db::name('ai_app_task')->where('id', 1)->update(['tenant_id' => 11, 'user_id' => 7]);
        foreach ([['tenant_id' => 12, 'user_id' => 7], ['tenant_id' => 11, 'user_id' => 8], ['tenant_id' => 11, 'user_id' => 7, 'delete_time' => 1]] as $scope) {
            Db::name('aigc_image_task')->where('id', 20)->update($scope);
            self::assertFalse(AiUsageService::consumptionDetail(2, 11)['support_detail']['source_available']);
        }
    }

    public function testMissingFailedOldAndUnknownSourcesFailSafelyWithoutChangingRows(): void
    {
        $this->binding();
        $before = Db::name('ai_consumption_log')->find();
        self::assertFalse(AiUsageService::consumptionDetail(2, 11)['support_detail']['source_available']);
        Db::name('ai_app_task')->where('id', 1)->update(['business_table' => 'config']);
        self::assertFalse(AiUsageService::consumptionDetail(2, 11)['support_detail']['source_available']);
        Db::name('ai_app_task')->where('id', 1)->update(['business_table' => 'aigc_image_task']);
        $this->source('aigc_image', ['status' => 'failed']);
        self::assertTrue(AiUsageService::consumptionDetail(2, 11)['support_detail']['source_available']);
        self::assertSame([], AiUsageService::consumptionDetail(2, 11)['support_detail']['media_results']);
        self::assertSame($before, Db::name('ai_consumption_log')->find());
        self::assertSame(99, request()->tenantId);
    }

    public function testPrivateMediaUsesFrozenTenantAndRejectsRawCredentialUrls(): void
    {
        $this->binding();
        $this->source('aigc_image', ['reference_images' => json_encode([
            'https://provider.test/raw.png?api_key=DO_NOT_LEAK',
            'https://provider.test/raw.png?token=DO_NOT_LEAK',
            'javascript:alert(1)',
            'inputs/private.png',
        ])]);
        Db::name('tenant_file')->insert(['tenant_id' => 11, 'source_id' => 7, 'source' => 1, 'uri' => 'inputs/private.png', 'storage_scope' => 'tenant', 'storage_engine' => 'qiniu', 'storage_domain' => 'https://tenant11.test']);
        foreach ([11, 99] as $tenant) Db::name('tenant_config')->insert(['tenant_id' => $tenant, 'type' => 'storage', 'name' => 'qiniu', 'value' => json_encode(['private_access' => true, 'access_key' => 'FIXTURE_ACCESS_' . $tenant, 'secret_key' => 'DO_NOT_LEAK', 'domain' => 'https://tenant' . $tenant . '.test'])]);
        $this->media('aigc_image', 30, ['image_uri' => 'private/image.png', 'storage_engine' => 'qiniu', 'storage_domain' => 'https://tenant11.test']);
        $this->media('aigc_image', 31, ['image_uri' => 'https://provider.test/raw.png?token=DO_NOT_LEAK', 'storage_engine' => 'qiniu', 'storage_domain' => 'https://tenant11.test']);
        request()->tenantId = 11;
        self::assertStringContainsString('tenant11', \app\common\service\FileService::getFileUrlByStorage('inputs/private.png', 'tenant', 'qiniu', 'https://tenant11.test'));
        request()->tenantId = 99;
        $support = AiUsageService::consumptionDetail(2, 11)['support_detail'];
        self::assertCount(1, $support['input_media']);
        self::assertStringContainsString('FIXTURE_ACCESS_11', $support['input_media'][0]['url']);
        self::assertCount(1, $support['media_results']);
        self::assertStringContainsString('https://tenant11.test/', $support['media_results'][0]['url']);
        self::assertStringContainsString('FIXTURE_ACCESS_11', $support['media_results'][0]['url']);
        self::assertStringNotContainsString('FIXTURE_ACCESS_99', json_encode($support));
        self::assertStringNotContainsString('DO_NOT_LEAK', json_encode($support));
        self::assertSame(99, request()->tenantId);
        request()->tenantId = null;
        AiUsageService::consumptionDetail(2, 11);
        self::assertFalse(isset(request()->tenantId));
    }

    public function testCanvasTextResultUsesOnlyScopedPersistedContentAndTerminalSuccess(): void
    {
        $this->binding('aigc_canvas', 'aigc_canvas_run');
        Db::execute('CREATE TABLE la_aigc_canvas_run (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, run_type TEXT, status TEXT, prompt TEXT, params_json TEXT, result_json TEXT, delete_time INTEGER DEFAULT 0)');
        $savedResult = json_encode([
            'content' => '<b>画布生成的文本</b>', 'consumption_id' => 2,
            'provider_payload' => ['api_key' => 'DO_NOT_LEAK'], 'authorization' => 'DO_NOT_LEAK',
        ]);
        Db::name('aigc_canvas_run')->insert([
            'id' => 20, 'tenant_id' => 11, 'user_id' => 7, 'run_type' => 'text',
            'status' => 'success', 'prompt' => '画布文本输入', 'params_json' => '{}', 'result_json' => $savedResult,
        ]);
        $before = Db::name('aigc_canvas_run')->find();
        $support = AiUsageService::consumptionDetail(2, 11)['support_detail'];
        self::assertTrue($support['source_available']);
        self::assertSame('<b>画布生成的文本</b>', $support['text_result']);
        self::assertStringNotContainsString('DO_NOT_LEAK', json_encode($support));
        self::assertSame('<b>画布生成的文本</b>', AiUsageService::appTaskDetail(1, 11)['text_result']);
        self::assertSame($before, Db::name('aigc_canvas_run')->find());

        foreach ([['tenant_id' => 12, 'user_id' => 7], ['tenant_id' => 11, 'user_id' => 8]] as $scope) {
            Db::name('aigc_canvas_run')->where('id', 20)->update($scope);
            $support = AiUsageService::consumptionDetail(2, 11)['support_detail'];
            self::assertFalse($support['source_available']);
            self::assertSame('', $support['text_result']);
        }
        Db::name('aigc_canvas_run')->where('id', 20)->update(['tenant_id' => 11, 'user_id' => 7]);
        foreach (['failed', 'running', 'canceled'] as $status) {
            Db::name('aigc_canvas_run')->where('id', 20)->update(['status' => $status]);
            self::assertSame('', AiUsageService::consumptionDetail(2, 11)['support_detail']['text_result']);
        }
        Db::name('aigc_canvas_run')->where('id', 20)->update(['status' => 'success']);
        Db::name('ai_consumption_log')->where('id', 2)->update(['run_status' => 'failed']);
        self::assertSame('', AiUsageService::consumptionDetail(2, 11)['support_detail']['text_result']);
        Db::name('ai_consumption_log')->where('id', 2)->update(['run_status' => 'success']);
        foreach ([json_encode(['content' => ['api_key' => 'DO_NOT_LEAK']]), '{invalid-json'] as $invalid) {
            Db::name('aigc_canvas_run')->where('id', 20)->update(['result_json' => $invalid]);
            self::assertSame('', AiUsageService::consumptionDetail(2, 11)['support_detail']['text_result']);
        }
        Db::name('aigc_canvas_run')->where('id', 20)->update(['result_json' => $savedResult, 'run_type' => 'image']);
        self::assertSame('', AiUsageService::consumptionDetail(2, 11)['support_detail']['text_result']);
        Db::name('aigc_canvas_run')->where('id', 20)->update(['run_type' => 'text', 'delete_time' => 1]);
        self::assertFalse(AiUsageService::consumptionDetail(2, 11)['support_detail']['source_available']);
        self::assertSame(99, request()->tenantId);
    }

    public function testNestedShortDramaRequestOnlyProjectsUserInputFields(): void
    {
        $this->binding('aigc_short_drama', 'aigc_short_drama_generation_task');
        Db::execute('CREATE TABLE la_aigc_short_drama_generation_task (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, status TEXT, prompt TEXT, request_json TEXT)');
        Db::name('aigc_short_drama_generation_task')->insert([
            'id' => 20, 'tenant_id' => 11, 'user_id' => 7, 'status' => 'success', 'prompt' => '',
            'request_json' => json_encode(['prompt' => '嵌套保存的短剧提示词', 'duration' => 10, 'provider_payload' => ['secret_key' => 'DO_NOT_LEAK']]),
        ]);
        $support = AiUsageService::consumptionDetail(2, 11)['support_detail'];
        self::assertSame('嵌套保存的短剧提示词', $support['prompt']);
        self::assertSame(10, $support['inputs']['duration']);
        self::assertStringNotContainsString('DO_NOT_LEAK', json_encode($support));
    }

    public function testCopywritingUserPromptAndResultAreAllowlistedFromFrozenSource(): void
    {
        [$task, $usage] = $this->binding('aigc_copywriting', 'aigc_copywriting_task');
        $usage['price_snapshot'] = '{}';
        Db::name('aigc_copywriting_task')->insert([
            'id' => 20, 'tenant_id' => 11, 'user_id' => 7, 'status' => 'success',
            'prompt_snapshot' => json_encode(['user_prompt' => '用户文案输入', 'system_prompt' => 'DO_NOT_LEAK', 'api_key' => 'DO_NOT_LEAK']),
            'model_snapshot' => json_encode(['name' => '冻结文案模型', 'secret' => 'DO_NOT_LEAK']),
            'input_snapshot' => '{"credentials":"DO_NOT_LEAK"}', 'result_text' => '生成文案正文',
        ]);
        $support = AiUsageSupportService::forAppTask($task, $usage);
        self::assertSame('用户文案输入', $support['prompt']);
        self::assertSame('生成文案正文', $support['text_result']);
        self::assertSame('冻结文案模型', $support['display_model']);
        self::assertStringNotContainsString('DO_NOT_LEAK', json_encode($support));
    }

    public function testSourceRebindingAndFailedResultsCannotShowAnotherAttempt(): void
    {
        $this->binding(); $this->source(); $this->media('aigc_image', 30);
        foreach ([['app_task_id' => 44, 'consumption_id' => 0], ['app_task_id' => 1, 'consumption_id' => 55]] as $binding) {
            Db::name('aigc_image_task')->where('id', 20)->update($binding);
            self::assertFalse(AiUsageService::consumptionDetail(2, 11)['support_detail']['source_available']);
        }
        Db::name('aigc_image_task')->where('id', 20)->update(['app_task_id' => 1, 'consumption_id' => 2, 'status' => 'failed']);
        $support = AiUsageService::consumptionDetail(2, 11)['support_detail'];
        self::assertSame('保留完整的客服提示词', $support['prompt']);
        self::assertSame([], $support['media_results']);
        Db::name('aigc_image_task')->where('id', 20)->update(['status' => 'success']);
        Db::name('ai_consumption_log')->where('id', 2)->update(['run_status' => 'failed']);
        self::assertSame([], AiUsageService::consumptionDetail(2, 11)['support_detail']['media_results']);
    }

    public function testManagedAssetsAndModelFallbackAreScopedAndAllowlisted(): void
    {
        [$task, $usage] = $this->binding(); $this->source();
        $usage['price_snapshot'] = '{}';
        $usage['run_status'] = 'success';
        Db::name('ai_task_result_asset')->insertAll([
            ['id' => 40, 'app_task_id' => 1, 'consumption_id' => 2, 'tenant_id' => 11, 'user_id' => 7, 'asset_type' => 'audio', 'local_uri' => 'managed.mp3', 'storage_engine' => 'local'],
            ['id' => 41, 'app_task_id' => 1, 'consumption_id' => 2, 'tenant_id' => 11, 'user_id' => 8, 'asset_type' => 'audio', 'local_uri' => 'OTHER-USER.mp3', 'storage_engine' => 'local'],
        ]);
        $support = AiUsageSupportService::forAppTask($task, $usage);
        self::assertSame('来源冻结模型名称', $support['display_model']);
        self::assertCount(1, $support['media_results']);
        self::assertSame('audio', $support['media_results'][0]['type']);
        Db::name('ai_task_result_asset')->where('id', 40)->update(['delete_time' => 1]);
        self::assertSame([], AiUsageSupportService::forAppTask($task, $usage)['media_results']);
        Db::name('ai_task_result_asset')->where('id', 40)->update(['delete_time' => 0, 'transfer_status' => 'retrying']);
        self::assertSame([], AiUsageSupportService::forAppTask($task, $usage)['media_results']);
        self::assertStringNotContainsString('DO_NOT_LEAK', json_encode($support));
        $method = new ReflectionMethod(AiUsageService::class, 'withoutSecrets');
        $method->setAccessible(true);
        self::assertSame(['quantity' => 1], $method->invoke(null, ['prompt' => 'hidden', 'reference_images' => ['hidden'], 'quantity' => 1]));
    }
}
