<?php
require dirname(__DIR__, 2) . '/app/common/service/open_platform/OpenPolicy.php';
use app\common\service\open_platform\OpenPolicy;
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
check(OpenPolicy::matches('/api/v1/tasks/{task_id}', '/api/v1/tasks/abc-123') === ['task_id' => 'abc-123'], 'task route');
check(OpenPolicy::matches('/api/v1/tasks/{task_id}', '/api/v1/tasks/a/other') === null, 'anchored route');
check(OpenPolicy::ipAllowed('192.0.2.5', ['192.0.2.0/24']), 'IPv4 CIDR');
check(!OpenPolicy::ipAllowed('192.0.3.5', ['192.0.2.0/24']), 'IP rejection');
check(OpenPolicy::ipAllowed('2001:db8::1', ['2001:db8::/32']), 'IPv6 CIDR');
check(OpenPolicy::fingerprint(['b' => 2, 'a' => ['y' => 1, 'x' => 2]]) === OpenPolicy::fingerprint(['a' => ['x' => 2, 'y' => 1], 'b' => 2]), 'canonical object hash');
check(OpenPolicy::fingerprint(['a' => [1,2]]) !== OpenPolicy::fingerprint(['a' => [2,1]]), 'array ordering');
check(!OpenPolicy::isPublicIp('127.0.0.1') && !OpenPolicy::isPublicIp('::1') && !OpenPolicy::isPublicIp('169.254.169.254'), 'SSRF rejects');
check(OpenPolicy::isPublicIp('8.8.8.8'), 'public address');
try { OpenPolicy::validateInput(['price_snapshot' => []]); throw new RuntimeException('reserved input accepted'); } catch (InvalidArgumentException $e) {}
$clean = OpenPolicy::publicData(['tenant_cost_points' => 10, 'results' => [['url' => 'https://example.com/a']], 'api_key' => 'secret', 'display_points' => 3]);
check(!isset($clean['api_key'], $clean['tenant_cost_points']) && $clean['display_points'] === 3, 'retail sanitization');
echo "PASS open platform policy\n";
