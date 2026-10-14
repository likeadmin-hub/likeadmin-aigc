<?php
if(getenv('OEM_DISPOSABLE')!=='1'||!str_starts_with((string)getenv('PHP_DATABASE_DATABASE'),'oem_e2e_virtual_')||getenv('PHP_DATABASE_HOSTNAME')!=='oem-mysql')throw new RuntimeException('Disposable virtual acceptance only');
$root=dirname(__DIR__,3);require $root.'/runtime-loader.php';$app=new \AigcRuntimeApp($root);$app->setBaseEnvName('oem-disposable')->setEnvName('oem-disposable')->initialize();require dirname(__DIR__).'/VirtualPayFixture.php';require __DIR__.'/Gateway.php';installVirtualGateway($app);$app->console->run();
