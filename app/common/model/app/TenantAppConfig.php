<?php

namespace app\common\model\app;

class TenantAppConfig extends AppBaseModel
{
    protected $name = 'tenant_app_config';
    protected $json = ['extra'];
    protected $jsonAssoc = true;
}
