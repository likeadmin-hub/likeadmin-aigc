<?php
namespace app\common\service\power;

/** Finite admission predicates reviewed against one pinned gateway revision. No PHP evaluation or I/O. */
final class MarketOwnedPlatformContractRules
{
    public const REVISION='b5887e1c153111edd4515beb0635e6b66d9978b6';

    /** New, exact source pin only. Existing frozen contracts retain their original encoders. */
    public static function applies(array $snapshot,string $profile): bool
    {
        $contract=MarketGenerationReviewedContract::selected(MarketGenerationRequest::metadata($snapshot));
        if($contract===null||($contract['generation_protocol']??'')!==$profile
            ||($contract['revision']??'')!=='owned-platform-b5887e1-'.$profile.'-v1'
            ||($contract['source']['revision']??'')!==self::REVISION
            ||($contract['source']['reference']??'')!=='https://gitee.com/yc_open/platform-api-saas/tree/'.self::REVISION
            ||($contract['source']['artifact_sha256']??'')!==MarketOwnedPlatformContractBuilder::ARTIFACT_SHA256)return false;
        $identities=match($profile){
            'qwen_image_v1'=>[['qwen-image-3.0','dashscope_compatible'],['qwen-image-3.0-pro','dashscope_compatible']],
            'h3_content_v1'=>[['h3-video','minimax']], 'wan_video_media_v1'=>[['wan3.0-video','dashscope_compatible']],
            'q4_video_v1'=>[['q4-preview-video','q4_video']],default=>[],
        };
        return in_array([$snapshot['model_code']??null,$snapshot['channel_code']??null],$identities,true)
            &&$contract['model_code']===($snapshot['model_code']??null)&&$contract['channel_code']===($snapshot['channel_code']??null);
    }

    public static function assertEnvelope(array $snapshot,array $request): void
    {
        $provider=(array)($request['provider_params']??[]);
        foreach(['model'=>'model_code','channel'=>'channel_code']as$field=>$key)
            if(array_key_exists($field,$provider)&&$provider[$field]!==($snapshot[$key]??null))
                throw new \InvalidArgumentException('原生请求型号或渠道与冻结合同不一致');
        foreach([$request,$provider]as$values)if(array_key_exists('callback_url',$values)&&!is_string($values['callback_url']))
            throw new \InvalidArgumentException('回调地址格式不正确');
        if(!empty($request['callback_url'])&&!empty($provider['callback_url'])&&$request['callback_url']!==$provider['callback_url'])
            throw new \InvalidArgumentException('请勿同时提供不同的回调地址');
    }

    public static function normalizeH3Request(array $snapshot,array $request): array
    {
        if(!self::applies($snapshot,'h3_content_v1'))return $request;
        self::assertEnvelope($snapshot,$request);
        if(is_array($request['provider_params']['content']??null))foreach($request['provider_params']['content']as&$item){
            if(is_array($item)&&($item['type']??'')==='image_url'&&(!array_key_exists('role',$item)||$item['role']===''))$item['role']='first_frame';
        }unset($item);
        return $request;
    }

    /** Classification/billing use the same transient representation before a frozen runtime snapshot exists. */
    public static function normalizeH3MetadataRequest(array $metadata,array $request): array
    {
        $contract=MarketGenerationReviewedContract::selected($metadata);
        if($contract===null||($contract['generation_protocol']??'')!=='h3_content_v1')return $request;
        return self::normalizeH3Request(['resource_type'=>'model','model_code'=>$contract['model_code'],
            'channel_code'=>$contract['channel_code'],'market_metadata'=>$metadata],$request);
    }

