<?php
declare(strict_types=1);
$path=dirname(__DIR__,2).'/app/common/service/decorate/agent/DecorationAgentReviewImages.php';
if(!is_file($path))throw new RuntimeException('review image transport normalizer is missing');
require_once $path;
use app\common\service\decorate\agent\DecorationAgentReviewImages as R;
$checks=0;
function checkImage(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rejectsImage(callable $fn,string $label):void {try{$fn();}catch(RuntimeException $e){checkImage(true,$label);return;}throw new RuntimeException($label);}
if(in_array('--no-gd',$argv,true)){
    checkImage(!function_exists('imagecreatefromstring'),'test process really lacks GD');
    rejectsImage(fn()=>R::references(['data:image/png;base64,YQ==','data:image/png;base64,YQ=='],true),'missing GD fails closed');
    echo "review images no-GD: $checks checks passed\n";exit;
}
function reviewPng(int $w,int $h):string {
    $im=imagecreatetruecolor($w,$h);imagealphablending($im,false);imagesavealpha($im,true);
    imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127));
    imagefilledrectangle($im,0,0,$w-1,15,imagecolorallocatealpha($im,255,0,0,0));
    imagefilledrectangle($im,0,$h-16,$w-1,$h-1,imagecolorallocatealpha($im,0,0,255,0));
    ob_start();imagepng($im);$bytes=ob_get_clean();imagedestroy($im);return $bytes;
}
function dataImage(string $bytes):string {return 'data:image/png;base64,'.base64_encode($bytes);}
$actual=reviewPng(375,676);$design=reviewPng(768,1376);$refs=[dataImage($actual),dataImage($design)];$hashes=array_map(fn($x)=>hash('sha256',$x),$refs);
$wire=R::references($refs,true);
checkImage($wire[1]===$refs[0],'actual capture is byte preserved and sent second, after the target');
checkImage(R::referenceOrder()===['图1：已确认设计稿（target）','图2：实际候选截图（actual）'],'model order explicitly names the target first and actual second');
$out=base64_decode(explode(',',$wire[0],2)[1],true);$info=getimagesizefromstring($out);
checkImage($info[0]===375&&$info[1]===672,'design is full-width resampled with proportional rounded height');
checkImage(array_map(fn($x)=>hash('sha256',$x),$refs)===$hashes&&hash('sha256',$design)===hash('sha256',base64_decode(explode(',',$refs[1],2)[1])),'source bytes and original identity remain unchanged');
$decoded=imagecreatefromstring($out);
checkImage((imagecolorat($decoded,180,0)&0xFFFFFF)===0xFF0000&&(imagecolorat($decoded,180,671)&0xFFFFFF)===0x0000FF,'top and bottom remain visible without crop or padding');
checkImage(((imagecolorat($decoded,180,300)>>24)&127)===127,'transparent source remains transparent');imagedestroy($decoded);
$small=R::references([dataImage(reviewPng(100,200)),$refs[0]],true);$smallInfo=getimagesizefromstring(base64_decode(explode(',',$small[1],2)[1]));
checkImage($smallInfo[0]===375&&$smallInfo[1]===750,'small images also use common width rather than mixed scale');
checkImage(R::references(['invalid ordinary image'],false)===['invalid ordinary image'],'ordinary reference transport stays exactly unchanged');
checkImage(R::references($refs,false)===$refs,'ordinary two-image chat or generation transport is not reordered');
$meta=R::dimensions(768,1376);
checkImage($meta['source']===['width_px'=>768,'height_px'=>1376]&&$meta['model_input']===['width_px'=>375,'height_px'=>672,'to_logical_px_scale'=>1],'model context dimensions describe actual source and transport geometry');
checkImage(R::dimensions(0,0)['model_input']===null,'unknown source dimensions are not invented');
foreach([[],[$refs[0]],[$refs[0],$refs[1],$refs[0]]] as $wrong)rejectsImage(fn()=>R::references($wrong,true),'review must contain the exact two-image pair');
rejectsImage(fn()=>R::references(['https://example.invalid/img.png',$refs[0]],true),'no URL fetching or arbitrary source');
rejectsImage(fn()=>R::references(['data:image/png;base64,%%%',$refs[0]],true),'invalid base64 is rejected');
rejectsImage(fn()=>R::references([dataImage('not an image'),$refs[0]],true),'invalid image is rejected');
rejectsImage(fn()=>R::references([str_replace('image/png','image/jpeg',$refs[0]),$refs[1]],true),'content MIME must agree with its transport type');
rejectsImage(fn()=>R::references([dataImage(str_repeat('x',10485761)),$refs[0]],true),'compressed byte bound');
$huge="\x89PNG\r\n\x1a\n".pack('N',13).'IHDR'.pack('NNCCCCC',10000,10000,8,2,0,0,0).pack('N',0);
rejectsImage(fn()=>R::references([dataImage($huge),$refs[0]],true),'pixel bound checked before decode');
$memory=new ReflectionMethod(R::class,'assertMemory');$memory->setAccessible(true);
rejectsImage(fn()=>$memory->invoke(null,768,1376,672,strlen($design),1024),'insufficient memory fails before decoding');
$decodeMemory=new ReflectionMethod(R::class,'assertDecodeMemory');$decodeMemory->setAccessible(true);
rejectsImage(fn()=>$decodeMemory->invoke(null,13981016,1024),'base64 substring and decoded payload are budgeted before either allocation');
$decodeMemory->invoke(null,13981016,67108864);
checkImage(true,'sufficient bounded decode headroom remains allowed');
echo "review images: $checks checks passed (synthetic GD, memory only; no DB/provider/files)\n";
