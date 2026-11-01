<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Finite producer contracts for exact direct gateway configurations. Never routes or calls a supplier. */
final class MarketOwnedPlatformFlatSourceRules
{
    public static function family(string $model,string $channel): ?string
    {
        return match($channel.'|'.$model){
            'Google|nano-banana-2','Google|nano-banana-pro','Google|nano-banana-2.1'=>'google_nano',
            'OpenAIYI|gpt-image-2.5'=>'dakka_plain',
            'OpenAIYI|gpt-image-2.5-flare','OpenAIYI|gpt-image-2.5-sunburst'=>'dakka_pixels',
            'OpenAIYI|gpt-image-2'=>'yi_gpt', 'OpenaiM|gpt-image-2'=>'m_gpt',
            'openaiD|gpt-image-2-fast'=>'fast_pixels','xAIQ|grok-video'=>'grok',
            'xAIQ|veo3.1-fast','xAIQ|veo3.1-pro'=>'veo',default=>null,
        };
    }

    public static function supported(array $rule,?string $protocol=null): bool
    {
        $family=self::family((string)($rule['model_code']??''),(string)($rule['channel_code']??''));
        return $family!==null&&($rule['family']??null)===$family&&($rule['source_revision']??null)===MarketOwnedPlatformContractRules::REVISION
            &&in_array($rule['mode']??'', $family==='veo'?['TEXT','FIRST&LAST','REFERENCE']:($family==='grok'?['text_to_video','reference_to_video']:['text_to_image','image_to_image']),true)
            &&($rule['profile']??'')===(in_array($family,['grok','veo'],true)?'flat_video_v1':'flat_image_v1')
            &&($protocol===null||$protocol===$rule['profile'])
            &&is_int($rule['maximum_images']??null)&&$rule['maximum_images']>=0&&$rule['maximum_images']<=100
            &&is_string($rule['configuration_fingerprint']??null)&&preg_match('/^[a-f0-9]{64}$/D',$rule['configuration_fingerprint'])===1;
    }

    public static function valid(array $rule,array $body): bool
    {
        if(!self::supported($rule))return false;
        $family=$rule['family'];$field=$family==='google_nano'?'urls':'image_urls';$urls=$body[$field]??[];
        if(is_string($urls)&&$family==='fast_pixels')$urls=$urls===''?[]:[$urls];
        if(!is_array($urls)||!array_is_list($urls)||count($urls)>$rule['maximum_images'])return false;
        foreach($urls as$url){
            if(!is_string($url)||trim($url)==='')return false;
            if(!preg_match('~^https?://~i',$url)&&!($family==='m_gpt'&&preg_match('~^data:image/[a-z0-9.+-]+;base64,[a-z0-9+/=\r\n]+$~i',$url)))return false;
        }
        $reference=in_array($rule['mode'],['image_to_image','reference_to_video'],true);
        if($family!=='veo'&&($reference&&$urls===[]||!$reference&&$urls!==[]))return false;
        if($family!=='google_nano'&&(!is_string($body['prompt']??null)||trim($body['prompt'])===''))return false;
        if($family==='google_nano')return !isset($body['prompt'])||is_string($body['prompt']);
        if($family==='grok')return in_array($body['duration']??null,[6,10,15,20,25,30],true)&&($body['quality']??'720p')==='720p';
        if($family==='veo')return ($body['generation_type']??'TEXT')===$rule['mode']&&($body['duration']??8)===8
            &&in_array($body['quality']??'720p',['720p','1080p','4k'],true)
            &&in_array($body['aspect_ratio']??'16:9',['16:9','9:16'],true)
            &&match($rule['mode']){'TEXT'=>count($urls)<=3,'FIRST&LAST'=>count($urls)>=1&&count($urls)<=2,
                'REFERENCE'=>count($urls)>=1&&count($urls)<=3&&($body['aspect_ratio']??'16:9')==='16:9',default=>false};
        if(in_array($family,['dakka_pixels','fast_pixels'],true)){
            if(isset($body['aspect_ratio'])&&!is_string($body['aspect_ratio']))return false;
            $size=trim(str_replace(['×','X'],'x',(string)($body['aspect_ratio']??'auto')));
            if($size===''||($family==='dakka_pixels'?strtolower($size)==='auto':$size==='auto')||preg_match('/^\d+x\d+$/D',$size))return true;
            $resolution=$body[$family==='fast_pixels'?'image_size':'resolution']??'1k';
            if(!is_string($resolution))return false;$resolution=strtolower($resolution);
            return isset(self::matrix($family)[str_replace(' ','',$size)][$resolution]);
        }
        return true;
    }

