<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use NeuronAI\Workflow\Workflow;
use RuntimeException;

/** Finite PHP SDK bridge. The existing provider owns every metered operation. */
final class NeuronConversationProvider implements ConversationProviderInterface
{
    public const ENGINE = 'neuron_v1';
    /** Read old accepted snapshots without rewriting their model/billing receipts. */
    public const PREVIOUS_ENGINE = 'deepagents_v1';

    private ?Workflow $workflow = null;
    private array $request = [];
    private bool $consumed = false;

    public function __construct(private ConversationProviderInterface $provider, private array $scope) {}

    public static function supports(string $engine): bool
    {
        return in_array($engine, [self::ENGINE, self::PREVIOUS_ENGINE], true);
    }

    public function preflight(int $tenant, int $user, array $request): void
    {
        $this->assertScope($tenant, $user);
        if ($this->consumed) throw new RuntimeException('AGENT_RUNTIME_ALREADY_CONSUMED');
        if ($this->workflow !== null) throw new RuntimeException('AGENT_RUNTIME_ALREADY_PREPARED');
        try {
            if (!class_exists(Workflow::class)) throw new RuntimeException('AGENT_RUNTIME_UNAVAILABLE');
            // A new graph and in-memory state per accepted run. PHP database
            // checkpoints remain the sole durable source across queue attempts.
            $workflowId = 'neuron:' . implode(':', array_map(fn($key) => $this->scope[$key], ['tenant', 'user', 'canvas', 'thread'])) . ':' . bin2hex(random_bytes(8));
            $workflow = (new Workflow($workflowId))->setMaxSteps(1);
            $workflow->addNode(new NeuronDecisionNode($this->provider, $tenant, $user, $request));
            // Validate the actual SDK graph before authorizeSubmission/billing.
            $workflow->export();
        } catch (\Throwable $error) {
            throw new RuntimeException('AGENT_RUNTIME_UNAVAILABLE', 0, $error);
        }
        $this->provider->preflight($tenant, $user, $request);
        $this->request = $request;
        $this->workflow = $workflow;
    }

    public function generate(int $tenant, int $user, array $request): array
    {
        $this->assertScope($tenant, $user);
        if ($this->consumed) throw new RuntimeException('AGENT_RUNTIME_ALREADY_CONSUMED');
        if ($this->workflow === null) throw new RuntimeException('AGENT_RUNTIME_UNAVAILABLE');
        if ($request !== $this->request) throw new RuntimeException('AGENT_RUNTIME_SNAPSHOT_MISMATCH');
        // Seal before invoking the SDK: an exception may follow a paid success.
        // Neither this graph nor another call on this bridge may retry it.
        $this->consumed = true;
        try {
            $state = $this->workflow->run();
            $result = $state->get('metered_result');
            if (!is_array($result)) throw new RuntimeException('AGENT_RUNTIME_PROTOCOL_ERROR');
            return $result;
        } finally {
            $this->workflow = null;
            $this->request = [];
        }
    }

    private function assertScope(int $tenant, int $user): void
    {
        foreach (['tenant', 'user', 'canvas', 'thread'] as $key) {
            if (!isset($this->scope[$key]) || !is_int($this->scope[$key]) || $this->scope[$key] <= 0) {
                throw new RuntimeException('AGENT_RUNTIME_SCOPE_MISMATCH');
            }
        }
        if ($tenant !== $this->scope['tenant'] || $user !== $this->scope['user']) {
            throw new RuntimeException('AGENT_RUNTIME_SCOPE_MISMATCH');
        }
    }
}
