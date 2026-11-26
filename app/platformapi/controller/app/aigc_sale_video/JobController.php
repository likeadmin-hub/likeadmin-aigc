<?php
namespace app\platformapi\controller\app\aigc_sale_video;

use app\common\service\app\aigc_product_video\ProductVideoPolicy;

class JobController extends \app\platformapi\controller\app\aigc_product_video\JobController
{
    protected function respond(callable $call)
    {
        return parent::respond(fn() => ProductVideoPolicy::forSale($call));
    }
}