    /** New source+execution binding only; existing frozen pins never adopt this serializer. */
    public static function applies(array $snapshot): bool
    {
        $contract=MarketGenerationReviewedContract::selected(MarketGenerationRequest::metadata($snapshot));
        if($contract===null||($contract['revision']??'')!=='owned-platform-b5887e1-flat-source-v1'
            ||($contract['source']['revision']??'')!==MarketOwnedPlatformContractRules::REVISION
            ||($contract['source']['reference']??'')!=='https://gitee.com/yc_open/platform-api-saas/tree/'.MarketOwnedPlatformContractRules::REVISION
            ||($contract['source']['artifact_sha256']??'')!==MarketOwnedPlatformContractBuilder::ARTIFACT_SHA256
            ||($contract['execution_binding']['kind']??'')!=='owned_platform_direct_execution')return false;
        return self::family((string)($snapshot['model_code']??''),(string)($snapshot['channel_code']??''))!==null
            &&$contract['model_code']===($snapshot['model_code']??null)&&$contract['channel_code']===($snapshot['channel_code']??null);
    }

    public static function payload(array $snapshot,array $request,bool $deferMissingPrompt=false): array
    {
        if(!self::applies($snapshot))throw new InvalidArgumentException('当前请求没有精确直接执行合同');
        $request=self::normalizeRequest($snapshot,$request);
        MarketOwnedPlatformContractRules::assertEnvelope($snapshot,$request);
        // Fast's native field accepts a scalar or list. The existing generic
        // business serializer has one concrete representation per field; supply
        // its declared list branch on this transient copy, preserving every slot.
        if(self::family($snapshot['model_code'],$snapshot['channel_code'])==='fast_pixels'){
            $assets=MarketGenerationMaterials::assets($request);$provider=(array)($request['provider_params']??[]);
            $present=array_key_exists('image_urls',$provider)&&$provider['image_urls']!==[]&&$provider['image_urls']!=='';
            if($assets!==[]&&!$present){
                foreach($assets as$asset)if(($asset['type']??'image')!=='image'||!in_array($asset['role']??'reference_image',['reference','reference_image'],true)
                    ||isset($asset['field'])&&$asset['field']!==''&&$asset['field']!=='image_urls')
                    throw new InvalidArgumentException('当前参考图方式不支持此素材角色');
                $request['provider_params']['image_urls']=array_column($assets,'url');unset($request['reference_assets'],$request['reference_images']);
            }
        }
        $metadata=MarketGenerationRequest::metadata($snapshot);$mode=MarketGenerationMaterials::select($metadata,$request);
        $schema=MarketGenerationNativeContract::schema($metadata,(string)($mode['native_mode']??''));
        $adapted=$snapshot;$adapted['locked_params']=self::canonicalLocks($snapshot,$schema);
        foreach(['duration','seconds','video_duration']as$key)if(array_key_exists($key,$request))self::assertWhole($request[$key]);
        foreach(['duration','n']as$key)if(array_key_exists($key,(array)($request['provider_params']??[])))self::assertWhole($request['provider_params'][$key]);
        $payload=MarketGenerationRequest::payload($adapted,$request,$deferMissingPrompt);
        foreach((array)($request['provider_params']??[])as$key=>$raw){
            if(in_array($key,['material_mode','generation_type'],true)||!array_key_exists($key,MarketGenerationSchema::properties($schema)))continue;
            $normalized=MarketGenerationSchema::normalize([$key=>MarketGenerationSchema::properties($schema)[$key]],[$key=>$raw],'',false);
            if(array_key_exists($key,$normalized)&&(!array_key_exists($key,$payload)||$normalized[$key]!==$payload[$key]))
                throw new InvalidArgumentException('业务或冻结参数与原生参数冲突，不能丢弃原生输入');
        }
        return $payload;
    }

