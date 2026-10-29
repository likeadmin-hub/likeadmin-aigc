<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use think\facade\Db;
use app\common\service\app\AppAccessService;

final class AgentStore
{
    public const TERMINAL = ['success','partial_success','failed','canceled','needs_review'];
    public static function table(string $name) { return Db::name('aigc_llm_agent_'.$name); }
    public static function owner(string $name,int $tenant,int $user) { return self::table($name)->where(['tenant_id'=>$tenant,'user_id'=>$user]); }
    public static function json($value): array { return is_array($value)?$value:(json_decode((string)$value,true)?:[]); }
    public static function encode($value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    public static function access(int $tenant,int $user): void
    {
        if($tenant<1||$user<1) throw new \InvalidArgumentException('请先登录');
        $denied=AppAccessService::assertTenantCanUse($tenant,AgentWorkspaceScope::app(),$user,true,false);
        if($denied) throw new \InvalidArgumentException('当前账号暂不可使用 Agent');
        if(AgentWorkspaceScope::app()==='aigc_llm'&&(int)\app\common\service\app\aigc_llm\AigcLlmService::config($tenant)['status']!==1) throw new \InvalidArgumentException('对话应用已停用');
    }
    public static function run(int $tenant,int $user,int $id): array
    {
        $row=self::owner('run',$tenant,$user)->where('id',$id)->find();
        if(!$row)throw new \InvalidArgumentException('执行记录不存在');
        return $row;
    }
    public static function event(array $run,string $type,array $data): void
    {
        self::table('event')->insert(['tenant_id'=>$run['tenant_id'],'user_id'=>$run['user_id'],'run_id'=>$run['id'],'event_type'=>$type,'payload'=>self::encode($data),'create_time'=>time()]);
    }
    public static function update(array $run,array $values): void
    {
        $query=self::owner('run',(int)$run['tenant_id'],(int)$run['user_id'])->where('id',$run['id']);
        if(!empty($run['lease_token']))$query->where('lease_token',$run['lease_token']);
        if(!$query->update($values+['update_time'=>time()])) {
            $current=self::run((int)$run['tenant_id'],(int)$run['user_id'],(int)$run['id']);
            if(!empty($run['lease_token'])&&$current['lease_token']!==$run['lease_token'])throw new \RuntimeException('执行租约已变更');
        }
    }
    public static function requiresConfirmation(array $preferences,float $total): bool
    {
        // Explicit manual/auto authorization stays intact. Retired cumulative
        // budgets, including frozen old preferences, cannot stop creation.
        return ($preferences['mode']??'ask')!=='auto';
    }
    public static function preferences(int $tenant,int $user,?array $input=null): array
    {
        $filter=AgentWorkspaceScope::filter('aigc_llm_agent_preference');
        $project=(int)(AgentWorkspaceScope::current()['project_id']??0);
        if(AgentWorkspaceScope::app()==='aigc_canvas' && $project>0)self::initializeProjectPreferences($tenant,$user,$project);
        $row=self::owner('preference',$tenant,$user)->where($filter)->find();
        $value=$row?self::json($row['preferences']):['mode'=>'ask','models'=>[]];
        unset($value['budget']); // Retired preference, including reads and favorite-only updates.
        if($input!==null){
            $favorites = array_values(array_filter((array)($value['skill_favorites'] ?? []), 'is_string'));
            if (array_key_exists('favorite_skill', $input)) {
                $change = $input['favorite_skill'];
                if (!is_array($change) || !is_string($change['id'] ?? null) || !in_array($change['favorite'] ?? null, [0,1,'0','1'], true)) throw new \InvalidArgumentException('技能收藏参数无效');
                $id = $change['id'];
                if ((int)$change['favorite'] === 1) {
                    $available = AgentSkillConfigService::forTools($tenant, array_keys(AgentCapabilityCatalog::all($tenant,$user)));
                    if (!array_filter($available, static fn($skill)=>$skill['id']===$id && $skill['enabled'])) throw new \InvalidArgumentException('此技能暂不可收藏');
                    $favorites[] = $id;
                } else $favorites = array_values(array_diff($favorites, [$id]));
                $favorites = array_values(array_unique($favorites));
                if (count($favorites)>100) throw new \InvalidArgumentException('收藏技能数量已达上限');
            } else $value=self::validatePreferences($input);
            $value['skill_favorites'] = $favorites;
            $values=['preferences'=>self::encode($value),'update_time'=>time()];
            if($row)self::owner('preference',$tenant,$user)->where(AgentWorkspaceScope::filter('aigc_llm_agent_preference'))->update($values);
            else self::table('preference')->insert($values+['tenant_id'=>$tenant,'user_id'=>$user,'create_time'=>time()]+AgentWorkspaceScope::filter('aigc_llm_agent_preference'));
        }
        return $value;
    }
    /** Snapshot legacy/entry defaults once; never fall back on another project's settings. */
    public static function initializeProjectPreferences(int $tenant,int $user,int $project): void
    {
        $owner=['tenant_id'=>$tenant,'user_id'=>$user,'workspace_key'=>'canvas:'.$project];
        if(self::table('preference')->where($owner)->find())return;
        Db::transaction(static function()use($tenant,$user,$project,$owner){
            if(!Db::name('aigc_canvas_project')->where(['id'=>$project,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find())throw new \InvalidArgumentException('项目不存在');
            if(self::table('preference')->where($owner)->find())return;
            $legacy=self::owner('preference',$tenant,$user)->where('workspace_key','aigc_canvas')->value('preferences');
            self::table('preference')->insert($owner+['preferences'=>$legacy?:self::encode(['mode'=>'ask','models'=>[]]),'create_time'=>time(),'update_time'=>time()]);
        });
    }
    public static function validatePreferences(array $input): array
    {
        $mode=(string)($input['mode']??'ask');
        if(!in_array($mode,['ask','auto'],true))throw new \InvalidArgumentException('运行模式无效');
        $models=[];
        foreach(['text','image','video','voice','music'] as $type){$model=$input['models'][$type]??'';if(!is_scalar($model)||strlen((string)$model)>120)throw new \InvalidArgumentException('模型偏好无效');$models[$type]=trim((string)$model);}
        $result=['mode'=>$mode,'models'=>$models];
        if(array_key_exists('generation',$input))$result['generation']=AgentComposerSettings::generation($input['generation']);
        return $result;
    }
}
