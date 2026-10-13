<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

/** Deliberately narrow first adapters: new music and user-supplied-model fitting. */
final class AgentExtendedTools
{
    public static function schema(string $code): array
    {
        $text=static fn(int $max)=>['type'=>'string','maxLength'=>$max];
        $files=static fn(int $max)=>['type'=>'array','minItems'=>1,'maxItems'=>$max,'items'=>['type'=>'string','pattern'=>'^of_[a-f0-9]{32}$']];
        if($code==='aigc_fitting')return ['type'=>'object','additionalProperties'=>false,'required'=>['garment_images','model_images'],'properties'=>[
            'prompt'=>$text(4000),'garment_images'=>$files(2)+['description'=>'服装素材 file_id，必须与模特图区分'],
            'model_images'=>$files(1)+['description'=>'用户指定的模特素材 file_id'],
            'upload_category'=>['type'=>'string','enum'=>['full','top','bottom','dress','suit']],
            'quality'=>$text(30),'ratio'=>$text(30),'quantity'=>['type'=>'integer','enum'=>[1]],
        ]];
        if($code==='aigc_music')return ['type'=>'object','additionalProperties'=>false,'required'=>['prompt'],'properties'=>[
            'prompt'=>['type'=>'string','minLength'=>1,'maxLength'=>4000],'title'=>$text(120),'lyrics'=>$text(10000),
            'genre'=>$text(100),'mood'=>$text(100),'instruments'=>$text(200),'language'=>$text(50),
            'custom'=>['type'=>'boolean'],'instrumental'=>['type'=>'boolean'],
            'duration'=>['type'=>'integer','minimum'=>5,'maximum'=>600],
        ]];
        throw new \InvalidArgumentException('未知工具');
    }
}
