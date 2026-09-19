# Episode timing policy v1

The existing tenant config detail/setup APIs accept `episode_duration_rule`:
`{enabled:false,target_seconds:120,min_seconds:110,max_seconds:130}`.
The lower bound is advisory for defaults: a short complete script is accepted,
never padded. Explicit user durations and timelines remain exact contracts.

Read-time defaults provide fresh-install/new-tenant/upgrade parity without
rewriting tenant JSON or historical tasks. Enable only the test tenant initially.
Only new submissions and explicit full replans adopt the current config. Internal
retries and episode children inherit `episode_duration_policy` from the parent.
Disabling the tenant switch affects future submissions, not running tasks.

Versioned policy fields: `version`, `source` (default/user/timeline), `scope`
(episode/series), target/min/max seconds and `timeline_segments`. No new tables,
permissions or routes. A whole-series duration receives a validated per-episode
allocation in the confirmed outline before any episode production job runs.

The timed generator plans scene budgets and per-card durations before expanding
groups of at most four cards. Skeleton timing repair is limited to two attempts;
one failed content unit per scene may be repaired once. Calls share V3's existing
48-call and 192000 reserved-output-token budget, and existing durable unit keys.
Completed provider units are reusable after restart. Timing checks precede
normalization; legacy padding and quantity-range gates are bypassed for v1.

Verification: `ShortDramaEpisodeDurationTest`, existing timing, generation,
story-workflow, revision, dialogue and video-duration contracts. Real smoke tests
must record task IDs, actual seconds/counts, retries and model errors separately.
