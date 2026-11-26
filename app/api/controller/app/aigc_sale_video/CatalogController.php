<?php
namespace app\api\controller\app\aigc_sale_video;

use app\common\service\app\aigc_product_video\ProductVideoPolicy;

class CatalogController extends \app\api\controller\app\aigc_product_video\CatalogController
{
    protected function respond(callable $call)
    {
        return parent::respond(fn() => ProductVideoPolicy::forSale($call));
    }
}
