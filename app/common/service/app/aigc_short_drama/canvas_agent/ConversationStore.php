<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;

/** App-scoped persistence. No model invocation or graph writes.
 * Callers must resolve/authorize model and Skill snapshots server-side before
 * enqueue; never pass a request body as the trusted snapshot argument.
 */
final class ConversationStore
{
    public const PREFIX='aigc_short_drama_canvas_agent_';

    public static function create(int $tenant,int $user,int $canvas,string $key,string $title=''): array
    {
        self::key($key);
        $title=trim($title);
        if (mb_strlen($title)>160) throw new RuntimeException('INVALID_THREAD_TITLE');
        return Db::transaction(function () use ($tenant,$user,$canvas,$key,$title): array {
            self::canvas($tenant,$user,$canvas,true);
            $scope=self::scope($tenant,$user,$canvas);
            $hash=hash('sha256',self::json(['title'=>$title]));
            $existing=Db::name(self::PREFIX.'thread')->where($scope+['request_key'=>$key])->lock(true)->find();
            if ($existing) {
                if (!hash_equals($existing['request_hash'],$hash)) throw new RuntimeException('IDEMPOTENCY_CONFLICT');
                if ((int)$existing['delete_time']!==0) throw new RuntimeException('THREAD_NOT_FOUND');
                return self::threadView($existing);
            }
            $id=Db::name(self::PREFIX.'thread')->insertGetId($scope+['request_key'=>$key,'request_hash'=>$hash,'title'=>$title,'settings_json'=>'{}','create_time'=>time(),'update_time'=>time()]);
            return self::threadView(Db::name(self::PREFIX.'thread')->where('id',$id)->find());
        });
    }

