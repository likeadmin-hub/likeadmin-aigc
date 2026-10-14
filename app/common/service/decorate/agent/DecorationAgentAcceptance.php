<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

use app\common\service\app\AppFrontendManifestService;
use app\common\service\decorate\DecorateTemplateService;
use RuntimeException;
use think\facade\Db;

/** Version-bound visual evidence. This helper never submits models or writes rows. */
final class DecorationAgentAcceptance
{
    private const CATEGORIES=['layout','typography','color','assets','icons','interaction'];

    public static function validateReview(int $tenant,int $admin,array $p): array
    {
        if($tenant<1||$admin<1) throw new RuntimeException('装修验收身份无效');
        $candidate=self::owned('candidate',$tenant,$admin,(int)($p['candidate_id']??0));
        $snapshot=DecorationAgentService::decode($candidate['payload_json']);
        $revision=(int)($p['revision']??0);$hash=(string)($p['candidate_hash']??'');
        if($revision!==(int)$candidate['revision']||!self::sameHash($hash,DecorationAgentService::hash($snapshot))) throw new RuntimeException('候选已变化，请重新截图');
        if(!in_array($candidate['status'],['ready','waiting_images'],true)||self::pendingImages($candidate)) throw new RuntimeException('候选尚未稳定，请等待图片完成后验收');
        $provenance=self::provenance($snapshot);
        if($provenance['run_id']!==(int)($p['design_run_id']??0)||!self::sameHash($provenance['hash'],(string)($p['design_hash']??''))) throw new RuntimeException('验收设计稿与候选不匹配');
        $approved=DecorationAgentDesignService::confirmedDesign($tenant,$admin,$provenance['run_id'],$provenance['hash']);
        if((int)($approved['revision']??0)!==$provenance['revision']) throw new RuntimeException('设计稿版本已变化');
        if((int)self::owned('run',$tenant,$admin,$provenance['run_id'])['session_id']!==(int)$candidate['session_id']) throw new RuntimeException('设计稿不属于当前装修会话');
        $session=self::owned('session',$tenant,$admin,(int)$candidate['session_id']);
        self::assertDraftHash($session,(string)($p['expected_hash']??''));
        $budget=(float)($p['budget_points']??0);
        if(!is_finite($budget)||$budget<=0) throw new RuntimeException('请确认本轮验收预算');
        $key=(string)($p['idempotency_key']??'');
        if(trim($key)===''||strlen($key)>100) throw new RuntimeException('验收提交标识无效');
        $fileId=(int)($p['screenshot_file_id']??0);
        if($fileId<=0) throw new RuntimeException('请先截取并上传实际装修预览');
        $attachments=DecorationAgentMediaService::validateAttachments($tenant,[['file_id'=>$fileId,'uses'=>['layout']]]);
        $screenshot=self::screenshotBytes($tenant,$fileId);
        $design=DecorationAgentDesignService::referenceBytes($tenant,$admin,$approved);
        if(($design['bytes']??'')===''||!self::sameHash((string)($design['sha256']??''),hash('sha256',$design['bytes']))) throw new RuntimeException('设计稿冻结内容校验失败');
        if(hash_equals(hash('sha256',$design['bytes']),$screenshot['sha256'])||hash_equals(self::pixelHash($design['bytes']),self::pixelHash($screenshot['bytes']))) throw new RuntimeException('设计稿不能作为实际装修截图，请重新截取预览');
        $evidence=['candidate_id'=>(int)$candidate['id'],'revision'=>$revision,'candidate_hash'=>$hash,
            'expected_hash'=>(string)$p['expected_hash'],'design_run_id'=>$provenance['run_id'],'design_revision'=>$provenance['revision'],
            'design_hash'=>$provenance['hash'],'screenshot_file_id'=>$fileId,'design_sha256'=>$design['sha256'],
            'screenshot_sha256'=>$screenshot['sha256'],'screenshot_width'=>$screenshot['width'],'screenshot_height'=>$screenshot['height'],
            'screenshot_origin'=>'client_preview_capture','capture_attested'=>false,'interaction_contract'=>self::interactionContract($snapshot,$tenant,(int)$session['template_id'])];
        return ['candidate'=>$candidate,'snapshot'=>$snapshot,'approved_design'=>$approved,'attachments'=>$attachments,'visual_review'=>$evidence];
    }

