<?php
// 事件定义文件
use app\common\listener\NoticeListener;

return [
    'bind'      => [
    ],

    'listen'    => [
        'AppInit'  => [],
        'HttpRun'  => [],
        'HttpEnd'  => [\app\common\listener\WechatAvatarSync::class],
        'LogLevel' => [],
        'LogWrite' => [],

        // 通知
        'Notice' => [NoticeListener::class],
    ],

    'subscribe' => [
    ],
];
