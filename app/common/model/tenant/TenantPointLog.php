<?php

namespace app\common\model\tenant;

use app\common\model\BaseModel;

class TenantPointLog extends BaseModel
{
    public static function onAfterInsert($model): void
    {
        \app\common\service\point_alert\AlertService::touch((int)$model['tenant_id']);
    }

    protected $name = 'tenant_point_log';
    protected $json = ['extra'];
    protected $jsonAssoc = true;
}
