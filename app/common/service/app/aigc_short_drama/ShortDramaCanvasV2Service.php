<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\canvas_agent\{ConversationStore,ConversationService,FeatureGate,GraphService};
use RuntimeException;
use think\facade\Db;

/** Compatibility facade: owned documents, CAS saves and generation receipts remain canonical. */
final class ShortDramaCanvasV2Service
{
    public static function capabilities(int $tenant): array
    {
        $models=['text'=>[],'image'=>[],'video'=>[],'music'=>[]];
        foreach (AigcShortDramaService::canvasModelGroups($tenant) as $group) {
            $key=$group['key']??'';$key=$key==='script_plan'?'text':$key;
            if (isset($models[$key])) $models[$key]=array_values((array)($group['options']??[]));
        }
        $rich=[];
        foreach (\app\common\service\app\aigc_image\AigcImageService::config($tenant)['option_config']['channels']??[] as $model) $rich[(string)$model['value']]=$model;
        foreach (\app\common\service\app\aigc_video\AigcVideoService::creationConfig($tenant)['option_config']['channels']??[] as $model) $rich[(string)$model['value']]=$model;
        foreach (['image','video'] as $kind) foreach ($models[$kind] as &$model) $model=array_replace($rich[(string)($model['id']??'')]??[],$model);
        unset($model);
        $music=\app\common\service\app\aigc_music\AigcMusicService::config($tenant)['option_config']??[];
        $models['music']=array_values((array)($music['channels']??[]));
        foreach ($models['music'] as &$model) $model['id']=$model['code']??$model['value']??'';
        unset($model);
        $enabled=FeatureGate::enabled($tenant);$ready=$enabled && FeatureGate::executionEnabled($tenant) && $models['text']!==[];
        return ['resource_models'=>$models,'agent_enabled'=>$enabled,'agent_ready'=>$ready,'maximum_nodes'=>200,
            'message'=>$ready?'当前短剧资源可用':($enabled?'当前暂无可用的创作助手资源':'当前租户未开启创作助手')];
    }

    public static function detail(int $tenant,int $user,int $id): array
    {
        $document=self::document($tenant,$user,$id);
        $threads=FeatureGate::enabled($tenant)?ConversationStore::threads($tenant,$user,$id):[];
        $thread=null;foreach ($threads as $candidate) if ($candidate['title']==='V2 画布对话') {$thread=$candidate;break;}
        return ['workspace'=>['id'=>$id,'title'=>$document['title'],'project_id'=>(int)($document['viewport']['v2_project_id']??0)],
            'graph'=>ShortDramaCanvasV2Graph::project($document),'messages'=>$thread?ConversationStore::messages($tenant,$user,$id,$thread['id']):[],
            'agent_thread'=>$thread,'capabilities'=>self::capabilities($tenant)];
    }

