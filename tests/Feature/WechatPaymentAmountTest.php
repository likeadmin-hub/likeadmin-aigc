<?php

use app\common\service\pay\WeChatPayService;
use PHPUnit\Framework\TestCase;

class WechatPaymentAmountTest extends TestCase
{
    /** @dataProvider paymentAmounts */
    public function testWechatRequestKeepsEveryCent(string $method, string $amount, int $expected): void
    {
        (new think\App(dirname(__DIR__, 2)))->initialize();
        // Replace only the merchant HTTP boundary; exercise each real payment payload builder.
        $client = new class {
            public array $payload = [];
            public function postJson($uri, $payload)
            {
                $this->payload = $payload;
                return new class {
                    public function toArray($throw)
                    {
                        return ['prepay_id' => 'offline-prepay', 'code_url' => 'offline-code', 'h5_url' => 'offline-h5'];
                    }
                };
            }
        };
        $sdk = new class($client) extends WeChatPayService {
            public function __construct($client)
            {
                $this->config = ['mch_id' => 'offline-merchant', 'notify_url' => 'https://example.invalid/notify'];
                $this->auth = ['openid' => 'offline-openid'];
                $this->app = new class($client) {
                    public function __construct(private $client) {}
                    public function getClient() { return $this->client; }
                };
            }
            public function getPrepayConfig($prepayId, $appId) { return ['prepay_id' => $prepayId]; }
        };

        $sdk->$method('membership', ['id' => 1, 'pay_sn' => 'offline-order', 'order_amount' => $amount, 'redirect_url' => 'https://example.invalid/result'], 'offline-app');

        self::assertSame($expected, $client->payload['amount']['total']);
        self::assertSame('offline-order', $client->payload['out_trade_no']);
    }

    public function paymentAmounts(): array
    {
        $cases = [];
        foreach (['jsapiPay', 'nativePay', 'appPay', 'mwebPay'] as $method) {
            foreach (['19.90' => 1990, '0.29' => 29, '19.89' => 1989, '0.01' => 1, '300.00' => 30000] as $amount => $cents) {
                $cases[$method . ' ' . $amount] = [$method, $amount, $cents];
            }
        }
        return $cases;
    }
}
