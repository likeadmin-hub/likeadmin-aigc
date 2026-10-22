<?php
namespace app\api\validate;
use app\common\validate\BaseValidate;
class RedeemCardValidate extends BaseValidate
{
    protected $rule = ['code'=>'require|max:80'];
    protected $message = ['code.require'=>'请输入卡密','code.max'=>'卡密格式无效'];
}
