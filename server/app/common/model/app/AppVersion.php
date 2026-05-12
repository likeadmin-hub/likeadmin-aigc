<?php

namespace app\common\model\app;

class AppVersion extends AppBaseModel
{
    protected $name = 'app_version';
    protected $json = ['manifest_json'];
    protected $jsonAssoc = true;
}

