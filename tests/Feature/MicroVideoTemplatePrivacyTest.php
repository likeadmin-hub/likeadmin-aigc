<?php
namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchRecords as R;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchJobs as J;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchWorker as Worker;
use app\common\service\app\aigc_micro_video\MicroVideoStore as Store;

/** All writes roll back. No provider submission, worker execution, or paid generation. */
final class MicroVideoTemplatePrivacyTest extends TestCase
{
    private const SECRET='PRIVATE_CATALOG_RULE_7d56 Keep the hidden choreography locked.';
    private int $tenant=987654311;
    private int $user=987654310;
    private bool $transaction=false;
    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();$db=config('database.connections.mysql');
        if($db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')self::markTestSkipped('Local transactional acceptance only');
        Db::startTrans();$this->transaction=true;
    }
    protected function tearDown(): void{if($this->transaction)Db::rollback();}
    private function template(): array
    {return R::saveTrustedTemplate($this->tenant,['kind'=>'template','title'=>'Protected fixture','visibility'=>'tenant','body'=>['seed_key'=>'commerce_v1_privacy_fixture','internal_prompt'=>self::SECRET,'protected_prompt'=>true,'script'=>'A person demonstrates a confirmed real product.','segments'=>[['sort'=>1,'duration'=>15,'prompt'=>'Public editable storyline.']],'images'=>[]]]);}
    private function raw(array $r): array{return Store::json(R::owned($this->tenant,$this->user,$r['kind'],$r['id'],true)['body_json']);}
    private function save(array $r,array $body,bool $admin=false): array
    {return R::save($this->tenant,$admin?0:$this->user,['kind'=>$r['kind'],'id'=>$r['id'],'revision'=>$r['revision'],'title'=>$r['title'],'body'=>$body],$admin);}
    private function secretFree(array $response): void
    {$json=json_encode($response,JSON_UNESCAPED_UNICODE);self::assertStringNotContainsString('internal_prompt',$json);self::assertStringNotContainsString(self::SECRET,$json);}

