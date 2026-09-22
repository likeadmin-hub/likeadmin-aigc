<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;

/**
 * Platform-owned short-drama creation workflow.
 *
 * The workflow is intentionally stored in the existing thread settings JSON:
 * it freezes an immutable catalog snapshot for a conversation without adding a
 * second task, billing or worker system. Tenant configuration may only opt in
 * to this platform definition; it can never replace stages or safety rules.
 */
final class ConversationWorkflow
{
    public const KEY = 'short_drama_creation';
    public const VERSION = '2026-09-22.1';

    /** @return array<string,mixed> */
    public static function catalog(): array
    {
        return [
            'key'=>self::KEY, 'version'=>self::VERSION, 'name'=>'短剧成片制作',
            'manual_aliases'=>['/短剧成片制作','/短剧创作','/short-drama','/story-to-drama'],
            'route_keywords'=>['短剧','微短剧','剧本','分集','分镜','角色设定','故事成片'],
            'slots'=>[
                ['key'=>'genre','label'=>'故事类型','ask'=>'想创作什么类型的故事？','options'=>['都市情感','悬疑反转','古风奇幻','甜宠喜剧','科幻冒险']],
                ['key'=>'episode_count','label'=>'集数规模','ask'=>'计划做多少集？','options'=>['1集（短片）','10集（微短剧）','30集（连载短剧）']],
                ['key'=>'episode_duration','label'=>'单集时长','ask'=>'每集大约多长？','options'=>['30秒','1分钟','2分钟','3分钟以上']],
                ['key'=>'visual_style','label'=>'视觉风格','ask'=>'希望整体是什么画风？','options'=>['电影写实','国风水墨','日系动画','都市时尚','奇幻史诗']],
                ['key'=>'audience','label'=>'目标受众','ask'=>'主要给谁看？','options'=>['年轻女性','年轻男性','泛娱乐用户','亲子家庭','海外受众']],
                ['key'=>'characters','label'=>'核心角色','ask'=>'请介绍主角及关键关系。','options'=>[]],
                ['key'=>'ending','label'=>'结局方向','ask'=>'希望故事如何收束？','options'=>['圆满治愈','反转开放','悬念续集','悲剧美学']],
            ],
            'stages'=>[
                ['key'=>'intake','label'=>'创作采集','skills'=>['创作采集'],'creates_nodes'=>false],
                ['key'=>'script','label'=>'剧本与角色设定','skills'=>['剧本创作','角色设定'],'creates_nodes'=>false],
                ['key'=>'art','label'=>'画风与美术规划','skills'=>['画风设计','主体设计','场景设计','道具设计'],'creates_nodes'=>false],
                ['key'=>'assets','label'=>'主体资产','skills'=>['主体图','主体三视图'],'creates_nodes'=>true,'auto_types'=>['image']],
                ['key'=>'storyboard','label'=>'场景与分镜图','skills'=>['场景设计','道具设计','分镜设计','分镜图'],'creates_nodes'=>true,'auto_types'=>['image']],
                ['key'=>'video_plan','label'=>'分镜视频规划','skills'=>['分镜视频规划'],'creates_nodes'=>false],
                ['key'=>'video_nodes','label'=>'分镜视频节点','skills'=>['分镜视频'],'creates_nodes'=>true,'manual_types'=>['video']],
                ['key'=>'audio_plan','label'=>'音频规划','skills'=>['音频规划'],'creates_nodes'=>true,'disabled_types'=>['audio']],
            ],
            'rules'=>[
                'platform_owned'=>true,
                'video_submission'=>'manual_quote_confirmed',
                'audio_submission'=>'disabled',
                'image_auto_submission'=>'requires_plan_confirmation',
            ],
        ];
    }

    /** Resolve a route and create/freeze state during the same thread lock as enqueue. */
    public static function prepare(int $tenant, array $thread, string $content, array $selectedIds, array $attachments, array $preferences): array
    {
        $settings=self::settings($thread);
        $state=self::stateFromSettings($settings);
        $route=self::route($state,$content);
        if ($route===null || !FeatureGate::workflowEnabled($tenant,self::KEY)) return ['workflow'=>[],'thread_settings'=>$settings];

        if ($state===[]) {
            $catalog=self::catalog();
            $state=[
                'workflow_snapshot'=>self::snapshot($catalog,$route,$selectedIds,$attachments,$preferences),
                'stage_state'=>['key'=>'intake','status'=>'collecting','completed'=>[]],
                'slot_values'=>[], 'plan_hash'=>'', 'plan_confirmation'=>['status'=>'not_required'],
                'state_revision'=>1,
            ];
        } elseif (($state['stage_state']['status']??'')==='awaiting_plan_confirmation') {
            throw new RuntimeException('WORKFLOW_PLAN_CONFIRMATION_REQUIRED');
        } elseif (($state['stage_state']['status']??'')==='ready') {
            // A normal conversation send is the only way to execute the next
            // creative stage. Card clicks never impersonate an Agent reply.
            $state['stage_state']['status']='running';
            $state['state_revision']=(int)$state['state_revision']+1;
        }
        self::assertState($state);
        $settings['workflow_state']=$state;
        return ['workflow'=>self::publicState($state),'thread_settings'=>$settings];
    }

