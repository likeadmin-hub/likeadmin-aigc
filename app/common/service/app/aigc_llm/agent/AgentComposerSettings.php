<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\power\MarketTextModelRuntimeService;

/** Server-owned text routing and bounded creation preferences for new runs only. */
final class AgentComposerSettings
{
    public static function textOptions(int $tenant): array
    {
        $config=AigcLlmService::config($tenant);
        $options=$config['option_config']??[];
        $options['models']=array_values(array_filter($options['models']??[],static function($model)use($tenant){
            try{MarketTextModelRuntimeService::resolveAgentModel($tenant,(string)$model['code']);return true;}catch(\Exception){return false;}
        }));
        $configured=trim((string)($config['config_json']['agent_text_model']??''));
        // Empty legacy configuration follows the first eligible backend catalogue default.
        $codes=array_column($options['models'],'code');
        $default=(string)($options['defaults']['model']??'');
        $options['defaults']['model']=$configured!==''?$configured:(in_array($default,$codes,true)?$default:(string)($codes[0]??''));
        return $options;
    }
    public static function textModel(int $tenant,bool $vision=false): string
    {
        $model=(string)(self::textOptions($tenant)['defaults']['model']??'');
        if($model==='')throw new \InvalidArgumentException('后台尚未配置可用的 Agent 文本模型');
        try{MarketTextModelRuntimeService::resolveAgentModel($tenant,$model,$vision);}catch(\Exception $e){throw new \InvalidArgumentException('后台 Agent 文本模型不可用：'.$e->getMessage());}
        return $model;
    }
    public static function generation($input): array
    {
        if(!is_array($input))throw new \InvalidArgumentException('生成偏好无效');
        $mode=$input['mode']??'auto';
        if(!in_array($mode,['auto','custom'],true))throw new \InvalidArgumentException('生成偏好模式无效');
        $out=['mode'=>$mode];
        foreach(['image'=>['ratio','quality','quantity'],'video'=>['ratio','resolution','duration']] as $type=>$fields){
            $values=$input[$type]??[];
            if(!is_array($values))throw new \InvalidArgumentException('生成参数无效');
            $out[$type]=[];
            foreach($fields as $key){
                $value=$values[$key]??'';
                if($value===''||$value===null)continue;
                if(in_array($key,['quantity','duration'],true)){
                    if(filter_var($value,FILTER_VALIDATE_INT)===false || (int)$value<1 || (int)$value>($key==='quantity'?4:60))throw new \InvalidArgumentException('生成数量或时长无效');
                    $value=(int)$value;
                }elseif(!is_string($value)||strlen($value)>40||!preg_match('/^[a-zA-Z0-9_:.-]+$/D',$value))throw new \InvalidArgumentException('生成规格无效');
                $out[$type][$key]=$value;
            }
        }
        return $out;
    }
    public static function parameters(array $preferences,string $code): array
    {
        $generation=$preferences['generation']??[];
        if(($generation['mode']??'auto')!=='custom')return [];
        $type=match($code){'aigc_image'=>'image','aigc_video'=>'video',default=>''};
        return $type===''?[]:(self::generation($generation)[$type]??[]);
    }
}
