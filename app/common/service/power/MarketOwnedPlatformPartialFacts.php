<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Public, incomplete evidence only. These facts never become an admission contract or a price catalogue. */
final class MarketOwnedPlatformPartialFacts
{
    public static function extract(array $profile,array $metadata,array $sourceTexts): array
    {
        $model=$profile['model_code'];$channel=$profile['channel_code'];
        $schema=MarketGenerationSchema::array($metadata['params_schema']??[]);
        $facts=['status'=>'partial_facts_not_admission','source_revision'=>MarketOwnedPlatformContractRules::REVISION,
            'raw_declaration_fingerprint'=>MarketGenerationReviewedContract::declarationFingerprint($metadata),
            'parameters'=>self::declarations($schema),'parameter_origin'=>'saved_public_declaration',
            'options_policy'=>'display_options_are_not_gateway_enums','saved_public_capabilities'=>[],
            'capability_origin'=>'saved_public_declaration_not_live_provider_measurement',
            'source_constraints'=>[],'conflicts'=>[],'deployment_verified'=>false];
        $upstream=MarketGenerationSchema::array($metadata['upstream_metadata']??[]);
        foreach(['images','videos','audios']as$kind){
            $key='max_reference_'.$kind;$value=$metadata[$key]??$metadata['capabilities'][$key]??$upstream[$key]??null;
            if(is_int($value)&&$value>=0)$facts['saved_public_capabilities'][$key]=$value;
        }
        $constraints=&$facts['source_constraints'];
        if($channel==='Google'&&in_array($model,['nano-banana-2','nano-banana-pro','nano-banana-2.1'],true)){
            $constraints=['documented_resolutions'=>['1K','2K','4K'],
                'documented_ratios'=>['auto','1:1','16:9','9:16','4:3','3:4','3:2','2:3','5:4','4:5','21:9'],
                'reference_parameter'=>'urls','empty_prompt_conditions'=>'not_fully_specified'];
        }elseif($channel==='OpenAIYI'){
            $constraints=['reference_parameter'=>'image_urls','resolution_options'=>['1k','2k','4k']];
            if(in_array($model,['gpt-image-2.5-flare','gpt-image-2.5-sunburst'],true)){
                $constraints['quality_options']=$model==='gpt-image-2.5-flare'?['low','medium','high']:['low','medium','high','xhigh','max'];
                $constraints['background_transparent_supported']=true;
                $constraints['pixel_matrix']=self::matrix($sourceTexts['server/app/common/service/ai/driver/DrawDriver.php']??'','dakkaGptImageSize');
                $constraints['pixel_matrix_function']='DrawDriver::dakkaGptImageSize';
                $constraints['explicit_pixel_size_bypass_in_driver']=true;
            }else $constraints['quality_options']=['auto'];
        }elseif($model==='gpt-image-2-fast'&&$channel==='openaiD'){
            $constraints=['reference_parameter'=>'image_urls','quality_options'=>['low','medium','high'],
                'documented_prompt_max_characters'=>5000,'prompt_limit_enforced_by_generic_driver'=>false,
                'pixel_matrix'=>self::matrix($sourceTexts['server/app/common/service/ai/driver/DrawDriver.php']??'','gptImage2FastSize'),
                'pixel_matrix_function'=>'DrawDriver::gptImage2FastSize','explicit_pixel_size_bypass_in_driver'=>true];
            $facts['conflicts'][]=['kind'=>'document_code_matrix','resolution'=>'1k','ratio'=>'3:1','document'=>'1881x836','driver'=>'2016x672'];
            $facts['conflicts'][]=['kind'=>'document_code_matrix','resolution'=>'1k','ratio'=>'1:3','document'=>'887x1774','driver'=>'672x2016'];
        }elseif($channel==='OpenaiM'&&in_array($model,['gpt-image-2','gpt-image-2-pro'],true)){
            $constraints=['reference_parameter'=>'image_urls','documented_output_count'=>1,'documented_reference_images'=>16];
            if($model==='gpt-image-2-pro')$constraints['mask_parameter']='mask_url';
            $saved=$facts['saved_public_capabilities']['max_reference_images']??null;
            if($saved!==null&&$saved!==16)$facts['conflicts'][]=['kind'=>'reference_count','saved'=>$saved,'document'=>16,
                'resolution'=>'keep_saved_cap_do_not_expand_from_document'];
        }elseif($model==='grok-video'&&$channel==='xAIQ'){
            $constraints=['documented_durations'=>[6,10,15,20,25,30],'documented_resolutions'=>['720p'],
                'documented_ratios'=>['2:3','3:2','1:1','9:16','16:9'],'documented_reference_images'=>7];
        }elseif(in_array($model,['veo3.1-fast','veo3.1-pro'],true)&&$channel==='xAIQ'){
            $constraints=['documented_durations'=>[8],'documented_resolutions'=>['720p','1080p','4k'],
                'documented_ratios'=>['16:9','9:16'],'documented_modes'=>[
                    'TEXT'=>['image_conditions'=>'example_contains_images_condition_unresolved'],
                    'FIRST&LAST'=>['minimum_images'=>1,'maximum_images'=>2],
                    'REFERENCE'=>['maximum_images'=>3,'ratios'=>['16:9']]],'sku_identity'=>$model];
        }elseif(($profile['profile']??null)==='qwen_image_v1'){
            $constraints=['gateway_profiles'=>['text_to_image','image_to_image'],'output_count'=>1,'user_messages'=>1,
                'text_items'=>1,'maximum_images'=>3,'pixel_product'=>['minimum'=>512*512,'maximum'=>2048*2048],
                'aspect_ratio_range'=>[1/8,8],'agent_with_images'=>false];
        }elseif(($profile['profile']??null)==='h3_content_v1'){
            $constraints=['gateway_media_limits'=>['first_frame'=>1,'last_frame'=>1,'reference_image'=>9,'reference_video'=>3,'reference_audio'=>3],
                'gateway_duration'=>['minimum'=>4,'maximum'=>15],'published_resolution_intersection'=>['768P','2K'],
                'implicit_image_role'=>'first_frame','tail_requires_first'=>true,'frames_with_references'=>false,
                'audio_requires_visual_reference'=>true,'documented_media_duration_not_measured'=>true];
        }elseif(($profile['profile']??null)==='wan_video_media_v1'){
            $constraints=['gateway_media_limits'=>['first_frame'=>1,'last_frame'=>1,'reference_image'=>10,'reference_video'=>5,'reference_audio'=>5,'file'=>1,'link'=>1],
                'gateway_duration'=>['automatic'=>-1,'minimum_with_reference_video'=>1,'minimum_without_reference_video'=>2,'maximum'=>30],
                'file_with_link'=>false,'frames_with_references'=>false,'tail_only'=>true,
                'documented_media_duration_not_measured'=>true,'automatic_duration_settlement_upper_bound'=>null];
        }elseif(($profile['profile']??null)==='q4_video_v1'){
            $constraints=['exact_images'=>1,'strict_integer_duration'=>['minimum'=>3,'maximum'=>16,'default'=>5],
                'resolutions'=>['540p','720p','1080p','2K','4K'],'strict_boolean_fields'=>['audio','is_rec','watermark'],
                'request_body_bytes_exclusive_maximum'=>20*1024*1024,'requires_verified_current_upstream_model'=>true];
        }
        $facts['source_constraint_layers']=[];
        foreach(array_keys($constraints)as$key)$facts['source_constraint_layers'][$key]=str_starts_with($key,'documented_')
            ?'published_document_not_measured':(in_array($key,['quality_options','resolution_options','background_transparent_supported'],true)
                ?'pinned_migration_declaration_not_live_gateway_enum':'pinned_source_requires_deployment_binding');
        return $facts;
    }