    /** Read current tenant-scoped workflow and a small card projection. */
    public static function read(int $tenant,int $user,int $canvas,int $thread): array
    {
        ConversationStore::assertThreadAccess($tenant,$user,$canvas,$thread);
        $row=Db::name(ConversationStore::PREFIX.'thread')->where(['id'=>$thread,'tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'delete_time'=>0])->find();
        if (!$row) throw new RuntimeException('THREAD_NOT_FOUND');
        $state=self::stateFromSettings(self::settings($row));
        if ($state===[]) return ['enabled'=>FeatureGate::workflowEnabled($tenant,self::KEY),'workflow'=>null,'card'=>null];
        self::assertState($state);
        return ['enabled'=>FeatureGate::workflowEnabled($tenant,self::KEY),'workflow'=>self::publicState($state),'card'=>self::card($state)];
    }

    /**
     * A card answer is a workflow-state action, not a model prompt and not a
     * generation request.  It is deliberately scoped to exactly the next
     * required slot so browser retries cannot skip collection stages.
     */
    public static function answer(int $tenant,int $user,int $canvas,int $thread,int $expectedRevision,string $slot,string $value): array
    {
        $slot=trim($slot);$value=trim($value);
        if (!preg_match('/^[a-z_]{2,64}$/D',$slot) || $value==='' || mb_strlen($value)>240) throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
        return Db::transaction(function () use ($tenant,$user,$canvas,$thread,$expectedRevision,$slot,$value): array {
            ConversationStore::assertThreadAccess($tenant,$user,$canvas,$thread);
            $row=Db::name(ConversationStore::PREFIX.'thread')->where(['id'=>$thread,'tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'delete_time'=>0])->lock(true)->find();
            if (!$row) throw new RuntimeException('THREAD_NOT_FOUND');
            if ((int)$row['active_run_id']!==0) throw new RuntimeException('THREAD_BUSY');
            $settings=self::settings($row);$state=self::stateFromSettings($settings);
            if ($state===[]) throw new RuntimeException('WORKFLOW_NOT_ACTIVE');
            self::assertState($state);
            if ((int)$state['state_revision']!==$expectedRevision) throw new RuntimeException('WORKFLOW_VERSION_CONFLICT');
            if (($state['stage_state']['key']??'')!=='intake' || ($state['stage_state']['status']??'')!=='collecting') throw new RuntimeException('WORKFLOW_STAGE_NOT_COLLECTING');
            $next=self::nextSlot($state);
            if (!$next || $next['key']!==$slot) throw new RuntimeException('WORKFLOW_SLOT_OUT_OF_ORDER');
            $state['slot_values'][$slot]=$value;
            if (self::nextSlot($state)===null) {
                $state['stage_state']=['key'=>'script','status'=>'ready','completed'=>['intake']];
            }
            $state['state_revision']++;
            $settings['workflow_state']=$state;
            Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>self::json($settings),'update_time'=>time()]);
            return ['workflow'=>self::publicState($state),'card'=>self::card($state)];
        });
    }

    /** Confirmation only unlocks the already frozen art plan for auto mode. */
    public static function confirmPlan(int $tenant,int $user,int $canvas,int $thread,int $expectedRevision,string $planHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$planHash)) throw new RuntimeException('INVALID_WORKFLOW_PLAN');
        return Db::transaction(function () use ($tenant,$user,$canvas,$thread,$expectedRevision,$planHash): array {
            ConversationStore::assertThreadAccess($tenant,$user,$canvas,$thread);
            $row=Db::name(ConversationStore::PREFIX.'thread')->where(['id'=>$thread,'tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'delete_time'=>0])->lock(true)->find();
            if (!$row || (int)$row['active_run_id']!==0) throw new RuntimeException($row?'THREAD_BUSY':'THREAD_NOT_FOUND');
            $settings=self::settings($row);$state=self::stateFromSettings($settings);self::assertState($state);
            if ((int)$state['state_revision']!==$expectedRevision) throw new RuntimeException('WORKFLOW_VERSION_CONFLICT');
            if (($state['stage_state']['key']??'')!=='assets' || ($state['stage_state']['status']??'')!=='awaiting_plan_confirmation' || !hash_equals((string)$state['plan_hash'],$planHash)) throw new RuntimeException('WORKFLOW_PLAN_STALE');
            $state['plan_confirmation']=['status'=>'confirmed','confirmed_at'=>time(),'plan_hash'=>$planHash];
            $state['stage_state']['status']='ready';$state['state_revision']++;
            $settings['workflow_state']=$state;
            Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>self::json($settings),'update_time'=>time()]);
            return ['workflow'=>self::publicState($state),'card'=>self::card($state)];
        });
    }

