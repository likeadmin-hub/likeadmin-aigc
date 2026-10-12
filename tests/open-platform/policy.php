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
check(!isset($clean['api_key']) && !isset($clean['tenant_cost_points']) && $clean['display_points'] === 3, 'retail sanitization');
$cap=OpenPolicy::publicData(['prompt_template'=>'internal','config_json'=>['system_prompt'=>'internal'],'tenant_id'=>1,'options'=>[['upstream_unit_cost'=>7,'platform_input_unit_cost'=>8,'platform_gross_margin_points'=>2,'cost_source_url'=>'https://internal.example','tenant_unit_price'=>9,'code'=>'selectable']]]);
check(!isset($cap['prompt_template'])&&!isset($cap['config_json'])&&!isset($cap['tenant_id']),'Internal configuration leaked');
check($cap['options'][0]===['tenant_unit_price'=>9,'code'=>'selectable'],'Nested cost fields leaked or user price removed');
check(OpenPolicy::fingerprint(['metadata'=>(object)[]])!==OpenPolicy::fingerprint(['metadata'=>[]]),'JSON object/array identities collapsed');
check(OpenPolicy::fingerprint(['metadata'=>(object)['b'=>2,'a'=>1]])===OpenPolicy::fingerprint(['metadata'=>['a'=>1,'b'=>2]]),'JSON object ordering is not canonical');
try { OpenPolicy::validateInput(['metadata'=>(object)['_billing_override'=>1]]); throw new RuntimeException('Nested object internal field accepted'); } catch (InvalidArgumentException $e) {}
echo "PASS open platform policy\n";