    public function testAll31BundledTemplatesKeepPublicStorylinesWhileRemovingHiddenGuidance(): void
    {
        $catalog=json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_micro_video/resources/commerce_templates.json'),true,64,JSON_THROW_ON_ERROR);self::assertCount(31,$catalog['templates']);
        foreach($catalog['templates'] as $template){
            self::assertTrue($template['protected_prompt']);self::assertNotSame('',trim($template['internal_prompt']));
            $public=R::publicBody($template);self::assertArrayNotHasKey('internal_prompt',$public);self::assertStringNotContainsString($template['internal_prompt'],Store::encode($public));self::assertSame($template['script'],$public['script']);self::assertSame($template['segments'],$public['segments']);
            // The private source can include the public storyline; repeating that storyline is legitimate.
            R::assertNoPrivateEcho(['script'=>['text'=>$template['script'],'segments'=>$template['segments']]],$template);
            $segments=$template['segments'];foreach($segments as &$segment)$segment['image_indices']=[0];unset($segment);
            $row=['action'=>'rewrite','request_json'=>Store::encode(['snapshot'=>['target_duration'=>$template['default_duration'],'template_snapshot'=>['body'=>$template]]])];
            $parsed=Worker::parse($row,['content'=>Store::encode(['script'=>['text'=>$template['script'],'segments'=>$segments]])]);
            self::assertSame($template['script'],$parsed['script']['text']);self::assertSame(array_column($template['segments'],'prompt'),array_column($parsed['script']['segments'],'prompt'));
        }
    }
    public function testAll31PrivateCameraChaptersAndSentencesCannotBeReturnedOrPublished(): void
    {
        $catalog=json_decode(file_get_contents(dirname(__DIR__,2).'/app/apps/aigc_micro_video/resources/commerce_templates.json'),true,64,JSON_THROW_ON_ERROR);
        foreach($catalog['templates'] as $template){
            preg_match('/^镜头指导：[^\r\n]+/mu',$template['internal_prompt'],$camera);self::assertNotEmpty($camera,$template['code']);
            $sentence=preg_split('/[。；]/u',preg_replace('/^镜头指导：/u','',$camera[0]))[0];
            preg_match('/^负面约束：[^\r\n]+/mu',$template['internal_prompt'],$negative);self::assertNotEmpty($negative,$template['code']);
            foreach([$camera[0],preg_replace('/^镜头指导：/u','',$camera[0]),$sentence,$negative[0]] as $fragment){
                $context=['snapshot'=>['target_duration'=>15,'template_snapshot'=>['body'=>$template]]];
                $payload=['script'=>['text'=>$fragment,'segments'=>[['duration'=>15,'prompt'=>"0-3秒：主角走近。\n3-15秒：主角展示商品。"]]]];
                $row=['id'=>1,'user_id'=>$this->user,'project_id'=>1,'project_revision'=>1,'action'=>'rewrite','status'=>'success','request_json'=>Store::encode($context),'result_json'=>Store::encode($payload),'receipt_json'=>'{}','error'=>$fragment,'user_charge_points'=>0,'consumption_id'=>0,'create_time'=>time(),'update_time'=>time()];
                $dto=J::dto($row,true);self::assertStringNotContainsString($fragment,Store::encode($dto),$template['code']);
                try{Worker::parse($row,['content'=>Store::encode($payload)]);self::fail('Private chapter accepted: '.$template['code']);}catch(\InvalidArgumentException $error){self::assertStringContainsString('模型结果包含内部创作规则',$error->getMessage());}
            }
        }
    }
    public function testPublicStoryAndWorldFactsRemainAllowedEvenWhenInternalPromptAlsoContainsThem(): void
    {
        $facts='保持人物服饰一致，保持商品结构和颜色不变。';$story='0-3秒：主角从桌上拿起商品，与朋友自然交流。';
        $camera='镜头指导：开场用能同时交代人物位置与悬念的中景；第二拍用视线匹配和短近景推进关系。';
        $template=['internal_prompt'=>$story."\n".$camera."\n".$facts,'protected_prompt'=>true,'script'=>$story,'segments'=>[['duration'=>15,'prompt'=>$story,'continuity'=>$facts]],'reference_rules'=>$facts];
        $public=R::publicBody($template);self::assertSame($story,$public['script']);self::assertSame($template['segments'],$public['segments']);self::assertSame($facts,$public['reference_rules']);
        $result=['script'=>['text'=>$story,'segments'=>$template['segments'],'continuity'=>$facts]];R::assertNoPrivateEcho($result,['template_snapshot'=>['body'=>$template]]);self::assertSame($result,R::publicBody($result,['template_snapshot'=>['body'=>$template]]));
    }
    public function testPublicDtoRecursivelyRemovesPrivateFieldsIncludingHistoricalSnapshots(): void
    {
        $body=['internal_prompt'=>self::SECRET,'protected_prompt'=>true,'script'=>'public','versions'=>[['body'=>['internal_prompt'=>self::SECRET,'script'=>'story']]],'template_snapshot'=>['body'=>['internal_prompt'=>self::SECRET,'segments'=>[['prompt'=>'public']]]]];
        $public=R::publicBody($body);$this->secretFree($public);self::assertTrue($public['protected_prompt']);self::assertSame('public',$public['script']);self::assertSame('public',$public['template_snapshot']['body']['segments'][0]['prompt']);
    }
    public function testClientIncludingAdministratorCannotForgeOrOverwriteInternalGuidance(): void
    {
        foreach([false,true] as $admin){$forged=R::save($this->tenant,$admin?0:$this->user,['kind'=>'template','title'=>'Forged rules','body'=>['seed_key'=>'commerce_v1_privacy_fixture','protected_prompt'=>true,'internal_prompt'=>'ATTACK','script'=>'editable','nested'=>['internal_prompt'=>'NESTED','protected_prompt'=>true]]],$admin);$raw=$this->raw($forged);self::assertArrayNotHasKey('internal_prompt',$raw);self::assertArrayNotHasKey('protected_prompt',$raw);self::assertArrayNotHasKey('internal_prompt',$raw['nested']);}
        $template=$this->template();$body=$template['body'];$body['internal_prompt']='ATTACK';$body['protected_prompt']=false;$body['script']='Administrator editable storyline';$saved=$this->save($template,$body,true);
        self::assertSame(self::SECRET,$this->raw($saved)['internal_prompt']);self::assertTrue($this->raw($saved)['protected_prompt']);self::assertSame('Administrator editable storyline',$saved['body']['script']);$this->secretFree($saved);
    }
    public function testPrivateTemplateCopyResaveAndRestorePreserveRulesWithoutReturningThem(): void
    {
        $template=$this->template();$copy=R::copy($this->tenant,$this->user,['kind'=>'template','id'=>$template['id']]);$this->secretFree($copy);self::assertSame(self::SECRET,$this->raw($copy)['internal_prompt']);
        $body=$copy['body'];$body['script']='User revised public storyline';$saved=$this->save($copy,$body);self::assertSame(self::SECRET,$this->raw($saved)['internal_prompt']);
        $restored=R::restore($this->tenant,$this->user,['kind'=>'template','id'=>$copy['id'],'revision'=>2,'version'=>1]);self::assertSame(self::SECRET,$this->raw($restored)['internal_prompt']);self::assertSame($copy['body']['script'],$restored['body']['script']);
        foreach([R::lists($this->tenant,$this->user,['kind'=>'template']),R::versions($this->tenant,$this->user,['kind'=>'template','id'=>$copy['id']]),$restored,R::dto(R::owned($this->tenant,$this->user,'template',$template['id']))] as $response)$this->secretFree($response);
    }
    /** @dataProvider revocations */
    public function testFrozenProjectRulesSurviveCloudSaveCopyAndHistoricalRestoreAfterRevocation(string $mode): void
    {
        $before=Db::name('ai_consumption_log')->count();$template=$this->template();$project=R::save($this->tenant,$this->user,['kind'=>'project','title'=>'Project','body'=>['template'=>['id'=>$template['id'],'revision'=>1],'script'=>['text'=>'Public script','segments'=>[]]]]);$this->secretFree($project);self::assertSame(self::SECRET,$this->raw($project)['template_snapshot']['body']['internal_prompt']);
        if($mode==='delete')R::delete($this->tenant,0,['kind'=>'template','id'=>$template['id'],'revision'=>1],true);else R::save($this->tenant,0,['kind'=>'template','id'=>$template['id'],'revision'=>1,'title'=>$template['title'],'status'=>0,'body'=>$template['body']],true);
        $body=$project['body'];$body['template_snapshot']['body']['internal_prompt']='ATTACK';$body['script']['internal_prompt']='SCRIPT_ATTACK';$saved=$this->save($project,$body);self::assertSame(self::SECRET,$this->raw($saved)['template_snapshot']['body']['internal_prompt']);self::assertArrayNotHasKey('internal_prompt',$this->raw($saved)['script']);
        $copy=R::copy($this->tenant,$this->user,['kind'=>'project','id'=>$saved['id']]);self::assertSame(self::SECRET,$this->raw($copy)['template_snapshot']['body']['internal_prompt']);
        $restored=R::restore($this->tenant,$this->user,['kind'=>'project','id'=>$saved['id'],'revision'=>2,'version'=>1]);self::assertSame(self::SECRET,$this->raw($restored)['template_snapshot']['body']['internal_prompt']);
        foreach([$saved,$copy,$restored,R::versions($this->tenant,$this->user,['kind'=>'project','id'=>$project['id']])] as $response)$this->secretFree($response);self::assertSame($before,Db::name('ai_consumption_log')->count());
    }
    public static function revocations(): array{return [['disable'],['delete']];}
    public function testRewriteUsesFrozenGuidanceOnlyInPrivateSystemAndJobDtoNeverExposesIt(): void
    {
        $template=$this->template();$project=R::save($this->tenant,$this->user,['kind'=>'project','title'=>'Project','body'=>['template'=>['id'=>$template['id'],'revision'=>1],'script'=>['text'=>'public','segments'=>[]]]]);$raw=$this->raw($project);
        $system=new \ReflectionMethod(J::class,'system');$text=$system->invoke(null,'rewrite',$raw);self::assertStringContainsString(self::SECRET,$text);$this->secretFree(R::publicBody($raw));
        $row=['id'=>1,'user_id'=>$this->user,'receipt_json'=>'{}','project_id'=>$project['id'],'project_revision'=>1,'action'=>'rewrite','status'=>'success','result_json'=>json_encode(['script'=>['text'=>'public','internal_prompt'=>self::SECRET]]),'request_json'=>json_encode(['snapshot'=>$raw,'system'=>$text]),'error'=>'','user_charge_points'=>0,'consumption_id'=>0,'create_time'=>time(),'update_time'=>time()];$this->secretFree(J::dto($row));$this->secretFree(J::dto($row,true));
        $row['result_json']=json_encode(['script'=>['text'=>self::SECRET]]);$row['error']=self::SECRET;$this->secretFree(J::dto($row,true));
    }
    public function testRealReadOnlyRequestPreparationKeepsSecretOutOfEditableProjectContent(): void
    {
        $this->tenant=1;$this->user=1;$models=\app\common\service\power\MarketTextModelRuntimeService::textOptions(1);
        if(!$models)self::markTestSkipped('Read-only request preparation needs a local text model catalog');
        $before=Db::name('ai_consumption_log')->count();$template=$this->template();$project=R::save(1,1,['kind'=>'project','title'=>'Protected request fixture','body'=>['template'=>['id'=>$template['id'],'revision'=>1],'script'=>['text'=>'User storyline','segments'=>[]]]]);
        $prepared=J::input(1,1,['project_id'=>$project['id'],'project_revision'=>1,'action'=>'rewrite','selection'=>['model_id'=>$models[0]['id']]]);
        self::assertStringContainsString(self::SECRET,$prepared['request']['system']);
        self::assertStringNotContainsString(self::SECRET,$prepared['request']['content']);self::assertStringNotContainsString('internal_prompt',$prepared['request']['content']);
        self::assertSame(self::SECRET,$prepared['request']['snapshot']['template_snapshot']['body']['internal_prompt']);self::assertSame($before,Db::name('ai_consumption_log')->count());
    }
    public function testAdoptionCannotSaveAStoredModelEchoInsideEditableScript(): void
    {
        $template=$this->template();$project=R::save($this->tenant,$this->user,['kind'=>'project','title'=>'Project','body'=>['template'=>['id'=>$template['id'],'revision'=>1],'script'=>['text'=>'Public story','segments'=>[]]]]);
        $id=J::table()->insertGetId(['tenant_id'=>$this->tenant,'user_id'=>$this->user,'project_id'=>$project['id'],'project_revision'=>1,'action'=>'rewrite','idempotency_key'=>'privacy_echo_'.bin2hex(random_bytes(8)),'input_hash'=>str_repeat('a',64),'status'=>'success','request_json'=>Store::encode(['snapshot'=>$this->raw($project)]),'result_json'=>Store::encode(['script'=>['text'=>self::SECRET,'segments'=>[]]]),'receipt_json'=>'{}','create_time'=>time(),'update_time'=>time()]);
        try{J::adopt($this->tenant,$this->user,['job_id'=>$id,'project_id'=>$project['id'],'project_revision'=>1]);self::fail('Hidden model output adopted');}catch(\InvalidArgumentException $e){self::assertStringContainsString('模型结果包含内部创作规则',$e->getMessage());}
        self::assertSame('Public story',$this->raw($project)['script']['text']);
    }
    public function testLegacyPublicTemplatesRetainEditablePromptsAndRules(): void
    {
        $body=['script'=>'Existing public prompt','direction_rules'=>'Existing direction','reference_rules'=>'Existing continuity','segments'=>[['duration'=>15,'prompt'=>'Existing segment']]];
        $old=R::save($this->tenant,0,['kind'=>'template','title'=>'Legacy','visibility'=>'tenant','body'=>$body],true);self::assertSame($body,$old['body']);$copy=R::copy($this->tenant,$this->user,['kind'=>'template','id'=>$old['id']]);self::assertSame($body,$copy['body']);
    }
    public function testModelCannotPublishLiteralPrivatePromptThroughScriptText(): void
    {
        $row=['action'=>'rewrite','request_json'=>json_encode(['snapshot'=>['template_snapshot'=>['body'=>['internal_prompt'=>self::SECRET,'protected_prompt'=>true]]]])];
        $this->expectException(\InvalidArgumentException::class);$this->expectExceptionMessage('模型结果包含内部创作规则');Worker::parse($row,['content'=>json_encode(['script'=>['text'=>self::SECRET,'segments'=>[['duration'=>30,'prompt'=>'0-15秒：主角走近。15-30秒：主角展示商品。']]]])]);
    }
}
