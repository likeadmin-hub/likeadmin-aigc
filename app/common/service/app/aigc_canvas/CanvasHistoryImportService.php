<?php
namespace app\common\service\app\aigc_canvas;

use app\common\service\app\aigc_llm\agent\AgentStore;
use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
use think\facade\Db;

/** Imports historical conversations once. Never creates executable runs or replays actions. */
final class CanvasHistoryImportService
{
    public static function importProject(int $tenant, int $user, array $project): void
    {
        if ((int)$project['tenant_id']!==$tenant || (int)$project['user_id']!==$user || $tenant<1 || $user<1) throw new \InvalidArgumentException('项目归属不匹配');
        $prefix=(string)config('database.connections.mysql.prefix');
        foreach (['aigc_canvas_agent_thread','aigc_canvas_agent_message','aigc_llm_session','aigc_llm_message'] as $table) {
            if (!Db::query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$prefix.$table])) return;
        }
        if (!isset(Db::name('aigc_llm_session')->getFields()['workspace_key'])) return;
        $owner=['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$project['id'],'delete_time'=>0];
        $viewport=AgentStore::json($project['viewport_json'] ?? []);
        $pages=array_column((array)($viewport['pages'] ?? []),'id');
        $fallback=(string)($pages[0] ?? 'canvas_1');
        // Individual thread transactions bound locks and make partial progress restartable.
        foreach (Db::name('aigc_canvas_agent_thread')->where($owner)->order('id')->column('id') as $id) {
            Db::transaction(static function () use ($id,$owner,$tenant,$user,$pages,$fallback): void {
                $thread=Db::name('aigc_canvas_agent_thread')->where($owner)->where('id',$id)->lock(true)->find();
                if (!$thread) return;
                $meta=AgentStore::json($thread['meta_json']);
                // A deleted imported conversation must never be resurrected by opening its project.
                if (isset($meta['_conversation_import_v1'])) return;
                $page=(string)($meta['page_id'] ?? $meta['currentCanvasId'] ?? '');
                if (!in_array($page,$pages,true)) $page=$fallback;
                $messages=Db::name('aigc_canvas_agent_message')->where($owner)->where('thread_id',$id)->whereIn('role',['user','assistant'])->order('id')->select()->toArray();
                $session=AigcLlmSession::create(['tenant_id'=>$tenant,'user_id'=>$user,'workspace_key'=>'canvas:'.$owner['project_id'].':'.$page,
                    'title'=>mb_substr((string)($thread['title'] ?: '历史对话'),0,100),'model_code'=>'','status'=>'idle',
                    'last_message_at'=>(int)$thread['update_time'],'message_count'=>count($messages),
                    'create_time'=>(int)$thread['create_time'],'update_time'=>(int)$thread['update_time'],'delete_time'=>0]);
                $seq=0;$parent=0;
                foreach ($messages as $message) {
                    $data=self::message($message);
                    $saved=AigcLlmMessage::create($data+['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'seq'=>++$seq,
                        'parent_user_message_id'=>$data['role']==='assistant'?$parent:0,'token_usage_json'=>[],
                        'create_time'=>(int)$message['create_time'],'update_time'=>(int)$message['update_time'],'delete_time'=>0]);
                    if ($data['role']==='user') $parent=(int)$saved['id'];
                }
                $meta['_conversation_import_v1']=['session_id'=>(int)$session['id'],'page_id'=>$page];
                Db::name('aigc_canvas_agent_thread')->where($owner)->where('id',$id)->update(['meta_json'=>AgentStore::encode($meta)]);
            });
        }
    }

    public static function message(array $row): array
    {
        $role=(string)$row['role'];
        $text=(string)($row['content'] ?? '');
        if ($role==='assistant') {
            $structured=AgentStore::json($row['content_json'] ?? []);
            $response=(array)($structured['agent_response'] ?? $structured['response'] ?? $structured);
            foreach ([$structured['assistant_reply'] ?? null,$response['assistant_reply'] ?? null] as $value) {
                if (is_string($value) && trim($value)!=='') { $text=$value;break; }
            }
            // Do not display old tool/reasoning envelopes as assistant prose.
            $envelope=json_decode(trim($text),true);
            if (is_array($envelope)) $text=(string)($envelope['assistant_reply'] ?? $envelope['reply'] ?? '历史结构化回复已保留在原记录中。');
        }
        $status=(string)($row['status'] ?? 'success');
        if ($role==='assistant' && in_array($status,['running','pending','queued','waiting','waiting_tasks'],true)) {
            $text=rtrim($text)."\n\n此记录来自旧版会话，尚未完成的操作未自动恢复；已生成的内容保留在项目中。";
            $status='canceled';
        }
        return ['role'=>$role,'content'=>$text,'status'=>in_array($status,['failed','error'],true)?'error':(in_array($status,['canceled','cancelled'],true)?'stopped':'done'),'finish_reason'=>'legacy_import'];
    }
}
