<?php

namespace app\common\model\app\aigc_geo;

use app\common\model\app\AppBaseModel;

class AigcGeoKeyword extends AppBaseModel
{
    protected $name = 'aigc_geo_keyword';
    protected $json = ['platforms_json', 'metadata_json'];
    protected $jsonAssoc = true;
}
