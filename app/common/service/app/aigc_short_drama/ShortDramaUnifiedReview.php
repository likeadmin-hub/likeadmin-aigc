<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** One semantic pass owns coverage, fields and continuity; only disputes branch. */
final class ShortDramaUnifiedReview
{
    private const WINDOW = 12;
    private const FIELDS = ['shot_id','scene_ref_id','subject_ref_ids','visual_description','dialogue','voice_role','speech_type','interior_exterior','recommended_duration_seconds'];

    public static function enabled(array $request): bool
    {
        return (int)($request['_input_contract_version'] ?? 0) >= 9 && !ShortDramaEpisodeDuration::localRevision($request);
    }

    private static function call(callable $call, string $stage, array $input, callable $validate): array
    {
        $key = 'unified_v9_' . $stage . '_' . substr(hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE)),0,20);
        return ShortDramaStructuredResponse::audit($input, static function($effective) use ($call,$key,$validate): array {
            $reply=$call($key . '_' . substr(hash('sha256',$effective['content']),0,10),$effective,6000,$validate);
            $validate($reply); return $reply;
        });
    }

    public static function generate(array $base, array $request, callable $call, ?callable $progress = null, ?callable $checkpoint = null): array
    {
        $plan=ShortDramaShotSemantics::normalize($base);
        $requirements=ShortDramaContentAcceptance::requirementsForPlan($plan,$request,$call);
        $budget=new ShortDramaRepairBudget();
        if (!empty($plan['dialogue_density_revision'])) $budget->consume('density',1);
        $repairs=[];
        $originalContext=(array)($request['series_context']??[]);
        $originalContext = array_replace($originalContext, ['_server_owned_state'=>true,'_canonical_state'=>true,'_bounded_memory'=>true]);
        $originalContext['outline']=ShortDramaPlanningContext::lockedStory((array)($originalContext['outline']??[]));
        for($pass=0;$pass<3;$pass++) {
            $plan=ShortDramaEpisodeTimingRepair::reconcile($plan,$request,$call);
            $query=ShortDramaNarrativeMemory::query(['current_episode'=>$originalContext['current_episode']??[], 'script'=>ShortDramaEpisodeReview::projection($plan)]);
            $dependency=ShortDramaNarrativeMemory::dependency((array)($originalContext['continuity']??[]),$query);
            $context=$originalContext; $context['_reviewed_block_state_keys']=[];
            $coverage=[];$issues=[];$ledgers=[];
            foreach(array_chunk($plan['storyboard'],self::WINDOW) as $index=>$shots) {
                $block=$plan; $block['storyboard']=$shots;
                $context['review_window']=['first_shot'=>$index*self::WINDOW+1,'last_shot'=>$index*self::WINDOW+count($shots),
                    'total_shots'=>count($plan['storyboard']), 'instruction'=>'仅核对当前窗口，不要求它展示其他窗口或未来集剧情；缺失事件由服务器汇总整集后判断。'];
                $input=ShortDramaContinuity::messages(ShortDramaEpisodeReview::projection($block),$context);
                $data=json_decode($input['content'],true,512,JSON_THROW_ON_ERROR);
                $data['script']['storyboard']=array_map(static fn($s)=>array_intersect_key($s,array_flip(self::FIELDS)),$shots);
                $data['requirements']=$requirements;
                $data['previous_shots']=array_map(static fn($s)=>array_intersect_key($s,array_flip(self::FIELDS)),array_slice($plan['storyboard'],max(0,$index*self::WINDOW-2),min(2,$index*self::WINDOW)));
                $data['response_contract']=['checks'=>[['id'=>'要求ID','status'=>'covered/absent/violated','evidence_refs'=>[['shot_id'=>'当前窗口镜头ID','field'=>'visual_description或dialogue']]]],
                    'issues'=>[['code'=>'scene_space/speaker/silent/dialogue_instruction/subject_reference/duplicate_action','message'=>'明确字段矛盾，无矛盾则不要返回','shot_ids'=>['当前窗口镜头ID']]],
                    'continuity'=>$data['response_contract']];
                $input['system_prompt'].=' 同一轮同时检查requirements覆盖、字段一致性与连续性，不另写故事。返回checks、issues、continuity三个字段。'
                    . '每个requirement必须恰好返回一次。event只有实际画面或台词发生才covered；本窗口未发生为absent，不能据此声称整集缺失；constraint无违反为covered，有违反为violated且必须提供证据。'
                    . '每个被覆盖事件和被违反约束必须引用实际镜头字段，不能用梗概替代。不要新增用户没有要求的事件。'
                    . 'issues只报告确定的画面地点、实际说话人、静默、对白指令冒充原话、已登记主体漏引用、重复完成同一动作。正常接续、复述与主观文风不阻断。'
                    . '多人对白按姓名标签和各行识别，不要求voice_role列出所有人。无名背景路人不必登记，用户明确闭合角色限制仍要遵守。'
                    . 'continuity记录有证据的事实变化，不把自己的推测写入状态；未知不填。';
                $input['content']=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $reply=self::call($call,'inspect',$input,static fn($r)=>self::validate($r,$block,$requirements,$context));
                foreach($reply['checks'] as $check) $coverage[$check['id']][]=$check;
                array_push($issues,...ShortDramaContentAcceptance::verifiedIssues($reply['issues'],$shots,$call,$plan['subjects']));
                // Reuse the proven fact-evidence protocol. Its first audit is the
                // combined result, not another paid reading of the same shots.
                $first=true;
                $audit=static function($input,$validate=null)use(&$first,$reply,$block,$context,$call):array{
                    if($first){$first=false;return $reply['continuity'];}
                    return self::call($call,'audit_metadata',$input,$validate ?? static fn($r)=>ShortDramaContinuity::assertAuditShape($r,$block,$context));
                };
                $verify=static function($review,$candidate)use($context,$call,$plan,$index):array{
                    if(empty($review['changes'])&&empty($review['hooks'])&&empty($review['warnings']))return $review;
                    $evidenceContext=$context;
                    $evidenceContext['_earlier_episode_shots']=ShortDramaEpisodeReview::earlierEvidence(array_slice($plan['storyboard'],0,$index*self::WINDOW),$review);
                    $input=ShortDramaContinuityPatch::meaningMessages($candidate,$review,$evidenceContext);
                    $proof=self::call($call,'facts',$input,static fn($r)=>ShortDramaContinuityPatch::verifiedReview($review,$r,$context,[],true));
                    return ShortDramaContinuityPatch::verifiedReview($review,$proof,$context,[],true);
                };
                try {
                    $ledger=ShortDramaContinuity::review($block,$context,(int)($request['episode_number']??1),$audit,$verify,false,true);
                    if(($ledger['audit_status']??'')==='pending_review')throw new RuntimeException('跨集事实证据尚未核验完成，候选剧本保留',409);
                    foreach($ledger['state'] as $key=>$value) if(($context['continuity']['state'][$key]??null)!==$value)$context['_reviewed_block_state_keys'][$key]=true;
                    $context['continuity']['state']=$ledger['state'];$context['continuity']['open_hooks']=$ledger['open_hooks'];$ledgers[]=$ledger;
                }catch(ShortDramaScriptConflict $error){
                    $issues[]=['code'=>'continuity','message'=>$error->getMessage(),'shot_ids'=>array_column($shots,'shot_id')];
                }
                if($checkpoint)$checkpoint($plan,($index*self::WINDOW)+count($shots),['unified_review'=>true,'pass'=>$pass,'repair_budget'=>$budget->snapshot()]);
                if($progress)$progress('stage',['status'=>'running','progress'=>min(96,80+(int)(16*($index*self::WINDOW+count($shots))/count($plan['storyboard']))),'current_step'=>'统一核验剧情、对白与衔接：'.min(count($plan['storyboard']),($index+1)*self::WINDOW).'/'.count($plan['storyboard']).'镜']);
            }
            $coverage=self::verifyViolations($plan,$requirements,$coverage,$call);
            foreach($requirements as $required){
                $checks=$coverage[$required['id']]??[];
                $bad=$required['kind']==='event' ? !in_array('covered',array_column($checks,'status'),true) : in_array('violated',array_column($checks,'status'),true);
                if($bad)$issues[]=['code'=>$required['kind']==='event'?'missing_event':'constraint','message'=>$required['text'],'requirement'=>$required,
                    'shot_ids'=>array_values(array_unique(array_merge([], ...array_map(static fn($c)=>array_column($c['evidence_refs'],'shot_id'),$checks))))];
            }
            if(!$issues){
                $plan['script_lines']=array_map(static fn($s)=>trim($s['visual_description']."\n".$s['dialogue']),$plan['storyboard']);
                $plan['content_acceptance']=['version'=>ShortDramaContentAcceptance::VERSION,'mode'=>'unified_v9','status'=>'verified','requirements'=>$requirements,'coverage'=>$coverage,'repairs'=>$repairs];
                $plan['_continuity']=['version'=>1,'episode_number'=>(int)($request['episode_number']??1),
                    'summary'=>implode("\n",array_column($ledgers,'summary')),'state'=>$context['continuity']['state']??[], 'open_hooks'=>$context['continuity']['open_hooks']??[],
                    'digest'=>ShortDramaContinuity::fingerprint($plan),'previous_digest'=>$originalContext['continuity']['previous_digest']??'',
                    'audit_status'=>'verified','review_mode'=>'unified_v9','content_status'=>'ready','dependency_status'=>'ready',
                    'warnings'=>array_merge([], ...array_column($ledgers,'warnings')),'observed_events'=>array_merge([], ...array_column($ledgers,'observed_events')),
                    'memory_dependency'=>['version'=>ShortDramaNarrativeMemory::VERSION,'history_available'=>array_key_exists('history',$originalContext['continuity']??[]),'query'=>$query,'fingerprint'=>$dependency]];
                $plan['execution_diagnostics']=['repair_budget'=>$budget->snapshot(),'provider_call_limit'=>ShortDramaExecutionPolicy::limit($request),'review_mode'=>'unified_v9'];
                return $plan;
            }
            if($pass===2)throw new RuntimeException('本集统一核验尚未通过：'.implode('；',array_column(array_slice($issues,0,3),'message')).'。候选内容已保留',422);
            $budget->consume('semantic',$pass+1);
            foreach(array_chunk($issues,4) as $batch){
                $ids=array_unique(array_merge([], ...array_column($batch,'shot_ids')));
                $targets=$ids?array_values(array_filter($plan['storyboard'],static fn($s)=>in_array($s['shot_id'],$ids,true))):array_slice($plan['storyboard'],-self::WINDOW);
                foreach(array_chunk($targets,self::WINDOW) as $target){
                    $data=['issues'=>$batch,'requirements'=>$requirements,'subjects'=>$plan['subjects'],'locations'=>$plan['locations'],'targets'=>$target,
                        'episode_budget'=>ShortDramaEpisodeDuration::policy($request)];
                    $input=['system_prompt'=>'只修复明确的issues，保留已正确事件、原话和资产。返回{"patches":[{"shot_id":"原ID","field":"字段","before":"原值","after":"新值"}],"subject_categories":[],"insertions":[]}。'
                        . 'patches允许visual_description、dialogue、voice_role、speech_type、interior_exterior、scene_ref_id、subject_ref_ids，不修改镜头ID或时长。数组字段保持数组。多人发言一行一人。'
                        . '只有missing_event时允许insertions:[{"after_shot_id":"targets中原镜头ID","shot":{完整新镜头}}]，最多4个。新镜头沿用已有主体场景，含完整画面、对白、说话人、speech_type和预估秒数；服务端分配ID。不为了补缺失而删除已正确剧情或偷换其他台词。其余情况insertions为空。',
                        'content'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
                    $key='r'.($pass+1).'_'.substr(hash('sha256',$input['content']),0,12);
                    $reply=self::call($call,'repair',$input,static fn($r)=>self::apply($plan,$r,array_column($target,'shot_id'),$batch,$key));
                    $plan=self::apply($plan,$reply,array_column($target,'shot_id'),$batch,$key);$repairs[]=$reply;
                    if($checkpoint)$checkpoint($plan,0,['unified_review'=>true,'pass'=>$pass+1,'repair_budget'=>$budget->snapshot()]);
                }
            }
        }
        throw new RuntimeException('统一核验未完成',409);
    }

    public static function validate(array $reply, array $block, array $requirements, array $context): void
    {
        foreach(['checks','issues','continuity'] as $field)if(!is_array($reply[$field]??null))throw new RuntimeException('统一核验缺少'.$field,422);
        $expected=array_column($requirements,'id');$actual=array_column($reply['checks'],'id');sort($expected);sort($actual);
        if($expected!==$actual)throw new RuntimeException('统一核验必须逐项覆盖本集要求',422);
        $byId=array_column($block['storyboard'],null,'shot_id');$requiredById=array_column($requirements,null,'id');
        foreach($reply['checks'] as $check){
            if(!in_array($check['status']??'', ['covered','absent','violated'],true)||!is_array($check['evidence_refs']??null))throw new RuntimeException('要求结论或证据结构无效',422);
            if(($requiredById[$check['id']]['kind']==='event'&&$check['status']==='violated')||($requiredById[$check['id']]['kind']==='constraint'&&$check['status']==='absent'))throw new RuntimeException('事件使用covered/absent，约束使用covered/violated',422);
            $needsEvidence=($requiredById[$check['id']]['kind']==='event'&&$check['status']==='covered')||$check['status']==='violated';
            if($needsEvidence&&!$check['evidence_refs'])throw new RuntimeException('通过事件或阻断约束必须有真实镜头证据',422);
            foreach($check['evidence_refs'] as $ref)if(!isset($byId[$ref['shot_id']??''])||!in_array($ref['field']??'',['visual_description','dialogue'],true)
                ||trim($byId[$ref['shot_id']][$ref['field']]??'')==='')throw new RuntimeException('要求证据必须引用当前窗口真实非空字段',422);
        }
        foreach($reply['issues'] as $issue)if(!in_array($issue['code']??'',['scene_space','speaker','silent','dialogue_instruction','subject_reference','duplicate_action'],true)
            ||!is_string($issue['message']??null)||!is_array($issue['shot_ids']??null)||!$issue['shot_ids']||array_diff($issue['shot_ids'],array_keys($byId)))throw new RuntimeException('字段问题必须指向当前窗口镜头',422);
        ShortDramaContinuity::assertAuditShape($reply['continuity'],$block,$context);
    }

    /** Only negative constraint claims branch; accepted events are not re-read. */
    public static function verifyViolations(array $plan,array $requirements,array $coverage,callable $call): array
    {
        $claims=[];$shots=array_column($plan['storyboard'],null,'shot_id');
        foreach($requirements as $requirement)if($requirement['kind']==='constraint')foreach($coverage[$requirement['id']]??[] as $index=>$check)if($check['status']==='violated'){
            $evidence=[];
            foreach($check['evidence_refs'] as $ref)$evidence[]=$ref+['quote'=>$shots[$ref['shot_id']][$ref['field']]];
            $claims[]=['id'=>$requirement['id'].':'.$index,'requirement'=>$requirement,'evidence'=>$evidence];
        }
        foreach(array_chunk($claims,6) as $batch){
            $input=['system_prompt'=>'独立核对证据是否确实违反用户要求。只凭原文，不采信前次检查结论，不引入新要求。人物限制不限制道具数量；准备、观察和接续不是重复完成。返回{"verdicts":[{"id":"原ID","violated":false,"reason":"证据与要求的明确关系"}]}，逐项一次；无法证实违反返回false。',
                'content'=>json_encode(['claims'=>$batch,'subjects'=>$plan['subjects'],'locations'=>$plan['locations']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
            $reply=self::call($call,'constraint_evidence',$input,static function($reply)use($batch):void{
                $expected=array_column($batch,'id');$actual=array_column((array)($reply['verdicts']??[]),'id');sort($expected);sort($actual);
                if($expected!==$actual)throw new RuntimeException('约束核实项不完整',422);
                foreach($reply['verdicts'] as $verdict)if(!is_bool($verdict['violated']??null)||!is_string($verdict['reason']??null)||trim($verdict['reason'])==='')throw new RuntimeException('约束核实缺少明确依据',422);
            });
            foreach($reply['verdicts'] as $verdict){[$id,$index]=explode(':',$verdict['id']);$coverage[$id][(int)$index]['violation_proof']=$verdict;if(!$verdict['violated'])$coverage[$id][(int)$index]['status']='covered';}
        }
        return $coverage;
    }

    public static function apply(array $plan,array $reply,array $targets,array $issues,string $key): array
    {
        $candidate=ShortDramaContentAcceptance::apply($plan,$reply,$targets,true);
        $insertions=$reply['insertions']??[];
        if(!is_array($insertions)||!array_is_list($insertions)||count($insertions)>4||($insertions&&!in_array('missing_event',array_column($issues,'code'),true)))throw new RuntimeException('只有明确缺失事件可以插入最多4个分镜',422);
        $after=[];
        foreach($insertions as $i=>$insertion){
            if(!in_array($insertion['after_shot_id']??'',$targets,true)||!is_array($insertion['shot']??null))throw new RuntimeException('补镜位置或字段越界',422);
            $validated=ShortDramaContentFirstGeneration::mergeUnit($candidate,['storyboard'=>[$insertion['shot']]],$key.'_'.($i+1));
            $after[$insertion['after_shot_id']][]=end($validated['storyboard']);
        }
        $out=[];foreach($candidate['storyboard'] as $shot){$out[]=$shot;array_push($out,...($after[$shot['shot_id']]??[]));}$candidate['storyboard']=$out;
        if($candidate===$plan)throw new RuntimeException('明确问题尚未修复，不能返回空补丁',422);
        return $candidate;
    }
}
