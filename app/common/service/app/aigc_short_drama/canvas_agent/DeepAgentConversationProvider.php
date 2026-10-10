<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;
use RuntimeException;

/** Actual Deep Agents graph with one authorized, metered PHP model adapter. */
final class DeepAgentConversationProvider implements ConversationProviderInterface
{
    public const ENGINE='deepagents_v1';
    private $process=null;
    private array $pipes=[];
    private string $buffer='';
    public function __construct(private ConversationProviderInterface $provider,private array $scope) {}
    public function preflight(int $tenant,int $user,array $request): void
    {
        if (($this->scope['tenant']??0)!==$tenant || ($this->scope['user']??0)!==$user) throw new RuntimeException('AGENT_RUNTIME_SCOPE_MISMATCH');
        $this->provider->preflight($tenant,$user,$request);
        $python=(string)\think\facade\Config::get('short_drama_agent.python','/opt/short-drama-agent/bin/python');
        if(!is_executable($python)) throw new RuntimeException('AGENT_RUNTIME_UNAVAILABLE');
        $script=root_path().'agent_runtime/short_drama/runtime.py';
        $this->process=proc_open([$python,'-u',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,dirname($script),
            ['PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8','PYTHONDONTWRITEBYTECODE'=>'1','LANGSMITH_TRACING'=>'false','LANGCHAIN_TRACING_V2'=>'false']);
        if(!is_resource($this->process)) throw new RuntimeException('AGENT_RUNTIME_UNAVAILABLE');
        foreach($this->pipes as $pipe) stream_set_blocking($pipe,false);
        $ready=$this->read(15);
        if(($ready['type']??'')!=='ready' || ($ready['protocol']??0)!==1 || ($ready['engine']??'')!==self::ENGINE) throw new RuntimeException('AGENT_RUNTIME_UNAVAILABLE');
        // Initialize the graph before authorizeSubmission. An unavailable SDK,
        // bad scope or context must fail before a paid submission is possible.
        $this->write(['scope'=>$this->scope,'messages'=>$request['messages'],'instruction'=>$request['system_prompt']]);
        $frame=$this->read(15);
        if(($frame['type']??'')!=='model_request' || ($frame['sequence']??0)!==1) throw new RuntimeException('AGENT_RUNTIME_PROTOCOL_ERROR');
    }
    public function generate(int $tenant,int $user,array $request): array
    {
        if(!is_resource($this->process)) throw new RuntimeException('AGENT_RUNTIME_UNAVAILABLE');
        try {
            // Never accept provider IDs, context, tools or a second model call
            // from the subprocess. This callback is sealed to the frozen run.
            $result=$this->provider->generate($tenant,$user,$request);
            $decision=ConversationAgentDecision::parse((string)($result['content']??''),$request['context'])['decision'];
            $this->write(['type'=>'model_result','sequence'=>1,'decision'=>$decision]);
            $frame=$this->read(15);
            if(($frame['type']??'')!=='complete' || ($frame['model_calls']??0)!==1 || ($frame['decision']??null)!=$decision) throw new RuntimeException('AGENT_RUNTIME_PROTOCOL_ERROR');
            $result['content']=json_encode($decision,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            return $result;
        } finally {$this->close();}
    }
    private function write(array $frame): void
    {
        $data=json_encode($frame,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        if(strlen($data)>2097152) throw new RuntimeException('CONTEXT_TOO_LARGE');
        $deadline=microtime(true)+15;
        while($data!=='') {
            $n=@fwrite($this->pipes[0],$data);
            if($n===false || microtime(true)>$deadline) throw new RuntimeException('AGENT_RUNTIME_UNAVAILABLE');
            if($n===0) {usleep(10000);continue;}
            $data=substr($data,$n);
        }
    }
    private function read(int $seconds): array
    {
        $deadline=microtime(true)+$seconds;
        while(microtime(true)<$deadline) {
            if(($pos=strpos($this->buffer,"\n"))!==false) {
                $line=substr($this->buffer,0,$pos);$this->buffer=substr($this->buffer,$pos+1);
                $frame=json_decode($line,true);
                if(!is_array($frame)) throw new RuntimeException('AGENT_RUNTIME_PROTOCOL_ERROR');
                return $frame;
            }
            if(feof($this->pipes[1])) throw new RuntimeException('AGENT_RUNTIME_UNAVAILABLE');
            $read=[$this->pipes[1],$this->pipes[2]];$write=null;$except=null;
            if(@stream_select($read,$write,$except,0,200000)===false) throw new RuntimeException('AGENT_RUNTIME_UNAVAILABLE');
            foreach($read as $pipe) {
                $chunk=fread($pipe,65536);
                if($pipe===$this->pipes[1]) $this->buffer.=$chunk;
                // stderr is deliberately drained without exposing model data.
            }
            if(strlen($this->buffer)>2097152) throw new RuntimeException('AGENT_RUNTIME_PROTOCOL_ERROR');
        }
        throw new RuntimeException('AGENT_RUNTIME_TIMEOUT');
    }
    private function close(): void
    {
        foreach($this->pipes as $pipe) if(is_resource($pipe)) fclose($pipe);
        $this->pipes=[];
        if(is_resource($this->process)) {proc_terminate($this->process);proc_close($this->process);}
        $this->process=null;
    }
    public function __destruct() {$this->close();}
}
