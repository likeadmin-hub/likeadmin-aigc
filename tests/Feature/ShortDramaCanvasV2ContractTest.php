<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaCanvasV2Graph as Graph;
use app\api\controller\app\aigc_short_drama\CanvasV2Controller;
use PHPUnit\Framework\TestCase;

class ShortDramaCanvasV2ContractTest extends TestCase
{
    private function document(): array
    {return ['id'=>12,'title'=>'Existing','graph_revision'=>3,'document_token'=>str_repeat('a',64),'viewport'=>['x'=>0,'y'=>0,'k'=>1,'v2_project_id'=>7],
        'nodes'=>[['id'=>'4','type'=>'video','title'=>'Shot','x'=>0,'y'=>0,'width'=>320,'height'=>240,'metadata'=>['v2_node_key'=>'node_old','v2_business_kind'=>'storyboard','prompt'=>'old','active_generation_id'=>9,'status'=>'running']]],
        'edges'=>[],'removed_node_ids'=>['8'],'runs'=>[['id'=>19,'node_id'=>'4','type'=>'video','status'=>'success','results'=>[['url'=>'https://example.invalid/managed.mp4']]]]];}
    public function testEveryFrontendEndpointIsAuthenticatedAndDeclared(): void
    {
        $schema=json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_short_drama/api_schema.json'),true,512,JSON_THROW_ON_ERROR);
        $api=[];foreach ($schema['apis'] as $row) $api[$row['api_path']]=$row;
        foreach (['capabilities'=>'GET','create'=>'POST','projectWorkspace'=>'GET','detail'=>'GET','saveGraph'=>'POST','agent'=>'POST','agentStatus'=>'GET','quoteMedia'=>'POST','submitMedia'=>'POST','refreshTask'=>'GET','applyNode'=>'POST'] as $name=>$verb) {
            self::assertTrue((new \ReflectionClass(CanvasV2Controller::class))->hasMethod($name));
            $row=$api['app.aigc_short_drama.canvas_v2/'.$name];self::assertSame($verb,$row['api_method']);self::assertSame(1,$row['need_login']);
            self::assertSame('user',$row['scene']);self::assertSame('aigc_short_drama:canvas:use:user',$row['permission_key']);
        }
    }
    public function testRoundTripRetainsStableIdentityOwnedTaskAndProjectScope(): void
    {
        $document=$this->document();$projected=Graph::project($document);
        self::assertSame('node_old',$projected['nodes'][0]['node_key']);self::assertSame(19,$projected['nodes'][0]['task']['id']);
        self::assertSame('https://example.invalid/managed.mp4',$projected['nodes'][0]['task']['result']['assets'][0]['url']);
        $new=['node_key'=>'node_new','node_type'=>'image','business_kind'=>'scene','title'=>'New','content'=>['prompt'=>'new','status'=>'success','active_generation_id'=>999],'position'=>['x'=>30,'y'=>40],'width'=>320,'height'=>240];
        $request=$projected;$request['nodes'][]=$new;$request['edges'][]=['edge_key'=>'edge_1','from_node_key'=>'node_old','to_node_key'=>'node_new','edge_type'=>'reference'];
        $saved=Graph::saveInput($document,$request);
        self::assertSame('4',$saved['nodes'][0]['id']);self::assertSame('9',$saved['nodes'][1]['id']);
        self::assertSame(9,$saved['nodes'][0]['metadata']['active_generation_id']);self::assertArrayNotHasKey('status',$saved['nodes'][1]['metadata']);
        self::assertArrayNotHasKey('active_generation_id',$saved['nodes'][1]['metadata']);self::assertSame(7,$saved['viewport']['v2_project_id']);
        self::assertSame('4',$saved['edges'][0]['from']);self::assertSame('9',$saved['edges'][0]['to']);self::assertSame(3,$saved['expected_revision']);
        self::assertSame($document['document_token'],$saved['expected_document_token']);
    }
    public function testExplicitRemovalRetainsTombstonesAndNeverReusesDeletedIds(): void
    {
        $doc=$this->document();$graph=Graph::project($doc);$graph['nodes']=[];
        self::assertSame(['4'],Graph::saveInput($doc,$graph)['removed_node_ids']);
    }
    /** @dataProvider invalidGraphs */
    public function testInvalidOrStaleGraphsCannotBePartiallySaved(string $case): void
    {
        $doc=$this->document();$graph=Graph::project($doc);
        if ($case==='stale')$graph['version']=2;
        if ($case==='duplicate')$graph['nodes'][]=$graph['nodes'][0];
        if ($case==='infinite')$graph['nodes'][0]['position']['x']=INF;
        if ($case==='too_many')$graph['nodes']=array_fill(0,201,$graph['nodes'][0]);
        if ($case==='edge')$graph['edges']=[['edge_key'=>'bad','from_node_key'=>'node_old','to_node_key'=>'missing','edge_type'=>'flow']];
        if ($case==='type')$graph['nodes'][0]['node_type']='executable';
        $this->expectException(\InvalidArgumentException::class);Graph::saveInput($doc,$graph);
    }
    public static function invalidGraphs(): array {return array_map(fn($s)=>[$s],['stale','duplicate','infinite','too_many','edge','type']);}
}
