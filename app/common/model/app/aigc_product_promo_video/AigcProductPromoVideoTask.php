<?php

namespace app\common\model\app\aigc_product_promo_video;

use app\common\model\app\AppBaseModel;

class AigcProductPromoVideoTask extends AppBaseModel
{
    protected $name = 'aigc_product_promo_video_task';
    protected $json = ['type_snapshot'];
    protected $jsonAssoc = true;
}
