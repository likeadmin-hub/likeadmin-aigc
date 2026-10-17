<?php
namespace app\common\service\app\aigc_canvas;

use app\common\model\app\aigc_canvas\AigcCanvasProject;
use app\common\model\TenantConfig;
use app\common\model\Config;
use app\common\enum\AdminTerminalEnum;
use app\common\model\tenant\Tenant;
use app\common\service\CanvasPortalService;
use InvalidArgumentException;
use think\facade\Db;

/** Published, tenant-owned graph snapshots. Never copies assets or starts generation. */
final class CanvasTemplateService
{
    private const TYPE = 'aigc_canvas_template';
    private static function query(int $tenant)
    {
        return $tenant === 0 ? Config::where('type',self::TYPE) : TenantConfig::where(['tenant_id'=>$tenant,'type'=>self::TYPE]);
    }
    private static function read(int $tenant, string $name): array
    {
        return json_decode((string)self::query($tenant)->where('name',$name)->value('value'), true) ?: [];
    }
    private static function write(int $tenant, string $name, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 60000) throw new InvalidArgumentException('模板内容过大，请减少节点或提示词后重试（最多60KB）');
        $where = ['type'=>self::TYPE,'name'=>$name];
        if ($tenant !== 0) $where['tenant_id']=$tenant;
        $row = self::query($tenant)->where('name',$name)->findOrEmpty();
        if ($row->isEmpty()) ($tenant === 0 ? new Config() : new TenantConfig())->save($where + ['value'=>$json]);
        else $row->save(['value'=>$json]);
    }
    private static function key(string $id): string { return 'graph_'.substr(hash('sha256', $id),0,48); }
    public static function source(int $tenant, int $id): array
    {
        $row = AigcCanvasProject::where(['tenant_id'=>$tenant,'id'=>$id,'delete_time'=>0])->findOrEmpty();
        if ($row->isEmpty()) throw new InvalidArgumentException('来源项目不存在或不属于当前租户');
        return CanvasProjectCompatibility::adapt($row->toArray());
    }
    public static function sources(int $tenant, array $params): array
    {
        if (!empty($params['source_project_id'])) {
            $source = self::source($tenant, (int)$params['source_project_id']);
            return ['pages'=>array_map(fn($page)=>['id'=>$page['id'],'name'=>$page['name'],'node_count'=>count($page['nodeIds'])], $source['viewport_json']['pages'])];
        }
        return AigcCanvasService::projectLists($tenant,0,['page_no'=>$params['page_no'] ?? 1,'page_size'=>20,'keyword'=>$params['keyword'] ?? '','summary'=>1]);
    }
    public static function decorate(array $cards, bool $public, ?int $owner = null): array
    {
        $tenant = $owner ?? (int)request()->tenantId;
        $keys=array_map(fn($card)=>self::key($card['id']),$cards);
        $snapshots=$keys ? self::query($tenant)->whereIn('name',$keys)->column('value','name') : [];
        foreach ($cards as &$card) {
            $snapshot = json_decode($snapshots[self::key($card['id'])] ?? '[]',true) ?: [];
            $card['template_scope'] = $tenant === 0 ? 'platform' : 'tenant';
            $card['workflow_ready'] = $card['section'] === 'template' && !empty($snapshot['nodes']);
            $card['workflow_version'] = (int)($snapshot['version'] ?? 0);
            $card['node_count'] = count($snapshot['nodes'] ?? []);
            if (!$public) {
                if ($tenant === 0) $card['source_tenant_id']=$snapshot['source_tenant_id'] ?? 0;
                $card['source_project_id'] = $snapshot['source_project_id'] ?? 0;
                $card['source_page_id'] = $snapshot['source_page_id'] ?? '';
            }
        }
        return $cards;
    }
    /** Called inside the portal's tenant lock and revision transaction. */
    public static function publish(array $cards, bool $platform = false): void
    {
        if ($platform && !AdminTerminalEnum::isPlatform()) throw new InvalidArgumentException('无权发布公共模板');
        $tenant = $platform ? 0 : (int)request()->tenantId;
        if (!$platform && $tenant <= 0) throw new InvalidArgumentException('缺少租户信息');
        foreach ($cards as $card) {
            if (($card['section'] ?? '') !== 'template' || empty($card['refresh_workflow'])) continue;
            $sourceTenant = $platform ? (int)($card['source_tenant_id'] ?? 0) : $tenant;
            if ($sourceTenant <= 0) throw new InvalidArgumentException('请选择来源租户');
            $source = self::source($sourceTenant,(int)($card['source_project_id'] ?? 0));
            $page = null;
            foreach ($source['viewport_json']['pages'] as $candidate) if ($candidate['id'] === ($card['source_page_id'] ?? '')) $page = $candidate;
            if (!$page) throw new InvalidArgumentException('请选择来源画布页面');
            $ids = array_fill_keys($page['nodeIds'],true);
            $nodes = array_values(array_filter($source['nodes_json'],fn($n)=>isset($ids[$n['id']])));
            $edges = array_values(array_filter($source['edges_json'],fn($e)=>isset($ids[$e['fromNodeId']],$ids[$e['toNodeId']])));
            $graph = self::sanitize($nodes,$edges);
            $old = self::read($tenant,self::key($card['id']));
            self::write($tenant,self::key($card['id']),$graph+['version'=>(int)($old['version'] ?? 0)+1,'source_tenant_id'=>$sourceTenant,'source_project_id'=>(int)$source['id'],'source_page_id'=>$page['id']]);
        }
    }
    public static function sanitize(array $nodes, array $edges): array
    {
        if (!$nodes || count($nodes)>200 || count($edges)>500) throw new InvalidArgumentException('模板需包含1至200个节点，连线不超过500条');
        $result=[]; $ids=[]; $allIds=array_fill_keys(array_column($nodes,'id'),true);
        $fields=['prompt','content','model','model_code','channel','model_id','market_model_id','market_sku_id','sku_id','size','ratio','quality','count','quantity','duration','resolution','custom','instrumental','lyrics','style','vocal_gender','mode','videoMode','negative_prompt','title','seed','temperature','max_tokens','agentGroupId','canvasGroupTitle','canvasGroupColor'];
        foreach ($nodes as $node) {
            $id=(string)($node['id'] ?? ''); $type=$node['type'] ?? '';
            if (!$id || isset($ids[$id]) || !in_array($type,['text','image','video','audio','config'],true)) throw new InvalidArgumentException('模板节点格式不正确');
            $ids[$id]=true; $metadata=['status'=>'idle','pending'=>false];
            foreach ($fields as $field) {
                $v=$node['metadata'][$field] ?? null;
                if (is_scalar($v) && ($field!=='content' || $type==='text')) $metadata[$field]=$v;
            }
            $metadata['mentions']=[];
            foreach ($node['metadata']['mentions'] ?? [] as $mention) {
                if (!is_array($mention) || ($mention['source'] ?? '')!=='node' || !isset($allIds[$mention['node_id'] ?? ''])) continue;
                $metadata['mentions'][]=['id'=>'node-'.$mention['node_id'],'node_id'=>$mention['node_id'],'source'=>'node','type'=>(string)($mention['type'] ?? 'text'),'name'=>(string)($mention['name'] ?? '')];
            }
            // Only scalar advanced controls are copied; file references and credential fields are excluded.
            foreach ($node['metadata']['generationParameters'] ?? [] as $model=>$parameters) {
                if (!is_array($parameters)) continue;
                foreach ($parameters as $key=>$value) {
                    if (!preg_match('/url|uri|file|asset|image|video|audio|reference|token|secret|password|api.?key/i',(string)$key) && is_scalar($value)) $metadata['generationParameters'][$model][$key]=$value;
                }
            }
            $result[]=['id'=>$id,'type'=>$type,'title'=>mb_substr((string)($node['title'] ?? ''),0,120),'position'=>['x'=>(float)($node['position']['x'] ?? 0),'y'=>(float)($node['position']['y'] ?? 0)],'width'=>max(100,min(2000,(float)($node['width'] ?? 340))),'height'=>max(80,min(2000,(float)($node['height'] ?? 217))),'metadata'=>$metadata];
        }
        $connections=[];
        foreach ($edges as $edge) {
            $from=(string)($edge['fromNodeId'] ?? ''); $to=(string)($edge['toNodeId'] ?? '');
            if (!isset($ids[$from],$ids[$to]) || $from===$to) throw new InvalidArgumentException('模板包含无效连线');
            $connections[]=['id'=>(string)($edge['id'] ?? ''),'fromNodeId'=>$from,'toNodeId'=>$to];
        }
        return ['nodes'=>$result,'edges'=>$connections];
    }
    public static function instantiate(array $graph): array
    {
        $ids=[]; $groups=[];
        foreach ($graph['nodes'] as $node) $ids[$node['id']]='node_'.bin2hex(random_bytes(12));
        foreach ($graph['nodes'] as &$node) {
            $node['id']=$ids[$node['id']];
            foreach ($node['metadata']['mentions'] ?? [] as $i=>$mention) {
                $node['metadata']['mentions'][$i]['node_id']=$ids[$mention['node_id']];
                $node['metadata']['mentions'][$i]['id']='node-'.$ids[$mention['node_id']];
            }
            if (!empty($node['metadata']['agentGroupId'])) {
                $group=$node['metadata']['agentGroupId'];
                $groups[$group] ??= 'group_'.bin2hex(random_bytes(12));
                $node['metadata']['agentGroupId']=$groups[$group];
            }
        }
        unset($node);
        foreach ($graph['edges'] as &$edge) {
            $edge=['id'=>'conn_'.bin2hex(random_bytes(12)), 'fromNodeId'=>$ids[$edge['fromNodeId']], 'toNodeId'=>$ids[$edge['toNodeId']]];
        }
        unset($edge);
        return ['nodes'=>$graph['nodes'],'edges'=>$graph['edges']];
    }
    public static function apply(int $tenant, int $user, array $params): array
    {
        if ($tenant !== (int)request()->tenantId) throw new InvalidArgumentException('租户上下文不匹配');
        if (!in_array($params['mode'] ?? 'create',['create','insert'],true)) throw new InvalidArgumentException('模板使用方式无效');
        if ($tenant<=0 || $user<=0) throw new InvalidArgumentException('请先登录');
        $id=(string)($params['template_id'] ?? '');
        $scope=(string)($params['template_scope'] ?? 'tenant');
        if (!in_array($scope,['tenant','platform'],true)) throw new InvalidArgumentException('模板来源无效');
        $request=(string)($params['request_id'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D',$request)) throw new InvalidArgumentException('使用请求标识无效');
        return Db::transaction(function () use ($tenant,$user,$id,$scope,$request,$params) {
            if (Tenant::where('id',$tenant)->lock(true)->findOrEmpty()->isEmpty()) throw new InvalidArgumentException('租户不存在');
            if ($scope === 'platform') CanvasPublicTemplateService::lockCatalog();
            $card=null;
            foreach (CanvasPortalService::public()['cards'] as $row) if ($row['id']===$id && ($row['template_scope'] ?? 'tenant')===$scope && $row['section']==='template' && $row['workflow_ready']) $card=$row;
            if (!$card) throw new InvalidArgumentException('模板未发布或已停用，请刷新列表');
            $snapshot=self::read($scope === 'platform' ? 0 : $tenant,self::key($id));
            if (($params['mode'] ?? 'create')==='insert') {
                $target=self::source($tenant,(int)($params['project_id'] ?? 0));
                if ((int)$target['user_id']!==$user) throw new InvalidArgumentException('无权操作此项目');
                if (!in_array($params['page_id'] ?? '',array_column($target['viewport_json']['pages'],'id'),true)) throw new InvalidArgumentException('目标画布不存在，请先保存');
                return self::instantiate($snapshot);
            }
            $receipt='use_'.substr(hash('sha256',$user.':'.$request),0,48);
            $existing=self::read($tenant,$receipt);
            if ($existing) {
                if ($existing['template_id']!==$id || ($existing['template_scope'] ?? 'tenant')!==$scope) throw new InvalidArgumentException('请求标识已用于其他模板');
                return AigcCanvasService::projectDetail($tenant,$user,(int)$existing['project_id']);
            }
            $graph=self::instantiate($snapshot);
            $project=AigcCanvasService::createProject($tenant,$user,$graph+['name'=>$card['title']]);
            self::write($tenant,$receipt,['template_id'=>$id,'template_scope'=>$scope,'project_id'=>$project['id']]);
            return $project;
        });
    }
}
