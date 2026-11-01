<?php

namespace app\common\model\app;

use app\common\model\BaseModel;

class AppBaseModel extends BaseModel
{
    protected $globalScope = [];
    /** App services own tenant scoping, including platform-owned rows (tenant 0).
     * A prior core-model query must not replace an explicit app owner. */
    public static function onBeforeInsert($model): void
    {
        if (!array_key_exists('tenant_id', $model->getData())) parent::onBeforeInsert($model);
    }
    public static function onBeforeUpdate($model): void
    {
        if (!array_key_exists('tenant_id', $model->getData())) parent::onBeforeUpdate($model);
    }
    public static function onAfterInsert($model): void
    {
        \app\common\service\open_platform\OpenCallContext::linkNativeTask($model);
        \app\common\service\ai\GenerationSubmission::linkTask($model);
    }
}

