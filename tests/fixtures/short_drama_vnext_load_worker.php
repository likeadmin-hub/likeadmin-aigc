<?php
// No real provider, queue scan or account mutation. Only this test's committed fixture jobs.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use app\common\service\app\aigc_short_drama\vnext\ExecutionStore;
use app\common\service\app\aigc_short_drama\vnext\ReceiptBuffer;
use app\common\service\app\aigc_short_drama\vnext\JobRunner;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
if (getenv('SHORT_DRAMA_VNEXT_LOAD_TESTS') !== '1' || !preg_match('/^vnext_load_[a-f0-9]{16}$/D', $argv[1] ?? '')) exit(2);
(new \think\App())->initialize();
$prefix = $argv[1]; $directory = $argv[2]; $worker = (int)$argv[3]; $count = (int)$argv[4];
if ($count < 1 || $count > 500 || $worker < 0 || $worker > 7) exit(2);
$buffer = new ReceiptBuffer($directory . '/host_' . $worker);
$receipt = static function (array $params): array {
    $data = json_decode($params['content'], true, 512, JSON_THROW_ON_ERROR);
    $units = array_values(ContentDocument::units($data['document']));
    if (!isset($data['shot_plan'])) $reply = ['shots' => array_map(static fn($u) => ['visual_description' => '人物说出全文', 'refs' => [['unit' => $u['ref']]]], $units)];
    else {
        $checks = [];
        foreach (['identity', 'continuity', 'shot_fidelity'] as $aspect) $checks[] = ['id' => 'aspect:' . $aspect, 'status' => 'covered', 'scope' => 'whole_episode', 'reason' => '核对全文',
            'evidence' => [['unit' => $units[0]['ref'], 'start' => 0, 'end' => mb_strlen($units[0]['text']), 'quote' => $units[0]['text']]]];
        $reply = ['document_hash' => $data['document']['content_hash'], 'shot_hash' => $data['shot_plan']['content_hash'], 'checks' => $checks];
    }
    return ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop',
        'usage' => ['input_tokens' => 12, 'output_tokens' => 34], 'billing' => ['billing_status' => 'settled', 'tenant_cost_points' => .2, 'user_charge_points' => .4]];
};
for ($index = 0; $index < $count; $index++) {
    if ($index % 4 !== $worker % 4) continue;
    $store = new ExecutionStore(2000000812 + $index % 2, 7, $prefix . '_' . $index, $buffer);
    try {
        JobRunner::run($store, 'load_worker_' . $worker, static function ($params, $key, $persist, $admit) use ($index, $receipt, $directory) {
            $admit(['total_tokens' => 200, 'tenant_cost_points' => .5, 'user_charge_points' => 1]);
            // An atomic marker detects duplicate submission across independent PHP processes.
            $marker = @fopen($directory . '/submitted_' . $key, 'x');
            if (!$marker) throw new RuntimeException('Duplicate fixture provider submission');
            fclose($marker); $result = $receipt($params); $persist($result);
            if (getenv('SHORT_DRAMA_VNEXT_KILL_TEST') === '1') {
                fwrite(STDOUT, "raw_receipt_saved\n"); fflush(STDOUT);
                fgets(STDIN); // Parent kills this process, then recovers using a different spool.
            }
            if ($index % 17 === 0 && !isset(json_decode($params['content'], true)['shot_plan'])) throw new RuntimeException('injected_settlement_interrupt');
            return $result;
        });
    } catch (RuntimeException $error) {
        if (!in_array($error->getCode(), [409, 425], true) && $error->getMessage() !== 'injected_settlement_interrupt') { fwrite(STDERR, $error->getMessage()); exit(1); }
    }
}
echo "fixture_worker_done\n";
