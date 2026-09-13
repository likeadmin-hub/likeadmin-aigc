<?php

namespace {
    class ShortDramaFallbackCapturedRequest extends \Error
    {
        public array $params;

        public function __construct(array $params)
        {
            parent::__construct('fallback provider captured');
            $this->params = $params;
        }
    }
}

namespace app\common\service\power {
    class MarketTextModelRuntimeService
    {
        public static bool $allUnavailable = false;

        public static function modelGroups(int $tenantId): array
        {
            return [[
                'key' => 'script_plan',
                'default' => 'fallback-model',
                'options' => [[
                    'id' => 'fallback-model',
                    'market_product_id' => 902,
                    'model_code' => 'qwen3.6-plus',
                ]],
            ]];
        }

        public static function generate(int $tenantId, int $userId, array $params, ?callable $callback = null): array
        {
            if (self::$allUnavailable || ($params['model_selection']['model_code'] ?? '') === 'retired-model') {
                throw new \Exception('model_not_found: upstream retired the model');
            }
            throw new \ShortDramaFallbackCapturedRequest($params);
        }
    }
}
