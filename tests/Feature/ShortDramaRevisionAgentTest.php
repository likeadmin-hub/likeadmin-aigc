<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\ShortDramaRevisionAgent as Agent;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProductionRevision;

final class ShortDramaRevisionAgentTest extends TestCase
{
    private function plan(): array
    {
        return ['title'=>'原剧本','story_outline'=>'甲与乙在办公室解决问题','script_lines'=>['甲：先解决问题','乙：好的'],
            'subjects'=>[['id'=>'a','name'=>'甲','description'=>'程序员','category'=>'character'],['id'=>'b','name'=>'乙','description'=>'同事','category'=>'character'],['id'=>'p','name'=>'纸张','description'=>'普通纸','category'=>'prop']],
            'locations'=>[['id'=>'room','name'=>'办公室','description'=>'明亮的办公室']],
            'episodes'=>[],'scene_screenplays'=>[],
            'storyboard'=>[['shot_id'=>'s1','sort'=>1,'scene_ref_id'=>'room','subject_ref_ids'=>['a','p'],'dialogue'=>'甲：先解决问题','voice_role'=>'甲','speech_type'=>'character','visual_description'=>'甲查看纸张','image_prompt'=>'甲查看纸张','video_prompt'=>'声音：甲：先解决问题','recommended_duration_seconds'=>5],
                ['shot_id'=>'s2','sort'=>2,'scene_ref_id'=>'room','subject_ref_ids'=>['b'],'dialogue'=>'乙：好的','voice_role'=>'乙','visual_description'=>'乙点头','image_prompt'=>'乙点头','recommended_duration_seconds'=>5]],
            'generation_settings'=>['aspect_ratio'=>'16:9']];
    }
    private function request(array $base=[]): array
    {
        return ['_revision_agent_version'=>1,'revision_message'=>'主体太多了','revision_base_task_id'=>'base','revision_base_result'=>$base?:$this->plan(),
            '_revision_stage'=>'production','shot_duration_rule'=>['min_seconds'=>3,'max_seconds'=>16]];
    }
    private function runReply(array $reply,array $request=[],bool $accept=true): array
    {
        return Agent::run($request?:$this->request(),static fn($key)=>$key==='revision_edit'?$reply:['passed'=>$accept,'reason'=>'未完成用户要求']);
    }
    public function testAmbiguousRequestAsksOneQuestionWithoutAChangedPlan(): void
    {
        $calls=[];$base=$this->plan();
        $result=Agent::run($this->request(),static function($key,$input)use(&$calls){$calls[]=$key;return ['action'=>'clarify','message'=>'想减少人物，还是独立生成素材？','operations'=>[]];});
        $this->assertSame(['revision_edit'],$calls);
        $this->assertFalse($result['revision_response']['edited']);
        unset($result['revision_response']);$this->assertSame($base,$result);
    }
    public function testAnUnreducedCollectionCannotBeReportedAsSuccessful(): void
    {
        $result=$this->runReply(['action'=>'edit','message'=>'已减少主体','operations'=>[['entity'=>'subject','op'=>'update','id'=>'a','values'=>['description'=>'年轻程序员']]],'expectations'=>['subjects_max'=>2]]);
        $this->assertFalse($result['revision_response']['edited']);
        $this->assertSame($this->plan()['subjects'],$result['subjects']);
        $this->assertStringContainsString('未应用',$result['revision_response']['message']);
    }
    public function testRemovalUpdatesActualReferencesAndKeepsOtherData(): void
    {
        $result=$this->runReply(['action'=>'edit','message'=>'不再单独使用纸张','operations'=>[
            ['entity'=>'subject','op'=>'remove','id'=>'p'],
            ['entity'=>'shot','op'=>'update','id'=>'s1','values'=>['subject_ref_ids'=>['a'],'visual_description'=>'甲思考','image_prompt'=>'甲思考']]],'expectations'=>['subjects_max'=>2]]);
        $this->assertTrue($result['revision_response']['edited']);
        $this->assertCount(2,$result['subjects']);
        $this->assertSame($this->plan()['storyboard'][1],$result['storyboard'][1]);
        $this->assertSame(['a'],$result['storyboard'][0]['subject_ref_ids']);
        $this->assertContains('主体从3个调整为2个',$result['revision_response']['changes']);
    }
    public function testDanglingReferencesAreRejectedAtomically(): void
    {
        $result=$this->runReply(['action'=>'edit','message'=>'已删除','operations'=>[['entity'=>'subject','op'=>'remove','id'=>'p']]]);
        $this->assertFalse($result['revision_response']['edited']);
        unset($result['revision_response']);$this->assertSame($this->plan(),$result);
    }
    public function testMergeAndMultipleRequestedChangesUseStableIds(): void
    {
        $result=$this->runReply(['action'=>'edit','message'=>'合并同事','operations'=>[
            ['entity'=>'subject','op'=>'merge','ids'=>['a','b'],'into_id'=>'a','values'=>['description'=>'独立解决问题的程序员']],
            ['entity'=>'plan','op'=>'update','values'=>['story_outline'=>'甲独自在办公室解决问题','script_lines'=>['甲：先解决问题','甲：有办法了']]],
            ['entity'=>'shot','op'=>'update','id'=>'s2','values'=>['voice_role'=>'甲','dialogue'=>'甲：有办法了','visual_description'=>'甲点头','image_prompt'=>'甲点头']]]]);
        $this->assertTrue($result['revision_response']['edited']);
        $this->assertSame(['a'],$result['storyboard'][1]['subject_ref_ids']);
        $this->assertSame('s2',$result['storyboard'][1]['shot_id']);
        $this->assertCount(2,$result['subjects']);
    }
    public function testEnglishAndAudioChangesDoNotInvalidateImages(): void
    {
        $result=$this->runReply(['action'=>'edit','message'=>'改英文','operations'=>[['entity'=>'shot','op'=>'update','id'=>'s1','values'=>['dialogue'=>'Let us solve it.','voice_role'=>'A','video_prompt'=>'声音：A: Let us solve it.']]],
            'expectations'=>['languages'=>[['entity'=>'shot','id'=>'s1','field'=>'dialogue','language'=>'en']]]]);
        $this->assertTrue($result['revision_response']['edited']);
        $this->assertSame('Let us solve it.',$result['storyboard'][0]['dialogue']);
        $this->assertSame([],$result['revision_response']['media_impact']['image_shot_ids']);
        $this->assertSame(['s1'],$result['revision_response']['media_impact']['video_shot_ids']);
        $this->assertSame(['s1'],$result['revision_response']['media_impact']['audio_shot_ids']);
        $this->assertFalse($result['revision_response']['media_impact']['regenerated']);
    }
    public function testChineseTextCannotSatisfyEnglishAcceptance(): void
    {
        $result=$this->runReply(['action'=>'edit','message'=>'已翻译','operations'=>[['entity'=>'shot','op'=>'update','id'=>'s1','values'=>['dialogue'=>'还是中文']]],
            'expectations'=>['languages'=>[['entity'=>'shot','id'=>'s1','field'=>'dialogue','language'=>'en']]]]);
        $this->assertFalse($result['revision_response']['edited']);
    }
    public function testEnglishSpeechRetainsNamedSpeakerAndSynchronizesOnlyTheSoundLine(): void
    {
        $out=$this->runReply(['action'=>'edit','message'=>'改英文','operations'=>[['entity'=>'shot','op'=>'update','id'=>'s1','values'=>['dialogue'=>'甲：Let us solve it.']]],
            'expectations'=>['languages'=>[['entity'=>'shot','id'=>'s1','field'=>'dialogue','language'=>'en']]]]);
        $this->assertTrue($out['revision_response']['edited']);
        $this->assertSame('声音：甲：Let us solve it.',$out['storyboard'][0]['video_prompt']);
        $this->assertSame($this->plan()['storyboard'][0]['image_prompt'],$out['storyboard'][0]['image_prompt']);
    }
    public function testSplitMoveAddRemoveAndTimeConstraints(): void
    {
        $base=$this->plan();
        $out=Agent::apply($base,[['entity'=>'shot','op'=>'split','id'=>'s1','items'=>[['shot_id'=>'s1','recommended_duration_seconds'=>3],['shot_id'=>'s1b','recommended_duration_seconds'=>3]]],
            ['entity'=>'shot','op'=>'move','id'=>'s2','after_id'=>null],['entity'=>'shot','op'=>'remove','id'=>'s1b']],$this->request());
        $this->assertSame(['s2','s1'],array_column($out['storyboard'],'shot_id'));
        $this->assertSame([1,2],array_column($out['storyboard'],'sort'));
        $this->assertSame($base['subjects'],$out['subjects']);
    }
    public function testInvalidFieldCannotWriteTaskAssetOrBillingData(): void
    {
        foreach (['image','storage_domain','library_subject_id','provider','tenant_id','user_charge_points'] as $field) {
            $out=$this->runReply(['action'=>'edit','message'=>'更改','operations'=>[['entity'=>'subject','op'=>'update','id'=>'a','values'=>[$field=>'malicious']]]]);
            $this->assertFalse($out['revision_response']['edited'],$field);
            $this->assertSame($this->plan()['subjects'],$out['subjects']);
        }
    }
    public function testIndependentRejectionAndNoOpKeepTheBaseline(): void
    {
        $reply=['action'=>'edit','message'=>'完成','operations'=>[['entity'=>'plan','op'=>'update','values'=>['title'=>'新标题']]]];
        $out=$this->runReply($reply,[],false);
        $this->assertFalse($out['revision_response']['edited']);$this->assertSame('原剧本',$out['title']);
        $reply['operations'][0]['values']['title']='原剧本';
        $this->assertFalse($this->runReply($reply)['revision_response']['edited']);
    }
    public function testStoryAndEpisodeStageBoundariesAreEnforced(): void
    {
        $request=$this->request();$request['_revision_stage']='story';
        $out=$this->runReply(['action'=>'edit','message'=>'完成','operations'=>[['entity'=>'shot','op'=>'remove','id'=>'s1']]],$request);
        $this->assertFalse($out['revision_response']['edited']);
        $request['_revision_stage']='episodes';$request['episode_count']=0;
        $out=$this->runReply(['action'=>'edit','message'=>'完成','operations'=>[['entity'=>'subject','op'=>'update','id'=>'a','values'=>['description'=>'新外观']]]],$request);
        $this->assertFalse($out['revision_response']['edited']);
    }
    public function testBoundLibraryIdentityRemainsProtected(): void
    {
        $base=$this->plan();$base['subjects'][0]['library_subject_id']=42;
        $out=$this->runReply(['action'=>'edit','message'=>'改身份','operations'=>[['entity'=>'subject','op'=>'update','id'=>'a','values'=>['name'=>'新人物']]]],$this->request($base));
        $this->assertFalse($out['revision_response']['edited']);
    }
    public function testRestoreOnlyUsesTheOwnedVersionCatalog(): void
    {
        $request=$this->request();$request['_revision_context']['versions']=[['task_id'=>'old']];
        $old=$this->plan();$old['title']='早期版本';
        $out=Agent::run($request,static fn($key)=>$key==='revision_edit'?['action'=>'restore','message'=>'恢复','restore_task_id'=>'old']:['passed'=>true],static fn($id)=>$old);
        $this->assertTrue($out['revision_response']['edited']);$this->assertSame('早期版本',$out['title']);
        $out=Agent::run($request,static fn()=>['action'=>'restore','message'=>'恢复','restore_task_id'=>'foreign'],static fn()=>throw new \LogicException('Must not resolve unlisted versions'));
        $this->assertFalse($out['revision_response']['edited']);
    }
    public function testContextRetainsCreativeFactsWithoutStorageOrCredentials(): void
    {
        $plan=$this->plan();$plan['subjects'][0]+=['image'=>'https://private.invalid/token','storage_domain'=>'private','api_key'=>'secret'];
        $context=Agent::contextPlan($plan);
        $this->assertArrayNotHasKey('image',$context['subjects'][0]);$this->assertArrayNotHasKey('api_key',$context['subjects'][0]);
        $this->assertSame('程序员',$context['subjects'][0]['description']);
    }
    public function testOnlyExplicitSettingsChangesCanAlterProjectDefaults(): void
    {
        $base=$this->plan();$after=$base;$after['generation_settings']['review_policy_version']=1;
        $this->assertSame([],Agent::settingPatch($base,$after));
        $after['generation_settings']['aspect_ratio']='9:16';
        $this->assertSame(['aspect_ratio'=>'9:16'],Agent::settingPatch($base,$after));
        $out=$this->runReply(['action'=>'edit','message'=>'更换分辨率','operations'=>[['entity'=>'settings','op'=>'update','values'=>['resolution'=>'4k']]]]);
        $this->assertFalse($out['revision_response']['edited']);
        $this->assertSame($base['generation_settings'],$out['generation_settings']);
    }
    public function testCanvasClarificationRestoresPriorCardWithoutGraphWrites(): void
    {
        $state=['state_revision'=>2,'artifact_memory'=>[['key'=>'old']],'series'=>['episode_number'=>1],
            'revision_request'=>['stage'=>'episode','binding'=>['task_id'=>'question','run_id'=>9],
                'return_stage'=>['key'=>'episode','status'=>'awaiting_stage_confirmation'],'return_plan'=>['nodes'=>[['key'=>'old']]],'artifact_memory'=>[['key'=>'old']]]];
        $task=['task_id'=>'question','status'=>'success','result_json'=>json_encode(['revision_response'=>['action'=>'clarify','edited'=>false,'message'=>'请指定角色']])];
        $out=ConversationProductionRevision::dialogueState($state,$task,'请指定角色',9);
        $this->assertSame($state['revision_request']['return_plan'],$out['stage_plan']);
        $this->assertSame($state['artifact_memory'],$out['artifact_memory']);
        $this->assertArrayNotHasKey('revision_request',$out);
        $this->assertNull(ConversationProductionRevision::dialogueState($state,$task,'伪造回复',9));
        $this->assertNull(ConversationProductionRevision::dialogueState($state,$task,'请指定角色',10));
    }

