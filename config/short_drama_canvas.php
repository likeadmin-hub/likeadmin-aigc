<?php

// Dedicated short-drama-canvas runtime. Tenant editing/read-only state is
// persisted in aigc_short_drama configuration and execution always uses the
// short-drama resource state plus its existing task services.
return [
    'execution_ready' => true,
    // Only reviewed first-party adapters may be added to CanvasExecutionRuntime.
    'execution_provider' => 'short_drama_resources',
];
