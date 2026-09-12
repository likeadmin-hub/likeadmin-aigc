<?php

namespace Tests\Fixtures {
    /** These fakes are loaded only in a separate PHPUnit process. No network/points writes. */
    class ImageSubmitTransport
    {
        public static array $response = ['task_id' => 'fixture-upstream', 'status' => 'running'];
        public static int $calls = 0;
        public static $beforeResponse;
        public static function getSource(): array { return ['base_url' => 'http://127.0.0.1', 'api_key' => 'fixture']; }
        public static function sslVerify($source): bool { return false; }
    }
    class ImageSubmitPoints
    {
        public static int $refunds = 0;
        public static int $settlements = 0;
        public static bool $failSettlement = false;
        public static function releaseReservedBusinessAmountsInCurrentTransaction(...$args): void { self::$refunds++; }
        public static function settleReservedBusinessAmountsInCurrentTransaction(...$args): void
        {
            if (self::$failSettlement) throw new \RuntimeException('injected local settlement failure');
            self::$settlements++;
        }
    }
    class ImageSubmitAssets
    {
        public static function persistGeneratedImage($url, ...$args): array { return ['uri' => $url]; }
    }
    class ImageSubmitBusinessResult
    {
        public static function syncTerminalByConsumptionId(...$args): bool { return true; }
        public static function syncByConsumptionId(...$args): bool { return true; }
    }
}

namespace app\common\service\power {
    // Intercept cURL at the runtime's namespace; never contact a real provider.
    function curl_exec($ch)
    {
        \Tests\Fixtures\ImageSubmitTransport::$calls++;
        if (\Tests\Fixtures\ImageSubmitTransport::$beforeResponse) {
            (\Tests\Fixtures\ImageSubmitTransport::$beforeResponse)();
        }
        return json_encode(\Tests\Fixtures\ImageSubmitTransport::$response);
    }
    function curl_getinfo($ch, $option) { return 200; }
}