    /** Accept bounded text and server-authorized short-drama image material. */
    public static function enqueue(int $tenant,int $user,int $canvas,int $thread,array $request,array|\Closure $resolvedSnapshot,array $selectionIdentity=[]): array
    {
        if (array_diff(array_keys($request),['request_key','content','selected_node_ids','base_revision','attachments'])) throw new RuntimeException('UNSUPPORTED_MESSAGE_FIELD');
        $attachments=ConversationAttachments::normalize($request['attachments']??[]);
        $key=$request['request_key']??null;
        if (!is_string($key)) throw new RuntimeException('INVALID_REQUEST_KEY');
        self::key($key);
        $content=$request['content']??null;
        if (!is_string($content) || trim($content)==='' || mb_strlen($content)>20000) throw new RuntimeException('INVALID_MESSAGE');
        $ids=$request['selected_node_ids']??[];
        if (!is_array($ids) || !array_is_list($ids) || count($ids)>50) throw new RuntimeException('INVALID_NODE_REFERENCES');
        foreach ($ids as &$id) {
            if ((!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]{0,15}$/D',(string)$id) || (float)$id>9007199254740991) throw new RuntimeException('INVALID_NODE_REFERENCES');
            $id=(string)$id;
        }
        unset($id);
        $ids=array_values(array_unique($ids));
        $revision=$request['base_revision']??null;
        if (!is_int($revision) || $revision<0) throw new RuntimeException('INVALID_BASE_REVISION');
        $identity=['thread'=>$thread,'content'=>$content,'nodes'=>$ids,'revision'=>$revision];
        if ($attachments) $identity['attachments']=$attachments;
        // Preserve the original storage-only hash when no selection identity
        // was supplied. Explicit model/Skill choices are part of send identity.
        if ($selectionIdentity!==[]) $identity['selection']=self::canonical($selectionIdentity);
        $hash=hash('sha256',self::json($identity));
        return Db::transaction(function () use ($tenant,$user,$canvas,$thread,$key,$content,$ids,$revision,$hash,$resolvedSnapshot,$attachments): array {
            // Fixed lock order: canvas -> thread -> run. Replay precedes busy/CAS
            // checks, so a retry remains stable after subsequent canvas edits.
            $document=self::canvas($tenant,$user,$canvas,true);
            $scope=self::scope($tenant,$user,$canvas);
            $conversation=self::thread($scope,$thread,true);
            $existing=Db::name(self::PREFIX.'run')->where($scope+['request_key'=>$key])->lock(true)->find();
            if ($existing) {
                if (!hash_equals($existing['request_hash'],$hash)) throw new RuntimeException('IDEMPOTENCY_CONFLICT');
                if ((int)$existing['delete_time']!==0) throw new RuntimeException('RUN_NOT_FOUND');
                return json_decode($existing['ack_json'],true,512,JSON_THROW_ON_ERROR);
            }
            if ((int)$conversation['active_run_id']!==0) throw new RuntimeException('THREAD_BUSY');
            if ((int)$document['graph_revision']!==$revision) throw new RuntimeException('VERSION_CONFLICT');
            $nodes=json_decode($document['nodes_json']?:'[]',true,512,JSON_THROW_ON_ERROR);
            $candidates=$attachments ? [] : ConversationReferenceResolver::ambiguousImageCandidates($nodes,$ids,$content);
            if ($candidates) return self::clarifyReference($scope,$conversation,$key,$hash,$revision,$content,$candidates);
            // Server-owned resolver performs local catalog reads only. Never
            // do provider I/O here; replay must not re-resolve changing defaults.
            if ($resolvedSnapshot instanceof \Closure) $resolvedSnapshot=$resolvedSnapshot($conversation);
            if (array_diff(array_keys($resolvedSnapshot),['settings','skill','workflow','thread_settings','intent_routing']) || !is_array($resolvedSnapshot['settings']??null) || !is_array($resolvedSnapshot['skill']??null) || (array_key_exists('workflow',$resolvedSnapshot) && !is_array($resolvedSnapshot['workflow'])) || (array_key_exists('thread_settings',$resolvedSnapshot) && !is_array($resolvedSnapshot['thread_settings'])) || (array_key_exists('intent_routing',$resolvedSnapshot) && !is_array($resolvedSnapshot['intent_routing']))) throw new RuntimeException('INVALID_RESOLVED_SNAPSHOT');
            $selected=[];
            foreach ($nodes as $node) {
                if (in_array((string)$node['id'],$ids,true)) {
                    // Node material is data, never privileged instructions.
                    $selected[(string)$node['id']]=[
                        'id'=>(string)$node['id'],'type'=>$node['type'],'x'=>$node['x']??0,'y'=>$node['y']??0,
                        'content_revision'=>$node['metadata']['content_revision']??0,
                        'content'=>$node['metadata']['content']??'',
                        'prompt'=>$node['metadata']['prompt']??'',
                    ];
                    if ($node['type']==='image' && trim((string)($node['metadata']['image']??$node['metadata']['url']??''))!=='') {
                        $selected[(string)$node['id']]['image_asset']=ConversationImages::freeze($tenant,$user,$canvas,(string)($node['metadata']['image']??$node['metadata']['url']));
                    }
                }
            }
            if (count($selected)!==count($ids)) throw new RuntimeException('NODE_NOT_FOUND');
            $history=Db::name(self::PREFIX.'message')->where($scope+['thread_id'=>$thread,'delete_time'=>0])->order('sequence','desc')->limit(38)->select()->toArray();
            $messages=[];
            foreach (array_reverse($history) as $message) {
                if (!in_array($message['role'],['user','assistant'],true)) throw new RuntimeException('INVALID_CONVERSATION_HISTORY');
                $messages[]=['role'=>$message['role'],'content'=>(string)(json_decode($message['content_json'],true,512,JSON_THROW_ON_ERROR)['text']??'')];
                $prior=ConversationAttachments::normalize(json_decode($message['attachments_json']?:'[]',true,512,JSON_THROW_ON_ERROR));
                $prior=self::contextAttachments($tenant,$user,$canvas,$prior);
                if ($prior) $messages[count($messages)-1]['attachments']=$prior;
            }
            $messages[]=['role'=>'user','content'=>$content];
            $contextAttachments=self::contextAttachments($tenant,$user,$canvas,$attachments);
            if ($contextAttachments) $messages[count($messages)-1]['attachments']=$contextAttachments;
            $attachmentImages=[];
            foreach ($attachments as $attachment) if ($attachment['type']==='image') $attachmentImages[]=ConversationImages::freezeAsset($tenant,$user,$canvas,(int)$attachment['asset_id']);
            if (count(array_filter($selected,static fn(array $node)=>$node['type']==='image'))+count($attachmentImages)>4) throw new RuntimeException('TOO_MANY_IMAGE_REFERENCES');
            $context=['graph_revision'=>$revision,'selected_nodes'=>array_map(static fn($id)=>$selected[$id],$ids),'attachment_images'=>$attachmentImages,'material_trust'=>'untrusted','messages'=>$messages,'history_policy'=>'last_38_plus_current'];
            if (!empty($resolvedSnapshot['workflow'])) $context['workflow']=$resolvedSnapshot['workflow'];
            if (!empty($resolvedSnapshot['intent_routing'])) $context['intent_routing']=$resolvedSnapshot['intent_routing'];
            $contextJson=self::json($context);
            $settings=self::json($resolvedSnapshot['settings']);$skill=self::json($resolvedSnapshot['skill']);
            if (strlen($contextJson)+strlen($settings)+strlen($skill)>1048576) throw new RuntimeException('CONTEXT_TOO_LARGE');
            $now=time();$sequence=(int)$conversation['next_message_sequence'];
            $run=Db::name(self::PREFIX.'run')->insertGetId($scope+['thread_id'=>$thread,'request_key'=>$key,'request_hash'=>$hash,'status'=>'queued','context_snapshot'=>$contextJson,'skill_snapshot'=>$skill,'settings_snapshot'=>$settings,'ack_json'=>'{}','create_time'=>$now,'update_time'=>$now]);
            Db::name(self::PREFIX.'message')->insert($scope+['thread_id'=>$thread,'run_id'=>$run,'sequence'=>$sequence,'role'=>'user','content_json'=>self::json(['text'=>$content]),'attachments_json'=>self::json(ConversationAttachments::public($attachments)),'create_time'=>$now]);
            // P2 has a deliberately narrow immutable plan: one conversation
            // response, no graph mutation, no media generation and no tools.
            // Keep it in the existing queued event so event cursors remain
            // backward-compatible while retries preserve the same intent.
            $cursor=Db::name(self::PREFIX.'event')->insertGetId($scope+['thread_id'=>$thread,'run_id'=>$run,'sequence'=>1,'kind'=>'run.queued','payload_json'=>self::json(['status'=>'queued','message_sequence'=>$sequence,'intent'=>['kind'=>'conversation','tools'=>[],'media_generation'=>false,'graph_mutation'=>false]]),'create_time'=>$now]);
            Db::name(self::PREFIX.'outbox')->insert($scope+['run_id'=>$run,'event_key'=>'run:'.$run,'available_at'=>$now,'create_time'=>$now,'update_time'=>$now]);
            $ack=['thread_id'=>$thread,'run_id'=>(int)$run,'status'=>'queued','event_cursor'=>(int)$cursor,'message_sequence'=>$sequence];
            Db::name(self::PREFIX.'run')->where('id',$run)->update(['ack_json'=>self::json($ack)]);
            $threadUpdate=['active_run_id'=>$run,'next_message_sequence'=>$sequence+1,'update_time'=>$now];
            if (array_key_exists('thread_settings',$resolvedSnapshot)) $threadUpdate['settings_json']=self::json($resolvedSnapshot['thread_settings']);
            Db::name(self::PREFIX.'thread')->where('id',$thread)->update($threadUpdate);
            return $ack;
        });
    }

    public static function threads(int $tenant,int $user,int $canvas,int $before=0): array
    {
        self::canvas($tenant,$user,$canvas,false,true);
        $query=Db::name(self::PREFIX.'thread')->where(self::scope($tenant,$user,$canvas)+['delete_time'=>0]);
        if ($before>0) $query->where('id','<',$before);
        return array_map([self::class,'threadView'],$query->order('id','desc')->limit(50)->select()->toArray());
    }

    /** Shared ownership gate for account preferences reached from a canvas UI. */
    public static function assertCanvasAccess(int $tenant,int $user,int $canvas): void
    {
        self::canvas($tenant,$user,$canvas);
    }

    /** Ownership validation used before a pre-enqueue safety decision. */
    public static function assertThreadAccess(int $tenant,int $user,int $canvas,int $thread): void
    {
        self::canvas($tenant,$user,$canvas);
        self::thread(self::scope($tenant,$user,$canvas),$thread);
    }

    /** Disabling Agent stops new work, but never hides an owner's durable history. */
    public static function assertThreadReadAccess(int $tenant,int $user,int $canvas,int $thread): void
    {
        self::canvas($tenant,$user,$canvas,false,true);
        self::thread(self::scope($tenant,$user,$canvas),$thread);
    }

    /** A replay is already a durable decision. Do not reinterpret it using a
     * policy changed after the original request; enqueue remains the final
     * request-hash and idempotency authority. */
    public static function hasRunRequestKey(int $tenant,int $user,int $canvas,int $thread,string $key): bool
    {
        self::key($key);
        self::canvas($tenant,$user,$canvas);
        $scope=self::scope($tenant,$user,$canvas);self::thread($scope,$thread);
        return Db::name(self::PREFIX.'run')->where($scope+['thread_id'=>$thread,'request_key'=>$key,'delete_time'=>0])->count()>0;
    }

    public static function messages(int $tenant,int $user,int $canvas,int $thread,int $after=0): array
    {
        self::canvas($tenant,$user,$canvas,false,true);
        $scope=self::scope($tenant,$user,$canvas);self::thread($scope,$thread);
        $rows=Db::name(self::PREFIX.'message')->where($scope+['thread_id'=>$thread,'delete_time'=>0])->where('sequence','>',max(0,$after))->order('sequence')->limit(100)->select()->toArray();
        return array_map(static function ($row) use ($scope,$thread) {
            $content=json_decode($row['content_json'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($content) || !is_string($content['text']??null)) throw new RuntimeException('INVALID_CONVERSATION_HISTORY');
            $message=['id'=>(int)$row['id'],'run_id'=>(int)$row['run_id'],'sequence'=>(int)$row['sequence'],'role'=>$row['role'],'content'=>$content,'attachments'=>json_decode($row['attachments_json'],true,512,JSON_THROW_ON_ERROR)];
            $actions=$content['canvas_actions']??null;
            if ($actions!==null) {
                if ($row['role']!=='assistant' || !is_array($actions) || !in_array($actions['mode']??'', ['manual','auto'], true) || !is_int($actions['graph_revision']??null) || !is_array($actions['nodes']??null) || count($actions['nodes'])>60) throw new RuntimeException('INVALID_CONVERSATION_HISTORY');
                $public=[];
                foreach ($actions['nodes'] as $node) {
                    if (!is_array($node) || !preg_match('/^[1-9][0-9]{0,15}$/D',(string)($node['id']??'')) || !in_array($node['type']??'', ['text','image','video','audio'],true) || !is_bool($node['auto_submit']??null)) throw new RuntimeException('INVALID_CONVERSATION_HISTORY');
                    $public[]=['id'=>(string)$node['id'],'type'=>$node['type'],'auto_submit'=>$node['auto_submit']];
                }
                $message['canvas_actions']=['mode'=>$actions['mode'],'graph_revision'=>$actions['graph_revision'],'nodes'=>$public];
            }
            $timeline=$content['workflow_timeline']??null;
            if ($timeline!==null) {
                if ($row['role']!=='assistant' || !is_array($timeline) || !array_is_list($timeline) || count($timeline)>3) throw new RuntimeException('INVALID_CONVERSATION_HISTORY');
                $public=[];
                foreach ($timeline as $item) {
                    if (!is_array($item) || !in_array($item['kind']??'', ['skill','tool'],true) || !is_string($item['label']??null) || !is_string($item['detail']??null)
                        || mb_strlen($item['label'])>24 || mb_strlen($item['detail'])>240) throw new RuntimeException('INVALID_CONVERSATION_HISTORY');
                    $public[]=['kind'=>$item['kind'],'label'=>$item['label'],'detail'=>$item['detail']];
                }
                if ($public) $message['workflow_timeline']=$public;
            }
            $candidates=ConversationReferenceResolver::publicCandidates($content['reference_candidates']??null);
            if ($candidates) $message['reference_candidates']=$candidates;
            $snapshot=Db::name(self::PREFIX.'run')->where($scope+['id'=>$row['run_id'],'thread_id'=>$thread,'delete_time'=>0])->value('context_snapshot');
            $references=[];
            foreach ((json_decode((string)$snapshot,true)['selected_nodes']??[]) as $node) {
                if (($node['type']??'')==='text') $references[]=['node_id'=>(string)$node['id'],'content_revision'=>(int)($node['content_revision']??0),'text'=>(string)($node['content']??'')];
            }
            if ($references) $message['text_references']=$references;
            return $message;
        },$rows);
    }

    public static function events(int $tenant,int $user,int $canvas,int $thread,int $after=0): array
    {
        self::canvas($tenant,$user,$canvas,false,true);
        $scope=self::scope($tenant,$user,$canvas);self::thread($scope,$thread);
        $rows=Db::name(self::PREFIX.'event')->where($scope+['thread_id'=>$thread])->where('id','>',max(0,$after))->order('id')->limit(100)->select()->toArray();
        return array_map(static fn($row)=>['cursor'=>(int)$row['id'],'run_id'=>(int)$row['run_id'],'sequence'=>(int)$row['sequence'],'kind'=>$row['kind'],'payload'=>json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR)],$rows);
    }

    /** Read-only refresh/polling snapshot. Cursors remain owned by their
     * respective message/event streams; reading status must not skip either.
     * Never expose provider snapshots, leases, raw errors or private evidence.
     */
    public static function run(int $tenant,int $user,int $canvas,int $thread,int $run): array
    {
        self::canvas($tenant,$user,$canvas,false,true);
        $scope=self::scope($tenant,$user,$canvas);self::thread($scope,$thread);
        $row=Db::name(self::PREFIX.'run')->where($scope+['thread_id'=>$thread,'id'=>$run,'delete_time'=>0])->find();
        if (!$row) throw new RuntimeException('RUN_NOT_FOUND');
        $status=(string)$row['status'];
        if (!in_array($status,['clarify','queued','running','success','failed','canceled','needs_reconciliation'],true)) throw new RuntimeException('INVALID_RUN_STATE');
        // Client actions depend on durable state, never a guessed provider
        // result. Unknown outcomes are not offered automatic retry/refund.
        return ['id'=>(int)$row['id'],'thread_id'=>(int)$row['thread_id'],
            'status'=>$status,'version'=>(int)$row['version'],
            'can_stop'=>in_array($status,['queued','running'],true),
            'needs_reconciliation'=>$status==='needs_reconciliation',
            'error_code'=>$status==='failed'?($row['error_code']==='PRECHECK_FAILED'?'PRECHECK_FAILED':($row['error_code']==='SAFETY_OUTPUT_BLOCKED'?'CONTENT_BLOCKED':($row['error_code']==='UNSUPPORTED_MODEL_RESPONSE'?'UNSUPPORTED_MODEL_RESPONSE':'RUN_FAILED'))):($status==='canceled'?'USER_STOPPED_BEFORE_SUBMIT':''),
            'create_time'=>(int)$row['create_time'],'update_time'=>(int)$row['update_time']];
    }

    private static function canvas(int $tenant,int $user,int $canvas,bool $lock=false,bool $readOnly=false): array
    {
        $query=Db::name(GraphService::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0]);
        if ($lock) $query->lock(true);
        $row=$query->find();
        if ($tenant<=0 || $user<=0 || !$row) throw new RuntimeException('CANVAS_NOT_FOUND');
        if (!$readOnly) FeatureGate::assertEnabled($tenant);
        return $row;
    }
    private static function thread(array $scope,int $id,bool $lock=false): array
    {
        $query=Db::name(self::PREFIX.'thread')->where($scope+['id'=>$id,'delete_time'=>0]);
        if ($lock) $query->lock(true);
        $row=$query->find();
        if (!$row) throw new RuntimeException('THREAD_NOT_FOUND');
        return $row;
    }
    private static function scope(int $tenant,int $user,int $canvas): array { return ['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas]; }
    /** Convert document references only after ownership and parser state have
     * been checked server-side. The immutable context receives inert text,
     * while the persisted message retains only the document asset reference. */
    private static function contextAttachments(int $tenant,int $user,int $canvas,array $attachments): array
    {
        $result=[];
        foreach ($attachments as $attachment) {
            $result[]=$attachment['type']==='document'
                ? ConversationDocuments::material($tenant,$user,$canvas,(int)$attachment['asset_id'],(string)$attachment['name'])
                : $attachment;
        }
        return $result;
    }
    /** Persist a no-cost clarification as a terminal local run. No outbox is
     * written, so a Worker can never submit it to a Provider. */
    private static function clarifyReference(array $scope,array $thread,string $key,string $hash,int $revision,string $content,array $candidates): array
    {
        $now=time();$sequence=(int)$thread['next_message_sequence'];
        $context=['graph_revision'=>$revision,'selected_nodes'=>[],'reference_candidates'=>$candidates,'material_trust'=>'untrusted','messages'=>[['role'=>'user','content'=>$content]],'history_policy'=>'clarification_only'];
        $run=Db::name(self::PREFIX.'run')->insertGetId($scope+['thread_id'=>$thread['id'],'request_key'=>$key,'request_hash'=>$hash,'status'=>'clarify','context_snapshot'=>self::json($context),'skill_snapshot'=>'{}','settings_snapshot'=>'{}','ack_json'=>'{}','create_time'=>$now,'update_time'=>$now]);
        Db::name(self::PREFIX.'message')->insert($scope+['thread_id'=>$thread['id'],'run_id'=>$run,'sequence'=>$sequence,'role'=>'user','content_json'=>self::json(['text'=>$content]),'attachments_json'=>'[]','create_time'=>$now]);
        $reply='画布中有多个可能的图片，请选择要引用的节点后再发送。';
        Db::name(self::PREFIX.'message')->insert($scope+['thread_id'=>$thread['id'],'run_id'=>$run,'sequence'=>$sequence+1,'role'=>'assistant','content_json'=>self::json(['text'=>$reply,'reference_candidates'=>$candidates]),'attachments_json'=>'[]','create_time'=>$now]);
        $cursor=Db::name(self::PREFIX.'event')->insertGetId($scope+['thread_id'=>$thread['id'],'run_id'=>$run,'sequence'=>1,'kind'=>'run.clarify','payload_json'=>self::json(['status'=>'clarify','candidate_count'=>count($candidates)]),'create_time'=>$now]);
        $ack=['thread_id'=>(int)$thread['id'],'run_id'=>(int)$run,'status'=>'clarify','event_cursor'=>(int)$cursor,'message_sequence'=>$sequence+1];
        Db::name(self::PREFIX.'run')->where('id',$run)->update(['ack_json'=>self::json($ack)]);
        Db::name(self::PREFIX.'thread')->where('id',$thread['id'])->update(['next_message_sequence'=>$sequence+2,'update_time'=>$now]);
        return $ack;
    }
    private static function canonical(array $value): array {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item=self::canonical($item);
        unset($item);return $value;
    }
    private static function key(string $key): void { if (!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D',$key)) throw new RuntimeException('INVALID_REQUEST_KEY'); }
    private static function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private static function threadView(array $row): array { return ['id'=>(int)$row['id'],'title'=>$row['title'],'active_run_id'=>(int)$row['active_run_id'],'create_time'=>(int)$row['create_time']]; }
}
