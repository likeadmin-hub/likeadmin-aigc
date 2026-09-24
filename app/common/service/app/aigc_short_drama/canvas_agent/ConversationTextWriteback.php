<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;

final class ConversationTextWriteback
{
    /** Called only under GraphService's owned canvas lock and transaction. */
    public static function apply(array $scope,array $operation,array $node): array
    {
        FeatureGate::assertEnabled((int)$scope['tenant_id']);
        if (array_diff(array_keys($operation),['op','node_id','run_id'])) throw new RuntimeException('INVALID_OPERATION');
        $id=(string)($operation['run_id']??'');
        if (!preg_match('/^[1-9][0-9]{0,15}$/D',$id)) throw new RuntimeException('RUN_NOT_FOUND');
        $run=Db::name(ConversationStore::PREFIX.'run')->where($scope+['id'=>$id,'delete_time'=>0,'status'=>'success'])->lock(true)->find();
        if (!$run || !Db::name(ConversationStore::PREFIX.'thread')->where($scope+['id'=>$run['thread_id'],'delete_time'=>0])->count()) throw new RuntimeException('RUN_NOT_FOUND');
        $selected=json_decode($run['context_snapshot'],true,512,JSON_THROW_ON_ERROR)['selected_nodes']??[];
        $source=null;
        foreach ($selected as $candidate) if ((string)$candidate['id']===(string)$node['id'] && $candidate['type']==='text') $source=$candidate;
        if (!$source || $node['type']!=='text') throw new RuntimeException('NODE_NOT_FOUND');
        $metadata=(array)($node['metadata']??[]);
        if ((int)($metadata['content_revision']??0)!==(int)$source['content_revision'] || ($metadata['content']??'')!==$source['content'] || ($metadata['prompt']??'')!==$source['prompt']) throw new RuntimeException('CONTENT_VERSION_CONFLICT');
        $reply=Db::name(ConversationStore::PREFIX.'message')->where($scope+['run_id'=>$id,'role'=>'assistant','delete_time'=>0])->value('content_json');
        $text=json_decode((string)$reply,true,512,JSON_THROW_ON_ERROR)['text']??null;
        if (!is_string($text) || trim($text)==='' || strlen($text)>200000) throw new RuntimeException('INVALID_CONTENT');
        $metadata['content']=$text;
        // The native text node prefers rich HTML if present; remove obsolete
        // presentation only, and render the new plain text safely.
        unset($metadata['richContent']);
        $metadata['content_revision']=(int)($metadata['content_revision']??0)+1;
        $node['metadata']=$metadata;
        return $node;
    }
}
