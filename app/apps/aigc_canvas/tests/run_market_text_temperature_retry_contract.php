<?php

$root = dirname(__DIR__, 4);
$path = $root . '/app/common/service/power/MarketTextModelRuntimeService.php';
$source = file_get_contents($path);

require $root . '/vendor/autoload.php';

if (!is_string($source) || !str_contains($source, 'provider_temperature_constraint')) {
    fwrite(STDERR, "temperature retry event is missing\n");
    exit(1);
}
if (!str_contains($source, 'private static function requiredTemperature')) {
    fwrite(STDERR, "temperature constraint parser is missing\n");
    exit(1);
}
if (!str_contains($source, 'private static function compatibleGenerationParams')) {
    fwrite(STDERR, "compatibility parameter adapter is missing\n");
    exit(1);
}
if (!str_contains($source, 'if ($retry === null || $compatibilityAttempts >= 2)')) {
    fwrite(STDERR, "unrelated provider errors must not retry\n");
    exit(1);
}

$method = new ReflectionMethod(app\common\service\power\MarketTextModelRuntimeService::class, 'requiredTemperature');
$method->setAccessible(true);
$required = $method->invoke(null, 'invalid temperature: only 0.6 is allowed for this model', []);
if (abs((float)$required - 0.6) > 0.000001) {
    fwrite(STDERR, "valid provider temperature constraint was not parsed\n");
    exit(1);
}
if ($method->invoke(null, 'invalid API key', []) !== null) {
    fwrite(STDERR, "unrelated provider error triggered a retry\n");
    exit(1);
}
if ($method->invoke(null, 'invalid temperature: only 0.6 is allowed for this model', ['temperature' => 0.6]) !== null) {
    fwrite(STDERR, "same constrained temperature triggered a duplicate retry\n");
    exit(1);
}

$compatibilityMethod = new ReflectionMethod(app\common\service\power\MarketTextModelRuntimeService::class, 'compatibleGenerationParams');
$compatibilityMethod->setAccessible(true);
$temperatureRetry = $compatibilityMethod->invoke(null, 'invalid temperature: only 0.6 is allowed for this model', ['temperature' => 1]);
if (($temperatureRetry['reason'] ?? '') !== 'provider_temperature_constraint'
    || abs((float)($temperatureRetry['params']['temperature'] ?? 0) - 0.6) > 0.000001) {
    fwrite(STDERR, "temperature compatibility retry is incorrect\n");
    exit(1);
}
$toolRetry = $compatibilityMethod->invoke(null, 'unsupported parameter: tools', ['tools' => [], 'tool_choice' => 'auto', 'temperature' => 0.6]);
if (($toolRetry['reason'] ?? '') !== 'provider_unsupported_optional_parameter'
    || array_key_exists('tools', (array)($toolRetry['params'] ?? []))
    || array_key_exists('tool_choice', (array)($toolRetry['params'] ?? []))) {
    fwrite(STDERR, "unsupported tool parameters were not removed together\n");
    exit(1);
}
if ($compatibilityMethod->invoke(null, 'invalid model code', ['temperature' => 0.6]) !== null) {
    fwrite(STDERR, "non-parameter errors must not retry\n");
    exit(1);
}

$payloadMethod = new ReflectionMethod(app\common\service\power\MarketTextModelRuntimeService::class, 'parseProviderPayload');
$payloadMethod->setAccessible(true);
$providerFailure = $payloadMethod->invoke(null, ['code' => 0, 'msg' => '任务处理失败，请稍后重试', 'data' => null], '', false);
if (($providerFailure['type'] ?? '') !== 'error' || ($providerFailure['message'] ?? '') !== '任务处理失败，请稍后重试') {
    fwrite(STDERR, "successful HTTP wrapper failure was not surfaced\n");
    exit(1);
}

echo "market text temperature retry contract passed\n";
