<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use think\facade\Db;
use app\common\service\open_platform\OpenAssets;

/** Bounded continuations inside one owned run; never submits or authorizes a paid task. */
final class AgentSequenceService
{
    public static function state(array $run): array
    {
        return (array)(AgentStore::json($run['preferences'])['_sequence'] ?? []);
    }
    public static function configure(array $run,array $items): array
    {
        if(!array_is_list($items)||count($items)<1||count($items)>3)throw new \InvalidArgumentException('最多安排3个依赖前一步结果的后续步骤');
        $available=AgentCapabilityCatalog::all((int)$run['tenant_id'],(int)$run['user_id']);$stages=[];
        foreach($items as $item){
            $item=(array)$item;$code=$item['tool_code']??'';
            if(!is_string($code)||(!isset($available[$code])&&$code!=='document'))throw new \InvalidArgumentException('后续步骤只能使用首页已接入工具或文档创作，不能调用工作室应用');
            foreach(['title'=>120,'instruction'=>2000] as $key=>$max)if(!is_string($item[$key]??null)||trim($item[$key])===''||mb_strlen($item[$key])>$max)throw new \InvalidArgumentException('请提供简洁且完整的后续步骤说明');
            $stages[]=['tool_code'=>$code,'title'=>trim($item['title']),'instruction'=>trim($item['instruction'])];
        }
        return Db::transaction(static function()use($run,$stages){
            $current=self::lock($run);$preferences=AgentStore::json($current['preferences']);$state=self::state($current);
            if($state){
                if(($state['original']??[])!==$stages)throw new \InvalidArgumentException('后续安排已保存，请完成当前步骤；调整目标请重新发起请求');
            }else{
                $preferences['_sequence']=['original'=>$stages,'pending'=>$stages,'active'=>null,'completed'=>[],'step_floor'=>0,'artifact_floor'=>0,'replies'=>[],'state'=>'planned'];
                AgentStore::update($run,['preferences'=>AgentStore::encode($preferences)]);
                AgentStore::event($run,'sequence.planned',['stages'=>array_column($stages,'title')]);
            }
            return ['scheduled'=>true,'instruction'=>'后续安排已保存。现在只为当前已具备素材的步骤调用 plan_creation；缺资料先询问。系统会在当前生成成功后恢复执行后续步骤，每次按累计预算或确认按钮授权。后续尚未估价，不能声称费用已确认。'];
        });
    }
    private static function lock(array $run): array
    {
        $current=AgentStore::owner('run',(int)$run['tenant_id'],(int)$run['user_id'])->where('id',$run['id'])->lock(true)->find();
        if(!$current||empty($run['lease_token'])||$current['lease_token']!==$run['lease_token']||(int)$current['cancel_requested'])throw new \InvalidArgumentException('已停止后续执行');
        return $current;
    }
    public static function assertTool(array $run,string $code): void
    {
        $active=self::state($run)['active']??null;
        if($active&&$active['tool_code']!==$code)throw new \InvalidArgumentException('当前后续步骤只允许执行'.$active['title'].'，不能新增其他收费工具');
    }
    public static function prefix(array $run): string
    {
        $replies=self::state($run)['replies']??[];
        return $replies?implode("\n\n",$replies)."\n\n":'';
    }
    /** Only actual files collected for this run may become the next stage's inputs. */
    public static function outputs(array $run): array
    {
        $rows=AgentStore::owner('artifact',(int)$run['tenant_id'],(int)$run['user_id'])->where('run_id',$run['id'])->order('id','desc')->limit(16)->select()->toArray();$out=[];
        foreach($rows as $row){
            try{$asset=OpenAssets::detail((int)$run['tenant_id'],(int)$run['user_id'],(string)(AgentStore::json($row['data'])['file_id']??''));}
            catch(\app\common\service\open_platform\OpenException){continue;}
            $out[]=['artifact_id'=>(int)$row['id'],'step_id'=>(int)$row['step_id'],'title'=>$row['title'],'kind'=>$row['kind']]+array_intersect_key($asset,array_flip(['file_id','mime','url']));
        }
        return $out;
    }
    /** Called only after this stage's jobs have finished. Atomic with phase/lease advancement. */
    public static function advance(array $run): bool
    {
        return Db::transaction(static function()use($run){
            $current=self::lock($run);$preferences=AgentStore::json($current['preferences']);$state=self::state($current);
            if(!$state)return false;
            $steps=AgentStore::owner('step',(int)$run['tenant_id'],(int)$run['user_id'])->where('run_id',$run['id'])->where('id','>',(int)$state['step_floor'])->select()->toArray();
            foreach($steps as $step)if($step['status']!=='success'||(AgentStore::json($step['result'])['status']??'')==='partial_success')return false;
            $artifacts=AgentStore::owner('artifact',(int)$run['tenant_id'],(int)$run['user_id'])->where('run_id',$run['id'])->where('id','>',(int)$state['artifact_floor'])->select()->toArray();
            // A clarification, a fictional plan or a completed job without output cannot trigger another charge.
            $document=($state['active']['tool_code']??'')==='document';
            $usable=$document?array_filter($artifacts,static fn($a)=>$a['kind']==='document'):array_filter($artifacts,static fn($a)=>in_array((int)$a['step_id'],array_map('intval',array_column($steps,'id')),true));
            if(!$usable||(!$document&&!$steps)){
                $state['state']='needs_input';$preferences['_sequence']=$state;
                AgentStore::update($run,['preferences'=>AgentStore::encode($preferences)]);return false;
            }
            if($state['active'])$state['completed'][]=$state['active'];
            if(!$state['pending']){
                $state['active']=null;$state['state']='completed';$preferences['_sequence']=$state;
                AgentStore::update($run,['preferences'=>AgentStore::encode($preferences)]);return false;
            }
            $state['active']=array_shift($state['pending']);$state['state']='continuing';
            $stageReply=trim(substr((string)$current['reply'],strlen(self::prefix($current))));
            if($stageReply!=='')$state['replies'][]=$stageReply;
            $state['step_floor']=max(array_merge([(int)$state['step_floor']],array_map('intval',array_column($steps,'id'))));
            $state['artifact_floor']=max(array_merge([(int)$state['artifact_floor']],array_map('intval',array_column($artifacts,'id'))));
            $preferences['_sequence']=$state;
            AgentStore::update($run,['preferences'=>AgentStore::encode($preferences),'phase'=>'chat','status'=>'queued','approval_hash'=>'','next_run_time'=>0,'lease_token'=>'','lease_until'=>0]);
            AgentStore::event($run,'sequence.continuing',['title'=>$state['active']['title']]);
            return true;
        });
    }
    public static function view(array $run): ?array
    {
        $state=self::state($run);if(!$state)return null;
        return ['state'=>$state['state'],'active'=>$state['active']['title']??'','pending'=>array_column($state['pending'],'title'),'completed'=>array_column($state['completed'],'title')];
    }
}
