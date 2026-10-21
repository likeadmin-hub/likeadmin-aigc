<?php
namespace app\tenantapi\validate\finance;
use app\common\validate\BaseValidate;
class RedeemCardValidate extends BaseValidate
{
    protected $rule = ['name'=>'require|max:60','type'=>'require|in:points,membership','quantity'=>'require|integer|between:1,200','expire_time'=>'integer|egt:0','remark'=>'max:255'];
}
