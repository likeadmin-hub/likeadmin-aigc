<?php

return [
    // Captured on NEW tasks only. Set to 0 to stop enrolling tasks in V3;
    // existing tasks retain their original engine and receipts.
    'generation_version' => 3,
    // Used only when the market's model metadata does not declare a capacity.
    'planning_context_fallback' => 32768,
    'planning_output_fallback' => 8192,
];
