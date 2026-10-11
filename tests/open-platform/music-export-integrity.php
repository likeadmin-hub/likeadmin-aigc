<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_music\AigcMusicExportStorageService as Storage;
use app\common\service\open_platform\OpenException;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($type,$bytes){try{Storage::validate($type,$bytes);}catch(OpenException $e){check($e->errorType==='result_storage_failed','Malformed export must stay recoverable');return;}throw new RuntimeException('Malformed '.$type.' accepted');}
$fmt=pack('vvVVvv',1,1,8000,16000,2,16);$chunks='fmt '.pack('V',16).$fmt.'data'.pack('V',4).str_repeat("\0",4);$wav='RIFF'.pack('V',4+strlen($chunks)).'WAVE'.$chunks;
$events=hex2bin('00903c408360803c0000ff2f00');$midi='MThd'.pack('Nnnn',6,0,1,480).'MTrk'.pack('N',strlen($events)).$events;
$mp4=file_get_contents(__DIR__.'/fixtures/music-export.mp4');
foreach(['wav'=>$wav,'midi'=>$midi,'mp4'=>$mp4] as $type=>$bytes){check(Storage::validate($type,$bytes)===''.['wav'=>'audio/wav','midi'=>'audio/midi','mp4'=>'video/mp4'][$type],'Complete '.$type.' rejected');foreach([1,4,8,13,strlen($bytes)-1] as $length)reject($type,substr($bytes,0,$length));reject($type,$bytes.'trailing');reject($type,'<html>error</html>');}
reject('wav','RIFF'.str_repeat("\0",4).'WAVE'.str_repeat("\0",32));
reject('wav',substr_replace($wav,pack('V',8001),24,4)); // sample rate / byte rate disagree
reject('wav',substr_replace($wav,pack('V',1000),40,4)); // truncated data chunk
reject('wav','RIFF'.pack('V',36).'WAVEfmt '.pack('V',16).$fmt.'data'.pack('V',0));
reject('midi','MThd'.pack('Nnnn',6,0,1,480));
reject('midi',substr_replace($midi,pack('n',2),10,2)); // missing declared track
reject('midi',substr_replace($midi,pack('N',strlen($events)+1),18,4));
reject('midi',substr_replace($midi,chr(128),25,1)); // invalid channel data
reject('midi',substr_replace($midi,chr(1),strlen($midi)-1,1)); // invalid end-of-track payload
reject('mp4',pack('N',24).'ftypisom'.str_repeat("\0",12));
$offset=strpos($mp4,'stco');check($offset!==false,'Fixture requires flat sample table');reject('mp4',substr_replace($mp4,pack('N',strlen($mp4)+20),$offset+12,4));
$mdat=strpos($mp4,'mdat');check($mdat!==false,'Fixture requires media bytes');reject('mp4',substr_replace($mp4,pack('N',strlen($mp4)*2),$mdat-4,4));
echo "PASS complete PCM WAV, MIDI note tracks and generated MP4 sample tables; truncated containers, missing media, invalid sizes/events and out-of-range offsets stay unbillable\n";
