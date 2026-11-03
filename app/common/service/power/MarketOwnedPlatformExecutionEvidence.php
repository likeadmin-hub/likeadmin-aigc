<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Read-only business configuration supplied by the authenticated platform capture, separate from immutable source bytes. */
final class MarketOwnedPlatformExecutionEvidence
{
    public static function parse(?string $bytes,?string $publicBytes=null,?string $routeBytes=null): array
    {
        if($bytes===null){if($publicBytes!==null||$routeBytes!==null)throw new InvalidArgumentException('当前公开上下文需要配套当前执行配置');return [];}
        $value=json_decode($bytes,true,128,JSON_THROW_ON_ERROR);
        if(($value['version']??null)!==1||!is_array($value['models']??null)||!array_is_list($value['models'])
            ||!is_array($value['channels']??null)||!array_is_list($value['channels']))throw new InvalidArgumentException('当前执行配置证据格式不正确');
        $models=[];$channels=[];
        foreach($value['models']as$model){
            if(!is_array($model)||!is_string($model['model_code']??null)||!is_string($model['channel_code']??null)
                ||self::id($model['platform_model_id']??null)===0)throw new InvalidArgumentException('当前模型执行身份缺失');
            $key=$model['model_code'].'|'.$model['channel_code'];if(isset($models[$key]))throw new InvalidArgumentException('当前执行模型身份重复');
            self::assertNoCredentials($model);$models[$key]=$model;
        }
        foreach($value['channels']as$channel){
            $code=$channel['channel_code']??$channel['code']??null;
            if(!is_array($channel)||!is_string($code)||$code===''||isset($channels[$code])
                ||isset($channel['channel_code'],$channel['code'])&&$channel['channel_code']!==$channel['code'])throw new InvalidArgumentException('当前执行渠道身份缺失或重复');
            self::assertNoCredentials($channel);$channels[$code]=$channel;
        }
        $public=[];$routes=[];
        foreach(['public'=>$publicBytes,'routes'=>$routeBytes]as$kind=>$data){
            if($data===null)continue;$document=json_decode($data,true,128,JSON_THROW_ON_ERROR);
            if(($document['version']??null)!==1||!is_array($document['models']??null)||!array_is_list($document['models']))throw new InvalidArgumentException('当前公开声明或路由上下文格式不正确');
            $seen=[];foreach($document['models']as$row){
                if(!is_array($row)||!is_string($row['model_code']??null)||!is_string($row['channel_code']??null))throw new InvalidArgumentException('当前公开上下文身份缺失');
                $key=$row['model_code'].'|'.$row['channel_code'];if(isset($seen[$key]))throw new InvalidArgumentException('当前公开上下文身份重复');$seen[$key]=true;
                if($kind==='public'&&($row['identity_verified']??false)===true&&is_array($row['declaration']??null))$public[$key]=$row['declaration'];
                if($kind==='routes'&&($row['read_state']??'')==='read'&&is_array($row['fields']??null))$routes[$key]=$row['fields'];
            }
        }
        return ['models'=>$models,'channels'=>$channels,'public_models'=>$public,'route_models'=>$routes,
            'artifact_sha256'=>hash('sha256',$bytes),'public_artifact_sha256'=>$publicBytes===null?null:hash('sha256',$publicBytes),
            'routing_context_artifact_sha256'=>$routeBytes===null?null:hash('sha256',$routeBytes)];
    }

