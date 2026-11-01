<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Offline compiler input only. Never attaches a contract to a persisted product or runs upstream PHP. */
final class MarketOwnedPlatformContractBuilder
{
    public const REVISION=MarketOwnedPlatformContractRules::REVISION;
    public const ARTIFACT_SHA256='807c75616b0fb7b7225c2814645360b8f9ea8e6e7371d1267540aefe373870db';
    private const REPOSITORY='https://gitee.com/yc_open/platform-api-saas.git';
    private const COMMON_SOURCES=['server/app/api/controller/v1/TaskController.php','server/app/common/service/ai/ModelRouterService.php',
        'server/app/common/service/ai/ParamsSchemaValidateService.php'];

    public static function profiles(): array
    {
        $path=dirname(__DIR__,4).'/resources/market/owned-platform-contract-profiles-b5887e1.json';
        $data=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if(($data['version']??null)!==1||($data['source_revision']??null)!==self::REVISION
            ||($data['activation']??'')!=='offline_candidates_only'||count($data['models']??[])!==18)
            throw new InvalidArgumentException('自有平台候选索引版本不匹配');
        $seen=[];
        foreach($data['models']as$row){$key=self::key($row);if(isset($seen[$key]))throw new InvalidArgumentException('自有平台候选身份重复');$seen[$key]=true;}
        return $data['models'];
    }

