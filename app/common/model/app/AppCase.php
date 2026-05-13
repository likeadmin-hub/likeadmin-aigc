<?php

namespace app\common\model\app;

class AppCase extends AppBaseModel
{
    protected $name = 'app_case';
    protected $json = ['reference_images', 'config_json'];
    protected $jsonAssoc = true;
}
