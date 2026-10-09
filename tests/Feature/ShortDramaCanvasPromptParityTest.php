<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaPromptCatalog as Catalog;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace as Workspace;
use app\common\service\app\aigc_short_drama\ShortDramaVisualStyle as Style;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeriesVisuals as Visuals;
use PHPUnit\Framework\TestCase;

class ShortDramaCanvasPromptParityTest extends TestCase
{
    private function call(string $method,...$args) {
        $r=new \ReflectionMethod(Service::class,$method);$r->setAccessible(true);return $r->invokeArgs(null,$args);
    }
    private function plan(): array {
        return ['art_style'=>'东方水墨画风','subjects'=>[['id'=>'s1','name'=>'甲','description'=>'黑外套','main_image_prompt'=>'甲站在窗边','three_view_prompt'=>'甲的三面参考']],
            'locations'=>[['id'=>'room','name'=>'房间','scene_image_prompt'=>'旧房间']],
            'storyboard'=>[['shot_id'=>'1','scene_ref_id'=>'room','subject_ref_ids'=>['s1'],'visual_description'=>'甲开门',
                'video_prompt'=>'保持上游独有描述：门轴轻响，慢慢推门。','dialogue'=>'有人吗？','voice_role'=>'甲','recommended_duration_seconds'=>6]]];
    }
    public function testAllMediaSubmissionsMatchRegularAssemblerInDefaultAndCustomModes(): void {
        $plan=$this->plan();
        foreach (['application','custom'] as $mode) foreach (['subject'=>'subject_image','three_view'=>'three_view','scene'=>'scene_image','storyboard'=>'shot_image','storyboard_video'=>'shot_video'] as $artifact=>$type) {
            $doc=$type==='three_view'?'subject_views':$type;
            $snapshot=Workspace::resolve(701,['mode'=>'documents','document_settings'=>[$doc=>['mode'=>$mode,'body'=>'自定义要求：保留独特描述']]],[]);
            $item=in_array($artifact,['subject','three_view'])?$plan['subjects'][0]:($artifact==='scene'?$plan['locations'][0]:$plan['storyboard'][0]);
            $params=['prompt'=>'用户修改：保留门上的红字','model_id'=>'mock','channel'=>'mock','ratio'=>'16:9','duration'=>6,'resolution'=>'720p','_prompt_snapshot'=>$snapshot];
            $canvas=Service::canvasMediaSubmissionParams(701,$snapshot,$artifact,$item,$plan,$params);
            if (in_array($artifact,['subject','three_view'])) $params['subject_id']='s1';
            if ($artifact==='three_view') $params['view_mode']='three_view';
            if ($artifact==='scene') $params['scene_id']='room';
            $regular=Catalog::run($snapshot,fn()=> $type==='shot_video'
                ? $this->call('assembleVideoPromptRequest',701,$item,$params,$plan,'16:9',['reference_assets'=>[],'input_asset_ids'=>[],'reference_plan'=>[],'generation_method'=>'','first_frame_image'=>false,'last_frame_image'=>false],true)
                : $this->call('shortDramaImageParams',701,$item,$params,$type,$plan));
            self::assertSame($regular['prompt'],$canvas['prompt'],"$mode/$artifact");
            self::assertSame($regular['negative_prompt'],$canvas['negative_prompt']);
            self::assertStringContainsString('东方水墨画风',$canvas['prompt']);
            self::assertStringContainsString('用户修改：保留门上的红字',$canvas['prompt']);
        }
    }
    public function testNodePromptsPreserveSelectedStyleAndAuthoredVideo(): void {
        $plan=Style::plan($this->plan(),['style_name'=>'纸雕','style_prompt'=>'层叠纸张']);
        $snapshot=Workspace::resolve(701,['mode'=>'documents'],[]);
        foreach (['subject','three_view','scene','storyboard','storyboard_video'] as $artifact) {
            $item=in_array($artifact,['subject','three_view'])?$plan['subjects'][0]:($artifact==='scene'?$plan['locations'][0]:$plan['storyboard'][0]);
            $prompt=Service::canvasProductionPrompt($plan,$artifact,$item,$snapshot);
            self::assertStringContainsString('纸雕',$prompt);
            self::assertStringContainsString('层叠纸张',$prompt);
            if ($artifact==='storyboard_video') self::assertStringContainsString($item['video_prompt'],$prompt);
        }
        self::assertSame('东方水墨画风',$this->plan()['art_style']);
        self::assertSame('内容\n视觉风格：纸雕',Style::append('内容\n视觉风格：纸雕',['art_style'=>['base_style'=>'纸雕']]));
    }
    public function testSourceResolutionUsesStableKeys(): void {
        $plan=$this->plan();
        self::assertSame($plan['subjects'][0],Visuals::promptItem($plan,'subject','assets:'.Visuals::key('subject','s1')));
        self::assertSame($plan['subjects'][0],Visuals::promptItem($plan,'three_view','assets:'.Visuals::key('view','s1')));
        self::assertSame($plan['locations'][0],Visuals::promptItem($plan,'scene','assets:'.Visuals::key('scene','room')));
        self::assertSame($plan['storyboard'][0],Visuals::promptItem($plan,'storyboard_video','video_nodes:ep1_shot1'));
        $this->expectException(\RuntimeException::class);
        Visuals::promptItem($plan,'subject','assets:invalid');
    }
    public function testCrossTenantSnapshotRejected(): void {
        $this->expectException(\Exception::class);
        Service::canvasMediaSubmissionParams(2,['tenant_id'=>1],'subject',[],[],[]);
    }
}