    public static function build(array $snapshot,string $sourceArtifact,?string $currentExecutionArtifact=null,?string $currentPublicArtifact=null,?string $smartRouteContextArtifact=null): array
    {
        if(!hash_equals(self::ARTIFACT_SHA256,hash('sha256',$sourceArtifact)))throw new InvalidArgumentException('原始平台源码证据指纹不匹配');
        $source=json_decode($sourceArtifact,true,64,JSON_THROW_ON_ERROR);
        if(($source['version']??null)!==1||($source['repository']??'')!==self::REPOSITORY||($source['revision']??'')!==self::REVISION)
            throw new InvalidArgumentException('平台源码证据版本不匹配');
        $files=[];$sourceTexts=[];
        foreach($source['files']??[]as$file){
            if(!is_string($file['path']??null)||isset($files[$file['path']])||!is_string($file['content']??null)
                ||!hash_equals((string)($file['sha256']??''),hash('sha256',$file['content']))
                ||!hash_equals((string)($file['git_blob']??''),sha1('blob '.strlen($file['content'])."\0".$file['content'])))
                throw new InvalidArgumentException('平台源码文件证据不完整');
            $files[$file['path']]=['git_blob'=>$file['git_blob'],'sha256'=>$file['sha256']];
            $sourceTexts[$file['path']]=$file['content'];
        }
        if(($snapshot['version']??null)!==1||!is_array($snapshot['entries']??null)||!array_is_list($snapshot['entries'])
            ||!is_string($snapshot['captured_at']??null)||strtotime($snapshot['captured_at'])===false)
            throw new InvalidArgumentException('原始声明快照格式不正确');
        $current=MarketOwnedPlatformExecutionEvidence::parse($currentExecutionArtifact,$currentPublicArtifact,$smartRouteContextArtifact);
        $rows=[];$profiles=self::profiles();$allowed=array_fill_keys(array_map([self::class,'key'],$profiles),true);
        foreach($snapshot['entries']as$row){
            $product=$row['product']??[];
            if(!is_array($product)||($product['resource_type']??'')!=='model')throw new InvalidArgumentException('候选只接受原模型资源');
            $identity=['generation_type'=>$row['generation_type']??'','model_code'=>$product['upstream_model_code']??'',
                'channel_code'=>$product['upstream_channel_code']??''];$key=self::key($identity);
            if(!isset($allowed[$key])||isset($rows[$key]))throw new InvalidArgumentException('原始声明型号渠道不匹配或重复');
            $rows[$key]=$row;
        }
        if(count($rows)!==18)throw new InvalidArgumentException('须逐项提供全部18个精确型号的原始声明');
        $bundle=['version'=>1,'source'=>['kind'=>'gateway_validator','reference'=>'https://gitee.com/yc_open/platform-api-saas/tree/'.self::REVISION,
            'revision'=>self::REVISION,'reviewed_at'=>$snapshot['captured_at'],'artifact_sha256'=>hash('sha256',$sourceArtifact)],
            'entries'=>[],'model_states'=>[],'activation'=>'offline_candidates_only','deployment_verified'=>false];
        foreach($profiles as$profile){
            $identity=array_intersect_key($profile,array_flip(['generation_type','model_code','channel_code']));$row=$rows[self::key($identity)];
            $state=$identity+['status'=>'waiting_authoritative_constraints','candidate_ready'=>false,
                'missing_constraints'=>$profile['missing_constraints']??[], 'runtime_dependencies'=>$profile['runtime_dependencies']??[],
                'external_verification'=>$profile['external_verification']??[], 'sources'=>[]];
            foreach(array_unique(array_merge(self::COMMON_SOURCES,$profile['sources']))as$path){
                if(!isset($files[$path]))$state['missing_constraints'][]='missing_source_file:'.$path;
                else $state['sources'][]=['path'=>$path]+$files[$path];
            }
            $metadata=$row['metadata']??$row['original_saved_metadata']??null;
            if(!is_array($metadata)||array_key_exists(MarketGenerationReviewedContract::PIN,$metadata)){
                $state['missing_constraints'][]='original_unreviewed_metadata_required';$bundle['model_states'][]=$state;continue;
            }
            $metadata=self::declarationMetadata($row);
            $fingerprint=MarketGenerationReviewedContract::declarationFingerprint($metadata);
            $upstream=MarketGenerationSchema::array($metadata['upstream_metadata']??[]);
            if(($upstream['model_code']??'')!==$identity['model_code']
                ||isset($upstream['channel_code'])&&$upstream['channel_code']!==$identity['channel_code']){
                $state['missing_constraints'][]='exact_original_public_identity_required';$bundle['model_states'][]=$state;continue;
            }
            $state['facts']=MarketOwnedPlatformPartialFacts::extract($profile,$metadata,$sourceTexts);
            $state['binding_declaration_fingerprint']=$fingerprint;
            $originalFingerprint=isset($row['original_saved_metadata'])?MarketGenerationReviewedContract::declarationFingerprint($row['original_saved_metadata']):null;
            $state['original_saved_declaration_fingerprint']=$originalFingerprint;
            $state['runtime_declaration_matches_original']=$originalFingerprint===null?null:$originalFingerprint===$fingerprint;
            if($profile['profile']===null||$state['missing_constraints']!==[]){$bundle['model_states'][]=$state;continue;}
            $protocol=$row['effective_protocol']??$metadata['generation_protocol']??$upstream['generation_protocol']??$profile['profile'];
            if($protocol!==$profile['profile']){
                $state['missing_constraints'][]='current_generation_protocol_conflicts_with_source_profile';$bundle['model_states'][]=$state;continue;
            }
            $binding=['params_schema'=>MarketGenerationSchema::array($metadata['params_schema']??[]),
                'material_capabilities'=>MarketGenerationSchema::array($metadata['material_capabilities']??$upstream['material_capabilities']??[])];
            if($binding['params_schema']===[]){$state['missing_constraints'][]='original_parameter_declaration_required';$bundle['model_states'][]=$state;continue;}
            $execution=MarketGenerationSchema::array($row['execution_metadata']??[]);
            if(array_key_exists('effective_upstream_model',$row))$execution['upstream_model']=$row['effective_upstream_model'];
            if(in_array($profile['profile'],['flat_image_v1','flat_video_v1'],true)||$profile['profile']==='q4_video_v1'&&$current!==[]){
                $resolved=MarketOwnedPlatformExecutionEvidence::resolve($current,$profile,$metadata);
                if($resolved['missing']!==[]){$state['missing_constraints']=array_merge($state['missing_constraints'],$resolved['missing']);$bundle['model_states'][]=$state;continue;}
                $execution=$resolved;$state['current_execution_binding']=$resolved['binding'];
                if(in_array($profile['profile'],['flat_image_v1','flat_video_v1'],true)){
                    $problem=self::flatMappingProblems($profile,$resolved,$sourceTexts);
                    if($problem!==[]){$state['missing_constraints']=array_merge($state['missing_constraints'],$problem);$bundle['model_states'][]=$state;continue;}
                }
            }
            if($profile['profile']==='q4_video_v1'&&(!is_string($execution['upstream_model']??null)||$execution['upstream_model']==='')){
                $state['missing_constraints'][]='verified_current_upstream_model_required_for_json_body_limit';$bundle['model_states'][]=$state;continue;
            }
            $entry=self::entry($profile,$binding,$execution);
            // Validate every proposed entry through the existing candidate and reviewed compilers before including it.
            $single=['version'=>1,'source'=>$bundle['source'],'entries'=>[$entry]];
            MarketGenerationContractReview::candidates($single,$sourceArtifact);
            MarketGenerationContractReview::reviewedCatalogue($single,$sourceArtifact);
            $bundle['entries'][]=$entry;$state['status']='source_candidate_ready_not_activated';$state['candidate_ready']=true;
            $state['binding_declaration_fingerprint']=MarketGenerationReviewedContract::digest([$binding['params_schema'],$binding['material_capabilities']]);
            $bundle['model_states'][]=$state;
        }
        $bundle['counts']=['models'=>18,'candidate_ready'=>count($bundle['entries']),'waiting'=>18-count($bundle['entries']),
            'active_contracts_written'=>0,'live_generations'=>0];
        return $bundle;
    }

