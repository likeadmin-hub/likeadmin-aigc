<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use RuntimeException;

/** One terminal proposal; no SDK tools, direct HTTP, summary or agent loop. */
final class NeuronDecisionNode extends Node
{
    private bool $submitted = false;

    public function __construct(
        private ConversationProviderInterface $provider,
        private int $tenant,
        private int $user,
        private array $request
    ) {}

    public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
    {
        if ($this->submitted) throw new RuntimeException('AGENT_RUNTIME_ALREADY_CONSUMED');
        $this->submitted = true;
        // Forward the original callbacks, multimodal input, model and receipt
        // metadata. The platform transport already owns SSE, safety and billing.
        $result = $this->provider->generate($this->tenant, $this->user, $this->request);
        $decision = ConversationAgentDecision::parse((string)($result['content'] ?? ''), $this->request['context'])['decision'];
        $result['content'] = json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $state->set('metered_result', $result);
        return new StopEvent();
    }
}
