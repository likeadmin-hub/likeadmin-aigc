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
        $denied=AppAccessService::assertTenantCanUse($tenant,'aigc_llm',$user,true,false);
        if($denied) throw new \InvalidArgumentException('当前账号暂不可使用 Agent');
        if((int)\app\common\service\app\aigc_llm\AigcLlmService::config($tenant)['status']!==1) throw new \InvalidArgumentException('对话应用已停用');
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
        // Only historical run snapshots retain a budget; new preferences omit it.
        // Never expand the authorization of a previously submitted paid task.
        return ($preferences['mode']??'ask')!=='auto'
            || (array_key_exists('budget',$preferences)&&$total>(float)$preferences['budget']);
    }
    public static function preferences(int $tenant,int $user,?array $input=null): array
    {
        $row=self::owner('preference',$tenant,$user)->find();
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
            if($row)self::owner('preference',$tenant,$user)->update($values);
            else self::table('preference')->insert($values+['tenant_id'=>$tenant,'user_id'=>$user,'create_time'=>time()]);
        }
        return $value;
    }
    public static function validatePreferences(array $input): array
    {
        $mode=(string)($input['mode']??'ask');
        if(!in_array($mode,['ask','auto'],true))throw new \InvalidArgumentException('运行模式无效');
        $models=[];
        foreach(['text','image','video','voice','music'] as $type){$model=$input['models'][$type]??'';if(!is_scalar($model)||strlen((string)$model)>120)throw new \InvalidArgumentException('模型偏好无效');$models[$type]=trim((string)$model);}
        return ['mode'=>$mode,'models'=>$models];
    }
}