    /** Bridge explicit native controls before SKU/duration negotiation, without adopting example/default values. */
    public static function normalizeH3BusinessSelection(array $metadata,array $selection): array
    {
        $contract=MarketGenerationReviewedContract::selected($metadata);
        if($contract===null||!self::applies(['model_code'=>$contract['model_code'],'channel_code'=>$contract['channel_code'],
            'market_metadata'=>$metadata],'h3_content_v1'))return $selection;
        $selection=self::normalizeH3MetadataRequest($metadata,$selection);
        $provider=(array)($selection['provider_params']??[]);$nested=(array)($selection['params']??[]);
        foreach(['duration'=>['duration','seconds','video_duration'],'resolution'=>['resolution','quality'],'ratio'=>['ratio','aspect_ratio']]as$key=>$aliases){
            if(!array_key_exists($key,$provider))continue;$value=$provider[$key];
            if(!is_string($value)&&!is_int($value))throw new \InvalidArgumentException('原生视频参数类型不正确');
            if($key==='duration'&&!self::integer($value,4,15))throw new \InvalidArgumentException('原生视频时长不符合审核合同');
            foreach([$selection,$nested]as$values)foreach($aliases as$alias)if(array_key_exists($alias,$values)&&$values[$alias]!==''){
                if(!is_string($values[$alias])&&!is_int($values[$alias]))throw new \InvalidArgumentException('业务视频参数类型不正确');
                $same=$key==='resolution'?strcasecmp((string)$values[$alias],(string)$value)===0:(string)$values[$alias]===(string)$value;
                if(!$same)throw new \InvalidArgumentException('请勿同时提供不同的业务与原生参数');
            }
            $selection[$aliases[0]]=$key==='duration'?(int)$value:$value;
        }
        return $selection;
    }

    public static function normalizeVideoBusinessSelection(array $metadata,array $selection,array $locked=[]): array
    {
        $selection=self::normalizeH3BusinessSelection($metadata,$selection);
        $contract=MarketGenerationReviewedContract::selected($metadata);
        if($contract===null||!self::applies(['model_code'=>$contract['model_code'],'channel_code'=>$contract['channel_code'],
            'market_metadata'=>$metadata],'wan_video_media_v1'))return $selection;
        self::assertEnvelope(['model_code'=>$contract['model_code'],'channel_code'=>$contract['channel_code']],$selection);
        foreach(['input','parameters']as$field)if(array_key_exists($field,(array)($selection['provider_params']??[]))
            &&!is_array($selection['provider_params'][$field]))throw new \InvalidArgumentException('原生视频 input 和 parameters 必须为对象');
        $native=(array)($selection['provider_params']['parameters']??[]);$nested=(array)($selection['params']??[]);
        foreach(['duration'=>['duration','seconds','video_duration'],'resolution'=>['resolution','quality'],'ratio'=>['ratio','aspect_ratio']]as$key=>$aliases){
            if(!array_key_exists($key,$native))continue;$value=$native[$key];
            if(!is_string($value)&&!is_int($value))throw new \InvalidArgumentException('原生视频参数类型不正确');
            if($key==='duration'&&(!self::integer($value,-1,30)||(int)$value===0))throw new \InvalidArgumentException('原生视频时长不符合审核合同');
            foreach([$selection,$nested]as$values)foreach($aliases as$alias)if(array_key_exists($alias,$values)&&$values[$alias]!==''){
                if(!is_scalar($values[$alias]))throw new \InvalidArgumentException('业务视频参数类型不正确');
                $same=$key==='resolution'?strcasecmp((string)$values[$alias],(string)$value)===0:(string)$values[$alias]===(string)$value;
                if(!$same)throw new \InvalidArgumentException('请勿同时提供不同的业务与原生参数');
            }
            foreach([$locked,(array)($locked['parameters']??[])]as$values)foreach($aliases as$alias)if(array_key_exists($alias,$values)&&$values[$alias]!==''&&$values[$alias]!==null){
                if($key==='duration'&&(int)$values[$alias]===0)continue;
                if(!is_scalar($values[$alias]))throw new \InvalidArgumentException('冻结视频参数类型不正确');
                $same=$key==='resolution'?strcasecmp((string)$values[$alias],(string)$value)===0:(string)$values[$alias]===(string)$value;
                if(!$same)throw new \InvalidArgumentException('原生视频参数与冻结 SKU 不一致');
            }
            $selection[$aliases[0]]=$key==='duration'?(int)$value:$value;
        }
        return $selection;
    }

