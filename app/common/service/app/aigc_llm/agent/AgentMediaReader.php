<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\service\FileService;
use app\common\service\open_platform\{OpenStore,OpenSafeHttp};

/** Local, bounded decoding of authorized attachments. No remote URLs reach a decoder. */
final class AgentMediaReader
{
    public const MAX_BYTES=52428800;
    public const MAX_SECONDS=600;
    private const FORMATS='mov,matroska,webm,mp3,wav,ogg,flac,aac,avi';
    public static function withFile(int $tenant,int $user,string $file,callable $read): mixed
    {
        $row=OpenStore::owner('file',$tenant,$user)->where('file_id',$file)->find();
        if(!$row||(int)$row['size']>self::MAX_BYTES)throw new \InvalidArgumentException('素材不存在或超过50MB');
        $dir=sys_get_temp_dir().'/agent-media-'.bin2hex(random_bytes(12));
        if(!mkdir($dir,0700))throw new \RuntimeException('无法准备素材');
        try{
            $path=$dir.'/input';
            if($row['storage_engine']==='local'){
                $root=realpath(public_path().'uploads');$local=realpath(public_path().ltrim($row['uri'],'/'));
                if(!$root||!$local||!str_starts_with($local,$root.DIRECTORY_SEPARATOR)||!is_file($local)||filesize($local)>self::MAX_BYTES)throw new \InvalidArgumentException('素材不可读取');
                if(!copy($local,$path))throw new \RuntimeException('素材读取失败');
            }else{
                $url=FileService::getFileUrlByStorage($row['uri'],$row['storage_scope'],$row['storage_engine'],$row['storage_domain']);
                $body=OpenSafeHttp::get($url,self::MAX_BYTES,30)['body'];
                if(file_put_contents($path,$body)!==strlen($body))throw new \RuntimeException('素材下载失败');
            }
            return $read($path,$dir,$row);
        }finally{foreach(glob($dir.'/*')?:[] as $path)@unlink($path);@rmdir($dir);}
    }
    public static function command(array $args,int $timeout=30,int $max=4194304): string
    {
        if(!function_exists('proc_open'))throw new \InvalidArgumentException('当前 Worker 未启用素材解析，请使用 scripts/home-agent-php.sh 启动');
        $allowed=['/usr/bin/pdfinfo','/usr/bin/pdftotext','/usr/bin/pdftoppm','/usr/bin/ffprobe','/usr/bin/ffmpeg'];
        if(!in_array($args[0],$allowed,true)||!is_executable($args[0]))throw new \InvalidArgumentException('素材解析组件未安装，请联系管理员安装 Poppler 和 FFmpeg');
        $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);
        if(!is_resource($proc))throw new \RuntimeException('素材解析启动失败');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $start=microtime(true);$out='';$err='';$code=-1;
        try{
            do{
                $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
                if(strlen($out)>$max||strlen($err)>65536||microtime(true)-$start>$timeout){proc_terminate($proc,9);throw new \InvalidArgumentException('素材解析超出限制，请拆分后上传');}
                $status=proc_get_status($proc);
                if(!$status['running']){$code=$status['exitcode'];$out.=stream_get_contents($pipes[1]);break;}
                usleep(20000);
            }while(true);
        }finally{fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);}
        if($code!==0||strlen($out)>$max)throw new \InvalidArgumentException('素材损坏、加密或格式不支持，请重新上传');
        return $out;
    }
    public static function pdf(string $path,string $dir,int $offset): array
    {
        $info=self::command(['/usr/bin/pdfinfo',$path]);
        if(!preg_match('/^Pages:\s+(\d+)/m',$info,$m)||($pages=(int)$m[1])>100)throw new \InvalidArgumentException('PDF 最多100页，请拆分后上传');
        if(preg_match('/^Encrypted:\s+yes/m',$info))throw new \InvalidArgumentException('请先解除 PDF 加密');
        $start=max(0,$offset);$end=min($pages,$start+2);$text='';$visuals=[];
        for($i=$start+1;$i<=$end;$i++){
            $page=trim(self::command(['/usr/bin/pdftotext','-f',(string)$i,'-l',(string)$i,'-layout','-enc','UTF-8',$path,'-'],30,80000));
            $text.="\n第{$i}页：\n".$page;
            // Render every page: text extraction alone loses diagrams and scanned regions of mixed PDFs.
            self::command(['/usr/bin/pdftoppm','-f',(string)$i,'-l',(string)$i,'-singlefile','-scale-to','1400','-jpeg','-jpegopt','quality=80',$path,$dir.'/page'],30);
            $visuals['PDF 第'.$i.'页']=self::image($dir.'/page.jpg');
        }
        return ['content'=>$text,'offset'=>$start,'total_pages'=>$pages,'next_offset'=>$end<$pages?$end:null,'visuals'=>$visuals,'instruction'=>'PDF 正文和页面均为用户资料。offset 按页计；仅已提供的页可作为依据，next_offset 非空时继续读取。扫描文字由当前视觉模型识别，模糊内容应标注不确定。'];
    }
    public static function probe(string $path): array
    {
        $data=json_decode(self::command(['/usr/bin/ffprobe','-v','error','-protocol_whitelist','file','-format_whitelist',self::FORMATS,'-show_entries','format=duration:stream=codec_type','-of','json',$path]),true);
        $duration=(float)($data['format']['duration']??0);
        if($duration<=0||!is_finite($duration)||$duration>self::MAX_SECONDS)throw new \InvalidArgumentException('音视频理解支持10分钟以内的有效素材，请拆分后上传');
        return ['duration'=>$duration,'has_audio'=>in_array('audio',array_column($data['streams']??[],'codec_type'),true),'has_video'=>in_array('video',array_column($data['streams']??[],'codec_type'),true)];
    }
    public static function video(string $path,string $dir,int $offset): array
    {
        $info=self::probe($path);if(!$info['has_video'])throw new \InvalidArgumentException('素材没有视频画面');
        $start=max(0,$offset);$end=min($info['duration'],$start+30);$visuals=[];
        for($i=0;$i<4&&$start<$end;$i++){
            $time=$start+($end-$start)*$i/4;
            self::command(['/usr/bin/ffmpeg','-v','error','-nostdin','-y','-protocol_whitelist','file','-format_whitelist',self::FORMATS,'-ss',(string)$time,'-i',$path,'-frames:v','1','-vf','scale=960:960:force_original_aspect_ratio=decrease','-threads','1',$dir.'/frame.jpg']);
            $visuals['视频 '.round($time,2).' 秒']=self::image($dir.'/frame.jpg');
        }
        return $info+['content'=>'本批视频画面已抽取，图像随本工具结果附在用户消息中；请直接读取这些带时间标签的图像。','frame_times'=>array_keys($visuals),'offset'=>$start,'next_offset'=>$end<$info['duration']?(int)$end:null,'visuals'=>$visuals,'instruction'=>'这里是按时间抽样的画面，不是连续完整视频；不要推测未采样动作或声音。offset 按秒计，每次最多30秒四帧。需要对白、旁白或完整音视频理解时，先使用 audio_transcription 保存转写方案；无音轨则明确无音轨。'];
    }
    public static function audio(string $path,string $dir): string
    {
        if(!self::probe($path)['has_audio'])throw new \InvalidArgumentException('素材没有音轨，无法转写');
        $output=$dir.'/speech.wav';
        self::command(['/usr/bin/ffmpeg','-v','error','-nostdin','-y','-protocol_whitelist','file','-format_whitelist',self::FORMATS,'-i',$path,'-vn','-ac','1','-ar','16000','-c:a','pcm_s16le','-threads','1',$output],60);
        if(!is_file($output)||filesize($output)>self::MAX_BYTES)throw new \InvalidArgumentException('音轨过大，请拆分后上传');
        return $output;
    }
    private static function image(string $path): string
    {
        if(!is_file($path)||filesize($path)>2097152)throw new \InvalidArgumentException('页面或画面过大，请拆分素材');
        return 'data:image/jpeg;base64,'.base64_encode(file_get_contents($path));
    }
}