    /** A missing/active route is a concrete pending state, never permission to force the old direct path. */
    public static function resolve(array $evidence,array $profile,array $metadata): array
    {
        $model=$evidence['models'][$profile['model_code'].'|'.$profile['channel_code']]??null;
        $channel=$evidence['channels'][$profile['channel_code']]??null;
        if($model===null||$channel===null)return ['missing'=>['exact_current_model_and_channel_configuration_required']];
        if(self::flag($model['channel_mapping_enabled']??null)!==false)return ['missing'=>['current_channel_mapping_enabled_or_unverified']];
        if(self::flag($model['fallback_configured']??null)!==false)return ['missing'=>['current_fallback_chain_enabled_or_unverified']];
        $key=$profile['model_code'].'|'.$profile['channel_code'];
        $route=$model['smart_route_context']??$evidence['route_models'][$key]??[];
        if(isset($model['smart_route_context'],$evidence['route_models'][$key]))foreach(['smart_route_supported','smart_route_tenant_force_enabled','smart_route_user_force_enabled']as$field)
            if(($model['smart_route_context'][$field]??null)!==($evidence['route_models'][$key][$field]??null))return ['missing'=>['conflicting_current_smart_route_context']];
        if(!is_array($route)||!is_bool($route['smart_route_supported']??null)
            ||!is_bool($route['smart_route_tenant_force_enabled']??null)||!is_bool($route['smart_route_user_force_enabled']??null))
            return ['missing'=>['configured_api_key_smart_route_force_context_required']];
        if($route['smart_route_supported']&&($route['smart_route_tenant_force_enabled']||$route['smart_route_user_force_enabled']))
            return ['missing'=>['configured_api_key_forces_smart_route_target_closure_required']];
        $upstream=MarketGenerationSchema::array($metadata['upstream_metadata']??[]);$savedId=MarketGenerationSourceIdentity::modelId($upstream);
        if($savedId>0&&$savedId!==self::id($model['platform_model_id']))return ['missing'=>['saved_and_current_platform_model_id_differ']];
        if(!is_string($model['upstream_model']??null)||trim($model['upstream_model'])==='')return ['missing'=>['current_upstream_model_required']];
        if(!is_string($model['observed_at']??null)||strtotime($model['observed_at'])===false)return ['missing'=>['current_execution_observation_time_required']];
        $modelConfig=self::object($model['api_config']??[]);$defaultState='object';
        if(array_key_exists('default_params',$model)&&$model['default_params']===null){
            $published=$evidence['public_models'][$key]??[];
            if(!array_key_exists('default_params',$published)||$published['default_params']!==[])
                return ['missing'=>['null_editor_defaults_require_exact_empty_current_public_declaration']];
            $defaults=[];$defaultState='null_editor_cross_checked_against_empty_public_defaults';
        }else $defaults=self::object($model['default_params']??[]);
        $nested=self::object($defaults['api_config']??[]);
        if($modelConfig!==[]&&$nested!==[]&&MarketGenerationReviewedContract::digest($modelConfig)!==MarketGenerationReviewedContract::digest($nested))
            return ['missing'=>['conflicting_current_model_api_configuration']];
        $config=array_replace_recursive(self::object($channel['extra_config']??[]),$modelConfig?:$nested);
        $group=$profile['generation_type']==='image'?'draw':'video';$driver=strtolower(trim((string)($config[$group]['driver']??'')));
        $matches=$profile['profile']==='q4_video_v1'?$driver==='vidu_q4':($profile['profile']==='qwen_image_v1'?$driver==='dashscope_qwen_image':($group==='draw'
            ?!in_array($driver,['official','dashscope_qwen_image'],true):!in_array($driver,['official','vidu_q4'],true)));
        if($profile['profile']!=='q4_video_v1'&&$profile['profile']!=='qwen_image_v1'){
            if($profile['profile']==='h3_content_v1'&&($config['video']['content_validation']??'')!=='minimax_h3')$matches=false;
            if($profile['profile']==='wan_video_media_v1'&&($config['video']['request_shape']??'')!=='dashscope_task')$matches=false;
        }
        if(!$matches)return ['missing'=>['current_driver_differs_from_reviewed_direct_profile']];
        if(!isset($config[$group])||!is_array($config[$group]))return ['missing'=>['current_effective_driver_configuration_required']];
        $limits=[];
        foreach(['images','videos','audios']as$kind){
            $key='max_reference_'.$kind;$cap=$model[$key]??null;
            if((!is_int($cap)&&(!is_string($cap)||!preg_match('/^(?:0|[1-9][0-9]*)$/D',$cap)))
                ||($cap=filter_var($cap,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]))===false)
                return ['missing'=>['typed_current_material_capability_required']];
            $saved=$metadata[$key]??$metadata['capabilities'][$key]??$upstream[$key]??null;
            if($saved!==null&&(int)$saved!==$cap)return ['missing'=>['saved_and_current_material_capability_differ']];
            $limits[$key]=$cap;
        }
        unset($defaults['api_config']);
        $binding=['version'=>1,'kind'=>'owned_platform_direct_execution','artifact_sha256'=>$evidence['artifact_sha256'],
            'platform_model_id'=>self::id($model['platform_model_id']),'model_code'=>$profile['model_code'],
            'channel_code'=>$profile['channel_code'],'upstream_model'=>$model['upstream_model'],'observed_at'=>$model['observed_at'],
            'configuration_fingerprint'=>MarketGenerationReviewedContract::digest([$model,$channel]),
            'channel_mapping_enabled'=>false,'fallback_configured'=>false,'material_limits'=>$limits,
            'ui_default_params_state'=>$defaultState,'public_artifact_sha256'=>$evidence['public_artifact_sha256']??null,
            'routing_context_artifact_sha256'=>$evidence['routing_context_artifact_sha256']??null,
            'smart_route_context'=>['request_control'=>'omitted','smart_route_supported'=>$route['smart_route_supported'],
                'smart_route_tenant_force_enabled'=>$route['smart_route_tenant_force_enabled'],'smart_route_user_force_enabled'=>$route['smart_route_user_force_enabled']]];
        return ['missing'=>[],'binding'=>$binding,'config'=>$config,'public_defaults'=>$defaults,'upstream_model'=>$model['upstream_model']];
    }

    private static function id($value): int
    {return (is_int($value)||is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value))?(filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0):0;}
    private static function flag($value): ?bool
    {return in_array($value,[false,0,'0'],true)?false:(in_array($value,[true,1,'1'],true)?true:null);}
    private static function object($value): array
    {
        if(is_string($value)){$decoded=json_decode($value,true);if(!is_array($decoded))throw new InvalidArgumentException('当前执行配置 JSON 无效');$value=$decoded;}
        if(!is_array($value))throw new InvalidArgumentException('当前执行配置必须为对象');
        foreach($value as$key=>$child)if(is_string($child)&&preg_match('/^\s*[\[{]/',$child)){
            $decoded=json_decode($child,true);if(is_array($decoded))$value[$key]=self::object($decoded);
        }elseif(is_array($child))$value[$key]=self::object($child);
        return $value;
    }
    private static function assertNoCredentials(array $value): void
    {
        foreach($value as$key=>$child){
            if(is_string($key)&&preg_match('/^(?:api_?key|api_?secret|access_token|authorization|password|private_key|secret|credentials)$/i',$key))throw new InvalidArgumentException('执行证据不得包含凭证');
            if(is_array($child))self::assertNoCredentials($child);
        }
    }
}
