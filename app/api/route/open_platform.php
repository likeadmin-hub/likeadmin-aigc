<?php
use think\facade\Route;
Route::get('app/open_platform/overview','app.open_platform.Console/overview')->completeMatch();
Route::get('app/open_platform/keys','app.open_platform.Console/keys')->completeMatch();
Route::get('app/open_platform/pages','app.open_platform.Console/pages')->completeMatch();
Route::get('app/open_platform/catalog','app.open_platform.Console/catalog')->completeMatch();
Route::get('app/open_platform/requests','app.open_platform.Console/requests')->completeMatch();
Route::get('app/open_platform/requestDetail','app.open_platform.Console/requestDetail')->completeMatch();
Route::get('app/open_platform/tasks','app.open_platform.Console/tasks')->completeMatch();
Route::get('app/open_platform/usage','app.open_platform.Console/usage')->completeMatch();
Route::get('app/open_platform/callbacks','app.open_platform.Console/callbacks')->completeMatch();
Route::get('app/open_platform/debugPermit','app.open_platform.Console/debugPermit')->completeMatch();
Route::get('app/open_platform/usageExport','app.open_platform.Console/usageExport')->completeMatch();
Route::post('app/open_platform/createKey','app.open_platform.Console/createKey')->completeMatch();
Route::post('app/open_platform/setKey','app.open_platform.Console/setKey')->completeMatch();
Route::post('app/open_platform/rotateKey','app.open_platform.Console/rotateKey')->completeMatch();
Route::post('app/open_platform/updateKey','app.open_platform.Console/updateKey')->completeMatch();
Route::post('app/open_platform/replayCallback','app.open_platform.Console/replayCallback')->completeMatch();
