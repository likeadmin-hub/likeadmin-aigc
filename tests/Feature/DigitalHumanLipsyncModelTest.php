<?php

namespace Tests\Feature;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanChannelService;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanGenerateRequest;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\common\service\app\aigc_digital_human\XhadminAigcDigitalHumanProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class DigitalHumanLipsyncModelTest extends TestCase
{
    public function testSelectedChannelReachesMarketPayloadDespiteLegacyOverrides(): void
    {
        foreach (['master' => 'xiaojiayu1.0', 'all' => 'xiaojiayu2.0', 'free' => 'xiaojiayu3.0'] as $code => $model) {
            $channel = AigcDigitalHumanChannelService::normalizeProviderChannel([
                'code' => $code,
                'provider' => 'xhadmin',
                'model' => 'xiaojiayu1.0',
                'config_json' => ['tts_model' => 's2-pro', 'lipsync_model' => 'xiaojiayu1.0'],
            ]);
            self::assertSame($model, $channel['model']);
            self::assertSame($model, $channel['config_json']['lipsync_model']);
            self::assertSame('s2-pro', $channel['config_json']['tts_model']);

            $request = new AigcDigitalHumanGenerateRequest('', '', $code,
                avatar: ['media_url' => 'https://example.test/avatar.mp4'],
                providerParams: ['lipsync_model' => 'xiaojiayu1.0', 'lipsync_payload' => ['model' => 'xiaojiayu1.0'], 'idempotency_key' => 'test-' . $code],
                channelConfig: $channel['config_json'] + ['model' => $channel['model']]
            );
            $method = new ReflectionMethod(XhadminAigcDigitalHumanProvider::class, 'buildLipsyncPayload');
            $method->setAccessible(true);
            $payload = $method->invoke(new XhadminAigcDigitalHumanProvider(), $request, 'https://example.test/audio.mp3', [
                'lipsync_model' => $model,
                'lipsync_payload' => ['model' => 'xiaojiayu1.0'],
            ]);
            self::assertSame($model, $payload['model']);
            self::assertSame('async_query', $payload['mode']);
            self::assertSame('https://example.test/avatar.mp4', $payload['video_url']);
            self::assertSame('https://example.test/audio.mp3', $payload['audio_url']);
            self::assertSame('test-' . $code, $payload['idempotency_key']);
        }
    }

    public function testCustomChannelsAndProvidersArePreserved(): void
    {
        foreach ([
            ['code' => 'custom', 'provider' => 'xhadmin', 'model' => 'custom-model'],
            ['code' => 'all', 'provider' => 'mock', 'model' => 'mock-digital-human'],
            ['code' => 'all', 'provider' => 'xhadmin', 'model' => 'custom-model'],
        ] as $channel) {
            $channel['config_json'] = ['lipsync_model' => 'custom-model'];
            self::assertSame($channel, AigcDigitalHumanChannelService::normalizeProviderChannel($channel));
        }
    }

    public function testExistingTaskKeepsItsRecordedModelWhenChannelsAreRepaired(): void
    {
        $method = new ReflectionMethod(AigcDigitalHumanService::class, 'buildRequestFromData');
        $method->setAccessible(true);
        $request = $method->invoke(null,
            ['tenant_id' => 1, 'user_id' => 1, 'model' => 'xiaojiayu1.0'], [], [], [
                'channel' => ['code' => 'all', 'model' => 'xiaojiayu2.0', 'config_json' => ['lipsync_model' => 'xiaojiayu2.0']],
                'spec' => ['quality' => '1k', 'ratio' => '9:16'],
            ]);
        self::assertSame('xiaojiayu1.0', $request->channelConfig['model']);
    }