    /** A transient copy shared by classification, SKU negotiation and actual preparation. */
    public static function normalizeMetadataRequest(array $metadata,array $request): array
    {
        $contract=MarketGenerationReviewedContract::selected($metadata);
        if($contract===null)return $request;
        return self::normalizeRequest(['resource_type'=>'model','model_code'=>$contract['model_code'],
            'channel_code'=>$contract['channel_code'],'market_metadata'=>$metadata],$request);
    }

    public static function normalizeRequest(array $snapshot,array $request): array
    {
        if(!self::applies($snapshot))return $request;
        foreach([$request,(array)($request['params']??[])]as$values)
            if(array_key_exists('quantity',$values))self::assertSingleQuantity($values['quantity']);
        if(array_key_exists('provider_params',$request)&&!is_array($request['provider_params']))
            throw new InvalidArgumentException('原生参数必须为对象');
        $provider=$request['provider_params']??[];
        if(array_key_exists('n',$provider))self::assertSingleQuantity($provider['n']);
        $family=self::family($snapshot['model_code'],$snapshot['channel_code']);
        if($family==='veo'){
            foreach(['duration'=>['duration','seconds','video_duration'],'quality'=>['quality','resolution'],'aspect_ratio'=>['aspect_ratio','ratio','size']]as$field=>$aliases){
                if(!array_key_exists($field,$provider))continue;$value=$provider[$field];
                if($field==='duration')self::assertWhole($value);
                elseif(!is_string($value))throw new InvalidArgumentException('Veo 原生规格类型不正确');
                if($field==='quality')$value=strtolower($value);
                foreach([$request,(array)($request['params']??[])]as$values)foreach($aliases as$alias)
                    if(array_key_exists($alias,$values)&&$values[$alias]!==''){
                        $same=$field==='quality'?is_string($values[$alias])&&strtolower($values[$alias])===$value:(string)$values[$alias]===(string)$value;
                        if(!$same)throw new InvalidArgumentException('Veo 业务规格与原生规格冲突');
                    }
                $request[$field==='aspect_ratio'?'ratio':$field]=$field==='duration'?(int)$value:$value;
            }
            return $request;
        }
        if($family!=='fast_pixels')return $request;
        MarketOwnedPlatformContractRules::assertEnvelope($snapshot,$request);
        $canonical=[];$sources=[];
        $accept=static function(string $field,$value,string $source)use(&$canonical,&$sources):void{
            $value=self::fastValue($field,$value);
            $compare=static fn($v)=>$field==='image_urls'&&is_string($v)?($v===''?[]:[$v]):$v;
            if(array_key_exists($field,$canonical)&&$compare($canonical[$field])!==$compare($value))
                throw new InvalidArgumentException('Fast 的兼容别名与显式业务或原生参数冲突');
            if(!array_key_exists($field,$canonical))$canonical[$field]=$value;
            $sources[$source]=$field;
        };
        foreach(['image_urls'=>'image_urls','image'=>'image_urls','image_size'=>'image_size','resolution'=>'image_size',
            'aspect_ratio'=>'aspect_ratio','size'=>'aspect_ratio']as$key=>$field)
            if(array_key_exists($key,$provider)){$accept($field,$provider[$key],'provider_params.'.$key);unset($provider[$key]);}
        foreach(['request'=>$request,'params'=>(array)($request['params']??[])]as$location=>$values){
            foreach(['image_urls'=>'image_urls','image'=>'image_urls','image_size'=>'image_size','resolution'=>'image_size',
                'aspect_ratio'=>'aspect_ratio','ratio'=>'aspect_ratio','size'=>'aspect_ratio']as$key=>$field)
                if(array_key_exists($key,$values)&&$values[$key]!=='')$accept($field,$values[$key],$location.'.'.$key);
            if(isset($values['quality'])&&is_string($values['quality'])&&preg_match('/^[124]k$/iD',$values['quality']))
                $accept('image_size',$values['quality'],$location.'.quality');
        }
        foreach($canonical as$field=>$value){
            if($field==='image_urls'&&($value===[]||$value===''))continue;
            $provider[$field]=$value;
        }
        if(isset($canonical['image_size']))$request['resolution']=$canonical['image_size'];
        if(isset($canonical['aspect_ratio']))$request['ratio']=$canonical['aspect_ratio'];
        // Generic preparation also accepts schema-named business fields. Keep
        // them canonical so they cannot overwrite a validated provider copy.
        foreach(['image_size','aspect_ratio','image_urls']as$field)
            if(array_key_exists($field,$request)&&array_key_exists($field,$canonical))$request[$field]=$canonical[$field];
        if($provider!==[]||array_key_exists('provider_params',$request))$request['provider_params']=$provider;
        if($sources!==[]){ksort($sources);$request['_owned_flat_normalization']=['source_revision'=>MarketOwnedPlatformContractRules::REVISION,'fields'=>$sources];}
        return $request;
    }

