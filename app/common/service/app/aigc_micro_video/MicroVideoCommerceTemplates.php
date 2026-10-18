<?php
namespace app\common\service\app\aigc_micro_video;

use RuntimeException;
use think\facade\Db;

/** Trusted, bundled examples only. Import creates workbench records, never generation tasks. */
final class MicroVideoCommerceTemplates
{
    private static ?array $catalog=null;

    public static function catalog(): array
    {
        if(self::$catalog!==null)return self::$catalog;
        $path=dirname(__DIR__,4).'/apps/aigc_micro_video/resources/commerce_templates.json';
        if(!is_file($path)||filesize($path)>1048576)throw new RuntimeException('商品短视频内置模板文件缺失或过大');
        try{$catalog=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);}
        catch(\JsonException $error){throw new RuntimeException('商品短视频内置模板格式无效',0,$error);}
        self::validate($catalog);
        return self::$catalog=$catalog;
    }

    private static function validate(mixed $catalog): void
    {
        $check=static function(bool $valid): void {if(!$valid)throw new RuntimeException('商品短视频内置模板内容无效');};
        $text=static fn(mixed $value): bool=>is_string($value)&&trim($value)!=='';
        $code=static fn(mixed $value): bool=>is_string($value)&&preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$value)===1;
        $check(is_array($catalog)&&($catalog['version']??null)===1);
        $check(is_array($catalog['categories']??null)&&array_is_list($catalog['categories'])&&count($catalog['categories'])===10);
        $check(is_array($catalog['templates']??null)&&array_is_list($catalog['templates'])&&count($catalog['templates'])===31);
        $categories=[];
        foreach($catalog['categories'] as $category){
            $check(is_array($category)&&$code($category['code']??null)&&$text($category['name']??null));
            $check(!isset($categories[$category['code']])&&!in_array($category['code'],['story','campus','office','palace','kungfu','twist','commerce','property'],true));
            $categories[$category['code']]=true;
        }
        $seen=[];
        foreach($catalog['templates'] as $template){
            $check(is_array($template)&&$code($template['code']??null)&&!isset($seen[$template['code']]));$seen[$template['code']]=true;
            foreach(['title','style','description','script','reference_rules','internal_prompt'] as $field)$check($text($template[$field]??null));
            $check(($template['protected_prompt']??null)===true);
            $check(mb_strlen($template['title'])<=120&&isset($categories[$template['category']??'']));
            $check(in_array($template['default_duration']??null,[10,15],true)&&($template['ratio']??null)==='9:16'&&($template['workflow_type']??null)==='commerce');
            $check(is_string($template['cover_image']??null)&&preg_match('#^/resource/image/apps/aigc_micro_video/templates/commerce/'.preg_quote($template['code'],'#').'\.(?:png|jpe?g|webp)$#D',$template['cover_image'])===1);
            $check(in_array($template['direction_code']??null,['free','palace','kungfu','campus','office','twist','product'],true));
            $check(is_array($template['segments']??null)&&array_is_list($template['segments'])&&count($template['segments'])>0&&count($template['segments'])<=24);
            $duration=0;
            foreach($template['segments'] as $index=>$segment){
                $check(is_array($segment)&&($segment['sort']??null)===$index+1&&is_int($segment['duration']??null)&&$segment['duration']>0);
                foreach(['title','prompt','start_state','end_state','continuity'] as $field)$check($text($segment[$field]??null));
                $check(($segment['image_indices']??null)===[]);$duration+=$segment['duration'];
            }
            $check($duration===$template['default_duration']);
            $check(is_array($template['beats']??null)&&array_is_list($template['beats'])&&count($template['beats'])>0&&count($template['beats'])<=24);$end=0;
            foreach($template['beats'] as $beat){
                $check(is_array($beat)&&$text($beat['name']??null)&&$text($beat['description']??null));
                $check((is_int($beat['start']??null)||is_float($beat['start']??null))&&(is_int($beat['end']??null)||is_float($beat['end']??null))&&$beat['start']==$end&&$beat['end']>$beat['start']);$end=$beat['end'];
            }
            $check($end==$template['default_duration']);
            $check(is_array($template['roles']??null)&&array_is_list($template['roles'])&&count($template['roles'])>0&&count($template['roles'])<=60);$roles=[];
            foreach($template['roles'] as $role){
                $check(is_array($role)&&$code($role['role_id']??null)&&!isset($roles[$role['role_id']])&&$text($role['name']??null)&&$text($role['description']??null));$roles[$role['role_id']]=true;
                $check(in_array($role['group']??null,['lead','support','crowd'],true)&&($role['fixed_identity']??null)===true&&($role['images']??null)===[]);
            }
            $check(is_array($template['variables']??null)&&array_is_list($template['variables']));
            foreach($template['variables'] as $variable)$check($text($variable));
            $check(strlen(MicroVideoStore::encode($template))<240000);
        }
    }

    /** Called only while MicroVideoWorkbenchTemplates holds the existing tenant seed lock. */
    public static function seed(int $tenant,array $directions): void
    {
        $templates=self::catalog()['templates'];$active=[];
        foreach($directions as $direction)if((int)($direction['status']??0)===1&&(int)($direction['delete_time']??0)===0)$active[$direction['code']]=$direction;
        $fallback=$active['free']??['id'=>0,'prompt'=>'遵循用户创作意图，不额外加入题材。','continuity_prompt'=>MicroVideoSettings::defaults()['continuity_template']];
        Db::transaction(static function()use($tenant,$templates,$active,$fallback){
            foreach($templates as $template){
                $seed='commerce_v1_'.$template['code'];
                // Include disabled and soft-deleted rows: tenant decisions must never be resurrected.
                $exists=MicroVideoWorkbenchRecords::table()->where(['tenant_id'=>$tenant,'user_id'=>0,'kind'=>'template'])->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(body_json, '$.seed_key')) = ?",[$seed])->find();
                if($exists)continue;
                $direction=$active[$template['direction_code']]??$fallback;
                $body=$template;unset($body['code'],$body['title']);
                $body+=['seed_key'=>$seed,'direction_id'=>(int)$direction['id'],'direction_rules'=>(string)$direction['prompt'],'durations'=>[$template['default_duration']],'example_video'=>'','images'=>[]];
                $body['reference_rules']=trim($template['reference_rules']."\n".(string)($direction['continuity_prompt']??''));
                MicroVideoWorkbenchRecords::saveTrustedTemplate($tenant,['kind'=>'template','title'=>$template['title'],'visibility'=>'tenant','status'=>1,'body'=>$body]);
            }
        });
    }
}
