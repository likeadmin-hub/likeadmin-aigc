<?php
namespace app\api\controller\app\aigc_video_replication;

use app\common\service\app\aigc_product_video\ProductVideoPolicy;

class JobController extends \app\api\controller\app\aigc_product_video\JobController
{
    protected function respond(callable $call)
    {
        return parent::respond(fn() => ProductVideoPolicy::forReplication($call));
    }
}