    /** Called only by ConversationExecution while it owns the thread lock. */
    public static function advanceAfterReplyLocked(array $thread,array $workflow,array $settings,string $text): ?array
    {
        if (($workflow['workflow_snapshot']['key']??'')!==self::KEY) return null;
        $state=self::stateFromSettings($settings); if ($state===[]) return null; self::assertState($state);
        if ((int)$state['state_revision']!==(int)($workflow['state_revision']??0) || ($state['stage_state']['key']??'')!==($workflow['stage_state']['key']??'') || ($state['stage_state']['status']??'')!=='running') return null;
        $current=(string)$state['stage_state']['key'];
        $next=['script'=>'art','art'=>'assets','assets'=>'storyboard','storyboard'=>'video_plan','video_plan'=>'video_nodes','video_nodes'=>'audio_plan','audio_plan'=>'complete'][$current]??'';
        if ($next==='') return null;
        $completed=array_values(array_unique(array_merge((array)($state['stage_state']['completed']??[]),[$current])));
        $status='ready';
        if ($current==='art' && (($state['workflow_snapshot']['model_preferences']['generation_mode']??'manual')==='auto')) {
            $state['plan_hash']=hash('sha256',self::json(['workflow'=>$state['workflow_snapshot'],'art_reply'=>$text]));
            $state['plan_confirmation']=['status'=>'required','plan_hash'=>$state['plan_hash']];
            $status='awaiting_plan_confirmation';
        }
        $state['stage_state']=['key'=>$next,'status'=>$status,'completed'=>$completed];$state['state_revision']++;
        $settings['workflow_state']=$state;
        return $settings;
    }

    public static function mayAutoSubmit(array $workflow): bool
    {
        return (($workflow['workflow_snapshot']['key']??'')===self::KEY)
            && in_array((string)($workflow['stage_state']['key']??''),['assets','storyboard'],true)
            && (($workflow['plan_confirmation']['status']??'')==='confirmed');
    }

    public static function instruction(array $workflow): string
    {
        if (($workflow['workflow_snapshot']['key']??'')!==self::KEY) return '';
        $stage=(string)($workflow['stage_state']['key']??'');
        $labels=[];
        foreach ((array)($workflow['workflow_snapshot']['stages']??[]) as $item) if (($item['key']??'')===$stage) $labels=(array)($item['skills']??[]);
        if ($stage==='intake') return "\n【短剧工作流】当前在创作采集阶段。只补问尚未确认的信息，不创建画布节点、不提交媒体任务。";
        $suffix=in_array($stage,['assets','storyboard'],true)
            ? '若建议生成节点，仍须使用受限 canvas-actions 格式；只引用已提供的画布素材，不能编造素材 ID、价格或任务状态。'
            : (in_array($stage,['video_nodes','audio_plan'],true) ? '视频节点只能建议插入，绝不能自动提交；音频只可规划，不能建议生成。' : '只在对话中交付内容，不创建故事设定或分集大纲文本节点。');
        return "\n【短剧工作流】当前阶段：{$stage}。读取技能指令：".implode('、',$labels)."。{$suffix}";
    }

