<?php

namespace app\common\model\app\aigc_geo;

use app\common\model\app\AppBaseModel;

class AigcGeoArticle extends AppBaseModel
{
    protected $name = 'aigc_geo_article';
    protected $json = ['metadata_json'];
    protected $jsonAssoc = true;
}
