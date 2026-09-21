<?php
declare(strict_types=1);
if (getenv('SHORT_DRAMA_AGENT_TEST')!=='isolated-mysql') throw new RuntimeException('Isolated Agent browser router only');
// This router exposes the persisted conversation contract only.  It never
// loads a Provider mock and http_router still denies every generation route.
define('SHORT_DRAMA_BROWSER_AGENT_CONVERSATION',true);
require __DIR__.'/http_router.php';
