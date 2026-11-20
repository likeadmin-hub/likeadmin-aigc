<?php

use think\facade\Route;

Route::post('brand/payment/notify/wechat/:intent_id', 'brand.Payment/notifyWechat')
    ->pattern(['intent_id' => '[1-9][0-9]*'])
    ->completeMatch();
