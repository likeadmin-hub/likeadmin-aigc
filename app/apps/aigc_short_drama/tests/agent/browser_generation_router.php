<?php
declare(strict_types=1);
if (getenv('SHORT_DRAMA_AGENT_TEST')!=='isolated-mysql') throw new RuntimeException('Isolated mock router only');
define('SHORT_DRAMA_BROWSER_MOCK_PROVIDER',true);
require __DIR__.'/browser_mock_provider.php';
require __DIR__.'/http_router.php';
