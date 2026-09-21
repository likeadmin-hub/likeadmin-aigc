<?php
declare(strict_types=1);
namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationService;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution;
use RuntimeException;

/** Authenticated short-drama conversation API; send is persistence-only. */
final class CanvasAgentController extends BaseApiController
{
    public function threads() { return $this->respond(function () {
        $p=$this->request->get();
        return ConversationStore::threads((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['before']??0,true));
    }); }
    public function createThread() { return $this->respond(function () {
        $p=$this->request->post();
        if (!is_string($p['request_key']??null) || !is_string($p['title']??'')) throw new RuntimeException('INVALID_THREAD_REQUEST');
        return ConversationStore::create((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),$p['request_key'],$p['title']??'');
    }); }
    public function messages() { return $this->respond(function () {
        $p=$this->request->get();
        return ConversationStore::messages((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['thread_id']??null),self::number($p['after']??0,true));
    }); }
    public function events() { return $this->respond(function () {
        $p=$this->request->get();
        $events=ConversationStore::events((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['thread_id']??null),self::number($p['after']??0,true));
        foreach ($events as &$event) {
            // Private late-response evidence must not become a successful
            // assistant message, nor expose internal diagnostics through SSE.
            if ($event['kind']==='run.late_reply') $event['payload']=['status'=>'needs_reconciliation'];
        }
        unset($event);return $events;
    }); }
    public function send() { return $this->respond(function () {
        $p=$this->request->post();
        $canvas=self::number($p['canvas_id']??null);$thread=self::number($p['thread_id']??null);
        unset($p['canvas_id'],$p['thread_id']);
        $p['base_revision']=self::number($p['base_revision']??null,true);
        foreach (['skill_id','skill_version'] as $key) if (array_key_exists($key,$p)) $p[$key]=self::number($p[$key],true);
        return ConversationService::send((int)$this->request->tenantId,$this->userId,$canvas,$thread,$p);
    }); }

    public function stop() { return $this->respond(function () {
        $p=$this->request->post();
        if (array_diff(array_keys($p),['canvas_id','thread_id','run_id'])) throw new RuntimeException('UNSUPPORTED_MESSAGE_FIELD');
        return ConversationExecution::stop((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['thread_id']??null),self::number($p['run_id']??null));
    }); }

    public function run() { return $this->respond(function () {
        $p=$this->request->get();
        return ConversationStore::run((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['thread_id']??null),self::number($p['run_id']??null));
    }); }

    private function respond(\Closure $action) {
        try {return $this->success('success',$action());}
        catch (\Throwable $error) {
            $code=$error->getMessage();
            $public=['CANVAS_NOT_FOUND','THREAD_NOT_FOUND','RUN_NOT_FOUND','CANVAS_AGENT_DISABLED','IDEMPOTENCY_CONFLICT','THREAD_BUSY','VERSION_CONFLICT','NODE_NOT_FOUND','INVALID_IDENTIFIER','INVALID_THREAD_REQUEST','INVALID_THREAD_TITLE','INVALID_REQUEST_KEY','INVALID_MESSAGE','INVALID_NODE_REFERENCES','INVALID_BASE_REVISION','UNSUPPORTED_MESSAGE_FIELD','CONTEXT_TOO_LARGE','INVALID_AGENT_PREFERENCES','INVALID_GENERATION_MODE','INVALID_MODEL_SELECTION','REASONING_MODEL_UNAVAILABLE','IMAGE_MODEL_UNAVAILABLE','VIDEO_MODEL_UNAVAILABLE','INVALID_SKILL_SELECTION','SKILL_UNAVAILABLE'];
            return $this->fail(in_array($code,$public,true)?$code:'AGENT_REQUEST_FAILED');
        }
    }
    private static function number($value,bool $zero=false): int {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D',(string)$value) || (float)$value>9007199254740991 || (!$zero && (int)$value===0)) throw new RuntimeException('INVALID_IDENTIFIER');
        return (int)$value;
    }
}