    /** Returns a report for the caller to persist in review-run context, never in page data. */
    public static function record(array $candidateRow,array $reviewRunRow,array $acceptance,bool $hadWrites): array
    {
        $ctx=DecorationAgentService::decode($reviewRunRow['context_json']??[]);
        $input=DecorationAgentService::decode($reviewRunRow['input_json']??[]);
        $evidence=$ctx['visual_review']??$input['visual_review']??[];
        $report=['passed'=>false,'status'=>'inconclusive','checks'=>[],'summary'=>(string)($acceptance['summary']??''),
            'candidate_id'=>(int)($evidence['candidate_id']??0),'revision'=>(int)($evidence['revision']??0),
            'candidate_hash'=>(string)($evidence['candidate_hash']??''),'design_run_id'=>(int)($evidence['design_run_id']??0),
            'design_revision'=>(int)($evidence['design_revision']??0),'design_hash'=>(string)($evidence['design_hash']??''),
            'screenshot_file_id'=>(int)($evidence['screenshot_file_id']??0),'screenshot_sha256'=>(string)($evidence['screenshot_sha256']??''),
            'review_run_id'=>(int)($reviewRunRow['id']??0),'reasons'=>[],'capture_attested'=>false,
            'scope'=>'视觉模型对照与服务器交互配置检查；不证明真实点击、登录或后端调用成功。'];
        $checks=$acceptance['checks']??null;$byCategory=[];
        if(!is_array($checks)||!array_is_list($checks)||count($checks)!==6) $report['reasons'][]='验收缺少完整六类检查';
        foreach(is_array($checks)?$checks:[] as $check) {
            $category=is_array($check)?($check['category']??''):'';
            if(!in_array($category,self::CATEGORIES,true)||isset($byCategory[$category])) {$report['reasons'][]='验收类别无效或重复';continue;}
            if(!is_bool($check['passed']??null)) $report['reasons'][]='验收检查结果必须为明确布尔值';
            $proof=$check['evidence']??[];
            $validProof=is_array($proof)&&array_is_list($proof)&&count($proof)>0&&count(array_filter($proof,fn($v)=>is_string($v)&&trim($v)!==''))===count($proof);
            $byCategory[$category]=['category'=>$category,'passed'=>($check['passed']??null)===true&&$validProof&&is_string($check['detail']??null)&&trim($check['detail'])!=='',
                'detail'=>is_string($check['detail']??null)?$check['detail']:'','evidence'=>$validProof?$proof:[],
                'source'=>$category==='interaction'?'server_contract':'visual_model'];
        }
        foreach(self::CATEGORIES as $category) $report['checks'][]=$byCategory[$category]??['category'=>$category,'passed'=>false,'detail'=>'缺少有效检查依据','evidence'=>[],'source'=>$category==='interaction'?'server_contract':'visual_model'];
        try {
            $tenant=(int)($reviewRunRow['tenant_id']??0);$admin=(int)($reviewRunRow['admin_id']??0);
            $current=self::owned('candidate',$tenant,$admin,(int)($candidateRow['id']??0));
            if((int)$candidateRow['tenant_id']!==$tenant||(int)$candidateRow['admin_id']!==$admin||(int)$current['session_id']!==(int)$reviewRunRow['session_id']) throw new RuntimeException('验收候选权限或会话不匹配');
            $payload=DecorationAgentService::decode($current['payload_json']);
            if((int)$current['id']!==$report['candidate_id']||(int)$current['revision']!==$report['revision']||!self::sameHash(DecorationAgentService::hash($payload),$report['candidate_hash'])||!self::sameHash(DecorationAgentService::hash(DecorationAgentService::decode($candidateRow['payload_json'])),$report['candidate_hash'])) throw new RuntimeException('候选版本已变化，请重新截图验收');
            if(!in_array($current['status'],['ready','waiting_images'],true)||self::pendingImages($current)) throw new RuntimeException('候选尚未稳定');
            $design=self::provenance($payload);
            if($design['run_id']!==$report['design_run_id']||$design['revision']!==$report['design_revision']||!self::sameHash($design['hash'],$report['design_hash'])) throw new RuntimeException('设计稿绑定已变化');
            $frozen=$ctx['approved_design']??[];
            if((int)($frozen['run_id']??0)!==$design['run_id']||(int)($frozen['revision']??0)!==$design['revision']||!self::sameHash((string)($frozen['hash']??''),$design['hash'])) throw new RuntimeException('模型参考设计稿与验收绑定不匹配');
            $approved=DecorationAgentDesignService::confirmedDesign($tenant,$admin,$design['run_id'],$design['hash']);
            if((int)self::owned('run',$tenant,$admin,$design['run_id'])['session_id']!==(int)$current['session_id']) throw new RuntimeException('设计稿不属于当前装修会话');
            $reference=DecorationAgentDesignService::referenceBytes($tenant,$admin,$approved);
            if(!self::sameHash((string)($reference['sha256']??''),(string)($evidence['design_sha256']??''))) throw new RuntimeException('冻结设计稿内容已变化');
            $session=self::owned('session',$tenant,$admin,(int)$current['session_id']);self::assertDraftHash($session,(string)($evidence['expected_hash']??''));
            self::assertScreenshot($tenant,$evidence);
            $run=self::owned('run',$tenant,$admin,(int)$reviewRunRow['id']);
            if((int)$run['cancel_requested']||in_array($run['status'],['canceled','failed','needs_review','limited','budget_exceeded'],true)) throw new RuntimeException('验收任务已停止或结果待核实');
            if($hadWrites||self::hasWrites($ctx,$acceptance)||(Db::name('decorate_agent_image_slot')->where('run_id',$run['id'])->count()>0)) throw new RuntimeException('本轮发生修改或生图，需重新截图复审');
            if(!self::settled($run,$ctx)) throw new RuntimeException('验收调用费用尚未明确结算');
            // Recompute trusted interaction facts instead of accepting client/model claims.
            $interaction=self::interactionContract($payload,$tenant,(int)$session['template_id']);
            foreach($report['checks'] as &$check) if($check['category']==='interaction') {
                $check['passed']=$check['passed']&&$interaction['passed'];$check['detail']=$interaction['detail'];$check['evidence']=$interaction['evidence'];
            }
            unset($check);
        } catch(\Throwable $e) {$report['reasons'][]=$e->getMessage();}
        $report['passed']=($acceptance['passed']??null)===true&&$report['reasons']===[]&&count(array_filter($report['checks'],fn($c)=>$c['passed']===true))===6;
        $report['status']=$report['passed']?'passed':($hadWrites?'needs_review':'inconclusive');
        return $report;
    }

