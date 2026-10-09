<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Shared capability contract for market administration and generation adapters. */
final class MarketModelCapabilities
{
    public const KEY = 'local_capability_overrides';

    public static function fields(): array
    {
        return [
            'supports_vision'=>['视觉理解','bool',['vision','supports_image_input']],
            'supports_reasoning'=>['深度思考','bool',['supports_thinking','reasoning']],
            'supports_audio'=>['音频理解','bool',['supports_audio_input']],
            'supports_audio_output'=>['音频输出','bool',['supports_audio_generation','supports_generate_audio']],
            'supports_video'=>['视频理解','bool',['supports_video_input']],
            'supports_tools'=>['工具调用','bool',['supports_function_calling','function_calling','tool_calling']],
            'supports_structured_output'=>['结构化输出','bool',['supports_json','json_mode','structured_output']],
            'supports_streaming'=>['流式输出','bool',['supports_stream','streaming']],
            'supports_search'=>['联网搜索','bool',['supports_web_search','web_search']],
            'supports_reference_images'=>['参考图生成','bool',[]],
            'supports_first_last_frame'=>['首尾帧生成','bool',[]],
            'input_modalities'=>['输入模态','list',['supported_input_modalities']],
            'output_modalities'=>['输出模态','list',['supported_output_modalities']],
            'context_window'=>['上下文长度（token）','int',['context_length','max_context_tokens']],
            'max_input_tokens'=>['最大输入（token）','int',[]],
            'max_output_tokens'=>['最大输出（token）','int',['max_completion_tokens','max_tokens']],
            'max_reference_images'=>['参考图上限','int',['max_reference_image_count','reference_image_limit']],
            'max_reference_audios'=>['参考音频上限','int',['max_reference_audio_count','reference_audio_limit']],
            'max_reference_videos'=>['参考视频上限','int',['max_reference_video_count','reference_video_limit']],
            'max_reference_assets'=>['参考素材总上限','int',['max_reference_files']],
            'supported_ratios'=>['画面比例','list',['ratio_options','ratios']],
            'supported_durations'=>['视频时长（秒）','list',['duration_options','durations']],
            'supported_resolutions'=>['输出分辨率','list',['resolution_options','resolutions']],
            'input_modes'=>['生成模式','list',['generation_modes']],
            'supported_asset_types'=>['参考素材类型','list',[]],
        ];
    }

    public static function arrayValue($value): array
    {
        return is_array($value) ? $value : (is_string($value) ? (json_decode($value,true) ?: []) : []);
    }

    public static function overrides(array $product): array
    {
        $s=self::arrayValue($product['source_payload']??[]);$record=$s[self::KEY]??[];
        if (($product['resource_type']??'')!=='model' || ($record['identity']??null)!==MarketModelCapabilityVerification::identity($product)) return [];
        try { return self::validate((array)($record['values']??[])); } catch (InvalidArgumentException) { return []; }
    }

    public static function validate(array $values): array
    {
        $fields=self::fields();$result=[];
        foreach($values as $key=>$value){
            if(!isset($fields[$key])) throw new InvalidArgumentException('未知模型能力字段：'.$key);
            if($value===null) continue;
            $type=$fields[$key][1];
            if($type==='bool' && !is_bool($value)) throw new InvalidArgumentException('能力开关必须为布尔值');
            if($type==='int' && (!is_int($value)||$value<0||$value>100000000)) throw new InvalidArgumentException('能力上限必须为有效的非负整数');
            if($type==='list'){
                if(!is_array($value)||count($value)>100) throw new InvalidArgumentException('能力选项必须为列表');
                foreach($value as $item) if(!is_string($item)||strlen($item)>100||trim($item)==='') throw new InvalidArgumentException('能力选项格式不正确');
                $value=array_values(array_unique(array_map('trim',$value)));
            }
            $result[$key]=$value;
        }
        return $result;
    }

    private static function normalize($value,string $type)
    {
        if($value===null) return null;
        if($type==='bool') {
            if(is_bool($value))return $value;
            if(in_array($value,[1,'1','true','yes','on','supported'],true))return true;
            if(in_array($value,[0,'0','false','no','off','unsupported'],true))return false;
            return null;
        }
        if($type==='int') return is_numeric($value)&&(int)$value>0?(int)$value:null;
        if(is_string($value))$value=preg_split('/[,|\s]+/',$value,-1,PREG_SPLIT_NO_EMPTY);
        if(!is_array($value)||$value===[])return null;
        $out=[];foreach($value as $item)if(is_string($item)||is_numeric($item))$out[]=(string)$item;
        return $out?:null;
    }

