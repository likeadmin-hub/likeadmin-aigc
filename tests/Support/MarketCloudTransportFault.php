<?php

namespace {
    /** Test-only OSS transport boundary; active solely in an owned disposable worker. */
    final class MarketCloudTransportFault
    {
        private static ?array $fault = null;
        private static string $root = '';
        private static array $urls = [];
        private static array $methods = [];
        private static mixed $failedHandle = null;

        public static function activate(string $root): void
        {
            if (!preg_match('~^/tmp/b56_[a-f0-9]{12}_fixture$~D', $root)
                || !is_file($root.'/owner') || trim(file_get_contents($root.'/owner')) !== basename($root, 'fixture')) {
                throw new \RuntimeException('Cloud fault requires an owned fixture');
            }
            if (!is_file($root.'/network-fault-next.json')) return;
            $fault = json_decode(file_get_contents($root.'/network-fault-next.json'), true, 512, JSON_THROW_ON_ERROR);
            if (!in_array($fault['mode'] ?? '', ['upload-not-sent', 'upload-ack-lost'], true)
                || !preg_match('~^uploads/ai_result/[0-9]+/[0-9]+/asset-[0-9]+-[a-f0-9]{64}\.(png|mp4|wav)$~D', $fault['object'] ?? '')) {
                throw new \RuntimeException('Invalid owned cloud fault');
            }
            self::$root = $root;
            self::$fault = $fault;
        }

        private static function key(mixed $handle): int
        {
            return is_object($handle) ? spl_object_id($handle) : (int)$handle;
        }

        public static function option(mixed $handle, int $option, mixed $value): bool
        {
            if (self::$fault !== null && $option === CURLOPT_URL) self::$urls[self::key($handle)] = (string)$value;
            if (self::$fault !== null && $option === CURLOPT_CUSTOMREQUEST) self::$methods[self::key($handle)] = strtoupper((string)$value);
            if (self::$fault !== null && $option === CURLOPT_UPLOAD && $value) self::$methods[self::key($handle)] = 'PUT';
            return \curl_setopt($handle, $option, $value);
        }

        public static function execute(mixed $handle): string|bool
        {
            $fault = self::$fault;
            $url = self::$urls[self::key($handle)] ?? '';
            $object = ltrim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/');
            if ($fault === null || $object !== $fault['object'] || (self::$methods[self::key($handle)] ?? '') !== 'PUT'
                || !is_file(self::$root.'/network-fault-next.json')) return \curl_exec($handle);
            if (!rename(self::$root.'/network-fault-next.json', self::$root.'/network-fault-claimed.json')) {
                throw new \RuntimeException('Cannot claim owned cloud fault');
            }

            $sent = $fault['mode'] === 'upload-ack-lost';
            if ($sent) {
                $response = \curl_exec($handle);
                $status = (int)\curl_getinfo($handle, CURLINFO_HTTP_CODE);
                if ($response === false || $status < 200 || $status >= 300) {
                    throw new \RuntimeException('Owned upload did not reach a successful OSS response');
                }
            }
            self::$failedHandle = $handle;
            file_put_contents(self::$root.'/network-fault-observed.json', json_encode([
                'mode' => $fault['mode'], 'method' => 'PUT', 'request_sent' => $sent,
                'real_success_response_discarded' => $sent, 'object_sha256' => hash('sha256', $object),
                'injection' => 'deterministic SDK curl boundary; not a real network outage',
            ], JSON_THROW_ON_ERROR));
            return false;
        }

        public static function failed(mixed $handle): bool
        {
            return self::$failedHandle !== null && self::$failedHandle === $handle;
        }
    }
}

namespace OSS\Http {
    function curl_setopt($handle, $option, $value): bool
    {
        return \MarketCloudTransportFault::option($handle, $option, $value);
    }
    function curl_exec($handle): string|bool
    {
        return \MarketCloudTransportFault::execute($handle);
    }
    function curl_errno($handle): int
    {
        return \MarketCloudTransportFault::failed($handle) ? 28 : \curl_errno($handle);
    }
    function curl_error($handle): string
    {
        return \MarketCloudTransportFault::failed($handle) ? 'Owned synthetic transport interruption' : \curl_error($handle);
    }
}
