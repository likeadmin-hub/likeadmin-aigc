<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();
use app\tenantapi\validate\setting\WebSettingValidate;
foreach (['likeadmin','inspiration','imagine','default','immersive','coral'] as $style) {
    $validator = new WebSettingValidate();
    if (!$validator->only(['pc_home_style'])->check(['pc_home_style'=>$style])) throw new RuntimeException('Website style rejected: '.$style);
}
$validator = new WebSettingValidate();
if ($validator->only(['pc_home_style'])->check(['pc_home_style'=>'invalid-style'])) throw new RuntimeException('Unknown website style accepted');
echo "PASS LikeAdmin and legacy website validation\n";
