<?php
namespace app\common\service\app\aigc_music;

/** Bounded container validation before storage/billing. Unsupported layouts stay recoverable. */
final class MusicExportValidator
{
    public static function valid(string $type,string $bytes): bool
    {
        try {
            return match($type){'wav'=>self::wav($bytes),'midi'=>self::midi($bytes),'mp4'=>self::mp4($bytes),default=>false};
        } catch (\UnexpectedValueException) {return false;}
    }
    private static function require(bool $ok): void {if(!$ok)throw new \UnexpectedValueException('Invalid media structure');}
    private static function u32(string $b,int $p,bool $little=false): int
    {
        self::require($p>=0&&$p+4<=strlen($b));return unpack($little?'V':'N',substr($b,$p,4))[1];
    }
    private static function u16(string $b,int $p,bool $little=false): int
    {
        self::require($p>=0&&$p+2<=strlen($b));return unpack($little?'v':'n',substr($b,$p,2))[1];
    }
    private static function wav(string $b): bool
    {
        $n=strlen($b);self::require($n>=44&&substr($b,0,4)==='RIFF'&&substr($b,8,4)==='WAVE'&&self::u32($b,4,true)===$n-8);
        $fmt=null;$data=[];$p=12;
        while($p<$n){self::require($p+8<=$n);$tag=substr($b,$p,4);$size=self::u32($b,$p+4,true);$p+=8;self::require($size<=$n-$p);
            if($tag==='fmt '){self::require($fmt===null&&$size>=16);$fmt=substr($b,$p,$size);}
            if($tag==='data')$data[]=$size;
            $p+=$size+($size%2);self::require($p<=$n);
        }
        self::require($fmt!==null&&$data!==[]);$format=self::u16($fmt,0,true);$channels=self::u16($fmt,2,true);$rate=self::u32($fmt,4,true);$byteRate=self::u32($fmt,8,true);$align=self::u16($fmt,12,true);$bits=self::u16($fmt,14,true);
        if($format===65534){self::require(strlen($fmt)>=40&&self::u16($fmt,16,true)>=22&&substr($fmt,26,14)===hex2bin('000000001000800000aa00389b71'));$format=self::u16($fmt,24,true);}
        self::require(in_array($format,[1,3],true)&&$channels>0&&$channels<=64&&$rate>0&&$rate<=768000&&in_array($bits,[8,16,24,32,64],true));
        self::require($format!==3||in_array($bits,[32,64],true));self::require($align===$channels*($bits/8)&&$byteRate===$rate*$align);
        foreach($data as $size)self::require($size>0&&$size%$align===0);return true;
    }
    private static function vlq(string $b,int &$p,int $end): int
    {
        $value=0;for($i=0;$i<4;$i++){self::require($p<$end);$c=ord($b[$p++]);$value=($value<<7)|($c&127);if(!($c&128))return $value;}
        throw new \UnexpectedValueException('Oversized MIDI variable integer');
    }
    private static function midi(string $b): bool
    {
        self::require(strlen($b)>=22&&substr($b,0,4)==='MThd'&&self::u32($b,4)===6);$format=self::u16($b,8);$tracks=self::u16($b,10);$division=self::u16($b,12);
        self::require($format<=2&&$tracks>0&&$tracks<=1024&&($format!==0||$tracks===1)&&$division!==0);
        if($division&32768)self::require(in_array(256-($division>>8),[24,25,29,30],true)&&($division&255)>0);
        $p=14;$note=false;
        for($t=0;$t<$tracks;$t++){
            self::require(substr($b,$p,4)==='MTrk');$size=self::u32($b,$p+4);$p+=8;$end=$p+$size;self::require($size>0&&$end<=strlen($b));$running=0;$closed=false;
            while($p<$end){self::vlq($b,$p,$end);self::require($p<$end);$status=ord($b[$p]);if($status&128){$p++;}else{$status=$running;self::require($status>=128&&$status<240);}
                if($status<240){$running=$status;$count=in_array($status>>4,[12,13],true)?1:2;self::require($p+$count<=$end);for($i=0;$i<$count;$i++)self::require(ord($b[$p+$i])<128);if(($status>>4)===9&&ord($b[$p+1])>0)$note=true;$p+=$count;}
                elseif($status===255){$running=0;self::require($p<$end);$meta=ord($b[$p++]);$len=self::vlq($b,$p,$end);self::require($len<=$end-$p);if($meta===47){self::require($len===0&&$p===$end);$closed=true;}if($meta===81)self::require($len===3);$p+=$len;}
                elseif($status===240||$status===247){$running=0;$len=self::vlq($b,$p,$end);self::require($len<=$end-$p);$p+=$len;}
                else self::require(false);
            }
            self::require($closed);
        }
        return $p===strlen($b)&&$note;
    }
    /** ISO BMFF flat sample tables. Fragmented/unknown layouts are held, never guessed. */
    private static function boxes(string $b,int $start,int $end): array
    {
        $out=[];$p=$start;$count=0;
        while($p<$end){self::require(++$count<=10000&&$p+8<=$end);$size=self::u32($b,$p);$header=8;
            if($size===1){self::require($p+16<=$end&&self::u32($b,$p+8)===0);$size=self::u32($b,$p+12);$header=16;}
            if($size===0)$size=$end-$p;
            self::require($size>=$header&&$size<=$end-$p);$out[]=['type'=>substr($b,$p+4,4),'start'=>$p+$header,'end'=>$p+$size];$p+=$size;
        }return $out;
    }
    private static function one(array $boxes,string $type): array
    {
        $found=array_values(array_filter($boxes,static fn($box)=>$box['type']===$type));self::require(count($found)===1);return $found[0];
    }
    private static function children(string $b,array $box): array {return self::boxes($b,$box['start'],$box['end']);}
    private static function duration(string $b,array $box,bool $movie=false): void
    {
        $p=$box['start'];self::require($p+4<=$box['end']);$version=ord($b[$p]);self::require($version===0||$version===1);
        $scale=$p+($version===0?12:20);$duration=$scale+4;self::require($box['end']-$p>=($movie?($version===0?100:112):($version===0?24:36)));
        self::require(self::u32($b,$scale)>0);if($version===1)self::require(self::u32($b,$duration)===0);self::require(self::u32($b,$duration+($version===1?4:0))>0);
    }
    private static function descriptions(string $b,array $desc,string $kind): array
    {
        $lengths=[];
        foreach($desc as $d){$p=$d['start'];self::require($d['end']-$p>=($kind==='vide'?78:28)&&self::u16($b,$p+6)>0);
            if($kind==='vide'){
                self::require(in_array($d['type'],['avc1','avc3'],true)&&self::u16($b,$p+24)>0&&self::u16($b,$p+26)>0&&self::u16($b,$p+40)>0);
                $config=self::one(self::boxes($b,$p+78,$d['end']),'avcC');$q=$config['start'];self::require($q+7<=$config['end']&&ord($b[$q])===1);$width=(ord($b[$q+4])&3)+1;self::require(in_array($width,[1,2,4],true));$q+=5;$sps=ord($b[$q++])&31;self::require($sps>0);
                for($i=0;$i<$sps;$i++){$size=self::u16($b,$q);$q+=2;self::require($size>=4&&$q+$size<=$config['end']&&(ord($b[$q])&159)===7);$q+=$size;}
                self::require($q<$config['end']);$pps=ord($b[$q++]);self::require($pps>0);for($i=0;$i<$pps;$i++){$size=self::u16($b,$q);$q+=2;self::require($size>=2&&$q+$size<=$config['end']&&(ord($b[$q])&159)===8);$q+=$size;}$lengths[]=$width;
            }else{
                self::require($d['type']==='mp4a'&&self::u16($b,$p+8)===0&&self::u16($b,$p+16)>0&&self::u16($b,$p+18)>0&&self::u32($b,$p+24)>0);
                $esds=self::one(self::boxes($b,$p+28,$d['end']),'esds');self::require($esds['end']-$esds['start']>=12&&self::u32($b,$esds['start'])===0);$lengths[]=0;
            }
        }return $lengths;
    }
    private static function avcSample(string $b,int $offset,int $size,int $width): void
    {
        $end=$offset+$size;self::require($offset>=0&&$end<=strlen($b));$vcl=false;
        while($offset<$end){self::require($offset+$width<=$end);$n=0;for($i=0;$i<$width;$i++)$n=($n<<8)|ord($b[$offset++]);self::require($n>=2&&$n<=$end-$offset);$type=ord($b[$offset])&31;self::require(!(ord($b[$offset])&128)&&$type>0&&$type<24);if($type<=5)$vcl=true;$offset+=$n;}
        self::require($vcl);
    }
    private static function table(string $b,array $box,int $width): array
    {
        $p=$box['start'];self::require($p+8<=$box['end']&&self::u32($b,$p)===0);$count=self::u32($b,$p+4);self::require($count>0&&$count<=1000000&&8+$width*$count===$box['end']-$p);$out=[];
        for($i=0;$i<$count;$i++){$row=[];for($j=0;$j<$width;$j+=4)$row[]=self::u32($b,$p+8+$i*$width+$j);$out[]=$row;}return $out;
    }
    private static function mp4(string $b): bool
    {
        $top=self::boxes($b,0,strlen($b));$ftyp=self::one($top,'ftyp');self::require($ftyp['end']-$ftyp['start']>=8&&($ftyp['end']-$ftyp['start'])%4===0);
        $moov=self::children($b,self::one($top,'moov'));self::duration($b,self::one($moov,'mvhd'),true);$mdat=array_values(array_filter($top,static fn($box)=>$box['type']==='mdat'&&$box['end']>$box['start']));self::require($mdat!==[]);
        $tracks=array_values(array_filter($moov,static fn($box)=>$box['type']==='trak'));self::require(count($tracks)>0&&count($tracks)<=64);$video=false;$ranges=[];
        foreach($tracks as $track){
            $tc=self::children($b,$track);$tkhd=self::one($tc,'tkhd');$p=$tkhd['start'];self::require($p+4<=$tkhd['end']);$version=ord($b[$p]);self::require(in_array($version,[0,1],true)&&$tkhd['end']-$p>=($version===0?84:96)&&self::u32($b,$p+($version===0?12:20))>0);
            $mdia=self::children($b,self::one($tc,'mdia'));self::duration($b,self::one($mdia,'mdhd'));$handler=self::one($mdia,'hdlr');self::require($handler['start']+24<=$handler['end']);$kind=substr($b,$handler['start']+8,4);self::require(in_array($kind,['vide','soun'],true));$video=$video||$kind==='vide';if($kind==='vide')self::require(self::u32($b,$tkhd['end']-8)>0&&self::u32($b,$tkhd['end']-4)>0);
            $minf=self::children($b,self::one($mdia,'minf'));$tables=self::children($b,self::one($minf,'stbl'));$descriptions=self::one($tables,'stsd');$dp=$descriptions['start'];self::require($dp+8<=$descriptions['end']&&self::u32($b,$dp)===0);$desc=self::boxes($b,$dp+8,$descriptions['end']);self::require(count($desc)===self::u32($b,$dp+4)&&count($desc)>0);
            $lengths=self::descriptions($b,$desc,$kind);
            $stsz=self::one($tables,'stsz');$p=$stsz['start'];self::require($p+12<=$stsz['end']&&self::u32($b,$p)===0);$fixed=self::u32($b,$p+4);$samples=self::u32($b,$p+8);self::require($samples>0&&$samples<=1000000&&$stsz['end']-$p===12+($fixed?0:4*$samples));
            $sizes=[];for($i=0;$i<$samples;$i++){$size=$fixed?:self::u32($b,$p+12+$i*4);self::require($size>0);$sizes[]=$size;}
            $timings=self::table($b,self::one($tables,'stts'),8);$timed=0;foreach($timings as [$count,$delta]){self::require($count>0&&$delta>0);$timed+=$count;}self::require($timed===$samples);
            $maps=self::table($b,self::one($tables,'stsc'),12);self::require($maps[0][0]===1);$previous=0;foreach($maps as [$first,$count,$index]){self::require($first>$previous&&$count>0&&$index>0&&$index<=count($desc));$previous=$first;}
            $offsetBoxes=array_values(array_filter($tables,static fn($box)=>in_array($box['type'],['stco','co64'],true)));self::require(count($offsetBoxes)===1);$offsetBox=$offsetBoxes[0];$offsets=self::table($b,$offsetBox,$offsetBox['type']==='co64'?8:4);self::require($previous<=count($offsets));$sample=0;$map=0;
            foreach($offsets as $i=>$parts){if(count($parts)===2){self::require($parts[0]===0);$offset=$parts[1];}else $offset=$parts[0];while(isset($maps[$map+1])&&$maps[$map+1][0]<=$i+1)$map++;$count=$maps[$map][1];self::require($count<=$samples-$sample);$len=0;for($j=0;$j<$count;$j++){$size=$sizes[$sample++];if($kind==='vide')self::avcSample($b,$offset+$len,$size,$lengths[$maps[$map][2]-1]);$len+=$size;}$end=$offset+$len;$inside=false;foreach($mdat as $m)if($offset>=$m['start']&&$end<=$m['end'])$inside=true;self::require($inside);$ranges[]=[$offset,$end];}
            self::require($sample===$samples);
        }
        usort($ranges,static fn($a,$c)=>$a[0]<=>$c[0]);$last=0;foreach($ranges as [$start,$end]){self::require($start>=$last);$last=$end;}return $video;
    }
}
