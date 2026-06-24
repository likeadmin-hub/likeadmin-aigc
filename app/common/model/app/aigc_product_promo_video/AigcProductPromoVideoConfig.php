<?php

namespace app\common\model\app\aigc_product_promo_video;

use app\common\model\app\AppBaseModel;

class AigcProductPromoVideoConfig extends AppBaseModel
{
    protected $name = 'aigc_product_promo_video_config';
    protected $json = ['config_json', 'price_matrix'];
    protected $jsonAssoc = true;
}
