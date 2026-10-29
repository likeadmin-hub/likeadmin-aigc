<?php
// Run directly with the local PHP runtime. Only provider HTTP and remote storage
// are fixtures; catalogue, native tasks, queue routing and billing use the DB.
namespace { require dirname(__DIR__, 2) . '/vendor/autoload.php'; }
namespace { require dirname(__DIR__) . '/Support/MusicMarketFixture.php'; }
namespace {
    (new think\App())->initialize();
    use think\facade\Db;
    use app\common\service\app\aigc_music\AigcMusicService as Music;
    use app\common\service\power\MarketMusicAppRuntimeService as Runtime;
    use app\common\service\power\MusicMarketWireFixture as Wire;
    use app\common\service\ai\AiMarketTaskRuntimeService as Worker;
    function musicCheck(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    $db = config('database.connections.mysql');
    musicCheck(PHP_SAPI === 'cli' && $db['hostname'] === '127.0.0.1' && in_array($db['database'], ['z_cn', 'x_cn'], true), 'Local rollback test only');
    $balance = fn() => [(float)Db::name('tenant')->where('id', 1)->value('point_balance'), (float)Db::name('user')->where(['tenant_id' => 1, 'id' => 1])->value('user_money')];
    $before = $balance();
    Db::startTrans();
    try {
        Db::name('aigc_music_channel')->where('tenant_id', 0)->update(['status' => 0]);
        $options = Runtime::options(1);
        musicCheck($options !== [], 'Fixture needs a synced music generate SKU');
        $sku = (int)$options[0]['market_sku_id']; $product = (int)$options[0]['market_product_id'];
        Db::name('power_market_sku')->where('id', $sku)->update(['upstream_price' => 1, 'sale_points' => 3]);
        \app\common\service\power\MarketPriceRuleService::manual(0, $product, $sku, 3);
        \app\common\service\power\MarketPriceRuleService::manual(1, $product, $sku, 7);
        $priceQuery = Db::name('tenant_power_market_sku_price')->where(['tenant_id' => 1, 'sku_id' => $sku]);
        if ($priceQuery->find()) $priceQuery->update(['sale_points' => 7, 'sale_status' => 1]);
        else Db::name('tenant_power_market_sku_price')->insert(['tenant_id' => 1, 'sku_id' => $sku, 'sale_points' => 7, 'sale_status' => 1, 'create_time' => time(), 'update_time' => time()]);
        $params = ['prompt' => 'offline market music fixture', 'instrumental' => true, 'market_sku_id' => $sku, 'market_product_id' => $product, 'duration' => 120];
        $quote = Music::estimate(1, $params);
        musicCheck((float)$quote['tenant_cost_points'] === 3. && (float)$quote['user_charge_points'] === 7. && (int)$quote['quantity'] === 1, 'Music must quote market per-call SKU without old channels or duration multiplication');
        $beforeCalls = count(Wire::$calls);
        foreach ([['market_sku_id' => 99999999], ['market_product_id' => 99999999], ['channel' => 'removed-old-channel']] as $invalid) {
            $rejected = false;
            try { Music::generate(1, 1, array_replace($params, $invalid)); } catch (\Exception $error) { $rejected = true; }
            musicCheck($rejected && count(Wire::$calls) === $beforeCalls && $balance() === $before, 'Invalid market selection dispatched or charged');
        }
        $retail = (float)Db::name('user')->where(['tenant_id' => 1, 'id' => 1])->value('user_money');
        Db::name('user')->where(['tenant_id' => 1, 'id' => 1])->update(['user_money' => 0]);
        $rejected = false;
        try { Music::generate(1, 1, $params); } catch (\Exception $error) { $rejected = true; }
        musicCheck($rejected && count(Wire::$calls) === $beforeCalls, 'Insufficient user balance reached provider');
        Db::name('user')->where(['tenant_id' => 1, 'id' => 1])->update(['user_money' => $retail]);
        $task = Music::generate(1, 1, $params);
        $id = (int)$task['task_id'];
        $hidden = false; try { Music::taskDetail(91003, $id, 1); } catch (\Exception $error) { $hidden = true; }
        musicCheck($hidden, 'Cross-tenant task became visible');
        $native = Db::name('aigc_music_task')->where('id', $id)->find();
        $cid = (int)$native['consumption_id'];
        musicCheck($cid > 0 && (int)$native['market_sku_id'] === $sku && $task['provider_task_id'] === 'music-market-fixture', 'Missing native task, market SKU or provider receipt');
        musicCheck($balance() === [$before[0] - 3, $before[1] - 7], 'Submit must reserve independent tenant and user amounts once');
        $duplicate = Music::generate(1, 1, $params);
        musicCheck((int)$duplicate['task_id'] === $id && count(Wire::$calls) === 1, 'Duplicate submit dispatched or reserved twice');
        $wire = json_decode(Wire::$calls[0][CURLOPT_POSTFIELDS], true);
        musicCheck($wire['type'] === 'generate' && (int)$wire['market_sku_id'] === $sku && $wire['instrumental'] === true, 'Provider payload lost SKU or generation flags');
        Db::name('power_market_sku')->where('id', $sku)->update(['sale_points' => 80, 'sale_status' => 0]);
        Worker::refresh($cid);
        musicCheck(Db::name('aigc_music_task')->where('id', $id)->value('status') === 'running', 'Pending query terminated task');
        Wire::$status = 'success'; Worker::refresh($cid); Worker::refresh($cid);
        Music::taskDetail(1, $id, 1);
        $result = Db::name('aigc_music_result')->where('task_id', $id)->find();
        $c = Db::name('ai_consumption_log')->where('id', $cid)->find();
        musicCheck($c['billing_status'] === 'settled' && (float)$c['actual_tenant_cost'] === 3. && (float)$c['actual_user_price'] === 7., 'Historical prices changed settlement');
        musicCheck($result && json_decode($result['result_json'], true)['audio_id'] === 'song-fixture' && (float)$result['duration'] === 42., 'Music result lost upstream audio ID or metadata');
        musicCheck(Db::name('aigc_music_result')->where('task_id', $id)->count() === 1 && Db::name('aigc_music_billing')->where('task_id', $id)->count() === 1 && $balance() === [$before[0] - 3, $before[1] - 7], 'Repeated refresh duplicated result or charge');
        try { Music::generate(1, 1, $params + ['idempotency_key' => 'off-shelf']); throw new \RuntimeException('Off-shelf SKU accepted'); } catch (\Exception $e) { musicCheck($e->getMessage() !== 'Off-shelf SKU accepted', $e->getMessage()); }
        Db::name('power_market_sku')->where('id', $sku)->update(['sale_points' => 3, 'sale_status' => 1]);
        Wire::$reject = true;
        $failed = Music::generate(1, 1, array_replace($params, ['prompt' => 'offline confirmed rejection']));
        $failedNative = Db::name('aigc_music_task')->where('id', $failed['task_id'])->find();
        Runtime::fail((int)$failedNative['consumption_id'], 'repeated failure'); Worker::refresh((int)$failedNative['consumption_id']);
        musicCheck($failed['status'] === 'failed' && $balance() === [$before[0] - 3, $before[1] - 7], 'Submit rejection failed to refund exactly once');
        Wire::$reject = false; Wire::$tracks = 2; Wire::$status = 'success';
        \app\common\service\app\aigc_music\AigcMusicAssetService::$calls = [];
        \app\common\service\app\aigc_music\AigcMusicAssetService::$failSecond = true;
        $multi = Music::generate(1, 1, array_replace($params, ['prompt' => 'offline two track transfer fixture']));
        Worker::refresh((int)$multi['consumption_id']);
        musicCheck(Db::name('ai_consumption_log')->where('id', $multi['consumption_id'])->value('billing_status') === 'reserved', 'Partial transfer settled prematurely');
        \app\common\service\app\aigc_music\AigcMusicAssetService::$failSecond = false;
        Worker::refresh((int)$multi['consumption_id']); Worker::refresh((int)$multi['consumption_id']);
        musicCheck(Db::name('aigc_music_result')->where('task_id', $multi['task_id'])->count() === 2, 'Retry dropped a generated song');
        musicCheck(\app\common\service\app\aigc_music\AigcMusicAssetService::$calls['https://fixture.invalid/song.mp3'] === 1, 'Retry re-transferred a stored song');
        $beforeUnknown = $balance(); Wire::$timeout = true;
        $unknown = Music::generate(1, 1, array_replace($params, ['prompt' => 'offline uncertain acceptance fixture', 'idempotency_key' => 'unknown-fixture']));
        $callCount = count(Wire::$calls);
        $again = Music::generate(1, 1, array_replace($params, ['prompt' => 'offline uncertain acceptance fixture', 'idempotency_key' => 'unknown-fixture']));
        Worker::refresh((int)$unknown['consumption_id']);
        musicCheck($unknown['status'] === 'pending_confirmation' && $again['task_id'] === $unknown['task_id'] && count(Wire::$calls) === $callCount, 'Unknown receipt was refunded or dispatched twice');
        musicCheck($balance() === [$beforeUnknown[0] - 3, $beforeUnknown[1] - 7], 'Unknown acceptance released its reservation');
        Wire::$timeout = false;
        $frozenQuote = Music::estimate(1, $params);
        Db::name('power_market_sku')->where('id', $sku)->update(['usage_unit' => 'token', 'usage_unit_size' => 1000000, 'sale_points' => 99]);
        $frozenReservation = Runtime::reserve(1, 1, 'frozen-contract-fixture', ['market_sku_id' => $sku], $params, 'aigc_music', 'music_generation', 'aigc_music_task', $frozenQuote['market_snapshot']);
        $snapshot = json_decode(Db::name('ai_consumption_log')->where('id', $frozenReservation['consumption_id'])->value('price_snapshot'), true);
        musicCheck($snapshot['usage_unit'] === 'per_call' && (float)$snapshot['platform_price'] === 3. && (float)$snapshot['tenant_price'] === 7., 'Accepted billing contract was reinterpreted from mutable SKU');
        Runtime::fail((int)$frozenReservation['consumption_id'], 'fixture cleanup');
        echo "Music market workflow passed: no old channels, SKU quote, reserve, duplicate submit, worker query, snapshot settlement, metadata, repeated refresh, shelf rejection and single refund; rolled back\n";
    } finally { Db::rollback(); }
    musicCheck($balance() === $before, 'Rollback did not restore balances');
}
