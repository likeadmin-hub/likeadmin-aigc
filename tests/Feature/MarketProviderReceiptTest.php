<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\power\MarketProviderReceipt;
use app\common\service\open_platform\OpenVault;
final class MarketProviderReceiptTest extends TestCase
{
    public function testProviderRejectionIsEncryptedAndDoesNotExposeItsMessage():void
    {
        if(getenv('AGENT_MODEL_DB_TESTS')!=='local-existing')self::markTestSkipped('Local rollback only');
        (new \think\App())->initialize();Db::startTrans();try{
            $body=['code'=>0,'msg'=>'private provider diagnostic','request_id'=>'fixture'];
            MarketProviderReceipt::record(1999999759,400,$body);
            $row=Db::name('ai_consumption_event')->where(['consumption_id'=>1999999759,'event_type'=>'provider_rejection'])->find();
            self::assertNotNull($row);self::assertStringNotContainsString('private provider',$row['payload_summary']);
            $sealed=json_decode(OpenVault::open($row['payload_ciphertext']),true);
            self::assertSame($body,$sealed['response']);self::assertSame(1999999759,$sealed['consumption_id']);self::assertSame(400,(int)$row['http_status']);
        }finally{Db::rollback();}
    }
}
