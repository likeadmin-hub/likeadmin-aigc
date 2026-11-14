<?php
namespace app\common\service\app;

/** Bounded concurrency for read-only upstream catalogues; never executes a generation. */
final class UpstreamCatalogReadBatch
{
    /** Every key receives a result, including requests cancelled when the batch budget expires. */
    public static function run(array $jobs, callable $create, callable $decode, float $budget, int $concurrency = 4): array
    {
        if (!$jobs) return [];
        $results = [];
        $pending = array_keys($jobs);
        $active = [];
        $multi = curl_multi_init();
        $deadline = microtime(true) + max(0, $budget);
        try {
            while ($pending || $active) {
                if (microtime(true) >= $deadline) break;
                while ($pending && count($active) < max(1, $concurrency)) {
                    $key = array_shift($pending);
                    try {
                        $handle = $create($jobs[$key], max(0.001, $deadline - microtime(true)));
                        curl_multi_add_handle($multi, $handle);
                        $active[spl_object_id($handle)] = ['key' => $key, 'handle' => $handle];
                    } catch (\Throwable $e) { $results[$key] = $e; }
                }
                do { $status = curl_multi_exec($multi, $running); } while ($status === CURLM_CALL_MULTI_PERFORM);
                if ($status !== CURLM_OK) break;
                while ($done = curl_multi_info_read($multi)) {
                    $handle = $done['handle'];
                    $id = spl_object_id($handle);
                    $key = $active[$id]['key'];
                    try {
                        if ($done['result'] !== CURLE_OK) throw new \RuntimeException('上游目录读取超时或暂不可用');
                        $results[$key] = $decode((string)curl_multi_getcontent($handle), (int)curl_getinfo($handle, CURLINFO_HTTP_CODE));
                    } catch (\Throwable $e) { $results[$key] = $e; }
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    unset($active[$id]);
                }
                if ($active && curl_multi_select($multi, min(0.2, max(0.001, $deadline - microtime(true)))) === -1) usleep(1000);
            }
        } finally {
            foreach ($active as $entry) {
                curl_multi_remove_handle($multi, $entry['handle']);
                curl_close($entry['handle']);
            }
            curl_multi_close($multi);
        }
        foreach ($jobs as $key => $_) if (!array_key_exists($key, $results)) $results[$key] = new \RuntimeException('上游目录读取超过本批次时限，已保留原有价格');
        return $results;
    }
}
