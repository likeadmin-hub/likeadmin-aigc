<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeries as Series;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeriesVisuals as Visuals;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationAssetReuse as Reuse;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class CanvasSeriesProductionTest extends TestCase
{
    private function state(int $count=10): array { return ['workflow_snapshot'=>['series_core'=>true,'key'=>Workflow::KEY,'version'=>Series::VERSION],
        'slot_values'=>['episode_count'=>$count.'集'],'series'=>['episode_number'=>1],'stage_state'=>['key'=>'complete','status'=>'ready'],
        'state_revision'=>4,'artifact_memory'=>[['stage'=>'story','artifact'=>'story_setting','content'=>'设定'],['stage'=>'episode','artifact'=>'episode_script','content'=>'旧集']]]; }
    private function plan(): array { return ['title'=>'旧宅','story_outline'=>'寻找旧信','type_judgement'=>'生活','core_theme'=>'信任',
        'subjects'=>[['id'=>'a','name'=>'甲','description'=>'黑外套'],['id'=>'b','name'=>'乙','description'=>'白外套']],
        'locations'=>[['id'=>'room','name'=>'屋内'],['id'=>'yard','name'=>'院子'],['id'=>'unused','name'=>'小巷']],
        'storyboard'=>[
            ['shot_id'=>'first','scene_ref_id'=>'room','subject_ref_ids'=>['a','b'],'visual_description'=>'取信','recommended_duration_seconds'=>6,'dialogue'=>'甲：这是信。乙：谢谢。'],
            ['shot_id'=>'second','scene_ref_id'=>'yard','subject_ref_ids'=>['b'],'visual_description'=>'走出院子','recommended_duration_seconds'=>5,'dialogue'=>'乙：再见。']]]; }
    public function testLegacyDoesNotOptInAndSinglePlanningRequiresServerMarker(): void {
        self::assertFalse(Series::enabled([]));
        $r=['workflow_variant'=>ShortDramaStoryWorkflow::VARIANT,'multi_episode'=>true,'episode_count'=>1];
        self::assertFalse(ShortDramaStoryWorkflow::enabled($r));
        self::assertTrue(ShortDramaStoryWorkflow::enabled($r+['_canvas_series_planning'=>true]));
        self::assertSame(1,ShortDramaStoryWorkflow::storyContext([],$r+['_canvas_series_planning'=>true])['story_scale']['target_episode_count']);
    }
    public function testOneTenAndFiveHundredHaveExplicitStageBoundaries(): void {
        foreach([1,10,500] as $count) {
            $s=$this->state($count);self::assertSame($count,Series::count($s));
            foreach(['story','outline'] as $stage) { $s['stage_state']['key']=$stage; self::assertTrue(Series::requiresConfirmation($s)); }
            $s['stage_state']['key']='episode';self::assertFalse(Series::requiresConfirmation($s));
        }
        self::assertSame('outline',Series::next('story'));self::assertSame('episode',Series::next('outline'));
    }
    public function testNextEpisodeDropsOldEpisodeContextWithoutJumpingToEnd(): void {
        $s=Series::nextEpisode($this->state(500));self::assertSame(2,Series::episode($s));
        self::assertSame(['key'=>'episode','status'=>'ready','completed'=>['intake','story','outline']],$s['stage_state']);
        self::assertCount(1,$s['artifact_memory']);self::assertSame([1],$s['series']['completed_episodes']);
    }
    public function testFinalEpisodeCannotAdvance(): void {
        $this->expectException(RuntimeException::class);Series::nextEpisode($this->state(1));
    }
    public function testPendingMissingOrDeletedVideoCannotUnlockNextEpisode(): void {
        $state=$this->state();$state['artifact_memory']=[['artifact'=>'storyboard','node_id'=>'1'],['artifact'=>'storyboard_video','node_id'=>'2']];
        foreach([[],[['id'=>2,'metadata'=>['status'=>'running','video_url'=>'old','asset_id'=>5]]]] as $nodes) {
            try { Series::assertEpisodeMedia($state,$nodes);self::fail('Incomplete video accepted'); } catch(RuntimeException $e) { self::assertSame('WORKFLOW_EPISODE_MEDIA_INCOMPLETE',$e->getMessage()); }
        }
        Series::assertEpisodeMedia($state,[['id'=>2,'metadata'=>['status'=>'success','video_url'=>'done','asset_id'=>5]]]);
    }
    public function testProjectionUsesOnlyScenesAndSubjectsActuallyUsedAndKeepsEveryShot(): void {
        $render=static fn($artifact,$item)=>$item['name']??$item['visual_description'];
        $assets=Visuals::proposals($this->plan(),'assets',1,$render);self::assertCount(4,$assets);
        $boards=Visuals::proposals($this->plan(),'storyboard',1,$render);self::assertCount(4,$boards);
        self::assertSame(['屋内','院子','分镜1','分镜2'],array_column($boards,'title'));
        self::assertSame(['assets:'.Visuals::key('subject','a'),'assets:'.Visuals::key('subject','b')],$boards[2]['reference_keys']);
        $validate=new ReflectionMethod(GraphService::class,'validateAgentProposals');$validate->setAccessible(true);
        $validate->invoke(null,$assets,'assets',true);$validate->invoke(null,$boards,'storyboard',true);
    }
    public function testSubjectAndSceneStepNeverCreatesStoryboardsAndLaterStepReusesScenes(): void {
        $render=static fn($artifact,$item)=>$item['name']??$item['visual_description'];
        $assets=Visuals::proposals($this->plan(),'assets',1,$render);
        self::assertSame(['subject','subject','scene','scene'],array_column($assets,'artifact'));
        $refs=['room'=>'assets:'.Visuals::key('scene','room'),'yard'=>'assets:'.Visuals::key('scene','yard')];
        $boards=Visuals::proposals($this->plan(),'storyboard',1,$render,$refs);
        self::assertCount(2,$boards);
        self::assertSame(['storyboard','storyboard'],array_column($boards,'artifact'));
        self::assertContains($refs['room'],$boards[0]['reference_keys']);
        self::assertArrayNotHasKey('depends_on',$boards[0]);
        $validate=new ReflectionMethod(GraphService::class,'validateAgentProposals');$validate->setAccessible(true);
        $validate->invoke(null,$boards,'storyboard',true);
    }
    public function testMediaStepsPauseDurablyUntilUserContinues(): void {
        $next=new ReflectionMethod(Workflow::class,'nextStageState');$next->setAccessible(true);
        $card=new ReflectionMethod(Workflow::class,'card');$card->setAccessible(true);
        foreach(['assets'=>'storyboard','storyboard'=>'video_plan','video_nodes'=>'audio_plan'] as $from=>$to) {
            $stage=$next->invoke(null,$from,[$from]);
            self::assertSame($to,$stage['key']);
            self::assertSame('awaiting_stage_start',$stage['status']);
            $state=$this->state();$state['stage_state']=$stage;$state['workflow_snapshot']['stages']=[];
            self::assertSame('next_stage',$card->invoke(null,$state)['type']);
            self::assertFalse(Workflow::validAutoStageRequest($state,1,2,'as:1:2:4:'.$to,Workflow::autoStagePrompt($to)));
        }
        self::assertSame('ready',$next->invoke(null,'episode',['episode'])['status']);
        self::assertSame('ready',$next->invoke(null,'art',['art'])['status']);
    }
    public function testVideoProjectionPreservesMultiSpeakerTextAndCoreDurations(): void {
        $video=Visuals::proposals($this->plan(),'video_nodes',10,static fn($artifact,$item)=>'声音：'.$item['dialogue']);
        self::assertCount(2,$video);self::assertSame(6.0,$video[0]['duration_seconds']);
        self::assertStringContainsString('甲：这是信。乙：谢谢。',$video[0]['prompt']);
        self::assertSame(['storyboard:ep10_shot1'],$video[0]['reference_keys']);
    }
    public function testReuseRejectsWrongEntityChangedPromptMissingReceiptOrPendingResult(): void {
        $p=['artifact'=>'subject','key'=>Visuals::key('subject','a'),'prompt'=>'黑外套'];
        $sig=Reuse::signatures([$p],$this->state(),['image_model'=>['id'=>'gpt']])[0];
        $node=['type'=>'image','metadata'=>['status'=>'success','image'=>'/owned.png','asset_id'=>1,'prompt'=>'黑外套','workflow_asset_signature'=>$sig,'workflow_artifact'=>'subject','workflow_key'=>'assets:'.$p['key']]];
        self::assertTrue(Reuse::matches($node,$sig,$p));
        foreach(['status'=>'running','asset_id'=>0,'prompt'=>'白外套','workflow_key'=>'assets:other'] as $k=>$v) {
            $changed=$node;$changed['metadata'][$k]=$v;self::assertFalse(Reuse::matches($changed,$sig,$p));
        }
        self::assertNotSame($sig,Reuse::signatures([$p],$this->state(),['image_model'=>['id'=>'nano']])[0]);
    }
    public function testSettingAndOutlinePreviewsAreNotAutomaticallyConfirmed(): void {
        $card=new ReflectionMethod(Workflow::class,'card');$card->setAccessible(true);
        $s=$this->state();$s['workflow_snapshot']['stages']=[];
        foreach(['story','outline'] as $stage) {
            $s['stage_state']=['key'=>$stage,'status'=>'awaiting_stage_confirmation'];
            $s['stage_plan']=['nodes'=>[['title'=>'内容','prompt'=>'核对全文']]];
            $view=$card->invoke(null,$s);self::assertTrue($view['requires_user_confirmation']);self::assertSame('核对全文',$view['artifacts'][0]['content']);
        }
    }
    public function testSingleEpisodeActuallyUsesStoryThenOutlineEngineWithoutPrematureScreenplay(): void {
        $base=$this->plan();unset($base['storyboard']);$base['series_bible']=['audience'=>'成人','core_hook'=>'寻找旧信','logline'=>'取信','relationships'=>['朋友'],'world_rules'=>['现实'],'series_arc'=>'找到信'];
        $request=['_canvas_series_planning'=>true,'multi_episode'=>true,'workflow_variant'=>ShortDramaStoryWorkflow::VARIANT,'episode_count'=>1,'multi_episode_stage'=>'story'];
        $calls=[];
        $story=\app\common\service\app\aigc_short_drama\ShortDramaStoryGeneration::generate($request,['context_window'=>100000,'max_tokens'=>16384],static fn()=>['system_prompt'=>'','content'=>'旧宅'],
            static function($key) use(&$calls,$base) {$calls[]=$key;return ['result'=>['content'=>json_encode($base)]];});
        self::assertSame(['story'],$calls);self::assertSame([],$story['result']['episodes']);self::assertSame([],$story['result']['storyboard']);
        $request['multi_episode_stage']='episodes';$request['confirmed_story_snapshot']=$story['result'];$calls=[];
        $outline=\app\common\service\app\aigc_short_drama\ShortDramaStoryGeneration::generate($request,['context_window'=>100000,'max_tokens'=>16384],static fn()=>['system_prompt'=>'','content'=>'第1集'],
            static function($key) use(&$calls) {$calls[]=$key;return ['result'=>['content'=>json_encode(['episodes'=>[['episode_number'=>1,'title'=>'取信','story_outline'=>'找到旧信','conflict_point'=>'寻找','ending_hook'=>'读信']]])]];});
        self::assertSame(['outline_1_1'],$calls);self::assertCount(1,$outline['result']['episodes']);self::assertSame([],$outline['result']['storyboard']);
    }
    public function testSingleOutlineValidationIsOptIn(): void {
        $p=$this->plan();$p['episodes']=[['episode_number'=>1,'title'=>'开场','story_outline'=>'取信','conflict_point'=>'寻找','ending_hook'=>'找到']];
        self::assertCount(1,ShortDramaEpisodeService::validateOutline($p,1,true));
        $this->expectException(\Exception::class);ShortDramaEpisodeService::validateOutline($p,1);
    }
}
