<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_short_drama\ShortDramaSkillService;
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
    public const VERSION = '2026-09-23.4';

    /** Old frozen conversations retain their original canvas projection. */
    public static function compactOutput(array $workflow): bool
    {
        return ($workflow['workflow_snapshot']['key']??'')===self::KEY
            && version_compare((string)($workflow['workflow_snapshot']['version']??'0'),'2026-09-23.3','>=');
    }

    /** Confirmed text is prompt context, not a decorative media edge. Only
     * explicitly selected stage artifacts are folded into the frozen media
     * proposal, so quotation and eventual submission use the same prompt. */
    public static function materializeTextReferences(array $workflow,array $proposals): array
    {
        if (!self::compactOutput($workflow)) return $proposals;
        $memory=[];
        foreach ((array)($workflow['artifact_memory']??[]) as $item) {
            if (!is_array($item) || !in_array((string)($item['stage']??''),['script','art','video_plan'],true)) continue;
            $key=(string)($item['reference_key']??'');
            if ($key!=='') $memory[$key]=$item;
        }
        foreach ($proposals as &$proposal) {
            if (!is_array($proposal) || !in_array((string)($proposal['type']??''),['image','video'],true)) continue;
            $refs=[];$parts=[];
            foreach ((array)($proposal['reference_keys']??[]) as $key) {
                $key=(string)$key;
                $stage=explode(':',$key,2)[0];
                if (!in_array($stage,['script','art','video_plan'],true)) { $refs[]=$key; continue; }
                $item=$memory[$key]??null;
                if (!is_array($item)) throw new RuntimeException('WORKFLOW_REFERENCE_UNAVAILABLE');
                $content=trim((string)($item['content']??''));
                if ($content==='') throw new RuntimeException('WORKFLOW_REFERENCE_UNAVAILABLE');
                $parts[]='【'.mb_substr((string)($item['title']??''),0,80).'】'."\n".mb_substr($content,0,3500);
            }
            $prompt=trim((string)($proposal['prompt']??''));
            if ($parts) {
                $prompt.="\n\n【已确认的相关创作规划】\n".implode("\n\n",$parts);
                if (mb_strlen($prompt)>20000) throw new RuntimeException('INVALID_AGENT_ACTION');
                $proposal['prompt']=$prompt;
            }
            if ($refs) $proposal['reference_keys']=$refs;
            else unset($proposal['reference_keys']);
        }
        unset($proposal);
        return $proposals;
    }

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
                ['key'=>'script','label'=>'剧本与角色设定','skills'=>['剧本创作','角色设定'],'creates_nodes'=>true],
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
                'structured_text_projection'=>'server_validated_canvas_actions',
            ],
        ];
    }

    /**
     * The concrete Skill keys behind the platform-owned stage sequence.
     * Keep this mapping in code, not tenant JSON: tenant admin chooses from
     * published versions but cannot silently omit a required safety boundary
     * or substitute a product-ad skill for a narrative-production stage.
     *
     * @return array<string,list<string>>
     */
    public static function defaultSkillKeys(): array
    {
        return [
            'intake' => ['short_drama_intake'],
            'script' => ['short_drama_script', 'short_drama_character_design'],
            'art' => ['short_drama_art_direction', 'short_drama_subject_design', 'short_drama_scene_design', 'short_drama_prop_design'],
            'assets' => ['short_drama_subject_image', 'short_drama_three_view'],
            'storyboard' => ['short_drama_scene_design', 'short_drama_prop_design', 'short_drama_storyboard_design', 'short_drama_storyboard_image'],
            'video_plan' => ['short_drama_video_plan'],
            'video_nodes' => ['short_drama_storyboard_video'],
            'audio_plan' => ['short_drama_audio_plan'],
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
                'workflow_snapshot'=>self::snapshot($tenant,$catalog,$route,$selectedIds,$attachments,$preferences),
                'stage_state'=>['key'=>'intake','status'=>'collecting','completed'=>[]],
                // Keep only server-validated stage artifacts here.  This is
                // the compact continuity ledger used by later Skills; it is
                // intentionally not a copy of the full chat transcript.
                'slot_values'=>[], 'intake_candidates'=>[], 'intake_questions'=>[], 'artifact_memory'=>[], 'plan_hash'=>'', 'image_plan'=>[], 'stage_plan'=>[], 'plan_confirmation'=>['status'=>'not_required'],
                'state_revision'=>1,
            ];
        } elseif (($state['stage_state']['status']??'')==='awaiting_plan_confirmation') {
            throw new RuntimeException('WORKFLOW_PLAN_CONFIRMATION_REQUIRED');
        } elseif (($state['stage_state']['status']??'')==='reviewing_intake') {
            throw new RuntimeException('WORKFLOW_INTAKE_REVIEW_REQUIRED');
        } elseif (($state['stage_state']['status']??'')==='ready') {
            // A normal conversation send is the only way to execute the next
            // creative stage. Card clicks never impersonate an Agent reply.
            $state['stage_state']['status']='running';
            $state['state_revision']=(int)$state['state_revision']+1;
        }
        self::assertState($state);
        $settings['workflow_state']=$state;
        // The queued run needs the complete frozen Skill snapshots; browser
        // reads receive the redacted state from read()/card actions instead.
        return ['workflow'=>self::runState($state),'thread_settings'=>$settings];
    }

    /** Add an untrusted extraction draft to the immutable initial run result.
     * No extracted value becomes a confirmed slot until the owner reviews it. */
    public static function withIntakeDraft(array $workflow,mixed $raw,array $availableSources=['message']): array
    {
        if (($workflow['workflow_snapshot']['key']??'')!==self::KEY
            || version_compare((string)($workflow['workflow_snapshot']['version']??'0'),self::VERSION,'<')
            || ($workflow['stage_state']['key']??'')!=='intake') return $workflow;
        $draft=ConversationIntakeDraft::parse($raw,(array)($workflow['workflow_snapshot']['slot_schema']??[]),$availableSources);
        // A later intake turn may add context, but must not re-open answers
        // already confirmed by the owner on an earlier card.
        foreach (array_keys((array)($workflow['slot_values']??[])) as $key) {
            unset($draft['candidates'][$key],$draft['questions'][$key]);
        }
        $workflow['intake_candidates']=$draft['candidates'];
        $workflow['intake_questions']=$draft['questions'];
        if ($draft['candidates']) $workflow['stage_state']['status']='reviewing_intake';
        $workflow['state_revision']++;
        return $workflow;
    }

    /** A direct /short-drama route uses its own single text turn for intake. */
    public static function applyIntakeDraftLocked(array $workflow,array $threadSettings,mixed $raw,array $availableSources=['message']): ?array
    {
        if (($workflow['workflow_snapshot']['key']??'')!==self::KEY
            || version_compare((string)($workflow['workflow_snapshot']['version']??'0'),self::VERSION,'<')
            || ($workflow['stage_state']['key']??'')!=='intake') return null;
        $state=self::stateFromSettings($threadSettings);
        if (($state['stage_state']['status']??'')!=='collecting'
            || (int)($state['state_revision']??0)!==(int)($workflow['state_revision']??-1)) throw new RuntimeException('WORKFLOW_VERSION_CONFLICT');
        $state=self::withIntakeDraft($state,$raw,$availableSources);
        $threadSettings['workflow_state']=$state;
        return $threadSettings;
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
        if (!preg_match('/^[a-z_]{2,64}$/D',$slot) || $value==='' || strlen($value)>($slot==='intake_review'?4096:960) || ($slot!=='intake_review' && mb_strlen($value)>240)) throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
        return Db::transaction(function () use ($tenant,$user,$canvas,$thread,$expectedRevision,$slot,$value): array {
            ConversationStore::assertThreadAccess($tenant,$user,$canvas,$thread);
            $row=Db::name(ConversationStore::PREFIX.'thread')->where(['id'=>$thread,'tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'delete_time'=>0])->lock(true)->find();
            if (!$row) throw new RuntimeException('THREAD_NOT_FOUND');
            if ((int)$row['active_run_id']!==0) throw new RuntimeException('THREAD_BUSY');
            $settings=self::settings($row);$state=self::stateFromSettings($settings);
            if ($state===[]) throw new RuntimeException('WORKFLOW_NOT_ACTIVE');
            self::assertState($state);
            if ((int)$state['state_revision']!==$expectedRevision) throw new RuntimeException('WORKFLOW_VERSION_CONFLICT');
            if ($slot==='intake_review') {
                if (($state['stage_state']['key']??'')!=='intake' || ($state['stage_state']['status']??'')!=='reviewing_intake') throw new RuntimeException('WORKFLOW_STAGE_NOT_COLLECTING');
                try { $values=json_decode($value,true,8,JSON_THROW_ON_ERROR); }
                catch (\Throwable $error) { throw new RuntimeException('INVALID_WORKFLOW_ANSWER',0,$error); }
                if (!is_array($values) || ($values && array_is_list($values)) || count($values)>7) throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
                foreach ($values as $key=>$answer) {
                    if (!is_string($key) || !isset($state['intake_candidates'][$key]) || !is_string($answer) || mb_strlen($answer)>240) throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
                    if (trim($answer)!=='') $state['slot_values'][$key]=trim($answer);
                }
                $state['intake_candidates']=[];
                $state['stage_state']['status']='collecting';
                if (self::nextSlot($state)===null) $state['stage_state']=['key'=>'script','status'=>'ready','completed'=>['intake']];
                $state['state_revision']++;
                $settings['workflow_state']=$state;
                Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>self::json($settings),'update_time'=>time()]);
                return ['workflow'=>self::publicState($state),'card'=>self::card($state)];
            }
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

    /**
     * Confirm a server-quoted image batch and publish its owned graph nodes
     * atomically. The browser supplies only a previously rendered hash;
     * prompts, model, references, price and request keys stay server-owned.
     */
    public static function confirmPlan(int $tenant,int $user,int $canvas,int $thread,int $expectedRevision,string $planHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$planHash)) throw new RuntimeException('INVALID_WORKFLOW_PLAN');
        return Db::transaction(function () use ($tenant,$user,$canvas,$thread,$expectedRevision,$planHash): array {
            // Same lock order as enqueue: canvas then thread. This keeps a
            // confirmation from racing a manual canvas save.
            $document=Db::name(GraphService::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            if (!$document) throw new RuntimeException('CANVAS_NOT_FOUND');
            $row=Db::name(ConversationStore::PREFIX.'thread')->where(['id'=>$thread,'tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'delete_time'=>0])->lock(true)->find();
            if (!$row || (int)$row['active_run_id']!==0) throw new RuntimeException($row?'THREAD_BUSY':'THREAD_NOT_FOUND');
            $settings=self::settings($row);$state=self::stateFromSettings($settings);self::assertState($state);
            if ((int)$state['state_revision']!==$expectedRevision) throw new RuntimeException('WORKFLOW_VERSION_CONFLICT');
            $stage=(string)($state['stage_state']['key']??'');
            $plan=(array)($state['image_plan']??[]);
            if (!in_array($stage,['assets','storyboard'],true) || ($state['stage_state']['status']??'')!=='awaiting_plan_confirmation' || !hash_equals((string)$state['plan_hash'],$planHash) || !self::validImagePlan($plan)) throw new RuntimeException('WORKFLOW_PLAN_STALE');
            self::assertPlanSources($document,$tenant,$user,$canvas,$plan);
            $frozenSettings=['generation_mode'=>'auto','image_model'=>(array)($state['workflow_snapshot']['model_preferences']['image_model']??[])];
            if (($frozenSettings['image_model']['id']??'')==='') throw new RuntimeException('WORKFLOW_IMAGE_MODEL_UNAVAILABLE');
            $state['plan_confirmation']=['status'=>'confirmed','confirmed_at'=>time(),'plan_hash'=>$planHash];
            $effects=GraphService::appendAgentNodesLocked($document,(array)$plan['nodes'],array_column((array)$plan['sources'],'id'),true,$frozenSettings,(int)$plan['run_id'],$state);
            $effects['mode']='auto';
            self::rememberArtifacts($state,$stage,(array)$plan['nodes'],$effects);
            $completed=array_values(array_unique(array_merge((array)($state['stage_state']['completed']??[]),[$stage])));
            $state['stage_state']=['key'=>self::nextStage($stage),'status'=>'ready','completed'=>$completed];
            $state['state_revision']++;
            $settings['workflow_state']=$state;
            Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>self::json($settings),'update_time'=>time()]);
            return ['workflow'=>self::publicState($state),'card'=>self::card($state),'canvas_actions'=>$effects];
        });
    }

    /**
     * Text stages are reviewed just like a paid image batch.  The provider's
     * result is durable in the conversation, but it cannot mutate the graph
     * until the owner confirms this frozen, server-validated proposal.
     */
    public static function confirmStagePlan(int $tenant,int $user,int $canvas,int $thread,int $expectedRevision): array
    {
        return Db::transaction(function () use ($tenant,$user,$canvas,$thread,$expectedRevision): array {
            $document=Db::name(GraphService::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            if (!$document) throw new RuntimeException('CANVAS_NOT_FOUND');
            $row=Db::name(ConversationStore::PREFIX.'thread')->where(['id'=>$thread,'tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'delete_time'=>0])->lock(true)->find();
            if (!$row || (int)$row['active_run_id']!==0) throw new RuntimeException($row?'THREAD_BUSY':'THREAD_NOT_FOUND');
            $settings=self::settings($row);$state=self::stateFromSettings($settings);self::assertState($state);
            if ((int)$state['state_revision']!==$expectedRevision) throw new RuntimeException('WORKFLOW_VERSION_CONFLICT');
            $stage=(string)($state['stage_state']['key']??'');$plan=(array)($state['stage_plan']??[]);
            if (!in_array($stage,['script','art','video_plan'],true) || ($state['stage_state']['status']??'')!=='awaiting_stage_confirmation' || !self::validStagePlan($plan,$stage)) throw new RuntimeException('WORKFLOW_STAGE_PLAN_STALE');
            self::assertPlanSources($document,$tenant,$user,$canvas,$plan);
            $visible=!self::compactOutput($state) || $stage==='script';
            $effects=$visible ? GraphService::appendAgentNodesLocked($document,(array)$plan['nodes'],array_column((array)$plan['sources'],'id'),false,[],(int)$plan['run_id'],$state) : [];
            if ($effects) $effects['mode']='manual';
            self::rememberArtifacts($state,$stage,(array)$plan['nodes'],$effects);
            $completed=array_values(array_unique(array_merge((array)($state['stage_state']['completed']??[]),[$stage])));
            $state['stage_plan']=[];
            $state['stage_state']=['key'=>self::nextStage($stage),'status'=>'ready','completed'=>$completed];
            $state['state_revision']++;
            $settings['workflow_state']=$state;
            Db::name(ConversationStore::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>self::json($settings),'update_time'=>time()]);
            return ['workflow'=>self::publicState($state),'card'=>self::card($state)]+($effects?['canvas_actions'=>$effects]:[]);
        });
    }

    /**
     * Freeze a model's bounded image proposal before it reaches the graph.
     * The estimate is a local catalog/billing read only; no task, reservation
     * or Provider request happens on this path.
     */
    public static function freezeImagePlanLocked(int $tenant,array $workflow,array $settings,array $context,array $proposals,int $runId,array $threadSettings,array $document): ?array
    {
        if (($workflow['workflow_snapshot']['key']??'')!==self::KEY || ($settings['generation_mode']??'manual')!=='auto') return null;
        $stage=(string)($workflow['stage_state']['key']??'');
        if (!in_array($stage,['assets','storyboard'],true)) return null;
        $state=self::stateFromSettings($threadSettings); if ($state===[]) return null; self::assertState($state);
        if ((int)($state['state_revision']??0)!==(int)($workflow['state_revision']??0) || ($state['stage_state']['key']??'')!==$stage || ($state['stage_state']['status']??'')!=='running') return null;
        if (!$proposals || count($proposals)>4) throw new RuntimeException('WORKFLOW_IMAGE_PLAN_REQUIRED');
        $model=(array)($workflow['workflow_snapshot']['model_preferences']['image_model']??$settings['image_model']??[]);
        if (($model['id']??'')==='') throw new RuntimeException('WORKFLOW_IMAGE_MODEL_UNAVAILABLE');
        $quotes=[];
        foreach ($proposals as $proposal) {
            if (!is_array($proposal) || ($proposal['type']??'')!=='image') throw new RuntimeException('WORKFLOW_IMAGE_PLAN_REQUIRED');
            try {
                $quote=AigcImageService::estimate($tenant,[
                    'channel'=>(string)$model['id'],'model_id'=>(string)$model['id'],'model_code'=>(string)($model['model_code']??''),
                    'prompt'=>(string)$proposal['prompt'],'quantity'=>1,
                ]);
            } catch (\Throwable $error) {
                // Cost must come from the tenant-authorized model. Never turn
                // unavailable pricing into a fabricated zero-cost approval.
                throw new RuntimeException('WORKFLOW_IMAGE_QUOTE_UNAVAILABLE',0,$error);
            }
            $quotes[]=self::publicImageQuote($quote);
        }
        $sources=[];
        $live=[];
        foreach (json_decode((string)($document['nodes_json']??'[]'),true,512,JSON_THROW_ON_ERROR) as $node) if (is_array($node)) $live[(string)($node['id']??'')]=$node;
        $source=static function (array $node): array {
            $meta=(array)($node['metadata']??[]);
            return ['id'=>(string)$node['id'],'type'=>(string)($node['type']??''),'content_revision'=>(int)($meta['content_revision']??0),
                'asset_id'=>(int)($meta['asset_id']??0),'projected_generation_id'=>(int)($meta['projected_generation_id']??0)];
        };
        foreach ((array)($context['selected_nodes']??[]) as $node) {
            if (!is_array($node) || !preg_match('/^[1-9][0-9]{0,15}$/D',(string)($node['id']??''))) continue;
            $id=(string)$node['id'];
            if (!isset($live[$id])) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
            $item=$source($live[$id]);
            if ($item['type']!==(string)($node['type']??'') || $item['content_revision']!==(int)($node['content_revision']??0)) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
            if (is_array($node['image_asset']??null)) $item['image_asset']=array_intersect_key($node['image_asset'],array_flip(['id','uri','storage_scope','storage_engine','storage_domain']));
            $sources[$id]=$item;
        }
        if (self::compactOutput($workflow)) {
            $memory=[];
            foreach ((array)($workflow['artifact_memory']??[]) as $artifact) if (is_array($artifact) && ($artifact['reference_key']??'')!=='') $memory[(string)$artifact['reference_key']]=$artifact;
            foreach ($proposals as $proposal) foreach ((array)($proposal['reference_keys']??[]) as $key) {
                $key=(string)$key;
                if (str_starts_with($key,'selected:node_')) {
                    $id=substr($key,14);
                    if (!isset($sources[$id])) throw new RuntimeException('WORKFLOW_REFERENCE_UNAVAILABLE');
                    continue;
                }
                $artifact=$memory[$key]??null;
                $id=(string)($artifact['node_id']??'');
                if ($id==='' || !isset($live[$id]) || (string)($live[$id]['metadata']['workflow_key']??'')!==$key) throw new RuntimeException('WORKFLOW_REFERENCE_UNAVAILABLE');
                $sources[$id]=$source($live[$id]);
            }
        }
        $sources=array_values($sources);
        $attachments=[];
        foreach ((array)($context['attachment_images']??[]) as $asset) if (is_array($asset) && (int)($asset['id']??0)>0) $attachments[]=array_intersect_key($asset,array_flip(['id','uri','storage_scope','storage_engine','storage_domain']));
        $plan=['nodes'=>array_values($proposals),'sources'=>$sources,'attachment_images'=>$attachments,'image_model'=>self::publicModel($model),'parameters'=>['quantity'=>1],'quotes'=>$quotes,'run_id'=>$runId];
        $plan['hash']=hash('sha256',self::json(['workflow'=>$state['workflow_snapshot'],'stage'=>$stage,'nodes'=>$plan['nodes'],'sources'=>$sources,'attachment_images'=>$attachments,'image_model'=>$plan['image_model'],'parameters'=>$plan['parameters'],'quotes'=>$quotes]));
        $state['image_plan']=$plan;
        $state['plan_hash']=$plan['hash'];
        $state['plan_confirmation']=['status'=>'required','plan_hash'=>$plan['hash']];
        $state['stage_state']['status']='awaiting_plan_confirmation';
        $state['state_revision']++;
        $threadSettings['workflow_state']=$state;
        return $threadSettings;
    }

    /** Hold only non-billable, structured text artifacts for owner review. */
    public static function freezeStagePlanLocked(array $workflow,array $context,array $proposals,int $runId,array $threadSettings): ?array
    {
        if (($workflow['workflow_snapshot']['key']??'')!==self::KEY) return null;
        $stage=(string)($workflow['stage_state']['key']??'');
        if (!in_array($stage,['script','art','video_plan'],true)) return null;
        $state=self::stateFromSettings($threadSettings);if ($state===[]) return null;self::assertState($state);
        if ((int)($state['state_revision']??0)!==(int)($workflow['state_revision']??0) || ($state['stage_state']['key']??'')!==$stage || ($state['stage_state']['status']??'')!=='running') return null;
        if (!$proposals || count($proposals)>8 || array_filter($proposals,static fn($node): bool=>!is_array($node) || ($node['type']??'')!=='text')) throw new RuntimeException('WORKFLOW_STAGE_PLAN_REQUIRED');
        $sources=[];
        foreach ((array)($context['selected_nodes']??[]) as $node) {
            if (!is_array($node) || !preg_match('/^[1-9][0-9]{0,15}$/D',(string)($node['id']??''))) continue;
            $sources[]=['id'=>(string)$node['id'],'type'=>(string)($node['type']??''),'content_revision'=>(int)($node['content_revision']??0)];
        }
        $state['stage_plan']=['stage'=>$stage,'nodes'=>array_values($proposals),'sources'=>$sources,'run_id'=>$runId];
        $state['stage_state']['status']='awaiting_stage_confirmation';
        $state['state_revision']++;
        $threadSettings['workflow_state']=$state;
        return $threadSettings;
    }

    /** Called only by ConversationExecution while it owns the thread lock. */
    public static function advanceAfterReplyLocked(array $thread,array $workflow,array $settings,string $text,array $proposals=[],array $effects=[]): ?array
    {
        if (($workflow['workflow_snapshot']['key']??'')!==self::KEY) return null;
        $state=self::stateFromSettings($settings); if ($state===[]) return null; self::assertState($state);
        if ((int)$state['state_revision']!==(int)($workflow['state_revision']??0) || ($state['stage_state']['key']??'')!==($workflow['stage_state']['key']??'') || ($state['stage_state']['status']??'')!=='running') return null;
        $current=(string)$state['stage_state']['key'];
        $next=self::nextStage($current);
        if ($next==='') return null;
        self::rememberArtifacts($state,$current,$proposals,$effects);
        $completed=array_values(array_unique(array_merge((array)($state['stage_state']['completed']??[]),[$current])));
        $state['stage_state']=['key'=>$next,'status'=>'ready','completed'=>$completed];$state['state_revision']++;
        $settings['workflow_state']=$state;
        return $settings;
    }

    /**
     * Timeline entries are derived from the frozen Skill snapshot and from
     * completed server actions only.  The UI may render them like Seko's
     * status chips, but they never claim a tool call that did not happen.
     *
     * @return list<array{kind:string,label:string,detail:string}>
     */
    public static function timeline(array $workflow,array $proposals,array $effects): array
    {
        if (($workflow['workflow_snapshot']['key']??'')!==self::KEY) return [];
        $stage=(string)($workflow['stage_state']['key']??'');
        $skills=self::stageSkillNames((array)$workflow['workflow_snapshot'],$stage);
        if (!$skills) {
            $definition=self::stageDefinition((array)$workflow['workflow_snapshot'],$stage);
            $skills=array_values(array_filter((array)($definition['skills']??[]),'is_string'));
        }
        $items=[];
        if ($skills) $items[]=['kind'=>'skill','label'=>'已调用技能','detail'=>implode('、',$skills)];
        $textNodes=count(array_filter($proposals,static fn($node): bool=>is_array($node) && ($node['type']??'')==='text'));
        if ($textNodes>0 && !empty($effects['nodes'])) $items[]=['kind'=>'tool','label'=>'已调用工具','detail'=>'文本节点创建 · '.$textNodes.' 项'];
        if (!empty($effects['nodes']) && !$textNodes) $items[]=['kind'=>'tool','label'=>'已调用工具','detail'=>'画布节点创建 · '.count((array)$effects['nodes']).' 项'];
        return $items;
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
        $configured=self::stageSkillNames((array)$workflow['workflow_snapshot'],$stage);
        if ($configured) $labels=$configured;
        if ($stage==='intake') return "\n【短剧工作流】当前在创作采集阶段。只补问尚未确认的信息，不创建画布节点、不提交媒体任务。";
        $contract=self::outputContract($stage);
        $contractText=$contract ? '本阶段结构化交付字段：'.implode('、',$contract).'。这些字段必须写入受控产物；不能输出任意画布 JSON。' : '';
        if (self::compactOutput($workflow)) {
            $suffix=match ($stage) {
                'script'=>'只把故事设定与大纲、当前单集剧本写入画布；它们是独立可读内容，不创建文本之间的生成依赖。',
                'art','video_plan'=>'本阶段规划保存在对话工作流状态中，不创建画布文本节点。后续生成提示词只引用相关规划，不把全部规划画成连线。',
                'assets'=>'主体三视图必须依赖对应主体图；除用户明确选择的素材外，不连无关节点。',
                'storyboard'=>'场景图只包含场景；分镜图只引用此镜头实际使用的主体图、三视图和场景图。',
                'video_nodes'=>'每个视频节点引用对应分镜图及必要素材，始终由用户逐节点手动提交。',
                default=>'按受控产物规则执行，不连接无关节点。',
            };
            if (in_array($stage,['script','art','video_plan'],true)) $suffix='必须输出唯一结构化结果对象。'.$suffix;
            return "\n【短剧工作流】当前阶段：{$stage}。读取技能指令：".implode('、',$labels)."。{$contractText}{$suffix}";
        }
        $suffix=$stage==='assets'
            ? '若建议生成节点，仍须使用受限 canvas-actions 格式；主体三视图必须真实依赖同批主体图；前序剧本和美术文本节点会被服务器建立为真实参考连线；只引用已提供的画布素材，不能编造素材 ID、价格或任务状态。'
            : ($stage==='storyboard'
                ? '若建议生成节点，仍须使用受限 canvas-actions 格式；分镜图必须真实依赖同批场景或道具，系统会把前序剧本、美术和主体资产写入参考连线；不能编造素材 ID、价格或任务状态。'
                : (in_array($stage,['video_nodes','audio_plan'],true) ? '视频节点只能建议插入，绝不能自动提交；音频只可规划，不能建议生成。' : '按本阶段受控结构化交付规则输出，不能创建任意画布 JSON。'));
        if (in_array($stage,['script','art','video_plan'],true)) $suffix='必须输出唯一的结构化结果对象，由 reply_markdown 展示正文、canvas_actions 写入受限文本节点；缺少任一项会被服务器拒绝。'.$suffix;
        return "\n【短剧工作流】当前阶段：{$stage}。读取技能指令：".implode('、',$labels)."。{$contractText}{$suffix}";
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
    public static function isManualAlias(string $content): bool
    {
        $content=trim($content);
        foreach (self::catalog()['manual_aliases'] as $alias) if (mb_stripos($content,$alias,0,'UTF-8')===0) return true;
        return false;
    }
    private static function snapshot(int $tenant,array $catalog,string $route,array $selectedIds,array $attachments,array $preferences): array
    {
        $assetIds=[];foreach ($attachments as $item) if (is_array($item) && in_array($item['type']??'', ['image','document'],true) && (int)($item['asset_id']??0)>0) $assetIds[]=(int)$item['asset_id'];
        return ['key'=>$catalog['key'],'version'=>$catalog['version'],'name'=>$catalog['name'],'route'=>$route,'frozen_at'=>time(),
            'stages'=>$catalog['stages'],'rules'=>$catalog['rules'],'slot_schema'=>$catalog['slots'],'stage_skill_versions'=>self::stageSkillSnapshots($tenant,$catalog),
            'selected_node_ids'=>array_values(array_unique(array_map('strval',$selectedIds))),'attachment_asset_ids'=>array_values(array_unique($assetIds)),
            'model_preferences'=>array_intersect_key($preferences,array_flip(['generation_mode','reasoning_model','image_model','video_model']))];
    }
    /** Resolve only tenant-authorized, published Skill snapshots once, when
     * the workflow begins. Later admin edits or releases cannot alter a run. */
    private static function stageSkillSnapshots(int $tenant,array $catalog): array
    {
        $allowed=[];
        foreach ((array)($catalog['stages']??[]) as $stage) if (is_array($stage) && is_string($stage['key']??null)) $allowed[$stage['key']]=true;
        $snapshots=[];
        foreach (FeatureGate::workflowStageSkillSelections($tenant) as $stage=>$selections) {
            if (!isset($allowed[$stage])) continue;
            foreach ($selections as $selection) {
                try {
                    $skill=ShortDramaSkillService::resolveForTask($tenant,['skill_id'=>(int)$selection['skill_id'],'skill_version'=>(int)$selection['skill_version'],'skill_source'=>'manual']);
                    ConversationSkillPolicy::assertSafe($skill);
                } catch (\Throwable $error) {
                    throw new RuntimeException('WORKFLOW_SKILL_UNAVAILABLE',0,$error);
                }
                $snapshots[$stage][]=['id'=>(int)$skill['id'],'version'=>(int)$skill['version'],'name'=>(string)$skill['name'],'skill_key'=>(string)$skill['skill_key'],'definition'=>(array)$skill['definition']];
            }
        }
        return $snapshots;
    }
    /** @return list<string> */
    private static function stageSkillNames(array $snapshot,string $stage): array
    {
        $names=[];
        foreach ((array)($snapshot['stage_skill_versions'][$stage]??[]) as $skill) {
            if (!is_array($skill)) continue;
            $name=trim((string)($skill['name']??''));
            if ($name!=='') $names[$name]=true;
        }
        return array_keys($names);
    }
    private static function publicWorkflowSnapshot(array $snapshot): array
    {
        $stageSkills=[];
        foreach ((array)($snapshot['stage_skill_versions']??[]) as $stage=>$skills) {
            if (!is_string($stage)) continue;
            foreach ((array)$skills as $skill) if (is_array($skill)) $stageSkills[$stage][]=[
                'id'=>(int)($skill['id']??0),'version'=>(int)($skill['version']??0),'name'=>(string)($skill['name']??''),'skill_key'=>(string)($skill['skill_key']??''),
            ];
        }
        $snapshot['stage_skill_versions']=$stageSkills;
        return $snapshot;
    }
    private static function settings(array $thread): array { $value=json_decode((string)($thread['settings_json']??'{}'),true);return is_array($value)?$value:[]; }
    private static function stateFromSettings(array $settings): array {
        $state=$settings['workflow_state']??[];
        if (!is_array($state)) return [];
        // Existing in-progress threads predate the image-plan field. Reading
        // them must remain safe; the field is populated only when a later
        // image phase actually produces a priced proposal.
        if (($state['workflow_snapshot']['key']??'')===self::KEY && !array_key_exists('image_plan',$state)) $state['image_plan']=[];
        if (($state['workflow_snapshot']['key']??'')===self::KEY && !array_key_exists('stage_plan',$state)) $state['stage_plan']=[];
        if (($state['workflow_snapshot']['key']??'')===self::KEY && !array_key_exists('artifact_memory',$state)) $state['artifact_memory']=[];
        if (($state['workflow_snapshot']['key']??'')===self::KEY && !array_key_exists('stage_skill_versions',$state['workflow_snapshot'])) $state['workflow_snapshot']['stage_skill_versions']=[];
        if (($state['workflow_snapshot']['key']??'')===self::KEY && !array_key_exists('intake_candidates',$state)) $state['intake_candidates']=[];
        if (($state['workflow_snapshot']['key']??'')===self::KEY && !array_key_exists('intake_questions',$state)) $state['intake_questions']=[];
        return $state;
    }
    private static function nextSlot(array $state): ?array {
        foreach ((array)($state['workflow_snapshot']['slot_schema']??[]) as $slot) if (is_array($slot) && !array_key_exists((string)($slot['key']??''),(array)($state['slot_values']??[]))) return $slot;
        return null;
    }
    private static function card(array $state): ?array {
        $stage=(array)$state['stage_state'];
        $definition=self::stageDefinition((array)$state['workflow_snapshot'],(string)($stage['key']??''));
        $stageKey=(string)($stage['key']??'');
        $configured=self::stageSkillNames((array)$state['workflow_snapshot'],$stageKey);
        $stages=array_values(array_filter((array)($state['workflow_snapshot']['stages']??[]),'is_array'));
        $stageIndex=0;
        foreach ($stages as $index=>$item) if (($item['key']??'')===$stageKey) {$stageIndex=$index+1;break;}
        $stageCard=['stage'=>$stageKey,'stage_label'=>(string)($definition['label']??'短剧创作'),'skills'=>$configured?:array_values((array)($definition['skills']??[])),'creates_nodes'=>(bool)($definition['creates_nodes']??false),
            'stage_index'=>$stageIndex,'stage_total'=>count($stages),'completed'=>array_values(array_map('strval',(array)($stage['completed']??[]))),
            'output_fields'=>self::outputContract($stageKey)];
        if ($stageKey==='intake' && ($stage['status']??'')==='reviewing_intake') {
            $candidates=[];
            foreach ((array)($state['workflow_snapshot']['slot_schema']??[]) as $slot) {
                $key=(string)($slot['key']??'');$candidate=$state['intake_candidates'][$key]??null;
                if (!is_array($candidate)) continue;
                $candidates[]=['key'=>$key,'label'=>(string)($slot['label']??$key),'value'=>(string)$candidate['value'],
                    'source'=>(string)$candidate['source'],'evidence'=>(string)$candidate['evidence']];
            }
            return $stageCard+['type'=>'intake_review','title'=>'核对已理解的创作设定',
                'body'=>'以下内容由 Agent 从本轮描述或已授权素材中提取，均未作为最终设定。请修改、删除不准确的内容后确认；其余信息会继续追问。',
                'candidates'=>$candidates];
        }
        if (($stage['key']??'')==='intake' && ($stage['status']??'')==='collecting') {
            $slot=self::nextSlot($state); if (!$slot) return null;
            $question=(array)($state['intake_questions'][$slot['key']]??[]);
            return $stageCard+['type'=>'question','title'=>'《短剧》剧集初始配置','step'=>count((array)$state['slot_values'])+1,'total'=>count((array)$state['workflow_snapshot']['slot_schema']),
                'slot'=>['key'=>$slot['key'],'label'=>$slot['label'],'ask'=>$question['ask']??$slot['ask'],'options'=>!empty($question['options'])?$question['options']:$slot['options']]];
        }
        if (in_array(($stage['key']??''),['assets','storyboard'],true) && ($stage['status']??'')==='awaiting_plan_confirmation') {
            return $stageCard+['type'=>'confirmation','title'=>'确认图片创作计划','body'=>'本批图片的模型、提示词、引用与预估积分已冻结。确认后才会插入画布并按既有任务链路生成；视频仍需逐节点报价确认。','plan_hash'=>$state['plan_hash'],'plan'=>self::publicImagePlan((array)($state['image_plan']??[]))];
        }
        if (in_array(($stage['key']??''),['script','art','video_plan'],true) && ($stage['status']??'')==='awaiting_stage_confirmation') {
            $plan=(array)($state['stage_plan']??[]);
            $internal=self::compactOutput($state) && in_array($stageKey,['art','video_plan'],true);
            return $stageCard+['type'=>'stage_confirmation','title'=>$internal?'确认创作规划':'确认写入本阶段成果','body'=>$internal?'确认后，规划只保存在本次对话与工作流状态中，不会新增画布文本节点。':'Agent 已完成本阶段的结构化内容。确认后才会写入画布；未确认前内容只保留在本次对话中。','plan'=>['node_count'=>$internal?0:count((array)($plan['nodes']??[])),'artifact_count'=>count((array)($plan['nodes']??[]))],'action_label'=>$internal?'确认并继续':'确认并写入画布'];
        }
        if (($stage['key']??'')==='script' && ($stage['status']??'')==='ready') return $stageCard+['type'=>'stage','title'=>'创作采集已完成','body'=>self::compactOutput($state)?'下一条消息会生成故事设定与大纲、当前单集剧本；确认后只把这两项写入画布。':'下一条消息会生成剧本设定、分集大纲和分镜脚本，并写回受控文本节点。'];
        if (($stage['key']??'')==='video_nodes' && ($stage['status']??'')==='ready') return $stageCard+['type'=>'stage','title'=>'准备插入分镜视频节点','body'=>'下一次受控对话会一次性插入全部分镜视频待生成节点；它们不会自动报价或提交。'];
        if (($stage['key']??'')==='audio_plan' && ($stage['status']??'')==='ready') return $stageCard+['type'=>'stage','title'=>'准备音频规划','body'=>'音频规划节点只用于展示与后续衔接，当前没有生成入口。'];
        return $stageCard+['type'=>'stage','title'=>'工作流进行中','body'=>'当前阶段状态已冻结，等待下一次受控对话执行。'];
    }
    private static function stageDefinition(array $snapshot,string $key): array {
        foreach ((array)($snapshot['stages']??[]) as $stage) if (is_array($stage) && ($stage['key']??'')===$key) return $stage;
        return [];
    }
    /** Complete frozen state used only inside the immutable run snapshot. */
    private static function runState(array $state): array { return $state; }
    private static function publicState(array $state): array { return ['workflow_snapshot'=>self::publicWorkflowSnapshot((array)$state['workflow_snapshot']),'stage_state'=>$state['stage_state'],'slot_values'=>$state['slot_values'],'artifact_memory'=>self::publicArtifacts((array)($state['artifact_memory']??[])),'plan_hash'=>$state['plan_hash'],'image_plan'=>self::publicImagePlan((array)($state['image_plan']??[])),'stage_plan'=>self::publicStagePlan((array)($state['stage_plan']??[])),'plan_confirmation'=>$state['plan_confirmation'],'state_revision'=>$state['state_revision']]; }
    private static function assertState(array $state): void {
        $snapshot=(array)($state['workflow_snapshot']??[]);$stage=(array)($state['stage_state']??[]);
        if (($snapshot['key']??'')!==self::KEY || !is_string($snapshot['version']??null) || !is_array($snapshot['stage_skill_versions']??null) || !is_string($stage['key']??null) || !is_string($stage['status']??null) || !is_array($state['slot_values']??null) || !is_array($state['artifact_memory']??null) || !is_array($state['image_plan']??null) || !is_array($state['stage_plan']??null) || !is_int($state['state_revision']??null) || $state['state_revision']<1 || !is_array($state['plan_confirmation']??null)) throw new RuntimeException('INVALID_WORKFLOW_STATE');
    }
    private static function nextStage(string $stage): string { return ['script'=>'art','art'=>'assets','assets'=>'storyboard','storyboard'=>'video_plan','video_plan'=>'video_nodes','video_nodes'=>'audio_plan','audio_plan'=>'complete'][$stage]??''; }
    /** Server-owned output contracts are frozen with the workflow snapshot and
     * shown to the model as writing requirements.  They are intentionally
     * fields, not executable model tools or client supplied graph JSON. */
    public static function outputContract(string $stage): array {
        return match ($stage) {
            'script'=>['project_title','logline','world_setting','character_profiles','episode_outline','scene_script','storyboard_script'],
            'art'=>['art_bible','character_asset_spec','scene_asset_spec','prop_asset_spec','subject_image_prompt','three_view_prompt','scene_image_prompt','storyboard_image_prompt'],
            'assets'=>['subject_image_prompt','three_view_prompt'],
            'storyboard'=>['shot_number','shot_duration','camera','action','dialogue_or_caption','image_prompt','asset_references'],
            'video_plan'=>['shot_number','duration','first_frame','last_frame','camera_motion','action_sequence','video_prompt','asset_references'],
            'video_nodes'=>['video_prompt','asset_references'],
            'audio_plan'=>['shot_number','dialogue','voiceover','ambient_sound','sound_effect','music_mood'],
            default=>[],
        };
    }
    private static function publicModel(array $model): array { return ['id'=>(string)($model['id']??''),'model_code'=>(string)($model['model_code']??''),'market_product_id'=>(int)($model['market_product_id']??0),'market_sku_id'=>(int)($model['market_sku_id']??0)]; }
    private static function publicImageQuote(array $quote): array { return ['market_product_id'=>(int)($quote['market_product_id']??0),'market_sku_id'=>(int)($quote['market_sku_id']??0),'tenant_cost_points'=>(float)($quote['tenant_cost_points']??0),'user_charge_points'=>(float)($quote['user_charge_points']??0),'usage_unit'=>(string)($quote['usage_unit']??''),'settlement_mode'=>(string)($quote['settlement_mode']??'')]; }
    private static function publicImagePlan(array $plan): array {
        if (!self::validImagePlan($plan)) return [];
        return ['node_count'=>count((array)$plan['nodes']),'estimated_tenant_cost_points'=>array_sum(array_map(static fn(array $quote): float=>(float)($quote['tenant_cost_points']??0),(array)$plan['quotes'])),'estimated_user_charge_points'=>array_sum(array_map(static fn(array $quote): float=>(float)($quote['user_charge_points']??0),(array)$plan['quotes'])),'image_model'=>self::publicModel((array)$plan['image_model'])];
    }
    private static function publicStagePlan(array $plan): array {
        if (!self::validStagePlan($plan,(string)($plan['stage']??''))) return [];
        return ['stage'=>(string)$plan['stage'],'node_count'=>count((array)$plan['nodes'])];
    }
    /** Keep the cross-stage creative context bounded and durable. Text node
     * content can be long, so a later provider gets the latest six artifacts
     * capped to a predictable request size rather than an ever-growing chat.
     */
    private static function rememberArtifacts(array &$state,string $stage,array $proposals,array $effects=[]): void {
        $memory=array_values(array_filter((array)($state['artifact_memory']??[]),'is_array'));
        foreach ($proposals as $index=>$proposal) {
            if (!is_array($proposal)) continue;
            $content=trim((string)($proposal['prompt']??''));
            $artifact=trim((string)($proposal['artifact']??''));
            if ($content==='' || $artifact==='') continue;
            $key=trim((string)($proposal['key']??''));
            $memory[]=['stage'=>$stage,'artifact'=>$artifact,'key'=>$key,'reference_key'=>$key===''?'':$stage.':'.$key,'node_id'=>(string)($effects['nodes'][$index]['id']??''),'title'=>mb_substr(trim((string)($proposal['title']??'')),0,80),'content'=>mb_substr($content,0,6000)];
        }
        $state['artifact_memory']=array_slice($memory,-32);
    }
    private static function publicArtifacts(array $artifacts): array {
        $items=[];
        foreach (array_slice($artifacts,-12) as $artifact) if (is_array($artifact)) $items[]=[
            'stage'=>(string)($artifact['stage']??''),'artifact'=>(string)($artifact['artifact']??''),'title'=>(string)($artifact['title']??''),
        ];
        return $items;
    }
    private static function validImagePlan(array $plan): bool {
        return preg_match('/^[a-f0-9]{64}$/D',(string)($plan['hash']??''))===1 && is_array($plan['nodes']??null) && $plan['nodes']!==[] && count($plan['nodes'])<=4 && is_array($plan['sources']??null) && is_array($plan['attachment_images']??null) && (array)($plan['parameters']??[])===['quantity'=>1] && is_array($plan['quotes']??null) && count($plan['quotes'])===count($plan['nodes']) && (int)($plan['run_id']??0)>0;
    }
    private static function validStagePlan(array $plan,string $stage): bool {
        if (!in_array($stage,['script','art','video_plan'],true) || (string)($plan['stage']??'')!==$stage || !is_array($plan['nodes']??null) || !$plan['nodes'] || count($plan['nodes'])>8 || !is_array($plan['sources']??null) || (int)($plan['run_id']??0)<=0) return false;
        foreach ($plan['nodes'] as $node) if (!is_array($node) || ($node['type']??'')!=='text' || trim((string)($node['artifact']??''))==='') return false;
        return true;
    }
    private static function assertPlanSources(array $document,int $tenant,int $user,int $canvas,array $plan): void {
        $live=[]; foreach (json_decode((string)($document['nodes_json']??'[]'),true,512,JSON_THROW_ON_ERROR) as $node) if (is_array($node)) $live[(string)($node['id']??'')]=$node;
        foreach ((array)$plan['sources'] as $source) {
            if (!is_array($source) || !isset($live[(string)($source['id']??'')])) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
            $node=$live[(string)$source['id']];
            if (($node['type']??'')!==($source['type']??'') || (int)($node['metadata']['content_revision']??0)!==(int)($source['content_revision']??0)) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
            if (array_key_exists('asset_id',$source) && (int)($node['metadata']['asset_id']??0)!==(int)$source['asset_id']) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
            if (array_key_exists('projected_generation_id',$source) && (int)($node['metadata']['projected_generation_id']??0)!==(int)$source['projected_generation_id']) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
        }
        foreach ((array)($plan['attachment_images']??[]) as $asset) {
            if (!is_array($asset) || (int)($asset['id']??0)<=0) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
            $row=Db::name('aigc_short_drama_asset')->where(['id'=>(int)$asset['id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0,'status'=>'ready'])->find();
            if (!$row || !in_array((int)($row['canvas_id']??0),[0,$canvas],true) || (string)$row['uri']!==(string)($asset['uri']??'') || (string)$row['storage_scope']!==(string)($asset['storage_scope']??'') || (string)$row['storage_engine']!==(string)($asset['storage_engine']??'') || (string)$row['storage_domain']!==(string)($asset['storage_domain']??'')) throw new RuntimeException('WORKFLOW_PLAN_SOURCE_CHANGED');
        }
    }
    private static function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
}
