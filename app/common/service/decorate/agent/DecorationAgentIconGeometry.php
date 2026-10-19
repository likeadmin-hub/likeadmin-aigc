<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

/** Bounded solid-background measurement, not semantic segmentation or image editing. */
final class DecorationAgentIconGeometry
{
    public const VERSION=1;
    public static function eligible(array $request): bool
    {
        return ($request['purpose']??'')!=='design'&&($request['generation_mode']??'')==='design_extract'
            &&($request['asset_role']??'')==='icon'&&($request['background']['mode']??'')==='solid'
            &&is_string($request['background']['color']??null)&&preg_match('/^#[0-9a-f]{6}$/iD',$request['background']['color'])===1;
    }
    public static function measure(string $bytes,array $request): array
    {
        $base=['analysis_version'=>self::VERSION,'method'=>'solid_background_threshold_stability','status'=>'unsupported','reason'=>'unsupported_request'];
        if(!self::eligible($request))return $base;
        if(strlen($bytes)>10*1024*1024)return array_replace($base,['reason'=>'byte_limit']);
        $info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info['mime']??'',['image/png','image/jpeg','image/webp'],true))return array_replace($base,['reason'=>'invalid_image']);
        [$width,$height]=$info;
        if($width<1||$height<1||$width>10000||$height>10000||$width*$height>10000000)return array_replace($base,['reason'=>'pixel_limit']);
        $base+=['asset_sha256'=>hash('sha256',$bytes),'canvas_width'=>$width,'canvas_height'=>$height,'background'=>strtoupper($request['background']['color'])];
        if(!extension_loaded('gd'))return array_replace($base,['reason'=>'gd_unavailable']);
        // Decode + resample overhead is bounded before asking GD to allocate the source bitmap.
        $limit=ini_get('memory_limit');$available=PHP_INT_MAX;
        if(preg_match('/^(\d+)([KMG]?)$/i',(string)$limit,$m))$available=(int)$m[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($m[2])]))-memory_get_usage(true);
        if($width*$height*8+strlen($bytes)*3+16*1024*1024>$available)return array_replace($base,['reason'=>'memory_limit']);
        $source=null;$sample=null;
        try {
            $source=@imagecreatefromstring($bytes);if(!$source)return array_replace($base,['reason'=>'decode_failed']);
            $scale=min(1,512/max($width,$height));$w=max(1,(int)round($width*$scale));$h=max(1,(int)round($height*$scale));
            $sample=imagecreatetruecolor($w,$h);
            $hex=$request['background']['color'];$bg=[hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2))];
            imagefill($sample,0,0,imagecolorallocate($sample,...$bg));imagealphablending($sample,true);
            imagecopyresampled($sample,$source,0,0,0,0,$w,$h,$width,$height);imagedestroy($source);$source=null;
            $base+=['sample_width'=>$w,'sample_height'=>$h];$base['status']='uncertain';
            $dist=[];$border=0;$borderOk=0;$colors=[];$contrast=0;
            for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++){
                $c=imagecolorat($sample,$x,$y);$r=($c>>16)&255;$g=($c>>8)&255;$b=$c&255;
                $d=max(abs($r-$bg[0]),abs($g-$bg[1]),abs($b-$bg[2]));$dist[$y*$w+$x]=$d;
                if($x<2||$y<2||$x>=$w-2||$y>=$h-2){$border++;if($d<=12)$borderOk++;}
                if($d>40){$colors[(($r>>4)<<8)|(($g>>4)<<4)|($b>>4)]=true;$contrast=max($contrast,$d);}
            }
            if($border===0||$borderOk/$border<.98)return array_replace($base,['reason'=>'background_not_uniform']);
            // Smooth multicolor glyphs can have many colors. Reject spatial texture, not color count alone.
            $pairs=0;$rough=0;
            for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++){
                $i=$y*$w+$x;if($dist[$i]<=40)continue;$c=imagecolorat($sample,$x,$y);
                foreach([[$x+1,$y],[$x,$y+1]] as [$nx,$ny]){
                    if($nx>=$w||$ny>=$h||$dist[$ny*$w+$nx]<=40)continue;$pairs++;$n=imagecolorat($sample,$nx,$ny);
                    if(max(abs((($c>>16)&255)-(($n>>16)&255)),abs((($c>>8)&255)-(($n>>8)&255)),abs(($c&255)-($n&255)))>32)$rough++;
                }
            }
            $texture=$pairs?$rough/$pairs:0;
            if($texture>.18||(count($colors)>128&&$texture>.08))return array_replace($base,['reason'=>'complex_foreground']);
            $boxes=[];
            foreach([16,28,40] as $threshold){
                $minX=$w;$minY=$h;$maxX=-1;$maxY=-1;$count=0;
                foreach($dist as $i=>$d)if($d>$threshold){$x=$i%$w;$y=intdiv($i,$w);$minX=min($minX,$x);$minY=min($minY,$y);$maxX=max($maxX,$x);$maxY=max($maxY,$y);$count++;}
                if($count<max(8,$w*$h*.002)||$contrast<64)return array_replace($base,['reason'=>'insufficient_contrast']);
                $boxes[]=[$minX,$minY,$maxX+1,$maxY+1];
            }
            $instability=0;foreach([0,1,2,3] as $axis)$instability=max($instability,max(array_column($boxes,$axis))-min(array_column($boxes,$axis)));
            if($instability>max(2,min($w,$h)*.01))return array_replace($base,['reason'=>'threshold_unstable']);
            [$x,$y,$right,$bottom]=$boxes[1];$bw=$right-$x;$bh=$bottom-$y;
            if($x<2||$y<2||$right>$w-2||$bottom>$h-2||$bw*$bh/($w*$h)>.75)return array_replace($base,['reason'=>'foreground_not_isolated']);
            $px=['x'=>(int)floor($x*$width/$w),'y'=>(int)floor($y*$height/$h),'width'=>(int)ceil($bw*$width/$w),'height'=>(int)ceil($bh*$height/$h)];
            return array_replace($base,['status'=>'measured','reason'=>'stable_solid_background','confidence'=>'conservative','bbox_px'=>$px,
                'bbox_1000'=>['x'=>(int)round($x/$w*1000),'y'=>(int)round($y/$h*1000),'width'=>(int)round($bw/$w*1000),'height'=>(int)round($bh/$h*1000)],
                'fill_x'=>round($bw/$w,6),'fill_y'=>round($bh/$h,6),'edge_error_px'=>(int)ceil(($instability+1)*max($width/$w,$height/$h)),
                'limitations'=>'Threshold foreground against declared solid background; not a semantic box or a rendered DOM measurement.']);
        } catch(\Throwable $e){return array_replace($base,['status'=>'uncertain','reason'=>'measurement_unavailable']);}
        finally {if($source instanceof \GdImage)imagedestroy($source);if($sample instanceof \GdImage)imagedestroy($sample);}
    }
}