    private static function assertSingleQuantity($value): void
    {
        if(!is_int($value)&&(!is_string($value)||preg_match('/^[1-9][0-9]*$/D',$value)!==1)
            ||filter_var($value,FILTER_VALIDATE_INT)!==1)
            throw new InvalidArgumentException('当前已审核的单任务请求数量必须为整数 1');
    }

    private static function fastValue(string $field,$value)
    {
        if($field==='image_urls'){
            if(is_string($value))return $value;
            if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Fast 参考图必须为地址或地址列表');
            foreach($value as$url)if(!is_string($url))throw new InvalidArgumentException('Fast 参考图地址类型不正确');
            return $value;
        }
        if($field==='image_size'){
            if(!is_string($value)&&!is_int($value))throw new InvalidArgumentException('Fast 分辨率类型不正确');
            $value=strtolower(trim((string)$value));if(in_array($value,['1','2','4'],true))$value.='k';
            if(!in_array($value,['1k','2k','4k'],true))throw new InvalidArgumentException('Fast 分辨率不符合已审核合同');
            return $value;
        }
        if(!is_string($value))throw new InvalidArgumentException('Fast 尺寸类型不正确');
        return str_replace(' ','',trim(str_replace(['×','X'],'x',$value)))?:'auto';
    }

    /** Existing market dimension aliases remain authoritative without modifying the frozen source record. */
    private static function canonicalLocks(array $snapshot,array $schema): array
    {
        $original=MarketGenerationSchema::array($snapshot['locked_params']??[]);$result=$original;$assigned=[];$props=MarketGenerationSchema::properties($schema);
        $dimension=isset($props['resolution'])?'resolution':(isset($props['image_size']['enum'])?'image_size':(isset($props['quality']['enum'])&&in_array(self::family((string)($snapshot['model_code']??''),(string)($snapshot['channel_code']??'')),['grok','veo'],true)?'quality':null));
        foreach($original as$key=>$value){
            $target=$key;
            if($dimension!==null&&in_array($key,['resolution','image_size','quality'],true)&&is_string($value)
                &&preg_match('/^(?:[124]k|720p|1080p)$/iD',$value))$target=$dimension;
            elseif(in_array($key,['ratio','size'],true)&&isset($props['aspect_ratio'])&&is_string($value)
                &&preg_match('/^(?:auto|[0-9]+:[0-9]+|[0-9]+[xX×][0-9]+)$/iD',$value))$target='aspect_ratio';
            elseif(in_array($key,['seconds','video_duration'],true)&&isset($props['duration']))$target='duration';
            if(!isset($props[$target]))continue;
            if(in_array($target,['duration','n'],true))self::assertWhole($value);
            foreach((array)($props[$target]['enum']??[])as$choice)if(is_string($choice)&&is_string($value)&&strcasecmp($choice,$value)===0){$value=$choice;break;}
            $normalized=MarketGenerationSchema::normalize([$target=>$props[$target]],[$target=>$value],'',false)[$target]??$value;
            if(array_key_exists($target,$assigned)&&$assigned[$target]!==$normalized)throw new InvalidArgumentException('冻结 SKU 的维度别名互相冲突');
            if($target!==$key)unset($result[$key]);$result[$target]=$normalized;$assigned[$target]=$normalized;
        }
        return $result;
    }

