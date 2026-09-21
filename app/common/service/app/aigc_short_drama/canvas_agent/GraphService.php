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
    private const SERVER_FIELDS = ['status','progress','error','canvasRunId','active_generation_id','asset_id','asset_version','asset_owner','tenant_id','user_id','owner_app','business_binding','cost','cost_points','billing_status','content_revision','layout_revision'];

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
    private static function edgeIdentity(array $edge): array {
        return [(string)($edge['from']??''),(string)($edge['to']??''),(string)($edge['kind']??'reference'),(string)($edge['role']??''),(string)($edge['order']??0)];
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
