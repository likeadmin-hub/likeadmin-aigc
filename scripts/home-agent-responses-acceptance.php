<?php
declare(strict_types=1);

// Opt-in live acceptance: ordinary test suites must never call paid suppliers.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['tenant-id:', 'user-id:', 'model-selection:', 'execute', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/home-agent-responses-acceptance.php --tenant-id=ID --user-id=ID --model-selection=ID [--execute]\n"
        . "Without --execute: read-only preflight. With --execute: up to two paid text requests, one read-only tool; no generation or retries.\n";
    exit(0);
}
$tenant = filter_var($options['tenant-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$user = filter_var($options['user-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selection = $options['model-selection'] ?? '';
if (!$tenant || !$user || !is_string($selection) || $selection === '' || strlen($selection) > 120) {
    fwrite(STDERR, "Provide tenant-id, user-id and model-selection; use --help for details.\n"); exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';
(new think\App())->initialize();

use app\common\service\app\aigc_llm\agent\{AgentStore, AgentCapabilityCatalog, MarketNeuronProvider};
use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\ai\MarketTaskReconciliationService;
use app\common\model\ai\AiConsumptionLog;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;

$report = ['ok' => false, 'executed' => false, 'tenant_id' => $tenant, 'user_id' => $user, 'model_selection' => $selection, 'tasks' => [], 'turns' => []];
$reportPath = null;
$save = static function () use (&$report, &$reportPath): void {
    if ($reportPath === null) return;
    $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($reportPath, $json, LOCK_EX) === false) throw new RuntimeException('Acceptance report cannot be written');
    chmod($reportPath, 0600);
};
try {
    AgentStore::access($tenant, $user);
    $model = MarketTextModelRuntimeService::resolveAgentModel($tenant, $selection);
    if (($model['protocol'] ?? '') !== 'openai_responses') throw new InvalidArgumentException('Select a Responses model');
    $before = AgentStore::preferences($tenant, $user);
    $codes = array_keys(AgentCapabilityCatalog::all($tenant, $user));
    $report['capabilities'] = $codes;
    if (!isset($options['execute'])) {
        $report['ok'] = true;
        $report['message'] = 'Preflight only; no provider request or accounting write.';
    } else {
        $reportPath = dirname(__DIR__) . '/runtime/home-agent-responses-acceptance-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
        $report['executed'] = true;
        $save(); // Fail before paid submission if the evidence directory is unavailable.
        $counter = (object)['calls' => 0, 'executions' => 0, 'capability_count' => null];
        $tool = new class($tenant, $user, $counter) extends Tool {
            protected string $name = 'list_creation_categories';
            protected ?string $description = 'Read the current user\'s available creation tool codes; never submit generation.';
            public function __construct(private int $tenant, private int $user, private object $counter) {}
            public function __invoke(): string {
                // Neuron clones tools; keep an object shared with the assertion state.
                if (++$this->counter->executions > 1) throw new RuntimeException('Acceptance permits only one tool execution');
                $codes = array_keys(AgentCapabilityCatalog::all($this->tenant, $this->user));
                $this->counter->capability_count = count($codes);
                return json_encode(['count' => count($codes), 'codes' => $codes], JSON_THROW_ON_ERROR);
            }
        };
        $provider = new MarketNeuronProvider($selection, static function (array $request) use ($tenant, $user, $counter, &$report, $save): array {
            if (++$counter->calls > 2) throw new RuntimeException('Acceptance permits only two text requests');
            $request = array_replace($request, [
                'source_app_code' => 'aigc_llm', 'business_table' => 'aigc_llm_agent_run', 'business_id' => 0,
                'max_tokens' => 1024, 'request_timeout_seconds' => 90,
                'tool_choice' => $counter->calls === 1 ? 'required' : 'none',
            ]);
            $visible = '';
            $result = MarketTextModelRuntimeService::generate($tenant, $user, $request, static function ($type, $data) use (&$visible, &$report, $save): void {
                if ($type === 'app_task') { $report['tasks'][] = $data; $save(); }
                if ($type === 'delta') $visible .= $data['delta'] ?? '';
            });
            $report['turns'][] = ['tool_count' => count($result['tool_calls'] ?? []), 'sse_matches' => $visible === ($result['content'] ?? ''), 'usage' => $result['usage'] ?? []];
            $save();
            return $result;
        });
        $reply = (new Agent())->setThreadId('responses-acceptance-' . bin2hex(random_bytes(12)))
            ->setAiProvider($provider)->setTools([$tool])->chat(new UserMessage('查询当前可用的创作工具数量。仅查询一次，不要生成作品。调用后只输出“共有 N 项。”，N 替换为工具返回的阿拉伯数字 count，不添加其他文字。'))->getMessage()->getContent();
        $report['reply'] = $reply;
        $report['model_calls'] = $counter->calls;
        $report['tool_executions'] = $counter->executions;
        $report['preferences_unchanged'] = $before === AgentStore::preferences($tenant, $user);
        $report['exact_answer_matches'] = trim((string)$reply) === '共有 ' . $counter->capability_count . ' 项。';
        $report['ok'] = $counter->calls === 2 && $counter->executions === 1 && trim((string)$reply) !== ''
            && array_column($report['turns'], 'tool_count') === [1, 0]
            && array_column($report['turns'], 'sse_matches') === [true, true] && $report['preferences_unchanged'] && $report['exact_answer_matches'];
    }
} catch (Throwable $error) {
    $report['error_type'] = get_class($error); // No raw provider error, prompt or credentials.
    $report['message'] = 'Acceptance incomplete. Inspect retained task IDs and accounting; do not replay unresolved requests.';
}
try {
    $ids = array_column($report['tasks'], 'consumption_id');
    if ($ids !== []) {
        $logs = AiConsumptionLog::whereIn('id', $ids)->field('id,app_task_id,run_status,billing_status,actual_user_price,reserved_user_price')->select()->toArray();
        $report['accounting'] = $logs;
        $report['charged_points'] = round(array_sum(array_column($logs, 'actual_user_price')), 2);
        $audit = MarketTaskReconciliationService::audit($tenant, 'aigc_llm', 5000, 'aigc_llm_agent_run', $user);
        $report['accounting_issues'] = array_values(array_filter($audit['issues'], static fn($issue) => in_array((int)($issue['consumption_id'] ?? 0), $ids, true)));
        $report['ok'] = $report['ok'] && count($logs) === count($ids) && $report['accounting_issues'] === []
            && count(array_filter($logs, static fn($log) => $log['run_status'] !== 'success' || $log['billing_status'] !== 'settled')) === 0;
    }
    $save();
} catch (Throwable $error) {
    $report['ok'] = false;
    $report['evidence_error_type'] = get_class($error);
}
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
if ($reportPath !== null) echo 'Report: ' . $reportPath . PHP_EOL;
exit($report['ok'] ? 0 : 1);
