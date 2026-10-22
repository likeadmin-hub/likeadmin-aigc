<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiConsumptionLog extends AppBaseModel
{
    protected $name = 'ai_consumption_log';
    protected $json = ['price_snapshot', 'usage_snapshot', 'request_summary', 'response_summary'];
    protected $jsonAssoc = true;
    public static function onBeforeInsert($model): void
    {
        parent::onBeforeInsert($model);
        $priceSnapshot = $model['price_snapshot'];
        if (!is_array($priceSnapshot)) $priceSnapshot = json_decode((string)$priceSnapshot, true) ?: [];
        $model['price_snapshot'] = \app\common\service\power\MarketPriceRuleService::captureSnapshot((int)$model['tenant_id'], $priceSnapshot);
        $requestId = \app\common\service\open_platform\OpenCallContext::id();
        if ($requestId !== '') {
            $snapshot = \app\common\service\open_platform\OpenStore::json($model['price_snapshot']);
            $snapshot['_open_platform_request_id'] = $requestId;
            $model['price_snapshot'] = $snapshot;
        }
    }

    public static function onAfterInsert($model): void
    {
        \app\common\service\open_platform\OpenCallContext::linkConsumption((int)$model['id']);
    }
}