    private static function assertWhole($value): void
    {
        if(is_bool($value)||!is_scalar($value)||!is_numeric($value)||!is_finite((float)$value)||(float)$value!==(float)(int)$value)
            throw new InvalidArgumentException('任务数量或视频时长必须为整数');
    }

    public static function matrix(string $family): array
    {
        if(!in_array($family,['dakka_pixels','fast_pixels'],true))return [];
        return self::MATRICES[$family];
    }

    private const MATRICES=[
        'dakka_pixels'=>[
            '1:1'=>['1k'=>'1024x1024','2k'=>'2048x2048','4k'=>'2880x2880'],
            '16:9'=>['1k'=>'1280x720','2k'=>'2048x1152','4k'=>'3840x2160'],
            '9:16'=>['1k'=>'720x1280','2k'=>'1152x2048','4k'=>'2160x3840'],
            '4:3'=>['1k'=>'1152x864','2k'=>'2304x1728','4k'=>'3264x2448'],
            '3:4'=>['1k'=>'864x1152','2k'=>'1728x2304','4k'=>'2448x3264'],
            '3:2'=>['1k'=>'1536x1024','2k'=>'2048x1360','4k'=>'3504x2336'],
            '2:3'=>['1k'=>'1024x1536','2k'=>'1360x2048','4k'=>'2336x3504'],
            '5:4'=>['1k'=>'1120x896','2k'=>'2240x1792','4k'=>'3200x2560'],
            '4:5'=>['1k'=>'896x1120','2k'=>'1792x2240','4k'=>'2560x3200'],
            '21:9'=>['1k'=>'1456x624','2k'=>'2912x1248','4k'=>'3840x1648'],
            '9:21'=>['1k'=>'624x1456','2k'=>'1248x2912','4k'=>'1648x3840'],
            '1:3'=>['1k'=>'688x2048','2k'=>'1280x3840'],'3:1'=>['1k'=>'2048x688','2k'=>'3840x1280'],
            '2:1'=>['1k'=>'1536x768','2k'=>'3072x1536','4k'=>'3840x1920'],
            '1:2'=>['1k'=>'768x1536','2k'=>'1536x3072','4k'=>'1920x3840']],
        'fast_pixels'=>[
            '1:1'=>['1k'=>'1024x1024','2k'=>'2048x2048','4k'=>'2880x2880'],
            '3:2'=>['1k'=>'1536x1024','2k'=>'2048x1360','4k'=>'3520x2336'],
            '2:3'=>['1k'=>'1024x1536','2k'=>'1360x2048','4k'=>'2336x3520'],
            '4:3'=>['1k'=>'1024x768','2k'=>'2048x1536','4k'=>'3312x2480'],
            '3:4'=>['1k'=>'768x1024','2k'=>'1536x2048','4k'=>'2480x3312'],
            '5:4'=>['1k'=>'1280x1024','2k'=>'2560x2048','4k'=>'3216x2576'],
            '4:5'=>['1k'=>'1024x1280','2k'=>'2048x2560','4k'=>'2576x3216'],
            '16:9'=>['1k'=>'1536x864','2k'=>'2048x1152','4k'=>'3840x2160'],
            '9:16'=>['1k'=>'864x1536','2k'=>'1152x2048','4k'=>'2160x3840'],
            '2:1'=>['1k'=>'2048x1024','2k'=>'2688x1344','4k'=>'3840x1920'],
            '1:2'=>['1k'=>'1024x2048','2k'=>'1344x2688','4k'=>'1920x3840'],
            '3:1'=>['1k'=>'2016x672','2k'=>'3072x1024','4k'=>'3840x1280'],
            '1:3'=>['1k'=>'672x2016','2k'=>'1024x3072','4k'=>'1280x3840'],
            '21:9'=>['1k'=>'2016x864','2k'=>'2688x1152','4k'=>'3840x1648'],
            '9:21'=>['1k'=>'864x2016','2k'=>'1152x2688','4k'=>'1648x3840']],
    ];
}