    /** Runtime's immutable declaration view owns the binding; raw saved evidence is compared independently, never rewritten. */
    public static function declarationMetadata(array $row): array
    {
        $metadata=$row['metadata']??$row['original_saved_metadata']??null;
        if(!is_array($metadata)||isset($metadata[MarketGenerationReviewedContract::PIN]))throw new InvalidArgumentException('原始运行时声明缺失');
        $views=['declaration_fingerprint'=>$metadata];
        if(array_key_exists('original_saved_metadata',$row)){
            if(!is_array($row['original_saved_metadata'])||isset($row['original_saved_metadata'][MarketGenerationReviewedContract::PIN]))
                throw new InvalidArgumentException('原始保存声明不正确');
            $views['original_saved_declaration_fingerprint']=$row['original_saved_metadata'];
        }
        foreach($views as$field=>$view)if(isset($row[$field])&&(!is_string($row[$field])
            ||!hash_equals(MarketGenerationReviewedContract::declarationFingerprint($view),$row[$field])))
            throw new InvalidArgumentException('声明指纹与对应证据视图不一致');
        return $metadata;
    }

    private static function key(array $identity): string
    {
        foreach(['generation_type','model_code','channel_code']as$key)if(!is_string($identity[$key]??null)||$identity[$key]==='')throw new InvalidArgumentException('精确合同身份缺失');
        return implode('|',[$identity['generation_type'],$identity['model_code'],$identity['channel_code']]);
    }

    /** Curated translation of the pinned source; called only after evidence and raw-declaration binding. */
    private static function entry(array $profile,array $binding,array $execution=[]): array
    {
        if(in_array($profile['profile'],['flat_image_v1','flat_video_v1'],true))return self::flatEntry($profile,$binding,$execution);
        $protocol=$profile['profile'];$schema=self::schema($protocol);$modes=self::modes($protocol);$contracts=[];
        $schema['properties']['model']=['type'=>'string','const'=>$profile['model_code']];
        $schema['properties']['channel']=['type'=>'string','const'=>$profile['channel_code']];
        $schema['properties']['callback_url']=['type'=>'string'];
        foreach($modes as$mode){
            $rule=['kind'=>'owned_platform','profile'=>$protocol,'source_revision'=>self::REVISION,'mode'=>$mode['code'],
                'message'=>'参数或素材组合不符合该型号的已审核平台源码合同'];
            if($protocol==='q4_video_v1')$rule['upstream_model']=$execution['upstream_model'];
            $contracts[$mode['code']]=['schema'=>$schema,'rules'=>[$rule]];
        }
        $entry=array_intersect_key($profile,array_flip(['generation_type','model_code','channel_code']))+[
            'public_model_code'=>$profile['model_code'],'generation_protocol'=>$protocol,'revision'=>'owned-platform-b5887e1-'.$protocol.'-v1',
            'binding_declaration'=>$binding,'declaration'=>['params_schema'=>$schema,'material_capabilities'=>['modes'=>$modes]],'modes'=>$contracts];
        if(isset($execution['binding']))$entry['execution_binding']=$execution['binding'];
        return $entry;
    }

