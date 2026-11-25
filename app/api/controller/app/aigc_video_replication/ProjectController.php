<?php
namespace app\api\controller\app\aigc_video_replication;

use app\common\service\app\aigc_product_video\ProductVideoPolicy;

class ProjectController extends \app\api\controller\app\aigc_product_video\ProjectController
{
    protected function respond(callable $call)
    {
        return parent::respond(fn() => ProductVideoPolicy::forReplication($call));
    }
}