    public static function profile(array $product): array
    {
        $snapshot=self::arrayValue($product['source_payload']??[]);
        $meta=self::arrayValue($snapshot['market_metadata']??[]);
        $upstream=self::arrayValue($meta['upstream_metadata']??[]);
        // Prefer the original response: historical flattened snapshots defaulted missing flags to false.
        $sources=$upstream!==[]?[$upstream,self::arrayValue($upstream['capabilities']??[])]:[$meta,self::arrayValue($meta['capabilities']??[]),self::arrayValue($snapshot['resource']??[])];
        $schema=self::arrayValue($meta['params_schema']??$upstream['params_schema']??[]);
        $schema=self::arrayValue($schema['properties']??$schema);
        $overrides=self::overrides($product);$rows=[];
        foreach(self::fields() as $key=>[$label,$type,$aliases]){
            $value=null;
            foreach($sources as $source){foreach([$key,...$aliases] as $alias){if(array_key_exists($alias,$source)){$value=self::normalize($source[$alias],$type);if($value!==null)break;}}if($value!==null)break;}
            $upstreamValue=$value;$origin=$value===null?'unknown':'upstream';
            $schemaKeys=['supports_tools'=>['tools','functions'],'supports_structured_output'=>['response_format'],'supports_streaming'=>['stream'],'supports_search'=>['enable_search'],'supports_reasoning'=>['enable_thinking']];
            if($value===null)foreach($schemaKeys[$key]??[] as $param)if(isset($schema[$param])){$value=true;$origin='schema';break;}
            if($key==='supports_vision' && MarketModelCapabilityVerification::vision($product)){$value=true;$origin='verified';}
            if(array_key_exists($key,$overrides)){$value=$overrides[$key];$origin='manual';}
            $rows[]=['key'=>$key,'label'=>$label,'type'=>$type,'value'=>$value,'upstream_value'=>$upstreamValue,'source'=>$origin,'override'=>$overrides[$key]??null];
        }
        $parameters=[];
        foreach($schema as $name=>$spec){
            if(!is_string($name)||self::sensitive($name)||!is_array($spec))continue;
            $parameters[]=['name'=>$name,'type'=>is_string($spec['type']??null)?$spec['type']:'','description'=>mb_substr((string)($spec['description']??''),0,500)];
        }
        // Expose provider extensions as inert data; never return credentials/default payloads.
        $extra=[];foreach(self::arrayValue($upstream['capabilities']??$meta['capabilities']??[]) as $key=>$value){
            if(is_string($key)&&!isset(self::fields()[$key])&&!self::sensitive($key)&&(is_scalar($value)||$value===null))$extra[$key]=$value;
        }
        return ['fields'=>$rows,'protocols'=>self::normalize($meta['protocols']??$upstream['protocols']??[],'list')??[],
            'parameters'=>$parameters,'extra'=>$extra,'updated_at'=>(int)($snapshot[self::KEY]['updated_at']??0)];
    }

    private static function sensitive(string $key): bool
    {
        return preg_match('/api.?key|secret|password|authorization|credential|access.?token/i',$key)===1;
    }

    /** Existing adapters retain their protocol/SKU logic; only known local corrections override it. */
    public static function applyMetadata(array $product,array $metadata): array
    {
        $values=self::overrides($product);
        if(MarketModelCapabilityVerification::vision($product)&&!array_key_exists('supports_vision',$values))$values['supports_vision']=true;
        if ($values === []) return $metadata;
        $metadata['capabilities']=self::arrayValue($metadata['capabilities']??[]);
        foreach($values as $key=>$value){$metadata[$key]=$value;$metadata['capabilities'][$key]=$value;}
        $metadata['_local_capability_overrides']=$values;
        return $metadata;
    }

    public static function carryForward(array $snapshot,array $old,array $new): array
    {
        unset($snapshot[self::KEY]);
        $oldSnapshot=self::arrayValue($old['source_payload']??[]);
        if(MarketModelCapabilityVerification::identity($old)===MarketModelCapabilityVerification::identity($new)&&isset($oldSnapshot[self::KEY])){
            $record=$oldSnapshot[self::KEY];$record['values']=self::overrides($old);$snapshot[self::KEY]=$record;
        }
        return $snapshot;
    }
}