    /** Pinned VideoTaskDriver default, only after the caller has verified the exact new Wan pin. */
    public static function wanDefaultDuration(array $request): int
    {
        foreach((array)($request['provider_params']['input']['media']??[])as$item)
            if(is_array($item)&&($item['type']??'')==='reference_video')return 1;
        foreach(MarketGenerationMaterials::assets($request)as$asset)if(($asset['type']??'')==='video')return 1;
        return empty($request['video_urls'])?2:1;
    }

    public static function supported(array $rule,?string $protocol=null): bool
    {
        if(in_array($rule['profile']??'', ['flat_image_v1','flat_video_v1'],true))return MarketOwnedPlatformFlatSourceRules::supported($rule,$protocol);
        $profile=$rule['profile']??'';
        $modes=match($profile){
            'qwen_image_v1'=>['text_to_image','image_to_image'],
            'q4_video_v1'=>['image_to_video'],
            'h3_content_v1'=>['text_to_video','image_to_video','first_last_frame','reference_to_video'],
            'wan_video_media_v1'=>['text_to_video','image_to_video','last_frame','first_last_frame','reference_to_video'],
            default=>[],
        };
        return ($rule['source_revision']??'')===self::REVISION&&$modes!==[]&&($protocol===null||$protocol===$profile)
            &&in_array($rule['mode']??'',array_merge([''],$modes),true)
            &&($profile!=='q4_video_v1'||is_string($rule['upstream_model']??null)&&$rule['upstream_model']!=='');
    }

    public static function valid(array $rule,array $input): bool
    {
        if (!self::supported($rule)) return false;
        try {
            if(in_array($rule['profile']??'', ['flat_image_v1','flat_video_v1'],true))return MarketOwnedPlatformFlatSourceRules::valid($rule,$input);
            return match($rule['profile']??'') {
                'qwen_image_v1'=>self::qwen($input,(string)($rule['mode']??'')),
                'h3_content_v1'=>self::h3($input,(string)($rule['mode']??'')),
                'wan_video_media_v1'=>self::wan($input,(string)($rule['mode']??'')),
                'q4_video_v1'=>self::q4($input,(string)($rule['upstream_model']??'')),
                default=>false,
            };
        } catch (\Throwable) { return false; }
    }

    private static function integer($value,int $minimum,int $maximum): bool
    {
        return (is_int($value)||is_float($value)||is_string($value)) && is_numeric($value)
            && is_finite((float)$value) && (float)$value===(float)(int)$value && (int)$value>=$minimum && (int)$value<=$maximum;
    }

    private static function nonempty($value): bool { return is_string($value)&&trim($value)!==''; }

    /** DashScopeQwenImageDriver::validateRequest/validSize/validImageReference. */
    private static function qwen(array $body,string $mode): bool
    {
        $messages=$body['input']['messages']??null;$parameters=$body['parameters']??null;
        if(!is_array($messages)||!array_is_list($messages)||count($messages)!==1||!is_array($messages[0])
            ||($messages[0]['role']??'')!=='user'||!is_array($parameters))return false;
        $content=$messages[0]['content']??null;
        if(!is_array($content)||!array_is_list($content)||$content===[])return false;
        $texts=0;$images=0;
        foreach($content as$item){
            if(!is_array($item)||array_key_exists('text',$item)&&array_key_exists('image',$item))return false;
            if(array_key_exists('text',$item)){if(!self::nonempty($item['text']))return false;$texts++;}
            elseif(array_key_exists('image',$item)){
                if(!is_string($item['image'])||!preg_match('~^(?:https?://|data:image/[a-z0-9.+-]+;base64,[a-z0-9+/=\r\n]+$)~i',trim($item['image'])))return false;
                $images++;
            }else return false;
        }
        if($texts!==1||$images>3||!isset($parameters['n'])||!is_scalar($parameters['n'])||(string)$parameters['n']!=='1')return false;
        if($mode==='text_to_image'&&$images!==0||$mode==='image_to_image'&&$images<1)return false;
        $size=$parameters['size']??'';
        if(!is_string($size))return false;
        $size=trim($size);
        if($size!==''){
            if(!preg_match('/^(\d{1,5})\*(\d{1,5})$/D',$size,$m))return false;
            $w=(int)$m[1];$h=(int)$m[2];
            if($w<1||$h<1||$w*$h<512*512||$w*$h>2048*2048||$w/$h<1/8||$w/$h>8)return false;
        }
        if(isset($parameters['seed'])&&$parameters['seed']!==''&&!self::integer($parameters['seed'],0,2147483647))return false;
        return !($images>0&&($parameters['prompt_extend_mode']??'')==='agent');
    }