    /** Preserve declared type/options/default only; an example is deliberately never a default or a bound. */
    public static function declarations(array $schema): array
    {
        if(isset($schema['type'])){
            $wrapped=self::declarations(['definition'=>$schema]);
            return $wrapped['definition']??[];
        }
        $result=[];
        foreach($schema as$key=>$value){
            if(!is_array($value))continue;
            if(in_array($key,['properties','items','children','item'],true)){$result[$key]=self::declarations($value);continue;}
            $definition=[];
            foreach(['type','required','default','minimum','maximum','minItems','maxItems','minLength','maxLength']as$field)
                if(array_key_exists($field,$value)&&!is_array($value[$field])&&!is_object($value[$field]))$definition[$field]=$value[$field];
            foreach(['enum','options']as$field)if(array_key_exists($field,$value)){
                $options=$value[$field];
                if(is_string($options)){
                    $decoded=json_decode($options,true);
                    $options=is_array($decoded)?$decoded:array_map('trim',explode(' / ',$options));
                }
                if(is_array($options))$definition[$field==='options'?'display_options':'declared_enum']=array_values(array_filter($options,static fn($v)=>is_scalar($v)||$v===null));
            }
            foreach(['properties','items','children','item']as$field)if(is_array($value[$field]??null))$definition[$field]=self::declarations($value[$field]);
            if($definition!==[])$result[$key]=$definition;
        }
        return $result;
    }

    /** Exact source matrix lookup only. A missing cell is unknown/unsupported mapping, never another channel's cell. */
    public static function pixelCell(array $facts,string $ratio,string $resolution): ?string
    {
        $value=$facts['source_constraints']['pixel_matrix'][$ratio][strtolower($resolution)]??null;
        return is_string($value)?$value:null;
    }

    /** Finite literal reader, never eval/include the upstream PHP. Called on authenticated source bytes by the builder. */
    public static function matrix(string $php,string $function): array
    {
        if(!in_array($function,['dakkaGptImageSize','gptImage2FastSize'],true)
            ||!preg_match('/function '.preg_quote($function,'/').'\([^\n]*\).*?\$matrix\s*=\s*\[(.*?)\n\s*\];/s',$php,$match))
            throw new InvalidArgumentException('固定版本尺寸矩阵源码缺失');
        preg_match_all("/'([0-9]+:[0-9]+)'\\s*=>\\s*\\[([^\\]]+)\\]/",$match[1],$rows,PREG_SET_ORDER);
        $matrix=[];
        foreach($rows as$row){
            preg_match_all("/'([124]k)'\\s*=>\\s*'([0-9]+x[0-9]+)'/",$row[2],$cells,PREG_SET_ORDER);
            if(isset($matrix[$row[1]]))throw new InvalidArgumentException('固定版本尺寸矩阵重复');
            foreach($cells as$cell)$matrix[$row[1]][$cell[1]]=$cell[2];
        }
        if(count($matrix)!==15)throw new InvalidArgumentException('固定版本尺寸矩阵不完整');
        return $matrix;
    }
}
