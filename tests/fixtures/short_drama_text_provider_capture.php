<?php

namespace {
    class ShortDramaCapturedRequest extends \Error
    {
        public int $tenantId;
        public array $params;
        public function __construct(int $tenantId, array $params) { parent::__construct('provider captured'); $this->tenantId = $tenantId; $this->params = $params; }
    }
}
namespace app\common\service\power {
    class MarketTextModelRuntimeService
    {
        public static function modelGroups(int $tenantId): array
        {
            return [];
        }

        public static function generate(int $tenantId, int $userId, array $params, ?callable $callback = null): array
        {
            throw new \ShortDramaCapturedRequest($tenantId, $params);
        }
    }
}
