<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaEpisodeReview as Review;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService as Episodes;
use PHPUnit\Framework\TestCase;

class ShortDramaEpisodeReviewTest extends TestCase
{
    private function plan(int $count = 9): array
    {
        return ['title'=>'重逢','story_outline'=>'甲走进房间','script_lines'=>['甲走进房间'],
            'subjects'=>[['id'=>'a','name'=>'甲','description'=>'寻找朋友','image'=>'original-subject']],
            'locations'=>[['id'=>'room','name'=>'房间']],
            'storyboard'=>array_map(static fn($n)=>['shot_id'=>'s'.$n,'scene_ref_id'=>'room','subject_ref_ids'=>['a'],
                'dialogue'=>'你好。','visual_description'=>'甲走进房间','voice_role'=>'甲','speech_type'=>'character',
                'recommended_duration_seconds'=>5,'image_url'=>'existing-image','video_prompt'=>str_repeat('制作描述',2000)], range(1,$count))];
    }
    private function request(): array
    {
        return ['episode_number'=>2,'series_context'=>['outline'=>['title'=>'重逢'],
            'continuity'=>['previous_digest'=>'previous','state'=>[],'open_hooks'=>[]]]];
    }
    private function reply(array $input): array
    {
        return isset($input['response_contract']['checks']) ? ['checks'=>[]]
            : ['summary'=>'甲走进房间','changes'=>[],'hooks'=>[],'warnings'=>[]];
    }
    public function testReviewKeepsCompleteSavedPlanAndNeverSendsProductionFields(): void
    {
        $plan=$this->plan(); $calls=0;
        $result=Review::generate($plan,$this->request(),function($key,$messages)use(&$calls){
            $calls++;
            self::assertStringNotContainsString('制作描述',$messages['content']);
            self::assertStringNotContainsString('existing-image',$messages['content']);
            $input=json_decode($messages['content'],true);
            self::assertLessThanOrEqual(4,count($input['script']['storyboard'] ?? $input['storyboard']));
            return $this->reply($input);
        });
        self::assertSame(6,$calls);
        self::assertSame('previous',$result['_continuity']['previous_digest']);
        self::assertSame('verified',$result['_continuity']['audit_status']);
        unset($result['_continuity']); self::assertSame($plan,$result);
    }
    public function testCapacityFailureSplitsOnlyCurrentBlock(): void
    {
        $seen=[];
        $result=Review::generate($this->plan(5),$this->request(),function($key,$messages)use(&$seen){
            $input=json_decode($messages['content'],true);
            $shots=$input['script']['storyboard'] ?? $input['storyboard'];
            if(count($shots)>2)throw new \RuntimeException('容量不足',413);
            if(isset($input['script']))array_push($seen,...array_column($shots,'shot_id'));
            return $this->reply($input);
        });
        self::assertSame(['s1','s2','s3','s4','s5'],$seen);
        self::assertCount(5,$result['storyboard']);
    }
    public function testPatchPreservesMediaIdentityTimingAndUnrelatedShots(): void
    {
        $plan=$this->plan(2);
        $patch=['patches'=>[['shot_id'=>'s1','field'=>'dialogue','before'=>'你好。','after'=>'再见。','reason'=>'前集已告别']]];
        $result=Review::apply($plan,$patch);
        self::assertSame('再见。',$result['storyboard'][0]['dialogue']);
        self::assertSame($plan['storyboard'][1],$result['storyboard'][1]);
        unset($result['storyboard'][0]['dialogue'],$plan['storyboard'][0]['dialogue']);
        self::assertSame($plan,$result);
    }
    public function testPatchCannotOverwriteChangedOriginal(): void
    {
        $this->expectExceptionCode(422);
        Review::apply($this->plan(),['patches'=>[['shot_id'=>'s1','field'=>'dialogue','before'=>'错误原文','after'=>'再见。','reason'=>'修复']]]);
    }
    public function testPatchCannotChangeDuration(): void
    {
        $this->expectExceptionCode(422);
        Review::apply($this->plan(),['patches'=>[['shot_id'=>'s1','field'=>'recommended_duration_seconds','before'=>5,'after'=>10,'reason'=>'修复']]]);
    }
    public function testFailedReviewKeepsOriginalEpisodeViewableAndRetryable(): void
    {
        $result=Episodes::summary(['status'=>'failed','completed_once'=>1,'error'=>'模型容量不足']);
        self::assertTrue($result['ready']); self::assertSame('success',$result['status']);
        self::assertSame('failed',$result['queue_state']); self::assertSame('failed',$result['review_status']);
        self::assertTrue($result['can_retry']); self::assertTrue($result['needs_review']);
    }
}
