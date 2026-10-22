<?php
namespace app\common\model\redeem;
use app\common\model\app\AppBaseModel;
class RedeemCard extends AppBaseModel
{
    protected $hidden = ['code_hash'];
    protected $name = 'redeem_card';
}
