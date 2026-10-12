<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_video\AigcVideoChannelService as Video;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$options=new ReflectionMethod(Video::class,'durationOptionsFromSpecs');$multiplier=new ReflectionMethod(Video::class,'billingMultiplier');
$fixed=[['quality'=>'video_5s'],['provider_params_json'=>['duration'=>10]]];check($options->invoke(null,[],$fixed)===[5,10],'Fixed-duration SKU options changed');
check($options->invoke(null,['config_json'=>['billing_unit'=>'second']],$fixed)===[],'Second-based billing units became selectable output duration');
$mixed=array_merge($fixed,[['quality'=>'1','usage_unit'=>'output_second']]);check($options->invoke(null,[],$mixed)===[5,10],'Mixed duration rules offered one-second billing unit as output');
check($multiplier->invoke(null,[],['usage_unit'=>'output_second'],6)===6.&&$multiplier->invoke(null,[],['usage_unit'=>'per_call'],6)===1.,'Configured billing unit no longer controls duration multiplier');
echo "PASS fixed, per-second and mixed video duration choices use existing billing-unit metadata without missing helpers\n";
