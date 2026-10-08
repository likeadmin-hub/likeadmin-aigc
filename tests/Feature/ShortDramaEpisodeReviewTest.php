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
    public function testSameEpisodeBlockStateReferenceIsRepairedWithoutRewritingShots(): void
    {
        $plan = $this->plan(5); $corrections = 0;
        $result = Review::generate($plan, $this->request(), function ($key, $messages) use (&$corrections) {
            self::assertStringNotContainsString('"patches"', $messages['system_prompt']);
            if (str_contains($messages['content'], '仅修正审校JSON')) {
                $corrections++;
                $contract = json_decode($messages['content'], true)['response_contract'];
                self::assertSame(['evidence_patches'], array_keys($contract));
                self::assertArrayNotHasKey('quote', $contract['evidence_patches'][0]);
                return ['evidence_patches' => [['collection'=>'changes', 'index'=>0, 'before'=>'房间']]];
            }
            $input = json_decode($messages['content'], true);
            if (isset($input['response_contract']['checks'])) return ['checks'=>[['collection'=>'changes','index'=>0,'supported'=>true,'reason'=>'镜头中甲走进房间']]];
            self::assertEmpty($input['script']['script_lines'] ?? []);
            self::assertNotEmpty($input['review_window']);
            return ['summary'=>'甲在房间', 'changes'=>[['entity_id'=>'a','field'=>'location','before'=>null,'after'=>'房间',
                'shot_id'=>$input['script']['storyboard'][0]['shot_id'],'quote'=>'甲走进房间']], 'hooks'=>[], 'warnings'=>[]];
        });
        self::assertSame(1, $corrections);
        self::assertSame('verified', $result['_continuity']['audit_status']);
        self::assertSame($plan['storyboard'], $result['storyboard']);
    }

    public function testPreviousEpisodeConflictStillBlocks(): void
    {
        $request = $this->request(); $request['series_context']['continuity']['state'] = ['a:location'=>'街道'];
        $this->expectExceptionCode(409);
        Review::generate($this->plan(1), $request, function ($key, $messages) {
            if (str_ends_with($key, '_patch')) return ['patches'=>[]];
            $input=json_decode($messages['content'], true);
            if (isset($input['response_contract']['checks'])) return ['checks'=>[['collection'=>'changes','index'=>0,'supported'=>true,'reason'=>'镜头证据']]];
            return ['summary'=>'甲在房间','changes'=>[['entity_id'=>'a','field'=>'location','before'=>'屋外','after'=>'房间','shot_id'=>'s1','quote'=>'甲走进房间']], 'hooks'=>[], 'warnings'=>[]];
        });
    }
    public function testInvalidCorrectionIsValidatedInsideReceiptBoundary(): void
    {
        $validated = false;
        try {
            Review::generate($this->plan(1), $this->request(), function ($key, $messages, $budget, $validate = null) use (&$validated) {
                if (str_contains($messages['content'], '仅修正审校JSON')) {
                    self::assertNotNull($validate);
                    $validated = true;
                    $validate(['evidence_patches'=>[['collection'=>'changes','index'=>9,'before'=>null]]]);
                }
                $input=json_decode($messages['content'], true);
                if (isset($input['response_contract']['checks'])) return ['checks'=>[['collection'=>'changes','index'=>0,'supported'=>true,'reason'=>'有证据']]];
                return ['summary'=>'甲在房间', 'changes'=>[['entity_id'=>'a','field'=>'location','before'=>'未登记','after'=>'房间','shot_id'=>'s1','quote'=>'甲走进房间']], 'hooks'=>[], 'warnings'=>[]];
            });
            self::fail('Malformed patch must fail');
        } catch (\RuntimeException $error) {
            self::assertSame(422, $error->getCode());
            self::assertTrue($validated);
        }
    }
    public function testUnchangedVerifiedStateDoesNotRequireNewBlockEvidence(): void
    {
        $request = $this->request(); $request['series_context']['continuity']['state'] = ['a:emotion'=>'释然、温情'];
        $result = Review::generate($this->plan(1), $request, function ($key, $messages) {
            $input=json_decode($messages['content'], true);
            if (isset($input['response_contract']['checks'])) {
                self::assertSame([], $input['review']['changes']);
                return ['checks'=>[]];
            }
            return ['summary'=>'甲在房间', 'changes'=>[['entity_id'=>'a','field'=>'emotion','before'=>'释然、温情','after'=>'释然、温情','shot_id'=>'s1','quote'=>'甲走进房间']], 'hooks'=>[], 'warnings'=>[]];
        });
        self::assertSame('verified', $result['_continuity']['audit_status']);
        self::assertSame('释然、温情', $result['_continuity']['state']['a:emotion']);
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
    public function testLaterBlockReceivesOriginalEarlierEvidenceWithoutFutureOrMedia(): void
    {
        $plan = $this->plan(6);
        $plan['storyboard'][2]['visual_description'] = '甲找到收音机，天线断折。';
        $plan['storyboard'][5]['visual_description'] = '未来才修好天线';
        $seen = false;
        $result = Review::generate($plan, $this->request(), function ($key, $messages) use (&$seen) {
            $input = json_decode($messages['content'], true);
            if (isset($input['response_contract']['checks'])) {
                $checks = $input['response_contract']['checks'];
                if ($checks) {
                    $seen = true;
                    self::assertSame(['s1','s2','s3','s4'], array_column($input['earlier_episode_shots'], 'shot_id'));
                    self::assertSame('甲找到收音机，天线断折。', $input['earlier_episode_shots'][2]['visual_description']);
                    self::assertStringNotContainsString('制作描述', json_encode($input, JSON_UNESCAPED_UNICODE));
                    return ['checks'=>[['collection'=>'hooks','index'=>0,'supported'=>true,'reason'=>'本集前段展示损坏，后段延续铺垫']]];
                }
                self::assertSame([], $input['earlier_episode_shots']);
                return ['checks'=>[]];
            }
            $later = $input['script']['storyboard'][0]['shot_id'] === 's5';
            return ['summary'=>'继续修理','changes'=>[], 'hooks'=>$later ? [['id'=>'repair','description'=>'断折的天线等待修复','status'=>'open','shot_id'=>'s5','quote'=>'甲走进房间']] : [], 'warnings'=>[]];
        });
        self::assertTrue($seen);
        self::assertSame('verified', $result['_continuity']['audit_status']);
        self::assertSame($plan['storyboard'], $result['storyboard']);
    }
    public function testEvidenceRetrievalFindsDistantRelevantShotAndRemainsBounded(): void
    {
        $shots = $this->plan(50)['storyboard'];
        $shots[0]['visual_description'] = '收音机天线断折，等待修复';
        $evidence = Review::earlierEvidence($shots, ['hooks'=>[['description'=>'收音机天线断折等待修复']]]);
        self::assertCount(4, $evidence);
        self::assertContains('s1', array_column($evidence, 'shot_id'));
        self::assertArrayNotHasKey('video_prompt', $evidence[0]);
    }
    public function testInventedAuditQualifierIsCorrectedAndIndependentlyVerifiedWithoutEditingScript(): void
    {
        $plan = $this->plan(1); $verifications = 0;
        $result = Review::generate($plan, $this->request(), function ($key, $messages, $budget, $validate = null) use (&$verifications) {
            $input = json_decode($messages['content'], true);
            if (isset($input['response_contract']['claim_patches'])) {
                $reply = ['claim_patches'=>[['collection'=>'changes','index'=>0,'value'=>'房间','shot_id'=>'s1','quote'=>'甲走进房间','reason'=>'正文未指定房间左侧，删除审校推断的位置修饰']]];
                $validate($reply); return $reply;
            }
            if (isset($input['response_contract']['checks'])) {
                $verifications++;
                return ['checks'=>[['collection'=>'changes','index'=>0,'supported'=>$input['review']['changes'][0]['after'] === '房间','reason'=>'镜头只显示房间，不含左侧']]];
            }
            return ['summary'=>'进入房间','changes'=>[['entity_id'=>'a','field'=>'location','before'=>null,'after'=>'房间左侧','shot_id'=>'s1','quote'=>'甲走进房间']], 'hooks'=>[], 'warnings'=>[]];
        });
        self::assertSame(2, $verifications);
        self::assertSame('verified', $result['_continuity']['audit_status']);
        self::assertSame('房间', $result['_continuity']['state']['a:location']);
        self::assertSame('房间左侧', $result['_continuity']['audit_corrections'][0]['original']['after']);
        self::assertSame($plan['storyboard'], $result['storyboard']);
    }
    public function testUnsupportedAuditCorrectionStillBlocks(): void
    {
        $result = Review::generate($this->plan(1), $this->request(), function ($key, $messages, $budget, $validate = null) {
            $input = json_decode($messages['content'], true);
            if (isset($input['response_contract']['claim_patches'])) {
                $reply = ['claim_patches'=>[['collection'=>'changes','index'=>0,'value'=>'另一个无证据地点','shot_id'=>'s1','quote'=>'甲走进房间','reason'=>'错误修正']]];
                $validate($reply); return $reply;
            }
            if (isset($input['response_contract']['checks'])) return ['checks'=>[['collection'=>'changes','index'=>0,'supported'=>false,'reason'=>'无地点证据']]];
            return ['summary'=>'进入房间','changes'=>[['entity_id'=>'a','field'=>'location','before'=>null,'after'=>'屋顶','shot_id'=>'s1','quote'=>'甲走进房间']], 'hooks'=>[], 'warnings'=>[]];
        });
        self::assertSame('pending_review', $result['_continuity']['audit_status']);
        self::assertArrayNotHasKey('a:location', $result['_continuity']['state']);
    }
    public function testUnknownResolutionCanBecomeVerifiedCurrentEventWithoutClosingExistingHook(): void
    {
        $request = $this->request();
        $request['series_context']['continuity']['open_hooks'] = ['promise'=>'甲答应修好收音机'];
        $checks = 0;
        $result = Review::generate($this->plan(1), $request, function ($key, $messages, $budget, $validate = null) use (&$checks) {
            $input = json_decode($messages['content'], true);
            if (isset($input['response_contract']['claim_patches'])) {
                $reply = ['claim_patches'=>[['collection'=>'hooks','index'=>0,'value'=>'甲走进房间','status'=>'observed','shot_id'=>'s1','quote'=>'甲走进房间','reason'=>'只是本集发生的进门事件，不是已登记的修理承诺']]];
                $validate($reply); return $reply;
            }
            if (isset($input['response_contract']['checks'])) {
                $checks++;
                return ['checks'=>[['collection'=>'hooks','index'=>0,'supported'=>true,'reason'=>'正文展示进门']]];
            }
            return ['summary'=>'进入房间','changes'=>[], 'hooks'=>[['id'=>'entered_room','description'=>'甲走进房间','status'=>'resolved','shot_id'=>'s1','quote'=>'甲走进房间']], 'warnings'=>[]];
        });
        self::assertSame(2, $checks);
        self::assertSame('verified', $result['_continuity']['audit_status']);
        self::assertSame(['promise'=>'甲答应修好收音机'], $result['_continuity']['open_hooks']);
    }
    public function testUnchangedExistingHookIsNotInventedAgainOrRequiredToRepeatItsSetup(): void
    {
        $request = $this->request();
        $request['series_context']['continuity']['open_hooks'] = ['letter'=>'前集收到的信，尚未拆开'];
        $result = Review::generate($this->plan(1), $request, function ($key, $messages) {
            $input = json_decode($messages['content'], true);
            if (isset($input['response_contract']['checks'])) {
                self::assertSame([], $input['review']['hooks']); return ['checks'=>[]];
            }
            return ['summary'=>'进入房间','changes'=>[], 'hooks'=>[['id'=>'letter','description'=>'前集收到的信，尚未拆开','status'=>'open','shot_id'=>'s1','quote'=>'甲走进房间']], 'warnings'=>[]];
        });
        self::assertSame('verified', $result['_continuity']['audit_status']);
        self::assertSame(['letter'=>'前集收到的信，尚未拆开'], $result['_continuity']['open_hooks']);
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
