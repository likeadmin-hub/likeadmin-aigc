<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningContext;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit;
use app\common\service\app\aigc_short_drama\ShortDramaStoryGeneration;
use app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaTextBoundaryTest extends TestCase
{
    private function story(): array
    {
        return ['title'=>'旧宅', 'type_judgement'=>'悬疑', 'core_theme'=>'信任', 'story_outline'=>'寻找旧信',
            'subjects'=>[['id'=>'s1','name'=>'甲']], 'locations'=>[['id'=>'l1','name'=>'老宅']]];
    }

    private function episode(int $number): array
    {
        return ['episode_number'=>$number,'title'=>'旧信'.$number,'story_outline'=>'保留剧情'.$number,
            'conflict_point'=>'冲突'.$number,'ending_hook'=>'线索'.$number];
    }

    public function testDisabledEditorialReviewStillReportsEveryNonTextOutlineField(): void
    {
        foreach (['title','story_outline','conflict_point','ending_hook'] as $field) {
            foreach ([['text'=>'不能猜成字符串'], 123, false, null] as $invalid) {
                $episode = $this->episode(1);
                $episode[$field] = $invalid;
                $issues = ShortDramaStoryWorkflow::issues($this->story()+['episodes'=>[$episode]], 'episodes', 1, false, true);
                self::assertContains('episodes.0.'.$field, array_column($issues,'path'));
                try {
                    ShortDramaStoryGeneration::episodes(['episodes'=>[$episode]],1,1,false);
                    self::fail('A non-string provider field must enter the bounded 422 repair path');
                } catch (\RuntimeException $error) { self::assertSame(422,$error->getCode()); }
            }
        }
    }

    public function testContextRejectsStructuredTextWithoutDroppingConfirmedFacts(): void
    {
        foreach (['id','name','description'] as $field) {
            $reference = ['id'=>'s1','name'=>'甲','description'=>'用户确认的身份事实'];
            $reference[$field] = ['background'=>'用户确认的身份事实'];
            $request = ['subject_references'=>[$reference]];
            $before = $request;
            try {
                ShortDramaPlanningContext::values($request);
                self::fail('Structured facts must neither become Array nor disappear from a budgeted context');
            } catch (\RuntimeException $error) {
                self::assertSame(422,$error->getCode());
                self::assertStringContainsString('文本',$error->getMessage());
            }
            self::assertSame($before,$request);
        }
    }

    public function testUnknownStructuredContinuityRuleCannotSilentlyDisappear(): void
    {
        $memory = ['series_bible'=>['continuity_rules'=>[['owner'=>'甲','fact'=>'信A不是信B']]]];
        $before = $memory;
        try {
            ShortDramaPlanningContext::episodeMemory($memory);
            self::fail('Unknown structured facts require correction instead of an empty projected ledger');
        } catch (\RuntimeException $error) { self::assertSame(422,$error->getCode()); }
        self::assertSame($before,$memory);
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function testOldReceivedMalformedOutlineRepairsOnlyFailedEpisodeWithoutRepeatingPaidCalls(): void
    {
        // Fully isolated, in-memory SQLite: never connect to a tenant/development database.
        (new \think\App())->initialize();
        \think\facade\Config::set(['default'=>'short_drama_boundary_test','connections'=>['short_drama_boundary_test'=>[
            'type'=>'sqlite','database'=>':memory:','prefix'=>'la_','fields_strict'=>true,'fields_cache'=>false,
        ]]],'database');
        Db::execute('CREATE TABLE la_aigc_short_drama_planning_unit (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, task_id TEXT, unit_key TEXT, status TEXT, attempt INTEGER, request_json TEXT, result_json TEXT, error TEXT DEFAULT "", create_time INTEGER, update_time INTEGER)');
        $scope=['tenant_id'=>1,'user_id'=>2,'task_id'=>'old_received_boundary'];
        $assemble=static fn(array $request): array=>['system_prompt'=>'只返回分集大纲','content'=>json_encode([
            'start'=>$request['episode_batch_start'],'count'=>$request['episode_count']],JSON_UNESCAPED_UNICODE)];
        $good=['episodes'=>array_map(fn($n)=>$this->episode($n),range(1,10))];
        $bad=['episodes'=>[$this->episode(11)]];
        $bad['episodes'][0]['story_outline']=['text'=>'模型返回错误结构，不能静默转成文本'];
        foreach (['outline_1_10'=>$good,'outline_11_1'=>$bad] as $key=>$payload) {
            $messages=$assemble(['episode_batch_start'=>$key==='outline_1_10'?1:11,'episode_count'=>$key==='outline_1_10'?10:1]);
            Db::name('aigc_short_drama_planning_unit')->insert($scope+['unit_key'=>$key,'status'=>'received','attempt'=>1,
                'request_json'=>json_encode($messages,JSON_UNESCAPED_UNICODE),
                'result_json'=>json_encode(['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]],JSON_UNESCAPED_UNICODE),
                'create_time'=>time(),'update_time'=>time()]);
        }
        $before=Db::name('aigc_short_drama_planning_unit')->order('id')->column('result_json','unit_key');
        $paid=[];
        $result=ShortDramaStoryGeneration::generate(['multi_episode_stage'=>'episodes','episode_count'=>11,
            'confirmed_story_snapshot'=>$this->story(),'quality_review_enabled'=>false],
            ['context_window'=>100000,'max_tokens'=>16384],$assemble,
            function(string $key,array $messages)use($scope,&$paid):array{
                return ShortDramaPlanningUnit::call(1,2,$scope['task_id'],$key,$messages,
                    function(array $effective)use($key,&$paid):array{
                        $paid[]=$key;
                        // Returning an old received response must be impossible here.
                        self::assertSame('outline_11_1_repair_v2',$key);
                        self::assertStringContainsString('保留已生成的合格剧情字段',$effective['content']);
                        return ['result'=>['content'=>json_encode(['episodes'=>[$this->episode(11)]],JSON_UNESCAPED_UNICODE)]];
                    },true);
            });
        self::assertSame(['outline_11_1_repair_v2'],$paid);
        self::assertSame(range(1,11),array_column($result['result']['episodes'],'episode_number'));
        self::assertSame($good['episodes'],array_slice($result['result']['episodes'],0,10));
        self::assertSame('保留剧情11',$result['result']['episodes'][10]['story_outline']);
        foreach($before as $key=>$json) self::assertSame($json,Db::name('aigc_short_drama_planning_unit')->where('unit_key',$key)->value('result_json'));
        self::assertSame('superseded:continued with a smaller or repaired unit',Db::name('aigc_short_drama_planning_unit')->where('unit_key','outline_11_1')->value('error'));
        self::assertSame(3,Db::name('aigc_short_drama_planning_unit')->count());
    }
}
