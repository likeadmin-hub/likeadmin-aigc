<?php
namespace Tests\Feature;

use app\common\model\app\aigc_digital_human\AigcDigitalHumanVoice;
use app\common\model\app\image_human\ImageHumanAvatar;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService;
use app\common\service\power\MarketPicLipsyncAppRuntimeService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class PicLipsyncReferenceVoiceTest extends TestCase
{
    private array $voice;
    private int $avatarId;
    private string $uri;

    protected function setUp(): void
    {
        if (getenv('PIC_LIPSYNC_MYSQL_TEST') !== '1') $this->markTestSkipped('Pinned local database opt-in required');
        (new \think\App())->initialize();
        self::assertSame('z_cn', Db::connect()->getConfig('database'));
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        request()->tenantId = 1;
        $this->uri = 'uploads/pic-reference-fixture-' . bin2hex(random_bytes(5)) . '.wav';
        $pcm = str_repeat("\0\0", 20000);
        file_put_contents(public_path() . $this->uri, 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm);
        Db::startTrans();
        $avatar = ImageHumanAvatar::create(['tenant_id'=>1, 'user_id'=>701, 'source'=>'mine', 'name'=>'fixture', 'image_uri'=>'uploads/fixture.jpg', 'storage_scope'=>'tenant', 'storage_engine'=>'local', 'storage_domain'=>'http://fixture.invalid', 'delete_time'=>0]);
        $this->avatarId = (int)$avatar['id'];
        $voice = AigcDigitalHumanVoice::create(['tenant_id'=>1, 'user_id'=>701, 'source'=>'mine', 'name'=>'fixture sample', 'audio_uri'=>$this->uri, 'duration'=>0, 'status'=>'running', 'storage_scope'=>'tenant', 'storage_engine'=>'local', 'storage_domain'=>'http://fixture.invalid', 'delete_time'=>0]);
        $this->voice = $voice->toArray();
    }

    protected function tearDown(): void
    {
        if (isset($this->uri)) { Db::rollback(); @unlink(public_path() . $this->uri); }
    }

    private function prepare(array $params, int $userId = 701): array
    {
        $method = new \ReflectionMethod(AigcPicLipsyncService::class, 'prepare');
        $method->setAccessible(true);
        return $method->invoke(null, 1, array_merge(['avatar_id'=>$this->avatarId, 'quality'=>'standard', 'content'=>'直接朗读这段文案', 'prompt'=>'自然微笑'], $params), true, $userId);
    }

    public function testVoiceSampleDrivesTextDirectlyForAllQualities(): void
    {
        foreach (['fast', 'standard', 'max'] as $quality) {
            $prepared = $this->prepare(['quality'=>$quality, 'mode'=>'text', 'voice_id'=>$this->voice['id']]);
            $payload = MarketPicLipsyncAppRuntimeService::buildPayload(['locked_params'=>$prepared['selection']['locked_params']], $prepared['request']);
            self::assertSame('text', $payload['mode']);
            self::assertSame('直接朗读这段文案', $payload['content']);
            self::assertStringEndsWith($this->uri, $payload['audio_url']);
            self::assertSame(0, $prepared['audio_asset_id']);
            self::assertEqualsWithDelta(1.25, $prepared['duration'], 0.001);
            self::assertArrayNotHasKey('voice_id', $payload);
        }
    }

    public function testHistorySampleCanBeExplicitAudioDriveWithoutText(): void
    {
        $prepared = $this->prepare(['mode'=>'audio', 'driver_voice_id'=>$this->voice['id']]);
        $payload = MarketPicLipsyncAppRuntimeService::buildPayload(['locked_params'=>$prepared['selection']['locked_params']], $prepared['request']);
        self::assertSame('audio', $payload['mode']);
        self::assertArrayNotHasKey('content', $payload);
        self::assertStringEndsWith($this->uri, $payload['audio_url']);
    }

    public function testAnotherUsersVoiceIsRejected(): void
    {
        AigcDigitalHumanVoice::where('id', $this->voice['id'])->update(['user_id'=>702]);
        $this->expectExceptionMessage('音色不存在或无权使用');
        $this->prepare(['mode'=>'text', 'voice_id'=>$this->voice['id']]);
    }

    public function testAnotherTenantsVoiceIsRejected(): void
    {
        AigcDigitalHumanVoice::where('id', $this->voice['id'])->update(['tenant_id'=>2]);
        $this->expectExceptionMessage('音色不存在或无权使用');
        $this->prepare(['mode'=>'text', 'voice_id'=>$this->voice['id']]);
    }
}
