<?php

return [
    // Captured on NEW tasks only. Set to 0 to stop enrolling tasks in V3;
    // existing tasks retain their original engine and receipts.
    'generation_version' => 3,
    // Used only when the market's model metadata does not declare a capacity.
    'planning_context_fallback' => 32768,
    'planning_output_fallback' => 8192,
    // New projects only. Populate explicit tenant IDs and validated model execution policies
    // after the corresponding workflow passes acceptance. Turning this off never reroutes saved jobs.
    // Optional per-model execution.resource_limits (frozen in each new job): total_tokens,
    // tenant_cost_points, user_charge_points. All three are required together; points use 6 decimals.
    // Budgeted models must publish input/context AND output limits. No implicit unlimited fallback.
    'vnext' => ['enabled' => false, 'series_enabled' => false, 'attachment_enabled' => false, 'tenant_ids' => [], 'models' => []],
];
