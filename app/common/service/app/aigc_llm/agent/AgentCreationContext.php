<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

/** Small, authoritative context; never expose the whole marketplace to the planner. */
final class AgentCreationContext
{
    public static function selectedModel(array $options,string $selection): array
    {
        foreach (($options['channels']??$options['models']??[]) as $model) {
            if (!in_array($selection,array_map('strval',[$model['id']??'', $model['value']??'', $model['code']??'']),true)) continue;
            return ['selection'=>$selection]+array_intersect_key($model,array_flip([
                'name','description','duration_options','resolution_options','ratio_options',
                'default_duration','default_resolution','generation_modes','max_reference_images',
            ]));
        }
        return ['selection'=>$selection,'available'=>false,'instruction'=>'所选模型不可用，不要猜测名称或替换模型。'];
    }

    public static function unsupportedPlanClaim(string $reply): bool
    {
        return (bool)preg_match('/(?:已.{0,8}(?:规划|保存|提交|安排|创建|准备好).{0,12}(?:方案|任务|生成)|(?:回复|输入|点击).{0,12}确认|(?:方案|任务).{0,8}已(?:保存|提交|创建)|(?:无法|不能|暂不支持).{0,8}(?:实际提交|提交生成|生成队列)|(?:plan|task).{0,15}(?:saved|submitted)|(?:reply|click).{0,15}confirm)/iu',$reply);
    }
}