    /** No provider calls: one receipt and one document are committed atomically. */
    public static function create(int $tenant,int $user,array $input,int $project=0): array
    {
        $key=self::requestKey($input['request_key']??null);
        $prompt=$input['prompt']??'';
        if (!is_string($prompt)||trim($prompt)===''||mb_strlen($prompt)>20000) throw new RuntimeException('请输入不超过20000字的创作内容');
        $hash=hash('sha256',json_encode([$prompt,(int)($input['skill_id']??0),(int)($input['skill_version']??0),$project],JSON_THROW_ON_ERROR));
        $skill=(int)($input['skill_id']??0)>0 ? ShortDramaSkillService::resolveForTask($tenant,$input) : [];
        $id=Db::transaction(static function()use($tenant,$user,$key,$prompt,$hash,$project,$skill):int {
            $scope=['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>0,'request_key'=>'v2-entry:'.$key];
            $receipt=Db::name(GraphService::RECEIPTS)->where($scope)->lock(true)->find();
            if ($receipt) {
                if (!hash_equals($receipt['request_hash'],$hash)) throw new RuntimeException('IDEMPOTENCY_CONFLICT');
                return (int)json_decode($receipt['result_json'],true,512,JSON_THROW_ON_ERROR)['canvas_id'];
            }
            $row=ShortDramaCanvasService::create($tenant,$user,['title'=>mb_substr(trim($prompt),0,40)]);
            $graph=['version'=>$row['graph_revision'],'title'=>$row['title'],'nodes'=>[['node_key'=>'idea','node_type'=>'text','business_kind'=>'script','title'=>'创作需求','content'=>['text'=>$prompt],'position'=>['x'=>80,'y'=>120],'width'=>420,'height'=>248,'z_index'=>1]],'edges'=>[],'viewport'=>['x'=>260,'y'=>160,'k'=>1]];
            $save=ShortDramaCanvasV2Graph::saveInput($row,$graph);$save['viewport']['v2_project_id']=$project;
            if ($skill) {$save['viewport']['v2_skill_id']=(int)$skill['id'];$save['viewport']['v2_skill_version']=(int)($skill['version']??0);}
            ShortDramaCanvasService::save($tenant,$user,$save);
            Db::name(GraphService::RECEIPTS)->insert($scope+['request_hash'=>$hash,'base_revision'=>0,'result_revision'=>1,'result_json'=>json_encode(['canvas_id'=>$row['id']],JSON_THROW_ON_ERROR),'create_time'=>time()]);
            return (int)$row['id'];
        });
        return self::detail($tenant,$user,$id);
    }

    public static function projectWorkspace(int $tenant,int $user,int $project): array
    {
        $detail=AigcShortDramaService::projectDetail($tenant,$user,$project);
        return self::create($tenant,$user,['request_key'=>'project-'.$project,'prompt'=>'短剧项目 '.$project],$project);
    }

    public static function saveGraph(int $tenant,int $user,int $id,array $input): array
    {
        $saved=ShortDramaCanvasService::save($tenant,$user,ShortDramaCanvasV2Graph::saveInput(self::document($tenant,$user,$id),$input));
        return ['version'=>(int)$saved['graph_revision'],'graph'=>ShortDramaCanvasV2Graph::project($saved)];
    }

    public static function agent(int $tenant,int $user,int $id,array $input): array
    {
        FeatureGate::assertEnabled($tenant);$document=self::document($tenant,$user,$id);
        $thread=ConversationStore::create($tenant,$user,$id,'v2-conversation','V2 画布对话');
        $run=ConversationService::send($tenant,$user,$id,$thread['id'],[
            'request_key'=>self::requestKey($input['request_key']??null),'content'=>$input['content']??'',
            'selected_node_ids'=>[],'base_revision'=>(int)$document['graph_revision'],
            'skill_id'=>(int)($document['viewport']['v2_skill_id']??0),'skill_version'=>(int)($document['viewport']['v2_skill_version']??0)]);
        return ['run'=>$run,'thread_id'=>$thread['id'],'messages'=>ConversationStore::messages($tenant,$user,$id,$thread['id'])];
    }

    public static function agentStatus(int $tenant,int $user,int $id,int $thread,int $run): array
    {
        $status=ConversationStore::run($tenant,$user,$id,$thread,$run);
        return ['run'=>$status,'thread_id'=>$thread,'messages'=>ConversationStore::messages($tenant,$user,$id,$thread),
            'graph'=>ShortDramaCanvasV2Graph::project(self::document($tenant,$user,$id))];
    }

    public static function quoteMedia(int $tenant,int $user,int $id,array $input): array
    {
        $params=self::mediaInput($tenant,$user,$id,$input);
        $params['request_key']=self::requestKey($input['request_key']??null);
        $allowed=[];foreach (self::capabilities($tenant)['resource_models'][$input['kind']]??[] as $model) $allowed[]=(string)($model['id']??$model['value']??'');
        if (!in_array($params['channel'],$allowed,true)) throw new RuntimeException('当前模型不可用，请重新选择');
        $quoted=ShortDramaCanvasService::quote($tenant,$user,$params,true);
        return ['quote'=>$quoted['quote'],'quote_hash'=>$quoted['quote_token'],'request_key'=>$params['request_key'],'request'=>['model_code'=>$params['channel']]];
    }

    public static function submitMedia(int $tenant,int $user,int $id,array $input): array
    {
        $params=self::mediaInput($tenant,$user,$id,$input);
        $params['request_key']=self::requestKey($input['request_key']??null);$params['quote_token']=$input['quote_hash']??'';
        return ShortDramaCanvasV2Graph::task(ShortDramaCanvasService::submitIdempotent($tenant,$user,$params,true));
    }

    public static function refreshTask(int $tenant,int $user,int $id,int $run): array
    {
        self::document($tenant,$user,$id);
        // Check the workspace as well as tenant/user before a polling call can refresh anything.
        if (!Db::name('aigc_short_drama_canvas_run')->where(['id'=>$run,'canvas_id'=>$id,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->find()) throw new RuntimeException('画布任务不存在或无权访问');
        return ShortDramaCanvasV2Graph::task(ShortDramaCanvasService::runDetail($tenant,$user,$run));
    }

    public static function applyNode(int $tenant,int $user,int $id,array $input): array
    {
        $document=self::document($tenant,$user,$id);$node=self::node($document,$input['node_key']??null);
        $project=(int)($document['viewport']['v2_project_id']??0);$shot=(string)($node['metadata']['shot_id']??'');
        if ($project<=0||$shot==='') throw new RuntimeException('请先从短剧项目进入画布，并关联已有分镜');
        $detail=AigcShortDramaService::projectDetail($tenant,$user,$project);
        // A graph's editable shot_id is only a reference, never permission to write another project.
        $owned=Db::name('aigc_short_drama_storyboard')->where(['project_id'=>$project,'tenant_id'=>$tenant,'user_id'=>$user,'shot_id'=>$shot,'delete_time'=>0])->find();
        if (!$owned) throw new RuntimeException('分镜不存在或不属于当前短剧项目');
        AigcShortDramaService::saveStoryboard($tenant,$user,['project_id'=>$project,'task_id'=>$owned['task_id'],'shot_id'=>$shot,
            ($node['type']==='video'?'video_prompt':'image_prompt')=>(string)($node['metadata']['prompt']??$node['metadata']['content']??'')]);
        return ['shot_id'=>$shot];
    }

    private static function mediaInput(int $tenant,int $user,int $id,array $input): array
    {
        $document=self::document($tenant,$user,$id);$node=self::node($document,$input['node_key']??null);
        $kind=$input['kind']??null;$type=$kind==='music'?'audio':$kind;
        if (!in_array($type,['image','video','audio'],true)||$node['type']!==$type) throw new RuntimeException('NODE_TYPE_MISMATCH');
        $channel=$input['model_code']??'';
        if (!is_string($channel)||$channel==='') throw new RuntimeException('请选择当前可用模型');
        $params=array_intersect_key($input,array_flip(['prompt','ratio','quality','resolution','generation_method','material_mode','advanced_params']));
        return $params+['canvas_id'=>$id,'node_id'=>(string)$node['id'],'type'=>$type,'channel'=>$channel,'duration'=>(int)($input['duration_seconds']??0)];
    }
    private static function node(array $document,$key): array
    {if (!is_string($key)) throw new RuntimeException('NODE_NOT_FOUND');foreach ($document['nodes']??[] as $node) if (ShortDramaCanvasV2Graph::key($node)===$key) return $node;throw new RuntimeException('NODE_NOT_FOUND');}
    private static function document(int $tenant,int $user,int $id): array
    {if ($tenant<=0||$user<=0||$id<=0) throw new RuntimeException('画布不存在或无权访问');return ShortDramaCanvasService::current($tenant,$user,$id);}
    private static function requestKey($key): string
    {if (!is_string($key)||!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D',$key)) throw new RuntimeException('INVALID_REQUEST_KEY');return $key;}
}