    private static function flatMappingProblems(array $profile,array $execution,array $texts): array
    {
        $family=MarketOwnedPlatformFlatSourceRules::family($profile['model_code'],$profile['channel_code']);
        $maximum=$execution['binding']['material_limits']['max_reference_images'];
        if($maximum<1||$maximum>100||$family==='grok'&&$maximum>7||$family==='m_gpt'&&$maximum>16)
            return ['current_material_capability_conflicts_with_finite_source_contract'];
        $image=$profile['generation_type']==='image';$config=$execution['config'][$image?'draw':'video'];
        $configs=[$config];
        if($image){$variant=$config['when_reference']??$config['reference_config']??[];if(is_array($variant)&&$variant!==[])$configs[]=array_replace($config,$variant);}
        foreach($configs as$cfg){
            $mapping=$cfg['request_params']??($image?['model'=>'model','prompt'=>'prompt','aspectRatio'=>'aspect_ratio','imageSize'=>'image_size','image_urls'=>'image_urls']:['prompt'=>'prompt','image_url'=>'image_url']);
            if(!is_array($mapping))return ['current_request_mapping_is_not_an_object'];
            $pixel=[];$reference=false;
            foreach($mapping as$target=>$spec){
                if(is_string($target)&&str_starts_with($target,'__draft_'))continue;
                if(is_array($spec)&&in_array($spec[1]??'', ['dakka_gpt_image_size','gpt_image_2_fast_size'],true))$pixel[]=$spec;
                if($spec==='image_urls')$reference=true;
            }
            $expected=$family==='dakka_pixels'?'dakka_gpt_image_size':($family==='fast_pixels'?'gpt_image_2_fast_size':null);
            if($expected!==null&&$pixel!==[['aspect_ratio',$expected]])return ['current_pixel_transform_differs_from_exact_source_profile'];
            if($expected===null&&$pixel!==[])return ['unexpected_current_pixel_transform_requires_separate_profile'];
            if($family!=='google_nano'&&!$reference)return ['current_reference_mapping_not_proven_lossless'];
            if(!$image&&!empty($cfg['minimal_create']))return ['current_video_minimal_create_drops_declared_fields'];
        }
        if(in_array($family,['dakka_pixels','fast_pixels'],true)){
            $function=$family==='dakka_pixels'?'dakkaGptImageSize':'gptImage2FastSize';
            $matrix=MarketOwnedPlatformPartialFacts::matrix($texts['server/app/common/service/ai/driver/DrawDriver.php']??'',$function);
            if(MarketGenerationReviewedContract::digest($matrix)!==MarketGenerationReviewedContract::digest(MarketOwnedPlatformFlatSourceRules::matrix($family)))
                throw new InvalidArgumentException('有限尺寸规则与固定原始源码不一致');
        }
        return [];
    }

