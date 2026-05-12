<?php

namespace app\common\model\update;

use app\common\model\BaseModel;

class UpdateLicense extends BaseModel
{
    protected $name = 'update_license';
    protected $globalScope = [];
    protected $json = ['domains_json', 'license_json'];
    protected $jsonAssoc = true;
}
