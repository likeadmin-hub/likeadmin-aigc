<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/common/service/decorate/agent/DecorationAgentIconGeometry.php';
use app\common\service\decorate\agent\DecorationAgentIconGeometry as G;
$checks=0;
function geometryCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function fixture(string $kind,int $size=1024):string {
 $im=imagecreatetruecolor($size,$size);$bg=imagecolorallocate($im,23,31,53);imagefill($im,0,0,$bg);
 if($kind==='gradient')for($y=0;$y<$size;$y++)imageline($im,0,$y,$size-1,$y,imagecolorallocate($im,23+(int)(80*$y/$size),31,53));
 elseif($kind==='photo'||$kind==='insetphoto')for($y=$kind==='insetphoto'?(int)($size*.25):0;$y<($kind==='insetphoto'?$size*.75:$size);$y+=4)for($x=$kind==='insetphoto'?(int)($size*.225):0;$x<($kind==='insetphoto'?$size*.775:$size);$x+=4)imagefilledrectangle($im,$x,$y,$x+3,$y+3,imagecolorallocate($im,($x*13+$y*7)%256,($x*3+$y*17)%256,($x*19+$y*11)%256));
 else {
  if($kind==='unstable')for($r=40;$r>0;$r--)imagefilledrectangle($im,(int)($size*.3)-$r,(int)($size*.3)-$r,(int)($size*.7)+$r,(int)($size*.7)+$r,imagecolorallocate($im,23+(int)(60*(1-$r/40)),31+(int)(60*(1-$r/40)),53+(int)(60*(1-$r/40))));
  if($kind==='shadow')for($r=12;$r>0;$r--)imagefilledrectangle($im,(int)($size*.225)-$r,(int)($size*.25)-$r,(int)($size*.775)+$r,(int)($size*.75)+$r,imagecolorallocate($im,23+$r,31+$r,53+$r));
  $color=$kind==='lowcontrast'?[35,43,65]:[60,204,239];
  if($kind==='smoothgradient'){for($gy=(int)($size*.25);$gy<(int)($size*.75);$gy++)imageline($im,(int)($size*.225),$gy,(int)($size*.775)-1,$gy,imagecolorallocate($im,60+(int)(120*$gy/$size),80+(int)(150*$gy/$size),239));}
  elseif($kind==='antialias')imagefilledellipse($im,(int)($size*.5),(int)($size*.5),(int)($size*.55),(int)($size*.5),imagecolorallocate($im,...$color));
  elseif($kind==='unstable')imagefilledrectangle($im,(int)($size*.3),(int)($size*.3),(int)($size*.7),(int)($size*.7),imagecolorallocate($im,...$color));
  else imagefilledrectangle($im,(int)($size*.225),(int)($size*.25),(int)($size*.775)-1,(int)($size*.75)-1,imagecolorallocate($im,...$color));
 }
 ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);return $bytes;
}
$request=['generation_mode'=>'design_extract','asset_role'=>'icon','background'=>['mode'=>'solid','color'=>'#171F35']];
$bytes=fixture('flat');$sha=hash('sha256',$bytes);$m=G::measure($bytes,$request);
geometryCheck($m['status']==='measured','flat isolated icon is measured');
geometryCheck(abs($m['fill_x']-.55)<.01&&abs($m['fill_y']-.5)<.01,'foreground fill measures actual subject rather than canvas');
geometryCheck($m['canvas_width']===1024&&$m['sample_width']<=512&&$m['asset_sha256']===$sha,'bounded sample retains immutable original dimensions/SHA');
geometryCheck(abs($m['bbox_px']['x']-230)<=3&&abs($m['bbox_1000']['width']-550)<=5,'source pixel and normalized coordinates agree');
geometryCheck(hash('sha256',$bytes)===$sha,'measurement never changes input bytes');
geometryCheck(G::measure(fixture('shadow'),$request)['status']==='measured','low contrast soft shadow does not become semantic subject');
geometryCheck(G::measure(fixture('smoothgradient'),$request)['status']==='measured','smooth multicolor glyph is not mistaken for a textured photograph');
geometryCheck(G::measure(fixture('antialias'),$request)['status']==='measured','resampled antialiased curved icon retains stable bbox');
foreach(['gradient','photo','insetphoto','lowcontrast','unstable'] as $kind)geometryCheck(G::measure(fixture($kind),$request)['status']==='uncertain',$kind.' must not invent a confident foreground box');
geometryCheck(G::measure('not image',$request)['status']==='unsupported','invalid bytes are safely unsupported');
geometryCheck(G::measure(str_repeat('x',10*1024*1024+1),$request)['reason']==='byte_limit','compressed bytes bounded before decoding');
$huge="\x89PNG\r\n\x1a\n".pack('N',13).'IHDR'.pack('NNCCCCC',10000,10000,8,2,0,0,0).pack('N',0);
geometryCheck(G::measure($huge,$request)['reason']==='pixel_limit','dimensions rejected before GD decode');
foreach([['asset_role'=>'image'],['purpose'=>'design'],['background'=>['mode'=>'solid','color'=>'red']],['generation_mode'=>'text_to_image']] as $change)geometryCheck(G::measure($bytes,array_replace($request,$change))['status']==='unsupported','only explicit regular solid-background extracted icons supported');
echo "icon geometry: $checks checks passed (synthetic in-memory GD fixtures; no file/provider writes)\n";