    public function testMigrationRepairsLegacyRowsAndPreservesCustomConfiguration(): void
    {
        if (getenv('DIGITAL_HUMAN_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Set DIGITAL_HUMAN_MYSQL_TEST=1 for the local migration test');
        }
        (new \think\App())->initialize();
        $db = \think\facade\Db::connect();
        self::assertContains($db->getConfig('hostname'), ['127.0.0.1', 'localhost', '::1']);
        $table = 'test_digital_human_lipsync_' . bin2hex(random_bytes(4));
        $db->execute("CREATE TEMPORARY TABLE `$table` (tenant_id int, code varchar(64), provider varchar(50), model varchar(100), config_json text, status int, sort int, update_time int)");
        $rows = [
            [0, 'master', 'xhadmin', 'xiaojiayu1.0', '{"tts_model":"s2-pro","description":"keep"}', 1, 300, 0],
            [0, 'all', 'xhadmin', 'xiaojiayu1.0', '{"lipsync_model":"xiaojiayu1.0","timeout":45}', 0, 200, 0],
            [7, 'free', 'xhadmin', 'xiaojiayu1.0', 'invalid-json', 1, 100, 0],
            [0, 'all', 'custom-provider', 'xiaojiayu1.0', '{}', 1, 100, 0],
            [0, 'custom', 'xhadmin', 'xiaojiayu1.0', '{}', 1, 100, 0],
            [0, 'free', 'xhadmin', 'custom-model', '{"lipsync_model":"custom-model"}', 1, 100, 0],
        ];
        foreach ($rows as $row) {
            $db->execute("INSERT INTO `$table` VALUES (?,?,?,?,?,?,?,?)", $row);
        }
        $sql = str_replace('`la_aigc_digital_human_channel`', '`' . $table . '`',
            file_get_contents(dirname(__DIR__, 2) . '/upgrade/20261007_digital_human_lipsync_models.sql'));
        $db->execute($sql);
        $after = $db->query("SELECT tenant_id,code,provider,model,config_json,status,sort FROM `$table`");
        self::assertSame(['xiaojiayu1.0', 'xiaojiayu2.0', 'xiaojiayu3.0', 'xiaojiayu1.0', 'xiaojiayu1.0', 'custom-model'], array_column($after, 'model'));
        self::assertSame(0, (int)$after[1]['status']);
        self::assertSame(200, (int)$after[1]['sort']);
        self::assertSame('keep', json_decode($after[0]['config_json'], true)['description']);
        self::assertSame(45, json_decode($after[1]['config_json'], true)['timeout']);
        self::assertSame('xiaojiayu3.0', json_decode($after[2]['config_json'], true)['lipsync_model']);
        self::assertSame('{"lipsync_model":"custom-model"}', $after[5]['config_json']);
        $db->execute($sql);
        self::assertSame($after, $db->query("SELECT tenant_id,code,provider,model,config_json,status,sort FROM `$table`"));
        $db->execute("DROP TEMPORARY TABLE `$table`");
    }

    public function testInstallationAndUpgradeUseTheSameRepair(): void
    {
        $root = dirname(__DIR__, 2);
        $sql = file_get_contents($root . '/upgrade/20261007_digital_human_lipsync_models.sql');
        self::assertSame($sql, file_get_contents($root . '/public/upgrade/20261007_digital_human_lipsync_models.sql'));
        self::assertSame($sql, file_get_contents($root . '/app/apps/aigc_digital_human/migrations/zz_20261007_lipsync_models.sql'));
        self::assertStringContainsString($sql, file_get_contents($root . '/public/install/db/like.sql'));
    }

    public function testSavingGlobalDefaultKeepsDistinctChannelModels(): void
    {
        if (getenv('DIGITAL_HUMAN_MYSQL_TEST') !== '1') {
            $this->markTestSkipped('Local database opt-in required');
        }
        (new \think\App())->initialize();
        $db = \think\facade\Db::connect();
        self::assertContains($db->getConfig('hostname'), ['127.0.0.1', 'localhost', '::1']);
        $db->startTrans();
        try {
            $method = new ReflectionMethod(AigcDigitalHumanService::class, 'syncPlatformChannelProvider');
            $method->setAccessible(true);
            $method->invoke(null, 0, 'xhadmin', 'xiaojiayu1.0');
            $rows = $db->name('aigc_digital_human_channel')->where('tenant_id', 0)->whereIn('code', ['master', 'all', 'free'])->select()->toArray();
            self::assertCount(3, $rows);
            $models = ['master' => 'xiaojiayu1.0', 'all' => 'xiaojiayu2.0', 'free' => 'xiaojiayu3.0'];
            foreach ($rows as $row) {
                self::assertSame($models[$row['code']], $row['model']);
                self::assertSame($row['model'], json_decode($row['config_json'], true)['lipsync_model']);
            }
        } finally {
            $db->rollback();
        }
    }
}
