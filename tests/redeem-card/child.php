<?php
require __DIR__.'/bootstrap.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
try{$r=\app\common\service\redeem\RedeemCardService::redeem(1,(int)$input['user_id'],(string)$input['code']);echo json_encode(['status'=>'success','record_id'=>$r['record_id']]);}
catch(\app\common\service\redeem\RedeemCardException $e){echo json_encode(['status'=>$e->errorCode]);}
