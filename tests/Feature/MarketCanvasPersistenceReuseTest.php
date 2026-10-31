<?php
namespace Tests\Feature;

use app\common\service\app\aigc_canvas\AigcCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Drama;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Real service/SQL round trips on new synthetic documents, always rolled back. */
class MarketCanvasPersistenceReuseTest extends TestCase
{
    private array $documents=[];
    private const TENANT=1;
    private const USER=1999999381;

    protected function setUp(): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        Db::startTrans();
    }
    protected function tearDown(): void
    {
        if(!$this->documents)return;
        Db::rollback();
        foreach($this->documents as [$table,$id])self::assertSame(0,(int)Db::name($table)->where('id',$id)->count(),'Synthetic document must not survive rollback');
    }
    private function nodes(): array
    {
        $image='https://fixtures.invalid/same.png';
        $video='https://fixtures.invalid/clip.mp4';
        $audio='https://fixtures.invalid/audio.wav';
        $metadata=[
            'channel'=>'declared-persistence-fixture','mode'=>'steady','resolution'=>'720p','duration'=>5,'ratio'=>'1:1',
            'generation_method'=>'start_end',
            'generationParameters'=>['declared-persistence-fixture'=>['sound'=>false,'seed'=>0,'optional'=>'']],
            'generationMaterialModes'=>['declared-persistence-fixture'=>'groups'],
            'generationMaterials'=>['declared-persistence-fixture'=>[
                'group_a'=>[['name'=>'Synthetic A','images'=>[$image],'video_url'=>$video,'audio_url'=>$audio,'enabled'=>false,'start_time'=>0]],
                'group_b'=>[['name'=>'Synthetic B','images'=>[$image],'video_url'=>$video,'audio_url'=>$audio,'enabled'=>false,'start_time'=>0]],
            ]],
            'referenceImages'=>[
                ['url'=>$image,'uri'=>$image,'role'=>'last_frame_image','field'=>'last_frame_url','reference_slot_id'=>'tail-use','sourceId'=>'9002'],
                ['url'=>$image,'uri'=>$image,'role'=>'first_frame_image','field'=>'first_frame_url','reference_slot_id'=>'head-use','sourceId'=>'9001'],
            ],
            'referenceVideos'=>[['url'=>$video,'uri'=>$video,'role'=>'reference_video','field'=>'reference_video','reference_slot_id'=>'clip-use']],
            'referenceAudios'=>[['url'=>$audio,'uri'=>$audio,'role'=>'reference_audio','field'=>'reference_voice','reference_slot_id'=>'voice-use']],
            'startEndReferenceOrder'=>['tail-use','head-use'],
            'legacy_snapshot'=>['false'=>false,'zero'=>0,'empty'=>'','null'=>null],
        ];
        return array_map(static fn($id,$meta)=>['id'=>(string)$id,'type'=>$id===9003?'video':'image','title'=>'Synthetic persisted fixture',
            'position'=>['x'=>($id-9001)*440,'y'=>0],'width'=>400,'height'=>300,'metadata'=>$meta],
            [9001,9002,9003],[['url'=>$image,'image'=>$image],['url'=>$image,'image'=>$image],$metadata]);
    }
    private function assertTarget(array $nodes,array $expected): void
    {
        $target=array_values(array_filter($nodes,static fn($node)=>(string)$node['id']==='9003'))[0];
        $business=$target['metadata'];
        foreach(['content_revision','layout_revision'] as $revision){if(array_key_exists($revision,$business)){self::assertGreaterThan(0,$business[$revision]);unset($business[$revision]);}}
        self::assertSame($expected,$business);
        self::assertSame(['last_frame_image','first_frame_image'],array_column($target['metadata']['referenceImages'],'role'));
        self::assertSame(['tail-use','head-use'],array_column($target['metadata']['referenceImages'],'reference_slot_id'));
        self::assertFalse($target['metadata']['generationParameters']['declared-persistence-fixture']['sound']);
        self::assertSame(0,$target['metadata']['generationParameters']['declared-persistence-fixture']['seed']);
    }
    private function refuses(callable $call): void
    {
        try{$call();}catch(\Throwable $error){self::assertMatchesRegularExpression('/不存在|无权|VERSION_CONFLICT|其他标签页|Agent 已更新/',$error->getMessage());return;}
        self::fail('Expected project ownership or revision rejection');
    }
    public function testOrdinaryCanvasSqlSaveAndReopenRetainMixedUsesAndLegacyDefaults(): void
    {
        $project=Canvas::createProject(self::TENANT,self::USER,['name'=>'Rollback graph fixture']);
        $this->documents[]=['aigc_canvas_project',$project['id']];
        $nodes=$this->nodes();
        $payload=['id'=>$project['id'],'name'=>'Rollback graph fixture','nodes'=>$nodes,
            'edges'=>[['id'=>'head','fromNodeId'=>'9001','toNodeId'=>'9003'],['id'=>'tail','fromNodeId'=>'9002','toNodeId'=>'9003']],
            'viewport'=>$project['viewport'],'save_request_id'=>'rollback_graph_fixture_001'];
        $saved=Canvas::saveProject(self::TENANT,self::USER,$payload);
        $read=Canvas::projectDetail(self::TENANT,self::USER,(int)$project['id']);
        $this->assertTarget($read['nodes'],$nodes[2]['metadata']);
        self::assertSame($saved['edges'],$read['edges']);
        self::assertSame($read['nodes'],Canvas::projectDetail(self::TENANT,self::USER,(int)$project['id'])['nodes']);
        $this->refuses(fn()=>Canvas::projectDetail(self::TENANT,self::USER+1,(int)$project['id']));
        $this->refuses(fn()=>Canvas::saveProject(self::TENANT+1,self::USER,$payload));
        $edit=$payload;$edit['viewport']=$read['viewport'];$edit['save_request_id']='rollback_graph_fixture_002';$edit['nodes'][2]['metadata']['duration']=6;
        Canvas::saveProject(self::TENANT,self::USER,$edit);
        $stale=$payload;$stale['save_request_id']='rollback_graph_fixture_003';$stale['nodes'][2]['metadata']['duration']=7;
        $this->refuses(fn()=>Canvas::saveProject(self::TENANT,self::USER,$stale));
        self::assertSame(6,Canvas::projectDetail(self::TENANT,self::USER,(int)$project['id'])['nodes'][2]['metadata']['duration']);
    }
    public function testShortDramaSqlSaveAndReopenRetainMixedUsesAndRejectStaleOwnership(): void
    {
        $project=Drama::create(self::TENANT,self::USER,['title'=>'Rollback graph fixture']);
        $this->documents[]=['aigc_short_drama_canvas',$project['id']];
        $nodes=$this->nodes();
        $payload=['id'=>$project['id'],'title'=>'Rollback graph fixture','nodes'=>$nodes,
            'edges'=>[['from'=>9001,'to'=>9003],['from'=>9002,'to'=>9003]],'viewport'=>['x'=>0,'y'=>0,'k'=>1],
            'expected_document_token'=>$project['document_token']];
        if(isset($project['graph_revision']))$payload['expected_revision']=$project['graph_revision'];
        $saved=Drama::save(self::TENANT,self::USER,$payload);
        $read=Drama::current(self::TENANT,self::USER,(int)$project['id']);
        $this->assertTarget($read['nodes'],$nodes[2]['metadata']);
        self::assertSame($saved['edges'],$read['edges']);
        self::assertSame($read['nodes'],Drama::current(self::TENANT,self::USER,(int)$project['id'])['nodes']);
        $this->refuses(fn()=>Drama::current(self::TENANT,self::USER+1,(int)$project['id']));
        $this->refuses(fn()=>Drama::save(self::TENANT+1,self::USER,$payload));
        $this->refuses(fn()=>Drama::save(self::TENANT,self::USER,$payload));
        self::assertSame($read['nodes'],Drama::current(self::TENANT,self::USER,(int)$project['id'])['nodes']);
    }
}
