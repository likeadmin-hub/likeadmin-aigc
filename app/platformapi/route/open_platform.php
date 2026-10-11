<?php
use think\facade\Route;
Route::get('app/open_platform/detail','app.open_platform.Management/detail')->completeMatch();
Route::get('app/open_platform/pages','app.open_platform.Management/pages')->completeMatch();
Route::get('app/open_platform/contracts','app.open_platform.Management/contracts')->completeMatch();
Route::get('app/open_platform/documents','app.open_platform.Management/documents')->completeMatch();
Route::get('app/open_platform/requests','app.open_platform.Management/requests')->completeMatch();
Route::get('app/open_platform/requestDetail','app.open_platform.Management/requestDetail')->completeMatch();
Route::get('app/open_platform/recoveryPreview','app.open_platform.Management/recoveryPreview')->completeMatch();
Route::post('app/open_platform/resumeTracking','app.open_platform.Management/resumeTracking')->completeMatch();
Route::get('app/open_platform/inventory','app.open_platform.Management/inventory')->completeMatch();
Route::post('app/open_platform/setup','app.open_platform.Management/setup')->completeMatch();
Route::post('app/open_platform/savePages','app.open_platform.Management/savePages')->completeMatch();
Route::post('app/open_platform/saveDocument','app.open_platform.Management/saveDocument')->completeMatch();
Route::post('app/open_platform/publishDocument','app.open_platform.Management/publishDocument')->completeMatch();
Route::post('app/open_platform/saveContract','app.open_platform.Management/saveContract')->completeMatch();
Route::post('app/open_platform/publishContract','app.open_platform.Management/publishContract')->completeMatch();
