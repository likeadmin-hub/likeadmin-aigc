<?php
namespace app\tenantapi\controller\app\aigc_sale_video;

use app\common\service\app\aigc_product_video\ProductVideoPolicy;

class ConfigController extends \app\tenantapi\controller\app\aigc_product_video\ConfigController
{
    protected function respond(callable $call)
    {
        return parent::respond(fn() => ProductVideoPolicy::forSale($call));
    }
}