    public static function assertCanApply(array $candidate,int $tenant,int $admin): void
    {
        $payload=DecorationAgentService::decode($candidate['payload_json']??[]);
        if(!array_key_exists('_decoration_design',$payload)) return;
        $design=self::provenance($payload);
        if((int)($candidate['tenant_id']??0)!==$tenant||(int)($candidate['admin_id']??0)!==$admin) throw new RuntimeException('验收候选不存在或无权访问');
        DecorationAgentDesignService::confirmedDesign($tenant,$admin,$design['run_id'],$design['hash']);
        $current=self::owned('candidate',$tenant,$admin,(int)$candidate['id']);
        if((int)$current['revision']!==(int)$candidate['revision']||$current['payload_json']!==$candidate['payload_json']||self::pendingImages($current)) throw new RuntimeException('候选已变化，请重新验收');
        $hash=DecorationAgentService::hash($payload);
        $runs=Db::name('decorate_agent_run')->where(['tenant_id'=>$tenant,'admin_id'=>$admin,'session_id'=>$candidate['session_id'],'status'=>'completed','cancel_requested'=>0])->order('id','desc')->select()->toArray();
        foreach($runs as $run) {
            $ctx=DecorationAgentService::decode($run['context_json']);$review=$ctx['visual_review']??[];$accepted=$ctx['acceptance']??[];
            if(($accepted['passed']??null)!==true||(int)($accepted['candidate_id']??0)!==(int)$candidate['id']||(int)($accepted['revision']??0)!==(int)$candidate['revision']||!self::sameHash((string)($accepted['candidate_hash']??''),$hash)) continue;
            if((int)($accepted['design_run_id']??0)!==$design['run_id']||(int)($accepted['design_revision']??0)!==$design['revision']||!self::sameHash((string)($accepted['design_hash']??''),$design['hash'])) continue;
            // Revalidate all persisted claims; forged/stale reports cannot become an apply ticket.
            $fresh=self::record($current,$run,$accepted,false);
            if($fresh['passed']&&self::settled($run,$ctx)&&self::sameHash((string)($review['candidate_hash']??''),$hash)) return;
        }
        throw new RuntimeException('请先完成当前版本的设计稿对照验收');
    }

