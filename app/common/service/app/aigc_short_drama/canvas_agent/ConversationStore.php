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

    /** Request whitelist intentionally excludes attachments until asset authorization is wired. */
    public static function enqueue(int $tenant,int $user,int $canvas,int $thread,array $request,array|\Closure $resolvedSnapshot,array $selectionIdentity=[]): array
    {
        if (array_diff(array_keys($request),['request_key','content','selected_node_ids','base_revision'])) throw new RuntimeException('UNSUPPORTED_MESSAGE_FIELD');
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
        // Preserve the original storage-only hash when no selection identity
        // was supplied. Explicit model/Skill choices are part of send identity.
        if ($selectionIdentity!==[]) $identity['selection']=self::canonical($selectionIdentity);
        $hash=hash('sha256',self::json($identity));
        return Db::transaction(function () use ($tenant,$user,$canvas,$thread,$key,$content,$ids,$revision,$hash,$resolvedSnapshot): array {
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
            // Server-owned resolver performs local catalog reads only. Never
            // do provider I/O here; replay must not re-resolve changing defaults.
            if ($resolvedSnapshot instanceof \Closure) $resolvedSnapshot=$resolvedSnapshot();
            if (array_diff(array_keys($resolvedSnapshot),['settings','skill']) || !is_array($resolvedSnapshot['settings']??null) || !is_array($resolvedSnapshot['skill']??null)) throw new RuntimeException('INVALID_RESOLVED_SNAPSHOT');
            $selected=[];
            foreach (json_decode($document['nodes_json']?:'[]',true,512,JSON_THROW_ON_ERROR) as $node) {
                if (in_array((string)$node['id'],$ids,true)) {
                    // Node material is data, never privileged instructions.
                    $selected[(string)$node['id']]=[
                        'id'=>(string)$node['id'],'type'=>$node['type'],'x'=>$node['x']??0,'y'=>$node['y']??0,
                        'content_revision'=>$node['metadata']['content_revision']??0,
                        'content'=>$node['metadata']['content']??'',
                        'prompt'=>$node['metadata']['prompt']??'',
                    ];
                }
            }
            if (count($selected)!==count($ids)) throw new RuntimeException('NODE_NOT_FOUND');
            $history=Db::name(self::PREFIX.'message')->where($scope+['thread_id'=>$thread,'delete_time'=>0])->order('sequence','desc')->limit(38)->select()->toArray();
            $messages=[];
            foreach (array_reverse($history) as $message) {
                if (!in_array($message['role'],['user','assistant'],true)) throw new RuntimeException('INVALID_CONVERSATION_HISTORY');
                $messages[]=['role'=>$message['role'],'content'=>(string)(json_decode($message['content_json'],true,512,JSON_THROW_ON_ERROR)['text']??'')];
            }
            $messages[]=['role'=>'user','content'=>$content];
            $context=['graph_revision'=>$revision,'selected_nodes'=>array_map(static fn($id)=>$selected[$id],$ids),'material_trust'=>'untrusted','messages'=>$messages,'history_policy'=>'last_38_plus_current'];
            $contextJson=self::json($context);
            $settings=self::json($resolvedSnapshot['settings']);$skill=self::json($resolvedSnapshot['skill']);
            if (strlen($contextJson)+strlen($settings)+strlen($skill)>1048576) throw new RuntimeException('CONTEXT_TOO_LARGE');
            $now=time();$sequence=(int)$conversation['next_message_sequence'];
            $run=Db::name(self::PREFIX.'run')->insertGetId($scope+['thread_id'=>$thread,'request_key'=>$key,'request_hash'=>$hash,'status'=>'queued','context_snapshot'=>$contextJson,'skill_snapshot'=>$skill,'settings_snapshot'=>$settings,'ack_json'=>'{}','create_time'=>$now,'update_time'=>$now]);
            Db::name(self::PREFIX.'message')->insert($scope+['thread_id'=>$thread,'run_id'=>$run,'sequence'=>$sequence,'role'=>'user','content_json'=>self::json(['text'=>$content]),'attachments_json'=>'[]','create_time'=>$now]);
            $cursor=Db::name(self::PREFIX.'event')->insertGetId($scope+['thread_id'=>$thread,'run_id'=>$run,'sequence'=>1,'kind'=>'run.queued','payload_json'=>self::json(['status'=>'queued','message_sequence'=>$sequence]),'create_time'=>$now]);
            Db::name(self::PREFIX.'outbox')->insert($scope+['run_id'=>$run,'event_key'=>'run:'.$run,'available_at'=>$now,'create_time'=>$now,'update_time'=>$now]);
            $ack=['thread_id'=>$thread,'run_id'=>(int)$run,'status'=>'queued','event_cursor'=>(int)$cursor,'message_sequence'=>$sequence];
            Db::name(self::PREFIX.'run')->where('id',$run)->update(['ack_json'=>self::json($ack)]);
            Db::name(self::PREFIX.'thread')->where('id',$thread)->update(['active_run_id'=>$run,'next_message_sequence'=>$sequence+1,'update_time'=>$now]);
            return $ack;
        });
    }

    public static function threads(int $tenant,int $user,int $canvas,int $before=0): array
    {
        self::canvas($tenant,$user,$canvas);
        $query=Db::name(self::PREFIX.'thread')->where(self::scope($tenant,$user,$canvas)+['delete_time'=>0]);
        if ($before>0) $query->where('id','<',$before);
        return array_map([self::class,'threadView'],$query->order('id','desc')->limit(50)->select()->toArray());
    }

    /** Shared ownership gate for account preferences reached from a canvas UI. */
    public static function assertCanvasAccess(int $tenant,int $user,int $canvas): void
    {
        self::canvas($tenant,$user,$canvas);
    }

    public static function messages(int $tenant,int $user,int $canvas,int $thread,int $after=0): array
    {
        self::canvas($tenant,$user,$canvas);
        $scope=self::scope($tenant,$user,$canvas);self::thread($scope,$thread);
        $rows=Db::name(self::PREFIX.'message')->where($scope+['thread_id'=>$thread,'delete_time'=>0])->where('sequence','>',max(0,$after))->order('sequence')->limit(100)->select()->toArray();
        return array_map(static fn($row)=>['id'=>(int)$row['id'],'run_id'=>(int)$row['run_id'],'sequence'=>(int)$row['sequence'],'role'=>$row['role'],'content'=>json_decode($row['content_json'],true,512,JSON_THROW_ON_ERROR),'attachments'=>json_decode($row['attachments_json'],true,512,JSON_THROW_ON_ERROR)],$rows);
    }

    public static function events(int $tenant,int $user,int $canvas,int $thread,int $after=0): array
    {
        self::canvas($tenant,$user,$canvas);
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
        self::canvas($tenant,$user,$canvas);
        $scope=self::scope($tenant,$user,$canvas);self::thread($scope,$thread);
        $row=Db::name(self::PREFIX.'run')->where($scope+['thread_id'=>$thread,'id'=>$run,'delete_time'=>0])->find();
        if (!$row) throw new RuntimeException('RUN_NOT_FOUND');
        $status=(string)$row['status'];
        if (!in_array($status,['queued','running','success','failed','canceled','needs_reconciliation'],true)) throw new RuntimeException('INVALID_RUN_STATE');
        // Client actions depend on durable state, never a guessed provider
        // result. Unknown outcomes are not offered automatic retry/refund.
        return ['id'=>(int)$row['id'],'thread_id'=>(int)$row['thread_id'],
            'status'=>$status,'version'=>(int)$row['version'],
            'can_stop'=>in_array($status,['queued','running'],true),
            'needs_reconciliation'=>$status==='needs_reconciliation',
            'error_code'=>$status==='failed'?($row['error_code']==='PRECHECK_FAILED'?'PRECHECK_FAILED':'RUN_FAILED'):($status==='canceled'?'USER_STOPPED_BEFORE_SUBMIT':''),
            'create_time'=>(int)$row['create_time'],'update_time'=>(int)$row['update_time']];
    }

    private static function canvas(int $tenant,int $user,int $canvas,bool $lock=false): array
    {
        $query=Db::name(GraphService::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0]);
        if ($lock) $query->lock(true);
        $row=$query->find();
        if ($tenant<=0 || $user<=0 || !$row) throw new RuntimeException('CANVAS_NOT_FOUND');
        FeatureGate::assertEnabled($tenant);
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
    private static function canonical(array $value): array {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item=self::canonical($item);
        unset($item);return $value;
    }
    private static function key(string $key): void { if (!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D',$key)) throw new RuntimeException('INVALID_REQUEST_KEY'); }
    private static function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private static function threadView(array $row): array { return ['id'=>(int)$row['id'],'title'=>$row['title'],'active_run_id'=>(int)$row['active_run_id'],'create_time'=>(int)$row['create_time']]; }
}
