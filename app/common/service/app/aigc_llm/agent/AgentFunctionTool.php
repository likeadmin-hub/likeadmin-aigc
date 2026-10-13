<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use NeuronAI\Tools\{Tool,ToolProperty,PropertyType,ToolOutput};

final class AgentFunctionTool extends Tool
{
    public function __construct(string $name,string $description,private array $schema,private \Closure $handler)
    {
        $this->name=$name;$this->description=$description;$this->maxRuns=4;
    }
    protected function properties(): array {return [new ToolProperty('payload',PropertyType::OBJECT,'工具参数',true)];}
    public function getInputSchema(): array
    {
        return ['type'=>'object','properties'=>['payload'=>$this->schema],'required'=>['payload'],'additionalProperties'=>false];
    }
    public function __invoke(array|\stdClass $payload): ToolOutput
    {
        try{return ToolOutput::text(AgentStore::encode(($this->handler)((array)$payload)));}
        catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException $e){return ToolOutput::error($e->getMessage());}
    }
}
