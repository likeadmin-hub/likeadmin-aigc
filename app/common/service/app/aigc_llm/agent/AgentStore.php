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
    public static function preferences(int $tenant,int $user,?array $input=null): array
    {
        $row=self::owner('preference',$tenant,$user)->find();
        $value=$row?self::json($row['preferences']):['mode'=>'ask','budget'=>0,'models'=>[]];
        if($input!==null){
            $value=self::validatePreferences($input);
            $values=['preferences'=>self::encode($value),'update_time'=>time()];
            if($row)self::owner('preference',$tenant,$user)->update($values);
            else self::table('preference')->insert($values+['tenant_id'=>$tenant,'user_id'=>$user,'create_time'=>time()]);
        }
        return $value;
    }
    public static function validatePreferences(array $input): array
    {
        $mode=(string)($input['mode']??'ask');$budget=$input['budget']??0;
        if(!in_array($mode,['ask','auto'],true)||!is_numeric($budget)||!is_finite((float)$budget)||(float)$budget<0||(float)$budget>1000000)throw new \InvalidArgumentException('运行模式或预算无效');
        if($mode==='auto'&&(float)$budget<=0)throw new \InvalidArgumentException('请先设置自动模式的工具积分预算');
        $models=[];
        foreach(['text','image','video','voice','music'] as $type){$model=$input['models'][$type]??'';if(!is_scalar($model)||strlen((string)$model)>120)throw new \InvalidArgumentException('模型偏好无效');$models[$type]=trim((string)$model);}
        return ['mode'=>$mode,'budget'=>round((float)$budget,4),'models'=>$models];
    }
}