    private static function flatEntry(array $profile,array $binding,array $execution): array
    {
        if(!isset($execution['binding'],$execution['config']))throw new InvalidArgumentException('直接执行候选缺少当前配置绑定');
        $family=MarketOwnedPlatformFlatSourceRules::family($profile['model_code'],$profile['channel_code']);
        if($family===null)throw new InvalidArgumentException('未审核的直接执行型号');
        $image=$profile['generation_type']==='image';$field=$family==='google_nano'?'urls':'image_urls';
        $maximum=$execution['binding']['material_limits']['max_reference_images'];$schemas=[];$modes=[];
        foreach([false,true]as$reference){
            $code=$image?($reference?'image_to_image':'text_to_image'):($reference?'reference_to_video':'text_to_video');
            $mode=['code'=>$code,'view_key'=>$reference?'reference':'text','method'=>$image?($reference?'image_reference':'text_to_image'):($reference?'image_reference':'text_to_video'),
                'fields'=>$reference?[['field'=>$field,'kind'=>'image','role'=>'reference','required'=>true,'maximum'=>$maximum]]:[]];
            $schema=self::flatSchema($profile,$execution,$reference);
            $rule=['kind'=>'owned_platform','profile'=>$profile['profile'],'family'=>$family,'model_code'=>$profile['model_code'],
                'channel_code'=>$profile['channel_code'],'source_revision'=>self::REVISION,'mode'=>$code,'maximum_images'=>$maximum,
                'configuration_fingerprint'=>$execution['binding']['configuration_fingerprint'],'message'=>'请求不符合该型号已绑定的直接执行合同'];
            $modes[]=$mode;$schemas[$code]=['schema'=>$schema,'rules'=>[$rule]];
        }
        $general=self::flatSchema($profile,$execution,true);$general['properties'][$field]=$family==='fast_pixels'
            ?['anyOf'=>[['type'=>'string'],['type'=>'array','maxItems'=>$maximum,'items'=>['type'=>'string']]]]
            :['type'=>'array','maxItems'=>$maximum,'items'=>['type'=>'string']];
        $general['required']=array_values(array_diff($general['required'],[$field]));
        return array_intersect_key($profile,array_flip(['generation_type','model_code','channel_code']))+[
            'public_model_code'=>$profile['model_code'],'generation_protocol'=>$profile['profile'],'revision'=>'owned-platform-b5887e1-flat-source-v1',
            'binding_declaration'=>$binding,'execution_binding'=>$execution['binding'],
            'declaration'=>['params_schema'=>$general,'material_capabilities'=>['modes'=>$modes]],'modes'=>$schemas];
    }

    private static function flatSchema(array $profile,array $execution,bool $reference): array
    {
        $family=MarketOwnedPlatformFlatSourceRules::family($profile['model_code'],$profile['channel_code']);$image=$profile['generation_type']==='image';
        $string=['type'=>'string'];$props=['model'=>$string+['const'=>$profile['model_code']],'channel'=>$string+['const'=>$profile['channel_code']],
            'callback_url'=>$string,'prompt'=>$string];$required=$family==='google_nano'?[]:['prompt'];
        if($family!=='google_nano')$props['prompt']['minLength']=1;
        $field=$family==='google_nano'?'urls':'image_urls';$maximum=$execution['binding']['material_limits']['max_reference_images'];
        $list=['type'=>'array','maxItems'=>$reference?$maximum:0,'items'=>['type'=>'string','minLength'=>1]];
        if($reference){$list['minItems']=1;$required[]=$field;}
        $props[$field]=$family==='fast_pixels'?['anyOf'=>[$reference?['type'=>'string','minLength'=>1]:['type'=>'string','const'=>''],$list]]:$list;
        $ratios=['auto','1:1','3:2','2:3','4:3','3:4','5:4','4:5','16:9','9:16','2:1','1:2','3:1','1:3','21:9','9:21'];
        if(!$image){
            $props['quality']=$string+['enum'=>['720p'],'default'=>'720p'];$props['duration']=['type'=>'integer','enum'=>[6,10,15,20,25,30],'default'=>6];
            $props['aspect_ratio']=$string+['enum'=>['2:3','3:2','1:1','9:16','16:9']];
        }elseif($family==='google_nano'){
            $props['image_size']=$string+['enum'=>['1K','2K','4K'],'default'=>'1K'];
            $props['aspect_ratio']=$string+['enum'=>['auto','1:1','16:9','9:16','4:3','3:4','3:2','2:3','5:4','4:5','21:9'],'default'=>'auto'];
        }else{
            $resolution=$family==='fast_pixels'?'image_size':'resolution';$props[$resolution]=$string+['enum'=>['1k','2k','4k'],'default'=>'1k'];
            $props['aspect_ratio']=$string+['enum'=>$ratios,'default'=>'auto'];
            if(in_array($family,['dakka_pixels','fast_pixels'],true))$props['aspect_ratio']=$string+[
                'pattern'=>'^(?:'.($family==='dakka_pixels'?'[aA][uU][tT][oO]':'auto').'|[0-9]+[xX×][0-9]+|[0-9]+[ ]*:[ ]*[0-9]+)$','default'=>'auto'];
            if(in_array($family,['dakka_plain','dakka_pixels','m_gpt'],true))$props['n']=['type'=>'integer','const'=>1,'default'=>1];
            if(in_array($family,['dakka_plain','dakka_pixels','yi_gpt'],true)){
                $quality=match($profile['model_code']){'gpt-image-2.5-flare'=>['low','medium','high'],'gpt-image-2.5-sunburst'=>['low','medium','high','xhigh','max'],default=>['auto']};
                $props['quality']=$string+['enum'=>$quality];
                if($family==='dakka_pixels')$props['background']=$string+['const'=>'transparent'];
            }
            if($family==='fast_pixels'){$props['quality']=$string+['enum'=>['low','medium','high']];$props['prompt']['maxLength']=5000;}
            if($family==='m_gpt')$props['image_size']=$string;
        }
        $config=$execution['config'][$image?'draw':'video'];
        if($image&&$reference){$variant=$config['when_reference']??$config['reference_config']??[];if(is_array($variant))$config=array_replace($config,$variant);}
        $defaults=array_merge($image?(array)($config['merge_defaults']??[]):[],(array)($execution['public_defaults']??[]));
        foreach($defaults as$key=>$value)if(isset($props[$key])&&!in_array($key,[$field,'prompt','model','channel'],true))$props[$key]['default']=$value;
        return self::object($props,$required);
    }

