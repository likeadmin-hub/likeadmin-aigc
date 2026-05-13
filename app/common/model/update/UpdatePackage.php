<?php

namespace app\common\model\update;

use app\common\model\BaseModel;

class UpdatePackage extends BaseModel
{
    protected $name = 'update_package';
    protected $globalScope = [];
    protected $json = ['manifest_json'];
    protected $jsonAssoc = true;
}

