<?php
// 全局中间件定义文件
return [
    \app\common\http\middleware\OpenGatewayMiddleware::class,
    //跨域中间件
    \app\common\http\middleware\LikeAdminAllowMiddleware::class,
    //基础中间件
    \app\common\http\middleware\BaseMiddleware::class,
];
