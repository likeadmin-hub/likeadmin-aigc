<?php
declare(strict_types=1);

if (getenv('SHORT_DRAMA_AGENT_TEST') !== 'isolated-mysql') {
    throw new RuntimeException('Isolated Agent generation browser router only');
}

// Both flags are intentionally limited to browser_http_bridge's internal
// fixture. The mock Provider only accepts its synthetic model identifier.
define('SHORT_DRAMA_BROWSER_MOCK_PROVIDER', true);
define('SHORT_DRAMA_BROWSER_AGENT_CONVERSATION', true);
require __DIR__ . '/browser_mock_provider.php';
require __DIR__ . '/http_router.php';
