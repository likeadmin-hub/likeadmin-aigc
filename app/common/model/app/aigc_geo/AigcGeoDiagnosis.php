<?php

namespace app\common\model\app\aigc_geo;

use app\common\model\app\AppBaseModel;

class AigcGeoDiagnosis extends AppBaseModel
{
    protected $name = 'aigc_geo_diagnosis';
    protected $json = ['sources_json'];
    protected $jsonAssoc = true;
}