    private static function route(array $state,string $content): ?string
    {
        if ($state!==[]) return 'continue';
        $content=trim($content);
        foreach (self::catalog()['manual_aliases'] as $alias) if (mb_stripos($content,$alias,0,'UTF-8')===0) return 'manual';
        if (str_starts_with($content,'/')) return null; // A different /Skill must not be stolen by this workflow.
        $hits=0;foreach (self::catalog()['route_keywords'] as $keyword) if (mb_stripos($content,$keyword,0,'UTF-8')!==false) $hits++;
        return $hits>=1 ? 'semantic' : null;
    }
    private static function snapshot(array $catalog,string $route,array $selectedIds,array $attachments,array $preferences): array
    {
        $assetIds=[];foreach ($attachments as $item) if (is_array($item) && in_array($item['type']??'', ['image','document'],true) && (int)($item['asset_id']??0)>0) $assetIds[]=(int)$item['asset_id'];
        return ['key'=>$catalog['key'],'version'=>$catalog['version'],'name'=>$catalog['name'],'route'=>$route,'frozen_at'=>time(),
            'stages'=>$catalog['stages'],'rules'=>$catalog['rules'],'slot_schema'=>$catalog['slots'],
            'selected_node_ids'=>array_values(array_unique(array_map('strval',$selectedIds))),'attachment_asset_ids'=>array_values(array_unique($assetIds)),
            'model_preferences'=>array_intersect_key($preferences,array_flip(['generation_mode','reasoning_model','image_model','video_model']))];
    }
    private static function settings(array $thread): array { $value=json_decode((string)($thread['settings_json']??'{}'),true);return is_array($value)?$value:[]; }
    private static function stateFromSettings(array $settings): array { $state=$settings['workflow_state']??[];return is_array($state)?$state:[]; }
    private static function nextSlot(array $state): ?array {
        foreach ((array)($state['workflow_snapshot']['slot_schema']??[]) as $slot) if (is_array($slot) && !array_key_exists((string)($slot['key']??''),(array)($state['slot_values']??[]))) return $slot;
        return null;
    }
    private static function card(array $state): ?array {
        $stage=(array)$state['stage_state'];
        $definition=self::stageDefinition((array)$state['workflow_snapshot'],(string)($stage['key']??''));
        $stageCard=['stage'=>(string)($stage['key']??''),'stage_label'=>(string)($definition['label']??'短剧创作'),'skills'=>array_values((array)($definition['skills']??[]))];
        if (($stage['key']??'')==='intake' && ($stage['status']??'')==='collecting') {
            $slot=self::nextSlot($state); if (!$slot) return null;
            return $stageCard+['type'=>'question','title'=>'《短剧》剧集初始配置','step'=>count((array)$state['slot_values'])+1,'total'=>count((array)$state['workflow_snapshot']['slot_schema']),
                'slot'=>['key'=>$slot['key'],'label'=>$slot['label'],'ask'=>$slot['ask'],'options'=>$slot['options']]];
        }
        if (($stage['key']??'')==='assets' && ($stage['status']??'')==='awaiting_plan_confirmation') return $stageCard+['type'=>'confirmation','title'=>'确认图片创作计划','body'=>'画风与美术计划已冻结。确认后，自动模式才会按既有节点任务链路生成图片；视频仍需逐节点确认。','plan_hash'=>$state['plan_hash']];
        if (($stage['key']??'')==='script' && ($stage['status']??'')==='ready') return $stageCard+['type'=>'stage','title'=>'创作采集已完成','body'=>'下一条消息将进入剧本与角色设定；设定与分集内容只保留在对话中。'];
        if (($stage['key']??'')==='video_nodes' && ($stage['status']??'')==='ready') return $stageCard+['type'=>'stage','title'=>'准备插入分镜视频节点','body'=>'下一次受控对话会一次性插入全部分镜视频待生成节点；它们不会自动报价或提交。'];
        if (($stage['key']??'')==='audio_plan' && ($stage['status']??'')==='ready') return $stageCard+['type'=>'stage','title'=>'准备音频规划','body'=>'音频规划节点只用于展示与后续衔接，当前没有生成入口。'];
        return $stageCard+['type'=>'stage','title'=>'工作流进行中','body'=>'当前阶段状态已冻结，等待下一次受控对话执行。'];
    }
    private static function stageDefinition(array $snapshot,string $key): array {
        foreach ((array)($snapshot['stages']??[]) as $stage) if (is_array($stage) && ($stage['key']??'')===$key) return $stage;
        return [];
    }
    private static function publicState(array $state): array { return ['workflow_snapshot'=>$state['workflow_snapshot'],'stage_state'=>$state['stage_state'],'slot_values'=>$state['slot_values'],'plan_hash'=>$state['plan_hash'],'plan_confirmation'=>$state['plan_confirmation'],'state_revision'=>$state['state_revision']]; }
    private static function assertState(array $state): void {
        $snapshot=(array)($state['workflow_snapshot']??[]);$stage=(array)($state['stage_state']??[]);
        if (($snapshot['key']??'')!==self::KEY || !is_string($snapshot['version']??null) || !is_string($stage['key']??null) || !is_string($stage['status']??null) || !is_array($state['slot_values']??null) || !is_int($state['state_revision']??null) || $state['state_revision']<1 || !is_array($state['plan_confirmation']??null)) throw new RuntimeException('INVALID_WORKFLOW_STATE');
    }
    private static function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
}