    public function testSavedEditAndAcceptanceReceiptsReplayThroughTheRealGenerationBridge(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1') self::markTestSkipped('Transactional local receipts only');
        (new \think\App())->initialize();
        \think\facade\Db::startTrans();
        try {
            $task='test_revision_'.bin2hex(random_bytes(8));$scope=['tenant_id'=>2000000719,'user_id'=>7,'task_id'=>$task];
            $request=$this->request();$request['_prompt_task_id']=$task;$request['quality_review_enabled']=false;
            $edit=['action'=>'edit','message'=>'减少独立素材','operations'=>[
                ['entity'=>'subject','op'=>'remove','id'=>'p'],
                ['entity'=>'shot','op'=>'update','id'=>'s1','values'=>['subject_ref_ids'=>['a'],'visual_description'=>'甲思考','image_prompt'=>'甲思考']]],'expectations'=>['subjects_max'=>2]];
            foreach (['revision_edit'=>$edit,'revision_acceptance'=>['passed'=>true]] as $key=>$payload) {
                \think\facade\Db::name('aigc_short_drama_planning_unit')->insert($scope+['unit_key'=>'agent_'.$key,'status'=>'received','attempt'=>1,
                    'request_json'=>'{}','result_json'=>json_encode(['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'usage'=>['prompt_tokens'=>10,'completion_tokens'=>5]]]),'create_time'=>time(),'update_time'=>time()]);
            }
            $method=new \ReflectionMethod(\app\common\service\app\aigc_short_drama\AigcShortDramaService::class,'generateScriptPlanResultScoped');$method->setAccessible(true);
            $run=static fn()=>\app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit::withinDispatch(2000000719,7,$task,0,
                static fn()=>$method->invoke(null,2000000719,7,'原始故事',$request,'原剧本',['id'=>'offline-receipt-only','provider'=>'offline']));
            $first=$run();$second=$run();
            $this->assertTrue($first['result']['revision_response']['edited']);
            $this->assertCount(2,$first['result']['subjects']);
            $this->assertSame($first,$second);
            $this->assertSame(20,$first['llm']['usage']['prompt_tokens']);
            $this->assertSame(2,\think\facade\Db::name('aigc_short_drama_planning_unit')->where($scope)->count());
        } finally { \think\facade\Db::rollback(); }
    }
}
