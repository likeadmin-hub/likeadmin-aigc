<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;

/** Versioned graph mutation boundary. Not exposed until all P1 writers migrate. */
final class GraphService
{
    public const TABLE = 'aigc_short_drama_canvas';
    public const RECEIPTS = 'aigc_short_drama_canvas_mutation_receipt';
    private const SERVER_FIELDS = ['status','progress','error','canvasRunId','active_generation_id','projected_generation_id','asset_id','asset_version','asset_owner','tenant_id','user_id','owner_app','business_binding','cost','cost_points','billing_status','content_revision','layout_revision','agent_auto_submit','agent_auto_run_id','agent_auto_request_key','agent_auto_payload_hash','agent_manual_submit','workflow_source_stage','workflow_artifact','workflow_key','workflow_submission_policy','workflow_audio_disabled','workflow_plan_hash','workflow_formal_fields','workflow_formal_content_hash','workflow_prompt_run_id','workflow_style_id','workflow_style_name'];

    /**
     * Server-only Agent writer.  ConversationExecution already owns the
     * document lock when this method is called, so this shares the exact same
     * CAS/revision path as manual saves and runtime projections.
     *
     * @param list<array{type:string,title:string,prompt:string,artifact?:string,key?:string,depends_on?:list<string>,reference_keys?:list<string>}> $proposals
     * @param list<string> $sourceIds frozen IDs from the accepted conversation
     * @return array{graph_revision:int,nodes:list<array{id:string,type:string,auto_submit:bool}>}
     */
    public static function appendAgentNodesLocked(array $document, array $proposals, array $sourceIds, bool $auto, array $settings=[], int $agentRunId=0, array $workflow=[]): array
    {
        $workflowStage=(string)($workflow['stage_state']['key']??'');
        $compact=ConversationWorkflow::compactOutput($workflow);
        $maximum=ConversationActionPlan::maximumNodesForStage($workflowStage,$compact);
        if (!$proposals || count($proposals) > $maximum) throw new RuntimeException('INVALID_AGENT_ACTION');
        $nodes = json_decode($document['nodes_json'] ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        $edges = json_decode($document['edges_json'] ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        $removed = json_decode($document['removed_node_ids_json'] ?: '[]', true, 512, JSON_THROW_ON_ERROR);
        if (count($nodes) + count($proposals) > 200) throw new RuntimeException('CANVAS_CAPACITY_EXCEEDED');
        $liveSources=[];
        foreach ($sourceIds as $sourceId) {
            if (!is_string($sourceId) || !preg_match('/^[1-9][0-9]{0,15}$/D', $sourceId)) continue;
            $index=self::index($nodes,$sourceId);
            if ($index!==null) $liveSources[]=$sourceId;
        }
        $liveSources=array_values(array_unique($liveSources));
        $maximumX=0.0; $maximumY=0.0;
        foreach ($nodes as $node) { $maximumX=max($maximumX,(float)($node['x']??0)); $maximumY=max($maximumY,(float)($node['y']??0)); }
        self::validateAgentProposals($proposals,$workflowStage,$compact);
        $created=[];
        $createdByKey=[];
        foreach ($proposals as $offset=>$proposal) {
            $type=(string)$proposal['type']; $title=trim((string)$proposal['title']); $prompt=trim((string)$proposal['prompt']);
            $id=(string)self::allocateNodeId($nodes,$removed);
            $size=$type==='text' ? [320,280] : [420,320];
            // Text artifacts are durable, readable workflow output.  Do not
            // leave their content blank and rely on a prompt label: later
            // stages and the user both consume exactly this projected text.
            $metadata=['prompt'=>$prompt,'content'=>$type==='text'?$prompt:'','status'=>'idle','progress'=>0,'error'=>'','content_revision'=>1,'layout_revision'=>1];
            if ($workflowStage!=='') {
                $metadata['workflow_source_stage']=$workflowStage;
                $metadata['workflow_artifact']=(string)($proposal['artifact']??'');
                $creative=(array)($workflow['creative_settings']??[]);
                if (!empty($creative['aspect_ratio'])) $metadata['ratio']=(string)$creative['aspect_ratio'];
                if (!empty($creative['style_id'])) $metadata['workflow_style_id']=(string)$creative['style_id'];
                if (!empty($creative['style_name'])) $metadata['workflow_style_name']=(string)$creative['style_name'];
                if (isset($proposal['key'])) $metadata['workflow_key']=$workflowStage.':'.(string)$proposal['key'];
                $metadata['workflow_plan_hash']=(string)($workflow['plan_hash']??'');
                // The run owns the frozen creative configuration. Store only
                // its scoped ID, never a copy of tenant prompt text on every
                // public canvas node (up to sixty video nodes per plan).
                if ($agentRunId>0 && in_array($type,['image','video'],true)
                    && version_compare((string)($workflow['workflow_snapshot']['version']??'0'),'2026-09-23.8','>=')) {
                    $metadata['workflow_prompt_run_id']=$agentRunId;
                }
            }
            if ($type==='text') $metadata['model_code']=(string)($settings['reasoning_model']['id']??'');
            else $metadata['channel']=(string)($settings[$type.'_model']['id']??'');
            // Videos are deliberately never automatic Agent work.  A video
            // proposal is a storyboard delivery: the graph is connected and
            // durable, but the user must intentionally enter the normal
            // node-level quote/submit flow.  Keeping this server-owned makes
            // a stale browser, a worker restart or a forged save unable to
            // turn an automatic image plan into a paid video submission.
            $autoSubmit = $auto && $type !== 'video' && $type !== 'audio';
            if ($type === 'video') {
                $metadata['agent_manual_submit']=1;
                $metadata['workflow_submission_policy']='manual_quote_confirmed';
            }
            if ($type === 'audio') {
                $metadata['workflow_audio_disabled']=1;
                $metadata['workflow_submission_policy']='disabled';
            }
            if ($autoSubmit) {
                if ($agentRunId <= 0) throw new RuntimeException('INVALID_AGENT_ACTION');
                // This key is allocated by the server before the document is
                // published.  Browser retries, a restarted worker and a
                // manual retry all therefore converge on the same durable
                // generation intent instead of creating another chargeable
                // task.
                $metadata['agent_auto_submit']=1;
                $metadata['agent_auto_run_id']=$agentRunId;
                $metadata['agent_auto_request_key']='agent.'.$agentRunId.'.'.$id;
                $metadata['agent_auto_payload_hash']=self::autoPayloadHash($metadata);
            }
            $node=['id'=>(int)$id,'type'=>$type,'title'=>$title,'x'=>$maximumX+420+($offset%2)*40,'y'=>$maximumY+($offset*360),'width'=>$size[0],'height'=>$size[1],'metadata'=>$metadata];
            // Workflow references are durable graph facts. The model can only
            // select from server-derived workflow keys, so it cannot forge a
            // node ID or turn every historic artifact into a noisy input.
            // Selected user material remains an independent agent_context.
            $workflowSources=$compact
                ? self::compactWorkflowReferenceSources($nodes,$edges,$workflowStage,$proposal,$workflow,$liveSources)
                : self::workflowReferenceSources($nodes,$workflowStage,$proposal);
            $referenceSources=[];
            foreach ($workflowSources as $sourceId) $referenceSources[$sourceId]='workflow_reference';
            if (!$compact) foreach ($liveSources as $sourceId) $referenceSources[$sourceId]='agent_context';
            foreach ($referenceSources as $sourceId=>$role) {
                $sourceId=(string)$sourceId;
                $sourceIndex=self::index($nodes,$sourceId);
                if ($sourceIndex===null || !self::referenceConnectionAllowed(array_merge($nodes,[$node]),$sourceIndex,count($nodes))) {
                    if ($compact) throw new RuntimeException('EDGE_CAPABILITY_UNSUPPORTED');
                    continue;
                }
                $edges[]=['from'=>(int)$sourceId,'to'=>(int)$id,'kind'=>'reference','role'=>$role,'order'=>count($edges)];
            }
            foreach ((array)($proposal['depends_on']??[]) as $order=>$dependencyKey) {
                $sourceId=(string)($createdByKey[$dependencyKey]??'');
                $sourceIndex=self::index($nodes,$sourceId);
                if ($sourceIndex===null || !self::referenceConnectionAllowed(array_merge($nodes,[$node]),$sourceIndex,count($nodes))) throw new RuntimeException('EDGE_CAPABILITY_UNSUPPORTED');
                $edges[]=['from'=>(int)$sourceId,'to'=>(int)$id,'kind'=>'reference','role'=>'agent_dependency','order'=>$order];
            }
            $nodes[]=$node;
            if (isset($proposal['key'])) $createdByKey[(string)$proposal['key']]=$id;
            $created[]=['id'=>$id,'type'=>$type,'auto_submit'=>$autoSubmit];
        }
        $updated=self::persistLockedDocument($document,['nodes_json'=>self::json($nodes),'edges_json'=>self::json($edges),'removed_node_ids_json'=>self::json($removed),'schema_version'=>2,'update_time'=>time()]);
        return ['graph_revision'=>(int)($updated['graph_revision']??0),'nodes'=>$created];
    }

    /**
     * A terminal upstream dependency is a local graph fact. Mark only its
     * still-idle automatic child failed; independent nodes remain eligible and
     * a later explicit retry is still routed through the normal run boundary.
     */
    public static function blockAgentDependentNode(int $tenant, int $user, int $canvas, string $nodeId, string $message): bool
    {
        return Db::transaction(function () use ($tenant,$user,$canvas,$nodeId,$message): bool {
            $document=Db::name(self::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            if (!$document) return false;
            $nodes=json_decode((string)($document['nodes_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            $changed=false;
            foreach ($nodes as &$node) {
                if ((string)($node['id']??'')!==$nodeId) continue;
                $metadata=(array)($node['metadata']??[]);
                if (empty($metadata['agent_auto_submit']) || (string)($metadata['status']??'idle')!=='idle') return false;
                $node['metadata']=array_replace($metadata,['status'=>'failed','progress'=>0,'error'=>mb_substr($message,0,300)]);
                $changed=true;
                break;
            }
            unset($node);
            if (!$changed) return false;
            self::persistLockedDocument($document,['nodes_json'=>self::json($nodes),'update_time'=>time()]);
            return true;
        });
    }

    /**
     * Repair only the legacy Agent asset batch that was written before
     * workflow references became scoped. It intentionally touches incoming
     * edges from workflow-owned script/art/asset nodes only, leaving every
     * non-workflow (therefore potentially user-created) edge unchanged.
     *
     * @return array{changed:bool,removed:int,added:int,normalized:int,graph_revision:int}
     */
    public static function repairLegacyAgentAssetReferences(int $tenant,int $user,int $canvas): array
    {
        return Db::transaction(function () use ($tenant,$user,$canvas): array {
            $document=Db::name(self::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            if (!$document) throw new RuntimeException('CANVAS_NOT_FOUND');
            $nodes=json_decode((string)($document['nodes_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            $edges=json_decode((string)($document['edges_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            $originalEdges=$edges;
            $byId=[];$assetNodes=[];$artSources=[];
            foreach ($nodes as $node) {
                $id=(string)($node['id']??'');$metadata=(array)($node['metadata']??[]);
                if ($id==='') continue;
                $byId[$id]=$node;
                if (($metadata['workflow_source_stage']??'')==='assets' && in_array((string)($metadata['workflow_artifact']??''),['subject','three_view'],true)) $assetNodes[]=$node;
                if (($metadata['workflow_source_stage']??'')==='art' && (string)($node['type']??'')==='text') $artSources[(string)($metadata['workflow_artifact']??'')][]=$id;
            }
            if (!$assetNodes) return ['changed'=>false,'removed'=>0,'added'=>0,'normalized'=>0,'graph_revision'=>(int)($document['graph_revision']??0)];
            $assetIds=array_fill_keys(array_map(static fn(array $node): string=>(string)$node['id'],$assetNodes),true);
            $removed=0;
            $edges=array_values(array_filter($edges,static function (array $edge) use ($assetIds,$byId,&$removed): bool {
                if (!isset($assetIds[(string)($edge['to']??'')])) return true;
                $source=$byId[(string)($edge['from']??'')]??[];
                $stage=(string)(($source['metadata']['workflow_source_stage']??''));
                if (in_array($stage,['script','art','assets'],true)) {$removed++;return false;}
                return true;
            }));
            $added=0;
            $addEdge=static function (string $from,string $to,string $role) use (&$edges,&$added): void {
                foreach ($edges as $edge) if ((string)($edge['from']??'')===$from && (string)($edge['to']??'')===$to && (string)($edge['kind']??'reference')==='reference' && (string)($edge['role']??'')===$role) return;
                $edges[]=['from'=>(int)$from,'to'=>(int)$to,'kind'=>'reference','role'=>$role,'order'=>count($edges)];$added++;
            };
            $subjects=[];
            foreach ($assetNodes as $node) {
                $metadata=(array)($node['metadata']??[]);$artifact=(string)($metadata['workflow_artifact']??'');$id=(string)$node['id'];
                if ($artifact==='subject') {
                    foreach (array_merge((array)($artSources['character_asset_spec']??[]),(array)($artSources['subject_image_prompt']??[])) as $sourceId) $addEdge($sourceId,$id,'workflow_reference');
                    $subjects[]=$node;
                    continue;
                }
                foreach ((array)($artSources['three_view_prompt']??[]) as $sourceId) $addEdge($sourceId,$id,'workflow_reference');
                $subject=self::matchingSubject($subjects,(string)($node['title']??''));
                if ($subject!==null) $addEdge((string)$subject['id'],$id,'agent_dependency');
            }
            // Old browser releases hydrated only `from`/`to` and then wrote a
            // whole-document save. Restore the meaning of *Agent-owned* edges
            // that survived that save without guessing at user-created edges.
            // Script/art workflow steps are prerequisites; art-to-asset edges
            // are actual bounded generation context. Asset-to-asset edges are
            // prerequisites again (for example, subject -> three view).
            $normalized=0;
            foreach ($edges as $index=>&$edge) {
                if (!is_array($edge) || (string)($edge['kind']??'reference')!=='reference') continue;
                $source=$byId[(string)($edge['from']??'')]??null;
                $target=$byId[(string)($edge['to']??'')]??null;
                if (!$source || !$target) continue;
                $sourceStage=(string)(($source['metadata']['workflow_source_stage']??''));
                $targetStage=(string)(($target['metadata']['workflow_source_stage']??''));
                if (!in_array($sourceStage,['script','art','assets'],true) || !in_array($targetStage,['script','art','assets'],true)) continue;
                $changed=false;
                if (!isset($edge['kind']) || $edge['kind']==='') {$edge['kind']='reference';$changed=true;}
                if (trim((string)($edge['role']??''))==='') {
                    $edge['role']=$sourceStage==='art' && $targetStage==='assets' ? 'workflow_reference' : 'agent_dependency';
                    $changed=true;
                }
                if (!isset($edge['order']) || !is_int($edge['order']) || $edge['order']<0) {$edge['order']=$index;$changed=true;}
                if ($changed) $normalized++;
            }
            unset($edge);
            // Rebuilding the desired asset inputs is convenient for legacy
            // recovery, but must not turn a no-op verification into another
            // graph revision. A byte-equivalent normalized graph is already
            // healthy, so report an idempotent no-op without writing it.
            if (self::json($edges)===self::json($originalEdges)) return ['changed'=>false,'removed'=>0,'added'=>0,'normalized'=>0,'graph_revision'=>(int)($document['graph_revision']??0)];
            if (!$removed && !$added && !$normalized) return ['changed'=>false,'removed'=>0,'added'=>0,'normalized'=>0,'graph_revision'=>(int)($document['graph_revision']??0)];
            $updated=self::persistLockedDocument($document,['edges_json'=>self::json($edges),'schema_version'=>2,'update_time'=>time()]);
            return ['changed'=>true,'removed'=>$removed,'added'=>$added,'normalized'=>$normalized,'graph_revision'=>(int)($updated['graph_revision']??0)];
        });
    }

    /** Reconcile only idle Agent-created video reference edges. User-drawn
     * edges, submitted runs and other canvas content are never rewritten. */
    public static function repairAgentVideoReferences(int $tenant,int $user,int $canvas): array
    {
        return Db::transaction(function () use ($tenant,$user,$canvas): array {
            $document=Db::name(self::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            if (!$document) throw new RuntimeException('CANVAS_NOT_FOUND');
            $nodes=json_decode((string)($document['nodes_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            $edges=json_decode((string)($document['edges_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            $original=self::json($edges);$repaired=0;
            foreach ($nodes as $node) {
                $meta=(array)($node['metadata']??[]);$id=(string)($node['id']??'');
                if ((string)($node['type']??'')!=='video' || (string)($meta['workflow_source_stage']??'')!=='video_nodes'
                    || (string)($meta['workflow_artifact']??'')!=='storyboard_video'
                    || (string)($meta['status']??'idle')!=='idle' || !empty($meta['active_generation_id'])) continue;
                $sources=[];
                foreach ($edges as $edge) if ((string)($edge['to']??'')===$id && (string)($edge['role']??'')==='workflow_reference'
                    && (string)($edge['kind']??'reference')==='reference') $sources[]=(string)($edge['from']??'');
                if (!$sources) continue;
                $desired=self::videoReferenceSources($nodes,$edges,array_values(array_unique($sources)));
                if ($desired===$sources) continue;
                $edges=array_values(array_filter($edges,static fn(array $edge): bool=>!((string)($edge['to']??'')===$id
                    && (string)($edge['role']??'')==='workflow_reference' && (string)($edge['kind']??'reference')==='reference')));
                foreach ($desired as $sourceId) {
                    $sourceIndex=self::index($nodes,$sourceId);$targetIndex=self::index($nodes,$id);
                    if ($sourceIndex===null || $targetIndex===null || !self::referenceConnectionAllowed($nodes,$sourceIndex,$targetIndex)) throw new RuntimeException('EDGE_CAPABILITY_UNSUPPORTED');
                    $edges[]=['from'=>(int)$sourceId,'to'=>(int)$id,'kind'=>'reference','role'=>'workflow_reference','order'=>count($edges)];
                }
                $repaired++;
            }
            if (self::json($edges)===$original) return ['changed'=>false,'nodes'=>0,'graph_revision'=>(int)($document['graph_revision']??0)];
            $updated=self::persistLockedDocument($document,['edges_json'=>self::json($edges),'schema_version'=>2,'update_time'=>time()]);
            return ['changed'=>true,'nodes'=>$repaired,'graph_revision'=>(int)($updated['graph_revision']??0)];
        });
    }

    private static function matchingSubject(array $subjects,string $threeViewTitle): ?array
    {
        $normalized=trim(str_replace(['角色三视图','三视图','主体定妆照','主体图'],'',$threeViewTitle));
        foreach (array_reverse($subjects) as $subject) {
            $title=(string)($subject['title']??'');
            if ($normalized!=='' && str_contains($title,$normalized)) return $subject;
        }
        return $subjects ? end($subjects) : null;
    }

    /** Whole-document compatibility adapter for concurrency-enabled documents. */
    public static function sanitizeManualNodes(array $document,array $nodes): array
    {
        $saved=[];
        foreach (json_decode($document['nodes_json']?:'[]',true,512,JSON_THROW_ON_ERROR) as $node) $saved[(string)$node['id']]=$node;
        $seen=[];
        foreach ($nodes as &$node) {
            if (!is_array($node) || !in_array($node['type']??'',['text','image','video','audio'],true)) throw new RuntimeException('INVALID_NODE_TYPE');
            $id=self::nodeId($node['id']??null);
            if (isset($seen[$id])) throw new RuntimeException('NODE_ID_CONFLICT');
            $seen[$id]=true;
            self::geometry($node);
            $incoming=$node['metadata']??[];
            if (!is_array($incoming)) throw new RuntimeException('INVALID_METADATA');
            $previous=$saved[$id]??[];$authoritative=(array)($previous['metadata']??[]);
            $metadata=$incoming;
            foreach (self::SERVER_FIELDS as $field) {
                unset($node[$field],$metadata[$field]);
                if (array_key_exists($field,$authoritative)) $metadata[$field]=$authoritative[$field];
            }
            // Legacy manual generation binds only a real, latest owned run.
            // Intent-backed nodes already have a server-bound active generation.
            $requestedRun=(int)($incoming['canvasRunId']??0);
            if (empty($authoritative['active_generation_id']) && $requestedRun>0) {
                $run=Db::name('aigc_short_drama_canvas_run')->where(['tenant_id'=>$document['tenant_id'],'user_id'=>$document['user_id'],'canvas_id'=>$document['id'],'node_id'=>$id,'node_type'=>$node['type'],'delete_time'=>0])->order('id','desc')->find();
                if ($run && (int)$run['id']===$requestedRun) $metadata=array_replace($metadata,['canvasRunId'=>$requestedRun,'status'=>$run['status'],'progress'=>(int)$run['progress'],'error'=>$run['error']]);
            }
            $content=static function (array $item): array {
                $meta=(array)($item['metadata']??[]);
                foreach (array_merge(self::SERVER_FIELDS,['groupId','agentGroupId','poster_url','poster_uri','poster_status']) as $field) unset($meta[$field]);
                return ['type'=>$item['type']??'','title'=>$item['title']??'','metadata'=>$meta];
            };
            $layout=static function (array $item): array {
                return ['x'=>(float)($item['x']??0),'y'=>(float)($item['y']??0),'width'=>(float)($item['width']??0),'height'=>(float)($item['height']??0),
                    'group'=>(string)($item['metadata']['agentGroupId']??$item['metadata']['groupId']??'')];
            };
            $node['metadata']=$metadata;
            $metadata['content_revision']=(int)($authoritative['content_revision']??0)+(!$previous || self::canonical($content($node))!==self::canonical($content($previous))?1:0);
            $metadata['layout_revision']=(int)($authoritative['layout_revision']??0)+(!$previous || $layout($node)!==$layout($previous)?1:0);
            $node['metadata']=$metadata;
        }
        unset($node);
        return $nodes;
    }

    /** Internal writers only: caller must hold the owned document row lock. */
    public static function persistLockedDocument(array $document, array $changes): array
    {
        if (array_diff(array_keys($changes), ['title','nodes_json','edges_json','viewport_json','removed_node_ids_json','schema_version','update_time'])) throw new RuntimeException('INVALID_GRAPH_WRITE');
        $query = Db::name(self::TABLE)->where(['id'=>(int)$document['id'],'tenant_id'=>(int)$document['tenant_id'],'user_id'=>(int)$document['user_id'],'delete_time'=>0]);
        if (array_key_exists('graph_revision',$document)) {
            $revision = self::revision($document['graph_revision'],'INVALID_GRAPH_REVISION');
            if ($revision===4294967295) throw new RuntimeException('GRAPH_REVISION_EXHAUSTED');
            $query->where('graph_revision',$revision);
            $changes['graph_revision']=$revision+1;
        }
        $written=$query->update($changes);
        if (array_key_exists('graph_revision',$document) && $written!==1) throw new RuntimeException('VERSION_CONFLICT');
        return array_replace($document,$changes);
    }

    public static function validateSaveRevision(array $document, array $params): void
    {
        if (!array_key_exists('expected_revision',$params)) {
            if ((int)($document['schema_version']??1)>=2) throw new RuntimeException('VERSION_CONFLICT: 请刷新页面后保存，当前画布需要版本校验');
            return; // Only legacy, not concurrency-enabled documents allow old clients.
        }
        if (!array_key_exists('graph_revision',$document)) throw new RuntimeException('GRAPH_SCHEMA_UPGRADE_REQUIRED');
        if (self::revision($params['expected_revision'],'EXPECTED_REVISION_REQUIRED')!==(int)$document['graph_revision']) throw new RuntimeException('VERSION_CONFLICT: 云端画布已变化，请重新读取');
    }

    public static function patch(int $tenant, int $user, int $canvas, array $request): array
    {
        $key = (string)($request['request_key'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $key)) throw new RuntimeException('INVALID_REQUEST_KEY');
        // ThinkPHP's request filter serializes numeric JSON values as strings.
        // Normalize only canonical unsigned integers, never floats/bools/exponents.
        $request['expected_revision'] = self::revision($request['expected_revision'] ?? null, 'EXPECTED_REVISION_REQUIRED');
        $operations = $request['operations'] ?? null;
        if (!is_array($operations) || !$operations || count($operations) > 200) throw new RuntimeException('INVALID_OPERATIONS');
        foreach ($operations as &$operation) {
            if (!is_array($operation)) throw new RuntimeException('INVALID_OPERATION');
            if (array_key_exists('expected_content_revision', $operation)) $operation['expected_content_revision'] = self::revision($operation['expected_content_revision'], 'CONTENT_VERSION_REQUIRED');
            if (in_array($operation['op'] ?? '', ['move_nodes','set_group'], true) && is_array($operation['nodes'] ?? null)) {
                foreach ($operation['nodes'] as &$move) {
                    if (is_array($move) && array_key_exists('expected_layout_revision', $move)) $move['expected_layout_revision'] = self::revision($move['expected_layout_revision'], 'LAYOUT_VERSION_REQUIRED');
                }
                unset($move);
            }
        }
        unset($operation);
        $hash = hash('sha256', self::json(self::canonical(['expected_revision'=>$request['expected_revision'],'operations'=>$operations])));
        return Db::transaction(function () use ($tenant,$user,$canvas,$request,$key,$hash,$operations): array {
            $scope = ['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas];
            $document = Db::name(self::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
            if (!$document) throw new RuntimeException('CANVAS_NOT_FOUND');
            // The canvas lock serializes writers, but a repeatable-read snapshot
            // can still predate the winner's receipt. Read the receipt as a
            // current locking read too, before comparing the graph revision.
            $receipt = Db::name(self::RECEIPTS)->where($scope + ['request_key'=>$key])->lock(true)->find();
            if ($receipt) {
                if (!hash_equals($receipt['request_hash'],$hash)) throw new RuntimeException('IDEMPOTENCY_CONFLICT');
                return json_decode($receipt['result_json'],true,512,JSON_THROW_ON_ERROR);
            }
            if ((int)$document['graph_revision'] !== $request['expected_revision']) throw new RuntimeException('VERSION_CONFLICT');
            $nodes = json_decode($document['nodes_json'] ?: '[]',true,512,JSON_THROW_ON_ERROR);
            $edges = json_decode($document['edges_json'] ?: '[]',true,512,JSON_THROW_ON_ERROR);
            $removed = json_decode($document['removed_node_ids_json'] ?: '[]',true,512,JSON_THROW_ON_ERROR);
            foreach ($operations as $operation) {
                if (!is_array($operation)) throw new RuntimeException('INVALID_OPERATION');
                $op = $operation['op'] ?? '';
                if ($op === 'add_node') {
                    $node = $operation['node'] ?? [];
                    if (!is_array($node) || !in_array($node['type'] ?? '',['text','image','video','audio'],true)) throw new RuntimeException('INVALID_NODE_TYPE');
                    if (!array_key_exists('id', $node)) $node['id'] = self::allocateNodeId($nodes, $removed);
                    $id = self::nodeId($node['id']);
                    if (self::index($nodes,$id) !== null || in_array($id,$removed,true)) throw new RuntimeException('NODE_ID_CONFLICT');
                    self::editable($node['metadata'] ?? []);
                    if (array_diff(array_keys($node),['id','type','title','x','y','width','height','metadata'])) throw new RuntimeException('INVALID_NODE_FIELD');
                    self::geometry($node);
                    $node['metadata'] = ($node['metadata'] ?? []) + ['content_revision'=>1,'layout_revision'=>1];
                    $nodes[] = $node;
                } elseif ($op === 'apply_agent_text') {
                    $i=self::index($nodes,self::nodeId($operation['node_id']??null));
                    if ($i===null) throw new RuntimeException('NODE_NOT_FOUND');
                    $nodes[$i]=ConversationTextWriteback::apply($scope,$operation,$nodes[$i]);
                } elseif ($op === 'update_node_content') {
                    $id = self::nodeId($operation['node_id'] ?? null);
                    $i = self::index($nodes,$id);
                    if ($i === null) throw new RuntimeException('NODE_NOT_FOUND');
                    $metadata = (array)($nodes[$i]['metadata'] ?? []);
                    if (self::revision($operation['expected_content_revision'] ?? null, 'CONTENT_VERSION_REQUIRED') !== (int)($metadata['content_revision'] ?? 0)) throw new RuntimeException('CONTENT_VERSION_CONFLICT');
                    $patch = $operation['patch'] ?? [];
                    if (!is_array($patch) || array_diff(array_keys($patch),['title','content','prompt'])) throw new RuntimeException('INVALID_CONTENT_FIELD');
                    foreach ($patch as $field=>$value) {
                        if (!is_string($value) || strlen($value)>200000) throw new RuntimeException('INVALID_CONTENT');
                        if ($field==='title') $nodes[$i]['title']=$value; else $metadata[$field]=$value;
                    }
                    $metadata['content_revision']=(int)($metadata['content_revision']??0)+1;
                    $nodes[$i]['metadata']=$metadata;
                } elseif ($op === 'move_nodes') {
                    $moves = $operation['nodes'] ?? null;
                    if (!is_array($moves) || !$moves || count($moves) > 200) throw new RuntimeException('INVALID_LAYOUT');
                    foreach ($moves as $move) {
                        if (!is_array($move) || (!array_key_exists('x', $move) && !array_key_exists('y', $move))) throw new RuntimeException('INVALID_LAYOUT');
                        $i = self::index($nodes,self::nodeId($move['id']??null));
                        if ($i===null) throw new RuntimeException('NODE_NOT_FOUND');
                        if (self::revision($move['expected_layout_revision']??null, 'LAYOUT_VERSION_REQUIRED')!==(int)($nodes[$i]['metadata']['layout_revision']??0)) throw new RuntimeException('LAYOUT_VERSION_CONFLICT');
                        if (array_diff(array_keys($move),['id','x','y','expected_layout_revision'])) throw new RuntimeException('INVALID_LAYOUT_FIELD');
                        self::geometry($move);
                        foreach (['x','y'] as $field) if (isset($move[$field])) $nodes[$i][$field]=$move[$field];
                        $nodes[$i]['metadata']['layout_revision']=(int)($nodes[$i]['metadata']['layout_revision']??0)+1;
                    }
                } elseif ($op === 'set_group') {
                    $group = $operation['group_id'] ?? null;
                    $members = $operation['nodes'] ?? null;
                    if (!is_string($group) || strlen($group)>100 || !preg_match('/^[a-zA-Z0-9_.:-]*$/D',$group) || !is_array($members) || !$members || count($members)>200) throw new RuntimeException('INVALID_GROUP');
                    foreach ($members as $member) {
                        if (!is_array($member) || array_diff(array_keys($member),['id','expected_layout_revision'])) throw new RuntimeException('INVALID_GROUP');
                        $i = self::index($nodes,self::nodeId($member['id']??null));
                        if ($i===null) throw new RuntimeException('NODE_NOT_FOUND');
                        if (self::revision($member['expected_layout_revision']??null,'LAYOUT_VERSION_REQUIRED')!==(int)($nodes[$i]['metadata']['layout_revision']??0)) throw new RuntimeException('LAYOUT_VERSION_CONFLICT');
                        // Existing four-node UI persists agentGroupId, not groupId.
                        $nodes[$i]['metadata']['agentGroupId']=$group;
                        $nodes[$i]['metadata']['layout_revision']=(int)($nodes[$i]['metadata']['layout_revision']??0)+1;
                    }
                } elseif ($op==='remove_nodes') {
                    $ids=array_map([self::class,'nodeId'],(array)($operation['node_ids']??[]));
                    $nodes=array_values(array_filter($nodes,static fn($n)=>!in_array((string)$n['id'],$ids,true)));
                    $edges=array_values(array_filter($edges,static fn($e)=>!in_array((string)$e['from'],$ids,true)&&!in_array((string)$e['to'],$ids,true)));
                    $removed=array_values(array_unique(array_merge($removed,$ids)));
                } elseif ($op==='add_edge') {
                    $edge=$operation['edge']??[];
                    if (!is_array($edge)||array_diff(array_keys($edge),['from','to','kind','role','order','id','source_asset_version'])) throw new RuntimeException('INVALID_EDGE');
                    $from=self::nodeId($edge['from']??null); $to=self::nodeId($edge['to']??null);
                    if ($from===$to||self::index($nodes,$from)===null||self::index($nodes,$to)===null) throw new RuntimeException('INVALID_EDGE');
                    if (!in_array($edge['kind']??'reference',['reference','annotation'],true)) throw new RuntimeException('INVALID_EDGE_KIND');
                    if (($edge['kind']??'reference')==='reference' && !self::referenceConnectionAllowed($nodes,self::index($nodes,$from),self::index($nodes,$to))) throw new RuntimeException('EDGE_CAPABILITY_UNSUPPORTED');
                    foreach ($edges as $existing) {
                        if ((isset($edge['id']) && isset($existing['id']) && (string)$edge['id']===(string)$existing['id']) || self::edgeIdentity($edge)===self::edgeIdentity($existing)) throw new RuntimeException('EDGE_ALREADY_EXISTS');
                    }
                    $edges[]=$edge;
                } elseif ($op==='remove_edge') {
                    $selector=$operation['edge']??null;
                    if (!is_array($selector) || !$selector || array_diff(array_keys($selector),['id','from','to','kind','role','order'])) throw new RuntimeException('INVALID_EDGE');
                    if (!isset($selector['id']) && (!isset($selector['from']) || !isset($selector['to']))) throw new RuntimeException('INVALID_EDGE');
                    $matches=[];
                    foreach ($edges as $i=>$edge) {
                        $match=true;
                        foreach ($selector as $field=>$value) {
                            if (!is_scalar($value) || !array_key_exists($field,$edge) || (string)$edge[$field] !== (string)$value) { $match=false; break; }
                        }
                        if ($match) $matches[]=$i;
                    }
                    if (!$matches) throw new RuntimeException('EDGE_NOT_FOUND');
                    if (count($matches)>1) throw new RuntimeException('AMBIGUOUS_EDGE');
                    unset($edges[$matches[0]]);
                    $edges=array_values($edges);
                } else throw new RuntimeException('UNSUPPORTED_OPERATION');
                if (count($nodes)>200) throw new RuntimeException('CANVAS_CAPACITY_EXCEEDED');
            }
            $revision=(int)$document['graph_revision']+1;
            self::persistLockedDocument($document,[
                'nodes_json'=>self::json($nodes),'edges_json'=>self::json($edges),'removed_node_ids_json'=>self::json($removed),
                'schema_version'=>2,'update_time'=>time(),
            ]);
            $result=['id'=>$canvas,'graph_revision'=>$revision,'schema_version'=>2,'nodes'=>$nodes,'edges'=>$edges,'removed_node_ids'=>$removed];
            Db::name(self::RECEIPTS)->insert($scope+['request_key'=>$key,'request_hash'=>$hash,'base_revision'=>$request['expected_revision'],'result_revision'=>$revision,'result_json'=>self::json($result),'create_time'=>time()]);
            return $result;
        });
    }
    private static function editable($metadata): void {
        if (!is_array($metadata)) throw new RuntimeException('INVALID_METADATA');
        if (array_intersect(array_keys($metadata),self::SERVER_FIELDS)) throw new RuntimeException('SERVER_FIELD_FORBIDDEN');
        // Explicit allowlist, not a blacklist that new authority fields could bypass.
        if (array_diff(array_keys($metadata),['content','prompt','groupId','agentGroupId','model_code','channel','ratio','resolution','duration','count','quality'])) throw new RuntimeException('INVALID_METADATA_FIELD');
    }
    /** Only the plan-confirmed, server-created base request may auto-submit.
     * User edits to the prompt or ratio must not silently reuse its quote. */
    public static function autoPayloadHash(array $metadata): string
    {
        $fields=[];
        foreach (['prompt','model_code','channel','model_id','ratio','resolution','quality','count'] as $field) {
            $fields[$field]=(string)($metadata[$field]??($field==='count'?1:''));
        }
        return hash('sha256',json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    private static function edgeIdentity(array $edge): array {
        return [(string)($edge['from']??''),(string)($edge['to']??''),(string)($edge['kind']??'reference'),(string)($edge['role']??''),(string)($edge['order']??0)];
    }
    /** Validate the model proposal again at the graph authority boundary. */
    private static function validateAgentProposals(array $proposals,string $workflowStage='',bool $compact=false): void {
        $allowedTypes=match ($workflowStage) {
            'script','art','video_plan'=>['text'], 'assets','storyboard'=>['image'], 'video_nodes'=>['video'], 'audio_plan'=>['audio'], default=>['text','image','video'],
        };
        $allowedArtifacts=match ($workflowStage) {
            'script'=>$compact?['story_setting','episode_script']:['story_setting','episode_outline','storyboard_script'],
            'art'=>['art_bible','character_asset_spec','scene_asset_spec','prop_asset_spec','subject_image_prompt','three_view_prompt','scene_image_prompt','storyboard_image_prompt'],
            'assets'=>['subject','three_view'], 'storyboard'=>$compact?['scene','storyboard']:['scene','prop','storyboard'], 'video_plan'=>['video_prompt_plan'], 'video_nodes'=>['storyboard_video'], 'audio_plan'=>['audio_plan'], default=>[],
        };
        $keys=[];
        $keyArtifacts=[];
        foreach ($proposals as $proposal) {
            $fields=$allowedArtifacts ? ['type','artifact','title','prompt','key','depends_on','reference_keys'] : ['type','title','prompt','key','depends_on'];
            if (!is_array($proposal) || array_diff(array_keys($proposal),$fields)) throw new RuntimeException('INVALID_AGENT_ACTION');
            $type=(string)($proposal['type']??''); $title=trim((string)($proposal['title']??'')); $prompt=trim((string)($proposal['prompt']??''));
            if (!in_array($type,$allowedTypes,true) || $title==='' || mb_strlen($title)>80 || $prompt==='' || mb_strlen($prompt)>20000) throw new RuntimeException('INVALID_AGENT_ACTION');
            if ($allowedArtifacts && !in_array((string)($proposal['artifact']??''),$allowedArtifacts,true)) throw new RuntimeException('INVALID_AGENT_ACTION');
            if (array_key_exists('key',$proposal) && !is_string($proposal['key'])) throw new RuntimeException('INVALID_AGENT_ACTION');
            $key=trim((string)($proposal['key']??''));
            if (array_key_exists('key',$proposal) && (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D',$key) || isset($keys[$key]))) throw new RuntimeException('INVALID_AGENT_ACTION');
            if (array_key_exists('depends_on',$proposal)) {
                if ($key==='' || !is_array($proposal['depends_on']) || !array_is_list($proposal['depends_on']) || count($proposal['depends_on'])>3) throw new RuntimeException('INVALID_AGENT_ACTION');
                $dependencies=[];
                foreach ($proposal['depends_on'] as $dependency) {
                    if (!is_string($dependency) || !isset($keys[$dependency]) || isset($dependencies[$dependency])) throw new RuntimeException('INVALID_AGENT_ACTION');
                    $dependencies[$dependency]=true;
                }
            }
            if (array_key_exists('reference_keys',$proposal)) {
                if (!is_array($proposal['reference_keys']) || !array_is_list($proposal['reference_keys']) || !$proposal['reference_keys'] || count($proposal['reference_keys'])>6) throw new RuntimeException('INVALID_AGENT_ACTION');
                $references=[];
                foreach ($proposal['reference_keys'] as $reference) {
                    if (!is_string($reference) || !preg_match('/^[a-z][a-z0-9_-]{0,47}:[a-z][a-z0-9_-]{0,31}$/D',$reference) || isset($references[$reference])) throw new RuntimeException('INVALID_AGENT_ACTION');
                    $references[$reference]=true;
                }
            }
            if ($key!=='') {
                $keys[$key]=true;
                $keyArtifacts[$key]=(string)($proposal['artifact']??'');
            }
            $artifact=(string)($proposal['artifact']??'');
            if ($compact && in_array($workflowStage,['script','video_nodes'],true) && !empty($proposal['depends_on'])) throw new RuntimeException('INVALID_AGENT_ACTION');
            if ($compact && $workflowStage==='script' && !empty($proposal['reference_keys'])) throw new RuntimeException('INVALID_AGENT_ACTION');
            if ($artifact==='three_view') {
                $dependencies=(array)($proposal['depends_on']??[]);
                if (count($dependencies)!==1 || ($keyArtifacts[$dependencies[0]]??'')!=='subject') throw new RuntimeException('INVALID_AGENT_ACTION');
            }
            if ($artifact==='storyboard') {
                $dependencies=(array)($proposal['depends_on']??[]);
                if (!$dependencies || !array_intersect(array_map(static fn(string $key): string => (string)($keyArtifacts[$key]??''),$dependencies),$compact?['scene']:['scene','prop'])) throw new RuntimeException('INVALID_AGENT_ACTION');
            }
            if ($artifact==='episode_outline') {
                $dependencies=(array)($proposal['depends_on']??[]);
                if (count($dependencies)!==1 || ($keyArtifacts[$dependencies[0]]??'')!=='story_setting') throw new RuntimeException('INVALID_AGENT_ACTION');
            }
            if ($artifact==='storyboard_script') {
                $dependencies=(array)($proposal['depends_on']??[]);
                if (count($dependencies)!==1 || ($keyArtifacts[$dependencies[0]]??'')!=='episode_outline') throw new RuntimeException('INVALID_AGENT_ACTION');
            }
        }
        if ($compact && $workflowStage==='script') {
            $artifacts=array_count_values(array_map(static fn(array $proposal): string=>(string)($proposal['artifact']??''),$proposals));
            if (count($proposals)!==2 || ($artifacts['story_setting']??0)!==1 || ($artifacts['episode_script']??0)!==1) throw new RuntimeException('INVALID_AGENT_ACTION');
        }
    }

    /** New workflow versions resolve only explicitly named generation inputs.
     * The frozen artifact ledger binds a reference key to one node ID, so a
     * second workflow on the same canvas cannot steal the first one's key. */
    private static function compactWorkflowReferenceSources(array $nodes,array $edges,string $stage,array $proposal,array $workflow,array $selected): array
    {
        $allowed=match ($stage) {
            'assets'=>[],
            'storyboard'=>['assets'=>['subject','three_view']],
            'video_nodes'=>['assets'=>['subject','three_view'],'storyboard'=>['scene','storyboard']],
            default=>[],
        };
        $byId=[];
        foreach ($nodes as $node) if (is_array($node)) $byId[(string)($node['id']??'')]=$node;
        $memory=[];
        foreach ((array)($workflow['artifact_memory']??[]) as $item) if (is_array($item)) {
            $key=(string)($item['reference_key']??'');
            if ($key!=='') $memory[$key]=$item;
        }
        $ids=[];$storyboardCount=0;
        foreach ((array)($proposal['reference_keys']??[]) as $key) {
            $key=(string)$key;
            if (preg_match('/^selected:node_([1-9][0-9]{0,15})$/D',$key,$match)) {
                $id=$match[1];
                if (!in_array($id,$selected,true) || !isset($byId[$id])) throw new RuntimeException('WORKFLOW_REFERENCE_UNAVAILABLE');
                $ids[$id]=true;
                continue;
            }
            $item=$memory[$key]??null;
            if (!is_array($item)) throw new RuntimeException('WORKFLOW_REFERENCE_UNAVAILABLE');
            $sourceStage=(string)($item['stage']??'');$artifact=(string)($item['artifact']??'');
            if (!in_array($artifact,(array)($allowed[$sourceStage]??[]),true)) throw new RuntimeException('WORKFLOW_REFERENCE_UNAVAILABLE');
            $id=(string)($item['node_id']??'');$node=$byId[$id]??null;
            $metadata=(array)($node['metadata']??[]);
            if (!$node || (string)($node['type']??'')!=='image' || (string)($metadata['workflow_key']??'')!==$key || (string)($metadata['workflow_artifact']??'')!==$artifact) throw new RuntimeException('WORKFLOW_REFERENCE_UNAVAILABLE');
            $ids[$id]=true;
            if ($sourceStage==='storyboard' && $artifact==='storyboard') $storyboardCount++;
        }
        if ($stage==='video_nodes' && $storyboardCount!==1) throw new RuntimeException('WORKFLOW_STORYBOARD_REFERENCE_REQUIRED');
        return $stage==='video_nodes' ? self::videoReferenceSources($nodes,$edges,array_keys($ids)) : array_keys($ids);
    }

    /** The original short-drama video request uses its current frame, then
     * the bound subject turnaround (main image fallback), then its scene.
     * Derive that binding from the storyboard graph, never from a model's
     * optional list of extra references or similarly named historic nodes. */
    private static function videoReferenceSources(array $nodes,array $edges,array $selectedIds): array
    {
        $byId=[];
        foreach ($nodes as $node) if (is_array($node) && isset($node['id'])) $byId[(string)$node['id']]=$node;
        $storyboards=[];$extras=[];
        foreach ($selectedIds as $id) {
            $node=$byId[$id]??[];
            $artifact=(string)(($node['metadata']['workflow_artifact']??''));
            if ($artifact==='storyboard' && (string)($node['type']??'')==='image') $storyboards[$id]=true;
            else $extras[$id]=true;
        }
        $storyboardIds=array_map('strval',array_keys($storyboards));
        $ordered=$storyboardIds;
        foreach ($storyboardIds as $boardId) {
            $bound=[];
            foreach ($edges as $edge) {
                if ((string)($edge['to']??'')!==$boardId || (string)($edge['kind']??'reference')!=='reference') continue;
                $sourceId=(string)($edge['from']??'');$source=$byId[$sourceId]??[];
                if ((string)($source['type']??'')!=='image') continue;
                $artifact=(string)($source['metadata']['workflow_artifact']??'');
                if (in_array($artifact,['subject','three_view','scene'],true)) $bound[$sourceId]=$artifact;
            }
            // Prefer one turnaround per character, as the formal short-drama
            // request does. The asset-stage edge identifies its own main.
            foreach ($bound as $sourceId=>$artifact) {
                $sourceId=(string)$sourceId;
                if ($artifact!=='subject') continue;
                foreach ($edges as $edge) {
                    if ((string)($edge['from']??'')!==$sourceId || (string)($edge['kind']??'reference')!=='reference') continue;
                    $viewId=(string)($edge['to']??'');$view=$byId[$viewId]??[];
                    if ((string)($view['type']??'')!=='image' || (string)($view['metadata']['workflow_artifact']??'')!=='three_view') continue;
                    if ((string)($view['metadata']['workflow_source_stage']??'')!=='assets') continue;
                    unset($bound[$sourceId],$extras[$sourceId]);
                    $bound[$viewId]='three_view';
                    break;
                }
            }
            foreach (['three_view','subject','scene'] as $type) foreach ($bound as $sourceId=>$artifact) {
                $sourceId=(string)$sourceId;
                if ($artifact===$type && !in_array($sourceId,$ordered,true)) $ordered[]=$sourceId;
            }
        }
        foreach (array_keys($extras) as $id) {
            $id=(string)$id;
            if (!in_array($id,$ordered,true)) $ordered[]=$id;
        }
        return $ordered;
    }
    /** @return list<string> workflow-owned node IDs allowed as durable references for this exact proposed artifact. */
    private static function workflowReferenceSources(array $nodes,string $stage,array $proposal): array {
        $sourceStages=match ($stage) {
            'assets'=>['script','art'],
            'storyboard'=>['script','art','assets'],
            'video_plan'=>['script','art','assets','storyboard'],
            'video_nodes'=>['script','art','assets','storyboard','video_plan'],
            'audio_plan'=>['script','storyboard','video_plan'],
            default=>[],
        };
        if (!$sourceStages) return [];
        $requested=array_values((array)($proposal['reference_keys']??[]));
        $requestedLookup=array_fill_keys($requested,true);
        $hasRequested=$requested!==[];
        $fallbackArtifacts=match ($stage) {
            'assets'=>match ((string)($proposal['artifact']??'')) {
                'subject'=>['character_asset_spec','subject_image_prompt'],
                'three_view'=>['three_view_prompt'],
                default=>[],
            },
            'storyboard'=>match ((string)($proposal['artifact']??'')) {
                'scene'=>['scene_asset_spec','scene_image_prompt'],
                'prop'=>['prop_asset_spec'],
                'storyboard'=>['storyboard_image_prompt'],
                default=>[],
            },
            'video_plan'=>['storyboard_script'],
            'video_nodes'=>['video_prompt_plan'],
            'audio_plan'=>['video_prompt_plan'],
            default=>[],
        };
        $ids=[];
        foreach ($nodes as $node) {
            $metadata=(array)($node['metadata']??[]);
            if (!in_array((string)($node['type']??''),['text','image'],true) || !in_array((string)($metadata['workflow_source_stage']??''),$sourceStages,true)) continue;
            $workflowKey=(string)($metadata['workflow_key']??'');
            $artifact=(string)($metadata['workflow_artifact']??'');
            if ($hasRequested) {
                if (!isset($requestedLookup[$workflowKey])) continue;
                unset($requestedLookup[$workflowKey]);
            } elseif (!in_array($artifact,$fallbackArtifacts,true)) continue;
            $id=(string)($node['id']??'');
            if (preg_match('/^[1-9][0-9]{0,15}$/D',$id)) $ids[]=$id;
        }
        if ($requestedLookup) throw new RuntimeException('WORKFLOW_REFERENCE_UNAVAILABLE');
        return array_values(array_unique($ids));
    }
    /**
     * The canvas graph stores only four node kinds. This is a deliberately
     * model-independent association boundary: an alternative model may make a
     * valid reference generatable later, so selected-model capacity and modes
     * remain the responsibility of the submit-time Market capability check.
     */
    private static function referenceConnectionAllowed(array $nodes, int $fromIndex, int $toIndex): bool {
        $source=(string)($nodes[$fromIndex]['type']??'');
        $target=(string)($nodes[$toIndex]['type']??'');
        return in_array($target,[
            'text'=>['text','image','video','audio'],
            'image'=>['text','image','video'],
            'video'=>['text','video'],
            'audio'=>['text','video','audio'],
        ][$source]??[],true);
    }
    private static function geometry(array $node): void {
        foreach (['x','y','width','height'] as $key) if (array_key_exists($key, $node) && (!is_numeric($node[$key]) || !is_finite((float)$node[$key]) || abs((float)$node[$key])>10000000 || (in_array($key,['width','height'],true) && (float)$node[$key]<=0))) throw new RuntimeException('INVALID_GEOMETRY');
    }
    private static function revision($value, string $error): int {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$value) || (float)$value > 4294967295) throw new RuntimeException($error);
        return (int)$value;
    }
    private static function allocateNodeId(array $nodes, array $removed): int {
        $maximum = 0;
        foreach (array_merge(array_column($nodes, 'id'), $removed) as $id) {
            // Preserve legacy IDs. Only supported numeric IDs participate in allocation.
            if (preg_match('/^[1-9][0-9]{0,15}$/D', (string)$id)) $maximum = max($maximum, (int)$id);
        }
        if ($maximum >= 9007199254740991) throw new RuntimeException('NODE_ID_EXHAUSTED');
        return $maximum + 1;
    }
    private static function nodeId($id): string {
        if ((!is_string($id)&&!is_int($id)) || !preg_match('/^[1-9][0-9]{0,15}$/D',(string)$id) || (float)$id>9007199254740991) throw new RuntimeException('INVALID_NODE_ID');
        return (string)$id;
    }
    private static function index(array $nodes,string $id): ?int { foreach($nodes as $i=>$node) if((string)$node['id']===$id)return $i;return null; }
    private static function canonical(array $value): array { if(array_keys($value)!==range(0,count($value)-1))ksort($value);foreach($value as &$item)if(is_array($item))$item=self::canonical($item);return $value; }
    private static function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
}
