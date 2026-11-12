<?php
declare(strict_types=1);
/** Real, tiny local media. No network or provider. Set AIGC_TRANSFER_MEDIA_DIR to this directory. */
$destination=$argv[1]??'';
if($destination===''||$destination[0]!=='/')throw new RuntimeException('Provide an absolute disposable fixture directory');
if(!is_dir($destination)&&!mkdir($destination,0700,true))throw new RuntimeException('Cannot create fixture directory');
$run=static function(array $args):string{
 $p=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Cannot start media tool');
 $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('Media fixture failed: '.$error);return$out;
};
foreach(['mp4','webm','mov','m4v','mp3','wav','ogg','flac','aac','m4a','opus']as$ext){
 $video=in_array($ext,['mp4','webm','mov','m4v'],true);$path=$destination.'/synthetic.'.$ext;
 $args=['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i',$video?'color=c=blue:s=64x64:r=5':'sine=frequency=440:sample_rate=16000','-t','1'];
 if($video){$args=array_merge($args,['-c:v',$ext==='webm'?'libvpx-vp9':'libx264','-pix_fmt','yuv420p','-threads','1']);if($ext==='m4v')$args=array_merge($args,['-f','mp4']);}
 elseif($ext==='wav')$args=array_merge($args,['-c:a','pcm_s16le']);
 $run(array_merge($args,[$path]));chmod($path,0600);
 $probe=json_decode($run(['ffprobe','-v','error','-show_entries','format=duration','-of','json',$path]),true,512,JSON_THROW_ON_ERROR);
 if((float)($probe['format']['duration']??0)<0.9)throw new RuntimeException('Fixture has no playable duration: '.$ext);
}
$path=$destination.'/measured-1.04.mp4';
$run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','lavfi','-i','color=c=blue:s=32x32:r=25','-t','1.04','-c:v','libx264','-threads','1','-pix_fmt','yuv420p',$path]);
$probe=json_decode($run(['ffprobe','-v','error','-show_entries','format=duration','-of','json',$path]),true,512,JSON_THROW_ON_ERROR);
if(abs((float)$probe['format']['duration']-1.04)>0.000001)throw new RuntimeException('Measured fixture duration changed');
chmod($path,0600);
echo "PASS 11 real media containers and exact 1.04-second video generated and probed\n";
