<?php
declare(strict_types=1);
require __DIR__ . '/../app/common/service/distribution/PosterLayout.php';
use app\common\service\distribution\PosterLayout;
function expectReject(array $data): void { try { PosterLayout::validate($data); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Invalid layout was accepted'); }
$valid = ['schema_version'=>1,'canvas'=>['width'=>1080,'height'=>1440,'background_asset_id'=>10],'layers'=>[
 ['id'=>'code','type'=>'invite_code','x'=>0.65,'y'=>0.70,'w'=>0.25,'h'=>0.1875,'z'=>20],
 ['id'=>'name','type'=>'nickname','x'=>0.1,'y'=>0.1,'w'=>0.4,'h'=>0.1,'z'=>10,'font_size'=>32,'color'=>'#222222'],
]];
PosterLayout::validate($valid);
$missing=$valid; array_shift($missing['layers']); expectReject($missing);
$overlap=$valid; $overlap['layers'][1]['x']=0.7; $overlap['layers'][1]['y']=0.72; expectReject($overlap);
$escape=$valid; $escape['layers'][0]['x']=0.95; expectReject($escape);
$small=$valid; $small['layers'][0]['w']=0.04; $small['layers'][0]['h']=0.03; expectReject($small);
$duplicate=$valid; $duplicate['layers'][]=$duplicate['layers'][0]; expectReject($duplicate);
$bad=$valid; $bad['layers'][1]['color']='url(https://example.com)'; expectReject($bad);
echo "poster layout validation passed\n";
