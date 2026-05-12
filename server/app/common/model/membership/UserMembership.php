<?php

namespace app\common\model\membership;

use app\common\model\app\AppBaseModel;

class UserMembership extends AppBaseModel
{
    protected $name = 'user_membership';
    protected $json = ['app_codes', 'features'];
    protected $jsonAssoc = true;
}
