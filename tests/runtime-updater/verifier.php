<?php
declare(strict_types=1);
require __DIR__.'/integration.php';
put($site.'/scripts/verify-composer-runtime.php',file_get_contents($root.'/scripts/verify-composer-runtime.php'));
put($result['generation'].'/vendor/composer/autoload_real.php','<?php class ComposerAutoloaderInitBroken {}');
function verifyProcess(string $script,array $args): int { $pipes=[];$p=proc_open(array_merge([PHP_BINARY,$script],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return proc_close($p); }
check(verifyProcess($site.'/scripts/verify-composer-runtime.php',[])===1,'verifier ignored corrupt active generation');
check(verifyProcess($site.'/scripts/verify-composer-runtime.php',['--canonical'])===0,'canonical diagnostic did not inspect original site vendor');
echo "PASS: diagnostics inspect selected effective vendor; canonical mode isolates original vendor\n";
