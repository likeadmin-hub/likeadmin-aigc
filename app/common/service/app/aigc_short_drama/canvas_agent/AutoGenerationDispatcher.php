<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use think\facade\Db;

/**
 * Resume server-authorized Agent auto nodes from the existing Agent worker.
 *
 * The canvas document itself is the durable outbox: a node is eligible only
 * when the Agent created it in auto mode, allocated its immutable request key
 * and it is still idle.  There is deliberately no extra process, queue or
 * database table.  GenerationIntentService remains the only boundary that can
 * claim provider submission and point settlement.
 */
final class AutoGenerationDispatcher
{
    /** @return array{scanned:int,submitted:int,waiting:int,failed:int} */
    public static function tick(int $tenant, int $limit=20): array
    {
        $limit=max(1,min(100,$limit));
        $rows=Db::name(GraphService::TABLE)->where([
            'tenant_id'=>$tenant,'delete_time'=>0,
        ])->order('id')->limit($limit)->select()->toArray();
        $result=['scanned'=>count($rows),'submitted'=>0,'waiting'=>0,'failed'=>0];
        foreach ($rows as $document) {
            try {
                foreach (self::blockedNodes($document) as $node) {
                    if (GraphService::blockAgentDependentNode((int)$document['tenant_id'],(int)$document['user_id'],(int)$document['id'],(string)$node['id'],'前序节点生成失败，未提交此依赖节点')) $result['failed']++;
                }
                foreach (self::eligibleNodes($document) as $node) {
                    $state=ShortDramaCanvasService::submitAgentAutoNode(
                        (int)$document['tenant_id'],(int)$document['user_id'],(int)$document['id'],(string)$node['id']
                    );
                    if ($state === 'submitted') $result['submitted']++;
                    elseif ($state === 'waiting') $result['waiting']++;
                    elseif ($state === 'blocked') $result['failed']++;
                }
            } catch (\Throwable) {
                // One malformed or unavailable node must not block other
                // tenant-owned Agent work.  The service records a friendly
                // terminal state only for proven local pre-submit failures.
                $result['failed']++;
            }
        }
        return $result;
    }

    /** @return list<array{id:string}> */
    public static function eligibleNodes(array $document): array
    {
        $nodes=json_decode((string)($document['nodes_json']??'[]'),true);
        $edges=json_decode((string)($document['edges_json']??'[]'),true);
        if (!is_array($nodes) || !is_array($edges)) return [];
        $result=[];
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $metadata=(array)($node['metadata']??[]);
            if (empty($metadata['agent_auto_submit']) || !in_array((string)($node['type']??''),['text','image'],true)) continue;
            if ((string)($metadata['status']??'idle')!=='idle') continue;
            if ((int)($metadata['agent_auto_run_id']??0)<=0 || !preg_match('/^agent\.[1-9][0-9]*\.[1-9][0-9]*$/D',(string)($metadata['agent_auto_request_key']??''))) continue;
            if (!isset($node['id']) || !preg_match('/^[1-9][0-9]*$/D',(string)$node['id'])) continue;
            if ((ShortDramaCanvasService::agentAutoDependencyState($nodes,$edges,(string)$node['id'])['state']??'waiting')!=='ready') continue;
            $result[]=['id'=>(string)$node['id']];
        }
        return $result;
    }

    /** @return list<array{id:string}> */
    public static function blockedNodes(array $document): array
    {
        $nodes=json_decode((string)($document['nodes_json']??'[]'),true);
        $edges=json_decode((string)($document['edges_json']??'[]'),true);
        if (!is_array($nodes) || !is_array($edges)) return [];
        $result=[];
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $metadata=(array)($node['metadata']??[]);
            if (empty($metadata['agent_auto_submit']) || (string)($metadata['status']??'idle')!=='idle' || !isset($node['id'])) continue;
            if ((ShortDramaCanvasService::agentAutoDependencyState($nodes,$edges,(string)$node['id'])['state']??'waiting')==='blocked') $result[]=['id'=>(string)$node['id']];
        }
        return $result;
    }
}
