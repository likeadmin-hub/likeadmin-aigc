<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_llm\agent;

use Generator;
use NeuronAI\Providers\{AIProviderInterface, ProviderResponse};
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Chat\Messages\{Message, SystemMessage, AssistantMessage, ToolCallMessage, ToolResultMessage, Usage};
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;

/** Keeps Neuron decisions on the application's metered model transport. */
final class MarketNeuronProvider implements AIProviderInterface
{
    private string $instructions = '';
    private array $tools = [];
    private int $calls = 0;
    private bool $repairAttempted = false;

    public function __construct(private string $model, private \Closure $transport, private ?\Closure $validateReply = null) {}
    public function getModel(): string { return $this->model; }
    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->instructions = $prompt instanceof SystemMessage ? (string)$prompt->getContent() : (string)$prompt;
        return $this;
    }
    public function setTools(array $tools): AIProviderInterface { $this->tools = $tools; return $this; }
    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        throw new \LogicException('首页 Agent 使用模型市场的统一传输服务');
    }
    public function chat(Message ...$messages): ProviderResponse
    {
        if (++$this->calls > 8) throw new \RuntimeException('本轮操作较多，请缩小任务范围后继续');
        $schemas = array_map(static fn($tool) => [
            'type'=>'function', 'function'=>['name'=>$tool->getName(), 'description'=>$tool->getDescription(), 'parameters'=>$tool->getInputSchema()],
        ], $this->tools);
        $request = [
            'content'=>'继续完成当前用户请求', 'model_selection'=>$this->model,
            'messages'=>self::messages($messages), 'system_prompt'=>$this->instructions,
            'tools'=>$schemas, 'tool_choice'=>'auto', '_agent_tool_history'=>true,
            '_disable_model_fallback'=>true, '_disable_transient_retry'=>true,
        ];
        $request['messages']=self::fitContext($request['messages'],$request['system_prompt'],$schemas);
        $result = ($this->transport)($request);
        if (empty($result['tool_calls']) && $this->validateReply !== null) {
            $correction=($this->validateReply)((string)($result['content']??''));
            if ($correction && !$this->repairAttempted && $this->calls < 8) {
                $this->repairAttempted=true;
                ++$this->calls;
                $request['system_prompt'].="\n".$correction;
                $request['tool_choice']='required';
                $request['messages']=self::fitContext($request['messages'],$request['system_prompt'],$schemas);
                $result=($this->transport)($request);
            }
            if (empty($result['tool_calls']) && ($this->validateReply)((string)($result['content']??''))) {
                // Do not leave a fictional plan in the conversation when repair fails.
                $result['content']='本次尚未创建可执行的生成方案，没有提交生成任务。请重试；方案校验通过后会按所选运行模式继续。';
            }
        }
        $calls=[];
        foreach ((array)($result['tool_calls']??[]) as $call) {
            $arguments=$call['function']['arguments']??'{}';
            if (is_string($arguments)) $arguments=json_decode($arguments,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($arguments)) throw new \RuntimeException('模型返回了无效工具参数');
            $id=(string)($call['id']??'');
            if ($id==='') throw new \RuntimeException('模型未返回工具调用标识');
            $calls[]=new ToolCall((string)($call['function']['name']??''),$id,$arguments);
        }
        $message=$calls ? new ToolCallMessage((string)($result['content']??''),$calls) : new AssistantMessage((string)($result['content']??''));
        $usage=(array)($result['usage']??[]);
        $message->setUsage(new Usage((int)($usage['prompt_tokens']??0),(int)($usage['completion_tokens']??0)));
        return new ProviderResponse($message, metadata:['billing'=>$result['billing']??[]]);
    }
    public function stream(Message ...$messages): Generator
    {
        // The metered transport publishes durable deltas as it receives them.
        $response=$this->chat(...$messages);
        if ($response->message()->getContent()) yield new TextChunk($response->message()->getContent());
        return $response;
    }
    public function structured(array|Message $messages,string $class,array $response_schema): ProviderResponse
    {
        throw new \LogicException('请使用已注册工具的结构化参数');
    }
    public static function messages(array $messages): array
    {
        $out=[];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $call) $out[]=['role'=>'tool','tool_call_id'=>$call->getCallId(),'content'=>(string)$call->getResult()];
                continue;
            }
            $row=['role'=>$message->getRole(),'content'=>$message->getContent()??''];
            $images=array_filter($message->getContentBlocks(),static fn($block)=>$block instanceof ImageContent);
            if($images && $row['role']==='user') {
                $row['content']=[['type'=>'text','text'=>$row['content']]];
                foreach($images as $image)$row['content'][]=['type'=>'image_url','image_url'=>['url'=>$image->content]];
            }
            if ($message instanceof ToolCallMessage) $row['tool_calls']=array_map(static fn(ToolCall $call)=>[
                'id'=>$call->getCallId(),'type'=>'function','function'=>['name'=>$call->getName(),'arguments'=>json_encode((object)$call->getInputs(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)],
            ],$message->getToolCalls());
            $out[]=$row;
        }
        return $out;
    }
    /** Approximate the complete input, including instructions/tools; drop only complete old turns. */
    public static function fitContext(array $messages,string $instructions,array $tools,int $budget=24000): array
    {
        $estimate=static function($value):int{
            $text=is_string($value)?$value:AgentStore::encode($value);
            $nonAscii=preg_match_all('/[^\x00-\x7F]/u',$text);
            return (int)ceil((mb_strlen($text)-$nonAscii)/4)+$nonAscii*2;
        };
        $base=$estimate($instructions)+$estimate($tools);
        $size=static function(array $rows)use($estimate,$base):int{
            $total=$base;
            foreach($rows as $row){$total+=$estimate($row)+12;foreach(is_array($row['content']??null)?$row['content']:[] as $block)if(($block['type']??'')==='image_url')$total+=1024;}
            return $total;
        };
        while($size($messages)>$budget){
            $users=[];foreach($messages as $i=>$row)if($row['role']==='user')$users[]=$i;
            if(count($users)<2)throw new \InvalidArgumentException('本轮内容较长，请分段处理文档或减少同时引用的素材后继续');
            $messages=array_slice($messages,$users[1]);
        }
        return $messages;
    }
}
