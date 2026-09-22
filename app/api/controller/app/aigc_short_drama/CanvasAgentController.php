<?php
declare(strict_types=1);
namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationService;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationPreferences;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationDocuments;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
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
        return $this->publicEvents(ConversationStore::events((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['thread_id']??null),self::number($p['after']??0,true)));
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

    public function preferences() { return $this->respond(function () {
        $p=$this->request->get();
        ConversationStore::assertCanvasAccess((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null));
        return ConversationPreferences::read((int)$this->request->tenantId,$this->userId);
    }); }

    public function savePreferences() { return $this->respond(function () {
        $p=$this->request->post();
        if (array_diff(array_keys($p),['canvas_id','expected_revision','preferences'])) throw new RuntimeException('UNSUPPORTED_MESSAGE_FIELD');
        ConversationStore::assertCanvasAccess((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null));
        $revision=self::number($p['expected_revision']??null,true);
        if (!is_array($p['preferences']??null)) throw new RuntimeException('INVALID_AGENT_PREFERENCES');
        return ConversationPreferences::save((int)$this->request->tenantId,$this->userId,$revision,$p['preferences']);
    }); }

    /** Platform-owned workflow definition plus this thread's frozen state. */
    public function workflow() { return $this->respond(function () {
        $p=$this->request->get();
        return ConversationWorkflow::read((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['thread_id']??null));
    }); }

    /** A structured answer card changes only the next frozen intake slot. */
    public function answerWorkflow() { return $this->respond(function () {
        $p=$this->request->post();
        if (array_diff(array_keys($p),['canvas_id','thread_id','expected_revision','slot','value'])) throw new RuntimeException('UNSUPPORTED_MESSAGE_FIELD');
        if (!is_string($p['slot']??null) || !is_string($p['value']??null)) throw new RuntimeException('INVALID_WORKFLOW_ANSWER');
        return ConversationWorkflow::answer((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['thread_id']??null),self::number($p['expected_revision']??null,true),$p['slot'],$p['value']);
    }); }

    /** Quotes a registered PDF/Office attachment. Quote itself never submits a provider task. */
    public function documentQuote() { return $this->respond(function () {
        $p=$this->request->post();
        if (array_diff(array_keys($p),['canvas_id','asset_id'])) throw new RuntimeException('UNSUPPORTED_MESSAGE_FIELD');
        return ConversationDocuments::quote((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['asset_id']??null));
    }); }

    /** Explicit user confirmation is required before an actual-usage document parser is submitted. */
    public function confirmDocumentParse() { return $this->respond(function () {
        $p=$this->request->post();
        if (array_diff(array_keys($p),['canvas_id','asset_id','quote_hash']) || !is_string($p['quote_hash']??null)) throw new RuntimeException('UNSUPPORTED_MESSAGE_FIELD');
        return ConversationDocuments::confirm((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['asset_id']??null),$p['quote_hash']);
    }); }

    /** Safe polling projection: no raw source URL or provider diagnostics leave this endpoint. */
    public function document() { return $this->respond(function () {
        $p=$this->request->get();
        return ConversationDocuments::detail((int)$this->request->tenantId,$this->userId,self::number($p['canvas_id']??null),self::number($p['asset_id']??null));
    }); }

    /**
     * Bounded SSE subscription to the durable conversation projection.
     *
     * This endpoint never calls a model and never advances a run.  The worker
     * owns dispatch; this connection only forwards persisted lifecycle events
     * and reply messages for the authenticated canvas owner.  A short-lived
     * stream lets clients reconnect with their cursors after a proxy timeout.
     */
    public function stream()
    {
        @ignore_user_abort(false);
        @set_time_limit(35);
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) @ob_end_flush();
        $this->prepareStreamResponse();

        try {
            $p=$this->streamParams();
            if (array_diff(array_keys($p),['canvas_id','thread_id','run_id','event_after','message_after','wait_seconds'])) throw new RuntimeException('UNSUPPORTED_MESSAGE_FIELD');
            $canvas=self::number($p['canvas_id']??null);
            $thread=self::number($p['thread_id']??null);
            $run=self::number($p['run_id']??null);
            $eventAfter=self::number($p['event_after']??0,true);
            $messageAfter=self::number($p['message_after']??0,true);
            $wait=max(0,min(25,self::number($p['wait_seconds']??20,true)));
            $tenant=(int)$this->request->tenantId;
            $user=$this->userId;
            $deadline=microtime(true)+$wait;
            $lastVersion=-1;
            $this->emitStreamEvent('ready',['run_id'=>$run,'event_after'=>$eventAfter,'message_after'=>$messageAfter]);

            do {
                $emitted=false;
                $messages=ConversationStore::messages($tenant,$user,$canvas,$thread,$messageAfter);
                foreach ($messages as $message) {
                    $messageAfter=max($messageAfter,(int)$message['sequence']);
                    $payload=['id'=>(int)$message['id'],'run_id'=>(int)$message['run_id'],'sequence'=>(int)$message['sequence'],'role'=>(string)$message['role'],'text'=>(string)($message['content']['text']??'')];
                    // Clarification cards are already ownership-filtered by
                    // ConversationStore.  Forward only their small public
                    // projection, never a graph snapshot or asset URI.
                    if (!empty($message['reference_candidates']) && is_array($message['reference_candidates'])) $payload['reference_candidates']=$message['reference_candidates'];
                    $this->emitStreamEvent('message',$payload);
                    $emitted=true;
                }
                $events=$this->publicEvents(ConversationStore::events($tenant,$user,$canvas,$thread,$eventAfter));
                foreach ($events as $event) {
                    $eventAfter=max($eventAfter,(int)$event['cursor']);
                    $this->emitStreamEvent('lifecycle',['cursor'=>(int)$event['cursor'],'run_id'=>(int)$event['run_id'],'sequence'=>(int)$event['sequence'],'kind'=>(string)$event['kind'],'payload'=>$event['payload']]);
                    $emitted=true;
                }
                $snapshot=ConversationStore::run($tenant,$user,$canvas,$thread,$run);
                if ($lastVersion!==$snapshot['version']) {
                    $lastVersion=(int)$snapshot['version'];
                    $this->emitStreamEvent('run',$snapshot);
                    $emitted=true;
                }
                if (in_array($snapshot['status'],['clarify','success','failed','canceled','needs_reconciliation'],true)) {
                    $this->emitStreamEvent('complete',['run_id'=>$run,'status'=>$snapshot['status'],'event_after'=>$eventAfter,'message_after'=>$messageAfter]);
                    break;
                }
                if (!$emitted) $this->emitStreamEvent('ping',['run_id'=>$run]);
                if ($wait===0 || connection_aborted()) break;
                usleep(250000);
            } while (microtime(true)<$deadline);
        } catch (\Throwable $error) {
            $this->emitStreamEvent('error',['code'=>$this->publicErrorCode($error)]);
        }
        exit;
    }

    private function respond(\Closure $action) {
        try {return $this->success('success',$action());}
        catch (\Throwable $error) {
            $code=$error->getMessage();
            $public=['CANVAS_NOT_FOUND','THREAD_NOT_FOUND','RUN_NOT_FOUND','CANVAS_AGENT_DISABLED','IDEMPOTENCY_CONFLICT','THREAD_BUSY','VERSION_CONFLICT','PREFERENCE_VERSION_CONFLICT','INVALID_PREFERENCE_REVISION','NODE_NOT_FOUND','INVALID_IDENTIFIER','INVALID_THREAD_REQUEST','INVALID_THREAD_TITLE','INVALID_REQUEST_KEY','INVALID_MESSAGE','INVALID_NODE_REFERENCES','INVALID_BASE_REVISION','UNSUPPORTED_MESSAGE_FIELD','CONTEXT_TOO_LARGE','INVALID_ATTACHMENTS','IMAGE_REFERENCE_UNAVAILABLE','TOO_MANY_IMAGE_REFERENCES','INVALID_AGENT_PREFERENCES','INVALID_GENERATION_MODE','INVALID_MODEL_SELECTION','REASONING_MODEL_UNAVAILABLE','IMAGE_MODEL_UNAVAILABLE','VIDEO_MODEL_UNAVAILABLE','INVALID_SKILL_SELECTION','SKILL_UNAVAILABLE','CONTENT_BLOCKED','DOCUMENT_NOT_FOUND','DOCUMENT_NOT_READY','DOCUMENT_INVALID_STATE','DOCUMENT_QUOTE_EXPIRED','DOCUMENT_URL_UNAVAILABLE','DOCUMENT_PARSE_SUBMIT_FAILED','DOCUMENT_PARSE_RECORD_MISSING','WORKFLOW_NOT_ACTIVE','WORKFLOW_VERSION_CONFLICT','WORKFLOW_SLOT_OUT_OF_ORDER','WORKFLOW_STAGE_NOT_COLLECTING','INVALID_WORKFLOW_ANSWER'];
            return $this->fail(in_array($code,$public,true)?$code:'AGENT_REQUEST_FAILED');
        }
    }

    private function publicEvents(array $events): array
    {
        foreach ($events as &$event) {
            // Late evidence is retained for support/reconciliation only. It
            // must never be mistaken for a delivered assistant reply.
            if ($event['kind']==='run.late_reply') $event['payload']=['status'=>'needs_reconciliation'];
        }
        unset($event);
        return $events;
    }

    private function streamParams(): array
    {
        $params=$this->request->post();
        if (!empty($params)) return $params;
        $raw=file_get_contents('php://input');
        if (!is_string($raw) || trim($raw)==='') return [];
        $decoded=json_decode($raw,true);
        return is_array($decoded)?$decoded:[];
    }

    private function prepareStreamResponse(): void
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
    }

    private function emitStreamEvent(string $event,array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";
        @ob_flush();
        @flush();
    }

    private function publicErrorCode(\Throwable $error): string
    {
        $code=$error->getMessage();
        $public=['CANVAS_NOT_FOUND','THREAD_NOT_FOUND','RUN_NOT_FOUND','CANVAS_AGENT_DISABLED','INVALID_IDENTIFIER','UNSUPPORTED_MESSAGE_FIELD'];
        return in_array($code,$public,true)?$code:'AGENT_REQUEST_FAILED';
    }
    private static function number($value,bool $zero=false): int {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D',(string)$value) || (float)$value>9007199254740991 || (!$zero && (int)$value===0)) throw new RuntimeException('INVALID_IDENTIFIER');
        return (int)$value;
    }
}
