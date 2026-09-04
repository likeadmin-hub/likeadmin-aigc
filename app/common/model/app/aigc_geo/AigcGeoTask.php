<?php

namespace app\common\model\app\aigc_geo;

use app\common\model\app\AppBaseModel;

class AigcGeoTask extends AppBaseModel
{
    protected $name = 'aigc_geo_task';
    protected $json = ['payload_json', 'result_json'];
    protected $jsonAssoc = true;
}
