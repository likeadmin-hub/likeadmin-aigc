<?php
namespace app;

// 应用请求对象类
class Request extends \think\Request
{
    // 全局过滤规则
    protected $filter = [self::class . '::trimString'];

    /** Keep JSON scalar types; trim(false) would erase an explicit choice. */
    public static function trimString($value)
    {
        return is_string($value) ? trim($value) : $value;
    }
}