    /** VideoTaskDriver::validateConfiguredVideoParams(content_validation=minimax_h3). */
    private static function h3(array $body,string $mode): bool
    {
        $content=$body['content']??null;
        if(!is_array($content)||!array_is_list($content)||$content===[])return false;
        $counts=['first_frame'=>0,'last_frame'=>0,'reference_image'=>0,'reference_video'=>0,'reference_audio'=>0];$text=false;
        foreach($content as$item){
            if(!is_array($item)||!is_string($item['type']??null)||!is_string($item['role']??''))return false;
            $type=trim($item['type']);$role=trim($item['role']??'');
            if($type==='text'){if($role!==''||!self::nonempty($item['text']??null))return false;$text=true;continue;}
            $kind=match($type){'image_url'=>'image','video_url'=>'video','audio_url'=>'audio',default=>''};
            if($kind===''||!self::nonempty($item[$type]['url']??null))return false;
            if($type==='image_url'&&$role==='')$role='first_frame';
            $roles=$kind==='image'?['first_frame','last_frame','reference_image']:['reference_'.$kind];
            if(!in_array($role,$roles,true))return false;$counts[$role]++;
        }
        foreach(['first_frame'=>1,'last_frame'=>1,'reference_image'=>9,'reference_video'=>3,'reference_audio'=>3]as$key=>$max)
            if($counts[$key]>$max)return false;
        $frames=$counts['first_frame']+$counts['last_frame'];$reference=$counts['reference_image']+$counts['reference_video']+$counts['reference_audio'];
        if(!$text||$frames>0&&$reference>0||$counts['last_frame']>0&&$counts['first_frame']===0
            ||$counts['reference_audio']>0&&$counts['reference_image']+$counts['reference_video']===0)return false;
        if($mode==='text_to_video'&&$frames+$reference!==0||$mode==='image_to_video'&&($counts['first_frame']!==1||$counts['last_frame']!==0)
            ||$mode==='first_last_frame'&&($counts['first_frame']!==1||$counts['last_frame']!==1)
            ||$mode==='reference_to_video'&&$reference<1)return false;
        if(!in_array($body['resolution']??'', ['768P','1080P','2K'],true)||!self::integer($body['duration']??null,4,15))return false;
        $ratio=$body['ratio']??'';
        return in_array($ratio,['adaptive','21:9','16:9','4:3','1:1','3:4','9:16'],true)&&($frames+$reference>0||$ratio!=='adaptive');
    }

