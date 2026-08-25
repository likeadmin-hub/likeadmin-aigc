<?php

namespace app\common\model\app\aigc_geo;

use app\common\model\app\AppBaseModel;

class AigcGeoConfig extends AppBaseModel
{
    protected $name = 'aigc_geo_config';
    protected $json = ['profile_json', 'settings_json'];
    protected $jsonAssoc = true;
}