    private static function owned(string $table,int $tenant,int $admin,int $id): array
    {
        $row=Db::name('decorate_agent_'.$table)->where(['tenant_id'=>$tenant,'admin_id'=>$admin,'id'=>$id])->find();
        if(!$row) throw new RuntimeException('装修验收记录不存在或无权访问');
        return $row;
    }
    private static function provenance(array $payload): array
    {
        $p=$payload['_decoration_design']??null;
        if(!is_array($p)||(int)($p['run_id']??0)<1||(int)($p['revision']??0)<1||!preg_match('/^[a-f0-9]{64}$/D',(string)($p['hash']??''))) throw new RuntimeException('候选缺少有效的已确认设计稿绑定');
        return ['run_id'=>(int)$p['run_id'],'revision'=>(int)$p['revision'],'hash'=>(string)$p['hash']];
    }
    private static function sameHash(string $a,string $b): bool {return preg_match('/^[a-f0-9]{64}$/D',$a)===1&&preg_match('/^[a-f0-9]{64}$/D',$b)===1&&hash_equals($a,$b);}
    private static function pendingImages(array $candidate): bool {return Db::name('decorate_agent_image_slot')->where('candidate_id',$candidate['id'])->whereIn('status',['queued','running','submitting','needs_review'])->count()>0;}
    private static function assertDraftHash(array $session,string $expected): void
    {
        $page=DecorateTemplateService::pageDetail((int)$session['tenant_id'],['template_id'=>$session['template_id'],'id'=>$session['page_id']]);
        if(!self::sameHash($expected,DecorationAgentService::hash(DecorationAgentService::payload($page)))) throw new RuntimeException('当前草稿已变化，请刷新后重新截图验收');
    }
    private static function screenshotBytes(int $tenant,int $fileId): array
    {
        if($fileId<1) throw new RuntimeException('缺少实际装修截图');
        $refs=DecorationAgentMediaService::visionReferences($tenant,[['file_id'=>$fileId,'uses'=>['layout']]]);
        if(count($refs)!==1||!preg_match('#^data:image/png;base64,(.+)$#D',$refs[0],$m)||($bytes=base64_decode($m[1],true))===false) throw new RuntimeException('实际装修截图必须是有效PNG');
        $info=@getimagesizefromstring($bytes);
        if(!$info||$info[0]*$info[1]>10000000) throw new RuntimeException('实际装修截图尺寸过大或无效');
        return ['bytes'=>$bytes,'sha256'=>hash('sha256',$bytes),'width'=>$info[0],'height'=>$info[1]];
    }
    private static function assertScreenshot(int $tenant,array $evidence): void
    {
        $s=self::screenshotBytes($tenant,(int)($evidence['screenshot_file_id']??0));
        if(!self::sameHash($s['sha256'],(string)($evidence['screenshot_sha256']??''))||self::sameHash($s['sha256'],(string)($evidence['design_sha256']??''))) throw new RuntimeException('截图内容已变化或误用了设计稿');
    }
    private static function pixelHash(string $bytes): string
    {
        $info=@getimagesizefromstring($bytes);
        if(!$info||$info[0]*$info[1]>10000000||!function_exists('imagecreatefromstring')) throw new RuntimeException('图片像素校验不可用或尺寸过大');
        $im=@imagecreatefromstring($bytes);if(!$im) throw new RuntimeException('图片解码失败');
        try {$hash=hash_init('sha256');hash_update($hash,$info[0].':'.$info[1].':');
            for($y=0;$y<$info[1];$y++)for($x=0;$x<$info[0];$x++) {$rgba=imagecolorsforindex($im,imagecolorat($im,$x,$y));hash_update($hash,pack('C4',$rgba['red'],$rgba['green'],$rgba['blue'],$rgba['alpha']));}
            return hash_final($hash);
        } finally {imagedestroy($im);}
    }
    private static function hasWrites(array $ctx,array $acceptance): bool
    {
        if(!empty($ctx['changes'])||!empty($ctx['image_requests'])) return true;
        $decision=$acceptance;
        if(isset($ctx['last_result'])) {$text=DecorationAgentDecision::finalContent($ctx['last_result']);$decision=json_decode($text,true)?:[];}
        foreach($decision['operations']??[] as $op) if(!in_array($op['type']??$op['op']??'', ['get_context','catalog','data_sources','links','search_materials','icons','validate'],true)) return true;
        return false;
    }
    private static function settled(array $run,array $ctx): bool
    {
        $ids=array_values(array_unique(array_filter(array_map(fn($r)=>(int)($r['consumption_id']??0),$ctx['receipts']??[]))));
        $taskIds=Db::name('ai_app_task')->where(['tenant_id'=>$run['tenant_id'],'user_id'=>0,'app_code'=>'system_default','business_table'=>'decorate_agent_run','business_id'=>$run['id']])->column('id');
        if(!$taskIds||!$ids) return false;
        $rows=Db::name('ai_consumption_log')->whereIn('app_task_id',$taskIds)->select()->toArray();$found=[];
        $total=0.0;
        foreach($rows as $row) {if((int)$row['tenant_id']!==(int)$run['tenant_id']||(int)$row['user_id']!==0||$row['app_code']!=='system_default'||$row['run_status']!=='success'||$row['billing_status']!=='settled'||$row['protocol']==='image_generate') return false;$found[]=(int)$row['id'];$total+=(float)$row['actual_tenant_cost'];}
        foreach($ids as $id) if(!in_array($id,$found,true)) return false;
        $billing=$ctx['last_result']['billing']['billing_status']??'settled';
        $budget=(float)(DecorationAgentService::decode($run['input_json'])['budget_points']??0);
        return $found!==[]&&$billing==='settled'&&is_finite($total)&&$total>=0&&$budget>0&&$total<=$budget;
    }
    private static function interactionContract(array $snapshot,int $tenant,int $template): array
    {
        $allowed=DecorateTemplateService::linkLists($tenant,$template,'mobile');
        foreach(AppFrontendManifestService::tenantEntries($tenant,'uniapp') as $entry) foreach(($entry['meta']['pages']??[])?:[['path'=>$entry['path'],'name'=>$entry['name']]] as $page) if(!empty($page['path'])) $allowed[]=['path'=>$page['path'],'query'=>$page['query']??[],'name'=>$page['name']??$entry['name'],'canTab'=>(bool)($page['canTab']??false),'type'=>'app_center','app_code'=>$entry['app_code'],'entry_key'=>$entry['entry_key']??'','terminal'=>'uniapp'];
        $count=0;$failures=[];
        foreach(DecorationAgentOperations::index($snapshot['data']??[]) as $id=>$node) {
            try {$d=DecorationAgentCatalog::definition($node['widget']['name']);$ops=[];
                foreach($d['content_fields']??[] as $key=>$schema) if(($schema['type']??'')==='link'&&!empty($node['widget']['content'][$key])) {$ops[]=['type'=>'update_component','widget_id'=>$id,'fields'=>['content.'.$key=>$node['widget']['content'][$key]]];$count++;}
                foreach($d['lists']??[] as $key=>$list) foreach($node['widget']['content'][$key]??[] as $index=>$row) foreach($list['fields']??[] as $field=>$schema) if(($schema['type']??'')==='link'&&!empty($row[$field])) {$ops[]=['type'=>'edit_list','widget_id'=>$id,'field'=>$key,'action'=>'update','index'=>$index,'fields'=>[$field=>$row[$field]]];$count++;}
                foreach($ops as $op) DecorationAgentOperations::apply($snapshot,[$op],['scope'=>'page','allowed_links'=>$allowed]);
            } catch(\Throwable $e) {$failures[]='组件交互配置未通过当前允许链接契约';}
        }
        return ['passed'=>$failures===[],'source'=>'server_contract','detail'=>'仅检查组件声明的交互配置和当前允许链接，未实测点击、登录或业务接口。',
            'evidence'=>['服务器按当前组件目录检查 '.$count.' 个已配置链接。',...$failures],'checked_link_count'=>$count];
    }
}