    /** VideoTaskDriver::applyDashScopeVideoDefaults/validateDashScopeVideoRequest. No invented media-duration probes. */
    private static function wan(array $body,string $mode): bool
    {
        $input=$body['input']??null;$parameters=$body['parameters']??null;
        if(!is_array($input)||!is_array($parameters)||!is_string($input['prompt']??''))return false;
        $prompt=trim($input['prompt']??'');$media=$input['media']??[];
        if(!is_array($media)||!array_is_list($media)||$prompt===''&&$media===[]||mb_strlen($prompt)>20000)return false;
        $limits=['first_frame'=>1,'last_frame'=>1,'reference_image'=>10,'reference_video'=>5,'reference_audio'=>5,'file'=>1,'link'=>1];
        $counts=array_fill_keys(array_keys($limits),0);
        foreach($media as$item){
            if(!is_array($item)||!is_string($item['type']??null)||!is_string($item['url']??null))return false;
            $type=trim($item['type']);$url=trim($item['url']);
            if(!isset($limits[$type])||$url===''||++$counts[$type]>$limits[$type])return false;
            $image=in_array($type,['first_frame','last_frame','reference_image'],true);
            if(!preg_match('~^(?:https?|oss)://~i',$url)&&!($image&&preg_match('~^data:image/[a-z0-9.+-]+;base64,[a-z0-9+/=\r\n]+$~i',$url)))return false;
        }
        $frames=$counts['first_frame']+$counts['last_frame'];
        $reference=$counts['reference_image']+$counts['reference_video']+$counts['reference_audio']+$counts['file']+$counts['link'];
        if($frames>0&&$reference>0||$counts['file']>0&&$counts['link']>0)return false;
        if($mode==='text_to_video'&&$frames+$reference!==0||$mode==='image_to_video'&&($counts['first_frame']!==1||$counts['last_frame']!==0)
            ||$mode==='last_frame'&&($counts['first_frame']!==0||$counts['last_frame']!==1)
            ||$mode==='first_last_frame'&&($counts['first_frame']!==1||$counts['last_frame']!==1)
            ||$mode==='reference_to_video'&&$reference<1)return false;
        $resolution=$parameters['resolution']??'1080P';
        if(!is_string($resolution)||!in_array(strtoupper(trim($resolution)),['480P','720P','1080P'],true))return false;
        if(array_key_exists('ratio',$parameters)&&!in_array($parameters['ratio'],['adaptive','16:9','4:3','1:1','3:4','9:16'],true))return false;
        $duration=$parameters['duration']??null;
        if($duration===null||$duration==='')$duration=$counts['reference_video']>0?1:2;
        if(!self::integer($duration,-1,30)||((int)$duration!==-1&&(int)$duration<($counts['reference_video']>0?1:2)))return false;
        foreach(['audio','watermark']as$key)if(array_key_exists($key,$parameters)&&!is_bool($parameters[$key]))return false;
        return !isset($parameters['seed'])||$parameters['seed']===''||self::integer($parameters['seed'],0,2147483647);
    }

    /** ViduQ4VideoDriver::validateRequest/requestBody. Model comes from verified execution metadata, never the browser. */
    private static function q4(array $body,string $upstreamModel): bool
    {
        if($upstreamModel==='')return false;
        $images=$body['images']??null;
        if(!is_array($images)||!array_is_list($images)||count($images)!==1||!self::nonempty($images[0]))return false;
        $image=$images[0];
        if(str_starts_with($image,'data:')){
            if(!preg_match('~^data:image/(png|jpeg|jpg|webp);base64,([a-z0-9+/=\r\n]+)$~i',$image,$m)||base64_decode($m[2],true)===false)return false;
        }elseif(!self::httpUrl($image))return false;
        $defaults=['duration'=>5,'resolution'=>'720p','audio'=>true,'is_rec'=>true,'seed'=>0,'watermark'=>true,'wm_position'=>3];
        $params=array_replace($defaults,$body);
        if(!is_int($params['duration'])||$params['duration']<3||$params['duration']>16
            ||!in_array($params['resolution'],['540p','720p','1080p','2K','4K'],true))return false;
        foreach(['audio','is_rec','watermark']as$key)if(!is_bool($params[$key]))return false;
        if(!is_int($params['seed'])||$params['seed']<0||!is_int($params['wm_position'])||!in_array($params['wm_position'],[1,2,3,4],true))return false;
        foreach(['prompt'=>20000,'payload'=>1048576,'wm_url'=>null,'meta_data'=>null]as$key=>$limit)
            if(array_key_exists($key,$params)&&(!is_string($params[$key])||$limit!==null&&mb_strlen($params[$key])>$limit))return false;
        if(!empty($params['wm_url'])&&!self::httpUrl($params['wm_url']))return false;
        if(isset($params['meta_data'])&&$params['meta_data']!==''){
            json_decode($params['meta_data']);if(json_last_error()!==JSON_ERROR_NONE)return false;
        }
        $fields=['images','prompt','is_rec','duration','resolution','audio','seed','payload','watermark','wm_position','wm_url','meta_data'];
        $request=['model'=>$upstreamModel]+array_intersect_key($params,array_flip($fields));
        return strlen(json_encode($request,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))<20*1024*1024;
    }

    private static function httpUrl(string $url): bool
    {
        return filter_var($url,FILTER_VALIDATE_URL)!==false&&in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true);
    }
}
