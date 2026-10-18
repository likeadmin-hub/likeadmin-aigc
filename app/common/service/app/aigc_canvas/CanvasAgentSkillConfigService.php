<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_canvas;
use app\common\model\app\TenantAppConfig;
use app\common\service\app\AppDisplayConfigService as Display;
use app\common\service\app\aigc_llm\agent\{AgentWorkspaceScope as Scope,AgentSkillConfigService as Skills};
use think\facade\Db;

/** One-time compatibility snapshot; subsequent home edits never affect canvas. */
final class CanvasAgentSkillConfigService
{
    private const SCOPE=['app'=>'aigc_canvas','project_id'=>0,'page_id'=>'entry'];
    public static function stored(int $tenant): array
    {
        $row=TenantAppConfig::where(['tenant_id'=>$tenant,'app_code'=>'aigc_canvas'])->findOrEmpty();
        if(!$row->isEmpty() && array_key_exists('agent_skills',(array)$row['extra']))return (array)$row['extra']['agent_skills'];
        // Existing installations keep their effective templates, frozen at first upgrade access.
        $items=Scope::within([],fn()=>Skills::detail($tenant));$snapshot=[];
        foreach($items as $item)$snapshot[$item['id']]=array_intersect_key($item,array_flip(['app_code','name','description','prompt','materials','enabled','sort','images','videos','preview_type','video_poster']));
        // Preserve deleted builtins as well as enabled/disabled custom skills.
        $llm=\app\common\model\app\aigc_llm\AigcLlmConfig::where('tenant_id',$tenant)->findOrEmpty();
        foreach((array)($llm['config_json']['agent_skills']??[]) as $id=>$value)if(!empty($value['deleted']))$snapshot[$id]=['deleted'=>true];
        return Db::transaction(function()use($tenant,$snapshot){
            $row=TenantAppConfig::where(['tenant_id'=>$tenant,'app_code'=>'aigc_canvas'])->lock(true)->findOrEmpty();
            if($row->isEmpty()){
                Display::save($tenant,'aigc_canvas',[]);
                $row=TenantAppConfig::where(['tenant_id'=>$tenant,'app_code'=>'aigc_canvas'])->lock(true)->findOrEmpty();
            }
            $extra=(array)$row['extra'];
            if(!array_key_exists('agent_skills',$extra)){$extra['agent_skills']=$snapshot;$extra['agent_skills_version']=1;$row->save(['extra'=>$extra,'update_time'=>time()]);}
            return (array)$extra['agent_skills'];
        });
    }
    public static function detail(int $tenant): array { return Scope::within(self::SCOPE,fn()=>Skills::detail($tenant)); }
    public static function save(int $tenant,array $params): void
    {
        if(!array_intersect(['skill_configs','skill_create','skill_delete_id'],array_keys($params)))return;
        self::stored($tenant);
        Db::transaction(function()use($tenant,$params){
            $row=TenantAppConfig::where(['tenant_id'=>$tenant,'app_code'=>'aigc_canvas'])->lock(true)->findOrEmpty();
            $next=Scope::within(self::SCOPE,fn()=>Skills::apply($tenant,$params));
            $extra=(array)$row['extra'];$extra['agent_skills']=$next;$extra['agent_skills_version']=1;
            $row->save(['extra'=>$extra,'update_time'=>time()]);
        });
    }
}
