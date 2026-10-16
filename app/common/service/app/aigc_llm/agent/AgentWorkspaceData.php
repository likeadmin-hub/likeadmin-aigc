<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;
use think\facade\Db;

/** Called only by explicit clear-data uninstall; soft uninstall retains all conversations. */
final class AgentWorkspaceData
{
    public static function clear(string $app, int $tenant = 0): void
    {
        if(!in_array($app,['aigc_llm','aigc_canvas'],true))throw new \InvalidArgumentException('Unknown conversation workspace');
        $exists=static fn($name)=>Db::query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[config('database.connections.mysql.prefix').$name]);
        if(!$exists('aigc_llm_session'))return;
        $scoped=isset(Db::name('aigc_llm_session')->getFields()['workspace_key']);
        if(!$scoped&&$app==='aigc_canvas')return;
        Db::transaction(static function()use($app,$scoped,$exists,$tenant){
            $sessions=Db::name('aigc_llm_session');
            if($tenant>0)$sessions->where('tenant_id',$tenant);
            if($scoped)$sessions=$app==='aigc_canvas'?$sessions->whereLike('workspace_key','canvas:%'):$sessions->where('workspace_key','home');
            $ids=$sessions->column('id');
            if($ids){
                if($exists('aigc_llm_agent_artifact'))Db::name('aigc_llm_agent_artifact')->whereIn('session_id',$ids)->delete();
                if($exists('aigc_llm_agent_run')){
                    $runs=Db::name('aigc_llm_agent_run')->whereIn('session_id',$ids)->column('id');
                    if($runs)foreach(['event','step'] as $suffix){
                        $name='aigc_llm_agent_'.$suffix;
                        if($exists($name))Db::name($name)->whereIn('run_id',$runs)->delete();
                    }
                    Db::name('aigc_llm_agent_run')->whereIn('session_id',$ids)->delete();
                }
                foreach(['message','usage'] as $suffix)if($exists('aigc_llm_'.$suffix))Db::name('aigc_llm_'.$suffix)->whereIn('session_id',$ids)->delete();
                Db::name('aigc_llm_session')->whereIn('id',$ids)->delete();
            }
            if($exists('aigc_llm_agent_preference')){
                $prefs=Db::name('aigc_llm_agent_preference');
                if($tenant>0)$prefs->where('tenant_id',$tenant);
                if(isset($prefs->getFields()['workspace_key']))$prefs->where('workspace_key',$app)->delete();
                elseif($app==='aigc_llm')$prefs->where('id','>',0)->delete();
            }
        });
    }
}
