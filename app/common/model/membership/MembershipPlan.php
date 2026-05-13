<?php

namespace app\common\model\membership;

use app\common\model\app\AppBaseModel;

class MembershipPlan extends AppBaseModel
{
    protected $name = 'membership_plan';
    protected $json = ['features'];
    protected $jsonAssoc = true;
}
