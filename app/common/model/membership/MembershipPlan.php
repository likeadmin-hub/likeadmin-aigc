<?php

namespace app\common\model\membership;

use app\common\model\app\AppBaseModel;
use think\model\concern\SoftDelete;

class MembershipPlan extends AppBaseModel
{
    use SoftDelete;

    protected $deleteTime = 'delete_time';
    protected $name = 'membership_plan';
    protected $json = ['features'];
    protected $jsonAssoc = true;
}