    private static function object(array $properties,array $required=[]): array
    {
        return ['type'=>'object','properties'=>$properties,'required'=>$required];
    }

    private static function schema(string $profile): array
    {
        $string=['type'=>'string'];$boolean=['type'=>'boolean'];$seed=['type'=>'integer','minimum'=>0,'maximum'=>2147483647];
        if($profile==='qwen_image_v1'){
            $content=['type'=>'array','minItems'=>1,'maxItems'=>4,'items'=>['anyOf'=>[
                self::object(['text'=>$string],['text']),self::object(['image'=>$string],['image'])]]];
            $message=self::object(['role'=>['type'=>'string','const'=>'user'],'content'=>$content],['role','content']);
            return self::object(['input'=>self::object(['messages'=>['type'=>'array','minItems'=>1,'maxItems'=>1,'items'=>$message]],['messages']),
                'parameters'=>self::object(['n'=>['type'=>'integer','const'=>1],'size'=>$string,'seed'=>$seed,
                    'prompt_extend'=>$boolean,'watermark'=>$boolean,'negative_prompt'=>$string,
                    'prompt_extend_mode'=>['type'=>'string','enum'=>['direct','agent']]],['n'])],['input','parameters']);
        }
        if($profile==='q4_video_v1')return self::object([
            'images'=>['type'=>'array','minItems'=>1,'maxItems'=>1,'items'=>$string], 'prompt'=>['type'=>'string','maxLength'=>20000],
            'duration'=>['type'=>'integer','minimum'=>3,'maximum'=>16,'default'=>5],
            'resolution'=>['type'=>'string','enum'=>['540p','720p','1080p','2K','4K'],'default'=>'720p'],
            'audio'=>$boolean+['default'=>true],'is_rec'=>$boolean+['default'=>true],'watermark'=>$boolean+['default'=>true],
            'seed'=>['type'=>'integer','minimum'=>0,'default'=>0],'wm_position'=>['type'=>'integer','enum'=>[1,2,3,4],'default'=>3],
            'payload'=>['type'=>'string','maxLength'=>1048576],'wm_url'=>$string,'meta_data'=>$string],['images']);
        if($profile==='h3_content_v1'){
            $branches=[self::object(['type'=>['type'=>'string','const'=>'text'],'text'=>$string,'role'=>['type'=>'string','const'=>'']],['type','text'])];
            foreach(['image','video','audio']as$kind){
                $roles=$kind==='image'?['first_frame','last_frame','reference_image']:['reference_'.$kind];
                $branches[]=self::object(['type'=>['type'=>'string','const'=>$kind.'_url'],'role'=>['type'=>'string','enum'=>$roles],
                    $kind.'_url'=>self::object(['url'=>$string],['url'])],['type','role',$kind.'_url']);
            }
            // The gateway accepts an omitted/empty role on an image as first_frame. Do not delete that valid branch.
            $branches[]=self::object(['type'=>['type'=>'string','const'=>'image_url'],'role'=>['type'=>'string','const'=>''],
                'image_url'=>self::object(['url'=>$string],['url'])],['type','image_url']);
            return self::object(['content'=>['type'=>'array','minItems'=>1,'items'=>['anyOf'=>$branches]],
                // The registered H3 encoder sends numeric strings. The gateway integer validator accepts them;
                // current published string enums also require preserving this outbound representation.
                'duration'=>['type'=>'string','enum'=>array_map('strval',range(4,15))],
                'resolution'=>['type'=>'string','enum'=>['768P','2K']],
                'ratio'=>['type'=>'string','enum'=>['adaptive','21:9','16:9','4:3','1:1','3:4','9:16']]],['content','duration','resolution','ratio']);
        }
        if($profile==='wan_video_media_v1')return self::object([
            'input'=>self::object(['prompt'=>['type'=>'string','maxLength'=>20000],
                'media'=>['type'=>'array','items'=>self::object(['type'=>['type'=>'string','enum'=>[
                    'first_frame','last_frame','reference_image','reference_video','reference_audio','file','link']], 'url'=>$string],['type','url'])]]),
            'parameters'=>self::object(['duration'=>['type'=>'integer','minimum'=>-1,'maximum'=>30],
                'resolution'=>['type'=>'string','enum'=>['480P','720P','1080P']],
                'ratio'=>['type'=>'string','enum'=>['adaptive','16:9','4:3','1:1','3:4','9:16']],
                'audio'=>$boolean,'watermark'=>$boolean,'seed'=>$seed])],['input','parameters']);
        throw new InvalidArgumentException('没有完整源码候选的协议');
    }

