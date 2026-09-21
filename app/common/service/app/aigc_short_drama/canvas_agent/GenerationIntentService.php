<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasPosterJobService;
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
            $node=null;$nodeIndex=null;
            $nodes=json_decode($document['nodes_json']?:'[]',true,512,JSON_THROW_ON_ERROR);
            foreach ($nodes as $index=>$candidate) {
                if ((string)$candidate['id']===$nodeId) {$node=$candidate;$nodeIndex=$index;break;}
            }
            if (!$node) throw new RuntimeException('NODE_NOT_FOUND');
            if (($node['type']??'')!==$type) throw new RuntimeException('NODE_TYPE_MISMATCH');
            $now=time();
            $run=Db::name(self::RUNS)->insertGetId($scope+[
                'node_id'=>$nodeId,'node_type'=>$type,'status'=>'queued','progress'=>0,
                'request_json'=>self::json($input+['__canvas_intent_version'=>1]),'result_json'=>'{}','error'=>'','create_time'=>$now,'update_time'=>$now,'delete_time'=>0,
            ]);
            $snapshot=['graph_revision'=>$document['graph_revision']??null,
                'content_revision'=>$node['metadata']['content_revision']??0,'target_signature'=>self::nodeInputSignature($node),
                'node_id'=>$nodeId,'type'=>$type,'input'=>$input];
            $id=Db::name(self::TABLE)->insertGetId($scope+[
                'request_key'=>$key,'request_hash'=>$hash,'canvas_run_id'=>$run,'node_id'=>$nodeId,
                'snapshot_json'=>self::json($snapshot),'state'=>'prepared','fencing_version'=>0,
                'claim_token'=>'','lease_until'=>0,'provider_task_id'=>'','error_code'=>'','create_time'=>$now,'update_time'=>$now,
            ]);
            // Bind the authoritative active generation before any provider call.
            // A late earlier run may remain in history but cannot replace it.
            $nodes[$nodeIndex]['metadata']=array_replace((array)($node['metadata']??[]),[
                'canvasRunId'=>$run,'active_generation_id'=>$run,'status'=>'queued','progress'=>0,'error'=>'',
            ]);
            GraphService::persistLockedDocument($document,['nodes_json'=>self::json($nodes),'update_time'=>$now]);
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
            if ((int)($target['metadata']['active_generation_id']??0)!==(int)$row['canvas_run_id']) {
                self::transition($row,['state'=>'canceled','error_code'=>'GENERATION_SUPERSEDED_BEFORE_SUBMIT'],['status'=>'canceled','error'=>'已有更新的生成请求，旧请求未提交']);
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
            self::assertClaimIdentity($row,$token,$fence);
            if ($row['state']==='needs_reconciliation' || ($row['state']==='submitting' && (int)$row['lease_until']<=time())) {
                // A lease fences state advancement, not durable evidence. The
                // original worker may receive a valid task receipt after expiry.
                // Retain it for reconciliation without re-submitting or charging.
                if ($row['error_code']==='LATE_ACCEPTANCE_RECORDED') {
                    $run=Db::name(self::RUNS)->where('id',$row['canvas_run_id'])->lock(true)->find();
                    if ($row['provider_task_id']!==$providerTaskId || ($run['result_json']??'')!==self::json($result)) throw new RuntimeException('PROVIDER_RECEIPT_CONFLICT');
                    return;
                }
                self::transition($row,['state'=>'needs_reconciliation','provider_task_id'=>$providerTaskId,'lease_until'=>0,'error_code'=>'LATE_ACCEPTANCE_RECORDED'],[
                    'provider_task_id'=>$providerTaskId,'result_json'=>self::json($result),'status'=>'needs_reconciliation',
                    'error'=>'已保留迟到的生成回执，待核实任务状态',
                ]);
                return;
            }
            self::assertClaim($row,$token,$fence);
            self::transition($row,['state'=>'accepted','provider_task_id'=>$providerTaskId,'lease_until'=>0],[
                'provider_task_id'=>$providerTaskId,'status'=>$synchronous?'success':'running','progress'=>$synchronous?100:25,
                'result_json'=>self::json($result),'error'=>'',
            ]);
        });
    }

    public static function rejected(int $tenant, int $user, int $id, string $token, int $fence, \app\common\service\ai\PreSubmissionRejected $error): void
    {
        Db::transaction(function () use ($tenant,$user,$id,$token,$fence,$error): void {
            $row=self::owned($tenant,$user,$id);
            self::assertClaim($row,$token,$fence);
            self::transition($row,['state'=>'failed','error_code'=>'PRE_SUBMISSION_REJECTED','lease_until'=>0],[
                'status'=>'failed','progress'=>0,'error'=>mb_substr($error->getMessage(),0,500),
            ]);
        });
    }

    public static function unknown(int $tenant, int $user, int $id, string $token, int $fence): void
    {
        Db::transaction(function () use ($tenant,$user,$id,$token,$fence): void {
            $row=self::owned($tenant,$user,$id);
            self::assertClaimIdentity($row,$token,$fence);
            if ($row['state']==='needs_reconciliation') return;
            if ($row['state']!=='submitting') throw new RuntimeException('STALE_SUBMISSION_CLAIM');
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

    /** Project only an authoritative completed run, never a caller-supplied result. */
    public static function projectResult(int $tenant,int $user,int $runId): bool
    {
        return Db::transaction(function () use ($tenant,$user,$runId): bool {
            $identity=Db::name(self::RUNS)->where(['id'=>$runId,'tenant_id'=>$tenant,'user_id'=>$user])->find();
            if (!$identity) return false;
            $document=Db::name(GraphService::TABLE)->where(['id'=>$identity['canvas_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            if (!$document) return false;
            $intent=Db::name(self::TABLE)->where(['canvas_run_id'=>$runId,'canvas_id'=>$document['id'],'tenant_id'=>$tenant,'user_id'=>$user])->lock(true)->find();
            if (!$intent || $intent['state']!=='accepted') return false;
            $run=Db::name(self::RUNS)->where('id',$runId)->lock(true)->find();
            if ($run['status']!=='success') return false;
            $nodes=json_decode($document['nodes_json']?:'[]',true,512,JSON_THROW_ON_ERROR);
            $snapshot=json_decode($intent['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            foreach ($nodes as &$node) {
                if ((string)$node['id']!==(string)$intent['node_id']) continue;
                $metadata=(array)($node['metadata']??[]);
                if ((int)($metadata['active_generation_id']??0)!==$runId || (int)($metadata['canvasRunId']??0)!==$runId || (int)($metadata['projected_generation_id']??0)===$runId) return false;
                if (!isset($snapshot['target_signature']) || !hash_equals($snapshot['target_signature'],self::nodeInputSignature($node))) return false;
                $result=json_decode($run['result_json']?:'{}',true,512,JSON_THROW_ON_ERROR);
                if ($run['node_type']==='text') {
                    $content=$result['content']??$result['text']??'';
                    if (!is_string($content) || $content==='') return false;
                    $metadata['content']=$content;
                    $metadata['richContent']='';
                } else {
                    $media=$result['results'][0]??null;
                    if (!is_array($media) || empty($media['url'])) return false;
                    $metadata['url']=(string)$media['url'];
                    $field=['image'=>'image','video'=>'video_url','audio'=>'audio_url'][$run['node_type']]??null;
                    if (!$field) return false;
                    $metadata[$field]=(string)$media['url'];
                    foreach (['storage_scope','storage_engine','storage_domain'] as $key) $metadata[$key]=(string)($media[$key]??'');
                    if ($run['node_type']==='video') {
                        $metadata['poster_url']=(string)($media['poster_url']??'');
                        $metadata['poster_uri']=(string)($media['poster_uri']??'');
                        $metadata['poster_status']=$metadata['poster_url']!==''?'ready':'pending';
                        if ($metadata['poster_url']==='' && $metadata['poster_uri']==='') {
                            // Reuse the existing tenant-scoped asynchronous job;
                            // do not download or extract media under graph locks.
                            ShortDramaCanvasPosterJobService::enqueue($tenant,$user,(int)$document['id'],(string)$node['id'],
                                (string)($media['uri']??$media['url']),$metadata['storage_scope'],$metadata['storage_engine'],$metadata['storage_domain']);
                        }
                    }
                }
                $node['metadata']=array_replace($metadata,['status'=>'success','progress'=>100,'error'=>'','projected_generation_id'=>$runId,'content_revision'=>(int)($metadata['content_revision']??0)+1]);
                GraphService::persistLockedDocument($document,['nodes_json'=>self::json($nodes),'update_time'=>time()]);
                return true;
            }
            return false;
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
    private static function assertClaimIdentity(array $row,string $token,int $fence): void {
        if ($token==='' || !hash_equals($row['claim_token'],$token) || $fence<1 || (int)$row['fencing_version']!==$fence) throw new RuntimeException('STALE_SUBMISSION_CLAIM');
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
        foreach (['status','progress','error','errorDetails','canvasRunId','active_generation_id','projected_generation_id','layout_revision','groupId','agentGroupId','poster_url','poster_uri','poster_status','poster'] as $field) unset($metadata[$field]);
        // Layout and progress do not authorize a different generation input.
        return hash('sha256',self::json(self::canonical(['type'=>$node['type']??'','title'=>$node['title']??'','metadata'=>$metadata])));
    }
    private static function json(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
