<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;

/** Durable submission boundary. Not exposed until the Canvas adapter is integrated. */
final class GenerationIntentService
{
    public const TABLE = 'aigc_short_drama_canvas_generation_intent';
    private const RUNS = 'aigc_short_drama_canvas_run';

    public static function lookup(int $tenant,int $user,int $canvas,string $key,string $nodeId,string $type,array $requestInput): ?array
    {
        $row=Db::name(self::TABLE)->where(['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'request_key'=>$key])->find();
        if ($row && !hash_equals($row['request_hash'],self::requestHash($nodeId,$type,$requestInput))) throw new RuntimeException('IDEMPOTENCY_CONFLICT');
        return $row ?: null;
    }

    public static function reserve(int $tenant, int $user, int $canvas, string $key, string $nodeId, string $type, array $input, ?array $requestInput=null): array
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D',$key)) throw new RuntimeException('INVALID_REQUEST_KEY');
        if (!in_array($type,['text','image','video','audio'],true)) throw new RuntimeException('INVALID_NODE_TYPE');
        $hash=self::requestHash($nodeId,$type,$requestInput??$input);
        return Db::transaction(function () use ($tenant,$user,$canvas,$key,$nodeId,$type,$input,$hash): array {
            $document=Db::name(GraphService::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            if (!$document) throw new RuntimeException('CANVAS_NOT_FOUND');
            $scope=['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas];
            $existing=Db::name(self::TABLE)->where($scope+['request_key'=>$key])->lock(true)->find();
            if ($existing) {
                if (!hash_equals($existing['request_hash'],$hash)) throw new RuntimeException('IDEMPOTENCY_CONFLICT');
                return $existing;
            }
            $node=null;
            foreach (json_decode($document['nodes_json']?:'[]',true,512,JSON_THROW_ON_ERROR) as $candidate) {
                if ((string)$candidate['id']===$nodeId) {$node=$candidate;break;}
            }
            if (!$node) throw new RuntimeException('NODE_NOT_FOUND');
            if (($node['type']??'')!==$type) throw new RuntimeException('NODE_TYPE_MISMATCH');
            $now=time();
            $run=Db::name(self::RUNS)->insertGetId($scope+[
                'node_id'=>$nodeId,'node_type'=>$type,'status'=>'queued','progress'=>0,
                'request_json'=>self::json($input),'result_json'=>'{}','error'=>'','create_time'=>$now,'update_time'=>$now,'delete_time'=>0,
            ]);
            $snapshot=['graph_revision'=>$document['graph_revision']??null,
                'content_revision'=>$node['metadata']['content_revision']??0,'target_signature'=>self::nodeInputSignature($node),
                'node_id'=>$nodeId,'type'=>$type,'input'=>$input];
            $id=Db::name(self::TABLE)->insertGetId($scope+[
                'request_key'=>$key,'request_hash'=>$hash,'canvas_run_id'=>$run,'node_id'=>$nodeId,
                'snapshot_json'=>self::json($snapshot),'state'=>'prepared','fencing_version'=>0,
                'claim_token'=>'','lease_until'=>0,'provider_task_id'=>'','error_code'=>'','create_time'=>$now,'update_time'=>$now,
            ]);
            return Db::name(self::TABLE)->where('id',$id)->find();
        });
    }

    /** A prepared row is the durable outbox; only its first claimant may submit. */
    public static function claim(int $tenant, int $user, int $id, int $leaseSeconds=120): ?array
    {
        return Db::transaction(function () use ($tenant,$user,$id,$leaseSeconds): ?array {
            $identity=Db::name(self::TABLE)->where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user])->find();
            if (!$identity) throw new RuntimeException('GENERATION_INTENT_NOT_FOUND');
            // Same lock order as reserve: canvas before intent. A delete before
            // this claim is visible; a delete after it must fence result projection.
            $document=Db::name(GraphService::TABLE)->where(['id'=>$identity['canvas_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            $row=self::owned($tenant,$user,$id);
            if ($row['state']!=='prepared') return null;
            $target=null;
            foreach (json_decode($document['nodes_json']??'[]',true)?:[] as $node) if ((string)$node['id']===(string)$row['node_id']) {$target=$node;break;}
            if (!$target) {
                self::transition($row,['state'=>'canceled','error_code'=>'NODE_REMOVED_BEFORE_SUBMIT'],['status'=>'canceled','error'=>'节点已删除，未提交生成']);
                return null;
            }
            $snapshot=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            if (!isset($snapshot['target_signature']) || !hash_equals($snapshot['target_signature'],self::nodeInputSignature($target))) {
                self::transition($row,['state'=>'canceled','error_code'=>'INPUT_CHANGED_BEFORE_SUBMIT'],['status'=>'canceled','error'=>'节点输入已变化，请确认新参数后重新提交']);
                return null;
            }
            $changes=['state'=>'submitting','claim_token'=>bin2hex(random_bytes(24)),
                'fencing_version'=>(int)$row['fencing_version']+1,'lease_until'=>time()+max(1,min(3600,$leaseSeconds))];
            self::transition($row,$changes,['status'=>'running','progress'=>5]);
            return array_replace($row,$changes);
        });
    }

    /** Called after provider I/O, never while holding graph or intent locks. */
    public static function accepted(int $tenant, int $user, int $id, string $token, int $fence, string $providerTaskId, array $result, bool $synchronous=false): void
    {
        Db::transaction(function () use ($tenant,$user,$id,$token,$fence,$providerTaskId,$result,$synchronous): void {
            $row=self::owned($tenant,$user,$id);
            self::assertClaim($row,$token,$fence);
            self::transition($row,['state'=>'accepted','provider_task_id'=>$providerTaskId,'lease_until'=>0],[
                'provider_task_id'=>$providerTaskId,'status'=>$synchronous?'success':'running','progress'=>$synchronous?100:25,
                'result_json'=>self::json($result),'error'=>'',
            ]);
        });
    }

    public static function unknown(int $tenant, int $user, int $id, string $token, int $fence): void
    {
        Db::transaction(function () use ($tenant,$user,$id,$token,$fence): void {
            $row=self::owned($tenant,$user,$id);
            self::assertClaim($row,$token,$fence);
            self::transition($row,['state'=>'needs_reconciliation','error_code'=>'SUBMISSION_OUTCOME_UNKNOWN','lease_until'=>0],[
                'status'=>'needs_reconciliation','error'=>'生成提交结果待核实，请勿重复提交',
            ]);
        });
    }

    public static function expire(int $tenant, int $user, int $id): bool
    {
        return Db::transaction(function () use ($tenant,$user,$id): bool {
            $row=self::owned($tenant,$user,$id);
            if ($row['state']!=='submitting' || (int)$row['lease_until']>time()) return false;
            // Never requeue an expired submit: the provider may already have accepted it.
            self::transition($row,['state'=>'needs_reconciliation','error_code'=>'SUBMISSION_LEASE_EXPIRED','lease_until'=>0],[
                'status'=>'needs_reconciliation','error'=>'生成提交中断，需核实外部任务状态',
            ]);
            return true;
        });
    }
    private static function owned(int $tenant,int $user,int $id): array {
        $row=Db::name(self::TABLE)->where(['id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user])->lock(true)->find();
        if (!$row) throw new RuntimeException('GENERATION_INTENT_NOT_FOUND');
        return $row;
    }
    private static function assertClaim(array $row,string $token,int $fence): void {
        if ($row['state']!=='submitting' || !hash_equals($row['claim_token'],$token) || (int)$row['fencing_version']!==$fence || (int)$row['lease_until']<=time()) throw new RuntimeException('STALE_SUBMISSION_CLAIM');
    }
    private static function transition(array $row,array $intent,array $run): void {
        Db::name(self::TABLE)->where('id',$row['id'])->update($intent+['update_time'=>time()]);
        Db::name(self::RUNS)->where(['id'=>$row['canvas_run_id'],'tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'canvas_id'=>$row['canvas_id']])->update($run+['update_time'=>time()]);
    }
    private static function canonical(array $value): array {
        if (array_keys($value)!==range(0,count($value)-1)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item=self::canonical($item);
        return $value;
    }
    private static function requestHash(string $nodeId,string $type,array $input): string {
        return hash('sha256',self::json(self::canonical(['node_id'=>$nodeId,'type'=>$type,'input'=>$input])));
    }
    private static function nodeInputSignature(array $node): string {
        $metadata=(array)($node['metadata']??[]);
        foreach (['status','progress','error','errorDetails','canvasRunId','active_generation_id','layout_revision','groupId','agentGroupId','poster_url','poster_uri','poster_status','poster'] as $field) unset($metadata[$field]);
        // Layout and progress do not authorize a different generation input.
        return hash('sha256',self::json(self::canonical(['type'=>$node['type']??'','title'=>$node['title']??'','metadata'=>$metadata])));
    }
    private static function json(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