    private static function modes(string $profile): array
    {
        if($profile==='qwen_image_v1')return [
            ['code'=>'text_to_image','view_key'=>'text','method'=>'text_to_image','fields'=>[]],
            ['code'=>'image_to_image','view_key'=>'reference','method'=>'image_reference','fields'=>[
                ['field'=>'input.messages.*.content.*.image','kind'=>'image','role'=>'reference','required'=>true,'maximum'=>3]]]];
        if($profile==='q4_video_v1')return [
            ['code'=>'image_to_video','view_key'=>'first_frame','method'=>'image_to_video','fields'=>[
                ['field'=>'images','kind'=>'image','role'=>'first','required'=>true,'maximum'=>1]]]];
        $h3=$profile==='h3_content_v1';
        $field=static function(string $kind,string $role,int $maximum,bool $required=false)use($h3):array{
            $tag=$role==='first'?'first_frame':($role==='last'?'last_frame':(in_array($kind,['file','link'],true)?$kind:'reference_'.$kind));
            return ['field'=>$h3?'content.*.'.$kind.'_url.url':'input.media.*.url','kind'=>$kind,'role'=>$role,
                'maximum'=>$maximum,'required'=>$required,'item_match'=>$h3?['type'=>$kind.'_url','role'=>$tag]:['type'=>$tag]];
        };
        $first=$field('image','first',1,true);$last=$field('image','last',1,true);
        $reference=[$field('image','reference',$h3?9:10),$field('video','reference',$h3?3:5),$field('audio','reference',$h3?3:5)];
        if(!$h3){$reference[]=$field('file','reference',1);$reference[]=$field('link','reference',1);}
        $modes=[['code'=>'text_to_video','view_key'=>'text','method'=>'text_to_video','fields'=>[]],
            ['code'=>'image_to_video','view_key'=>'first_frame','method'=>'image_to_video','fields'=>[$first]],
            ['code'=>'first_last_frame','view_key'=>'frames','method'=>'start_end','fields'=>[$first,$last]],
            ['code'=>'reference_to_video','view_key'=>'reference','method'=>'omni_reference','fields'=>$reference]];
        if(!$h3)$modes[]=['code'=>'last_frame','view_key'=>'last_frame','method'=>'last_frame','fields'=>[$last]];
        return $modes;
    }
}
