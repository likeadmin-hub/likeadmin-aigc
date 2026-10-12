<?php
namespace Tests\Feature;

use app\common\service\app\aigc_image\AigcImageSubmission as Submission;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_image\AigcImageCreationBatch;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class AigcImageSubmissionTest extends TestCase
{
    public function testKeysRemainCompatibleAndAppScoped(): void
    {
        // Page submission keys now inspect the trusted request context.
        (new \think\App())->initialize();
        self::assertSame('', Submission::key([], 'aigc_image'));
        $params = ['batch_key' => 'mobile-image-1791700000000-1'];
        self::assertSame(Submission::key($params, 'aigc_image'), Submission::key($params, 'aigc_image'));
        self::assertNotSame(Submission::key($params, 'aigc_image'), Submission::key($params, 'other_app'));
        self::assertSame(['variant' => 'pro'], AigcImageCreationBatch::providerParams(['variant' => 'pro', '_submission_key' => 'key']));
    }

    public function testLostAcknowledgementReturnsTheSameTaskWithoutProviderOrBillingCalls(): void
    {
        if (getenv('AIGC_IMAGE_SUBMISSION_DB_TESTS') !== '1') self::markTestSkipped('Local transactional fixtures only');
        (new \think\App())->initialize();
        $database = getenv('AIGC_TEST_DATABASE') ?: 'x_cn';
        self::assertContains($database, ['x_cn', 'z_cn'], 'Only explicitly named local fixture databases are allowed');
        self::assertSame($database, Db::connect()->getConfig('database'));
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        Db::startTrans();
        try {
            $userId = (int)Db::name('user')->value('id');
            self::assertGreaterThan(0, $userId);
            $tenantId = 2000000719;
            $params = ['batch_key' => 'pc-image-test-' . bin2hex(random_bytes(12))];
            $key = Submission::key($params, 'aigc_image');
            $data = ['tenant_id' => $tenantId, 'user_id' => $userId, 'prompt' => 'transactional fixture',
                'status' => 'running', 'quantity' => 1, 'provider_params_json' => [], 'reference_images' => [], 'delete_time' => 0];
            $first = Submission::claim($data, $key);
            $second = Submission::claim($data, $key);
            self::assertTrue($first['created']);
            self::assertFalse($second['created']);
            self::assertSame($first['task']['id'], $second['task']['id']);
            self::assertNull(Submission::find($tenantId + 1, $userId, $key));
            self::assertNull(Submission::find($tenantId, $userId + 1, $key));
            // No prompt/model config is supplied: only a replay can return successfully.
            $replay = AigcImageService::generateMarketModelWithBillingOverride($tenantId, $userId, $params, [], 'aigc_image');
            self::assertSame((int)$first['task']['id'], $replay['task_id']);
            self::assertSame('running', $replay['status']);
            $first['task']->save(['status' => 'failed', 'error' => 'fixture failure', 'delete_time' => time()]);
            $terminal = Submission::claim($data, $key);
            self::assertFalse($terminal['created']);
            self::assertSame('failed', $terminal['task']['status']);
            self::assertSame('fixture failure', $terminal['task']['error']);
            self::assertSame(1, Db::name('aigc_image_task')->where(['tenant_id' => $tenantId, 'user_id' => $userId])->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(provider_params_json, '$._submission_key')) = ?", [$key])->count());
        } finally { Db::rollback(); }
    }
}
