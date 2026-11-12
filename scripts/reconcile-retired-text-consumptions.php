<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php'; (new think\App())->initialize();
set_exception_handler(static function(Throwable $e): void { fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL); exit(1); });
$options = getopt('', ['plan:', 'sha256:', 'actor:', 'reason:', 'apply', 'writeoff-approved']);
$path = $options['plan'] ?? '';
if (!is_file($path) || !preg_match('/^[a-f0-9]{64}$/D',$options['sha256']??'') || !hash_equals($options['sha256'],hash_file('sha256',$path))) throw new RuntimeException('必须提供已审核清单的准确 SHA256');
$plan = json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
echo json_encode(\app\common\service\ai\RetiredTextConsumptionReconciliationService::process($plan,isset($options['apply']),$options['actor']??'',$options['reason']??'',isset($options['writeoff-approved'])),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
