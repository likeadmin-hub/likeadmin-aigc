<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;

final class DecorationAgentDecisionNode extends Node
{
    private bool $called=false;
    public function __construct(private \Closure $provider){}
    public function __invoke(StartEvent $event,WorkflowState $state): StopEvent
    {
        if($this->called)throw new \RuntimeException('装修决策已执行');
        $this->called=true;
        $result=($this->provider)();
        DecorationAgentDecision::finalContent($result);
        $state->set('result',$result);
        return new StopEvent();
    }
}
