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
groups sized to the selected model's output budget (at most four cards; two for
4096-token models). Skeleton timing repair is limited to two attempts;
one failed content unit per scene may be repaired once. Calls share V3's existing
48-call and 192000 reserved-output-token budget, and existing durable unit keys.
Completed provider units are reusable after restart. Timing checks precede
normalization; legacy padding and quantity-range gates are bypassed for v1.
Timed episode generation reserves two calls / 8192 output tokens inside that
budget for continuity review and one audit-format correction. A first-seen state
field must have `before:null`; malformed audit JSON may be corrected once without
rewriting any script. Conflicts against already registered facts still block.
Receipt identity excludes model presentation-only fields such as display icons;
actual prompts, model identity, parameters and budgets remain protected. Historical
raw fingerprints are compared through the same canonical request without changing
or re-submitting the old receipts.

Verification: `ShortDramaEpisodeDurationTest`, existing timing, generation,
story-workflow, revision, dialogue and video-duration contracts. Real smoke tests
must record task IDs, actual seconds/counts, retries and model errors separately.

## Same-scene cuts

No subshot schema is introduced. Camera movement carries up to three continuous
relative-time paragraphs within the same location and bound cast. Image prompts
describe only the opening frame. Saved movement is retained after template
assembly without overriding manual prompts. Lip-sync remains single-subject.
Automatic cuts require the server-resolved video model capability
`supports_same_scene_cuts: true` (a boolean); unknown capabilities stay disabled.
Submission success is not visual acceptance. No video path has been newly marked
capable by this change; visual model certification remains a separate rollout gate.
The local image prerequisite succeeded (test project 1107, asset 716, 20 points),
and the 10-second Grok image-to-video quote resolved the owned first frame at
50 points. Browser media navigation returned ERR_BLOCKED_BY_CLIENT, so no paid
video was submitted and neither dialogue nor detail-cut visual acceptance is
claimed. Do not enable automatic cuts until an accessible playback environment
has verified the actual video outputs.
Video quoting and submission reject unsupported planned durations rather than
silently changing the script. Explicit user-selected render durations are separate.

## Local acceptance, 2026-09-20

Tenant 1 only; all cases below used the server-resolved Qwen3.6-Plus model.

| Project | Case | Shots | Seconds | Provider units | Content / timing repairs |
| --- | --- | ---: | ---: | ---: | --- |
| 1104 | Everyday dialogue | 13 | 110 | 7 | 1 / 0 |
| 1106 | Explicit 0–2s, 2–10s timeline | 2 | 10 | 3 | 0 / 0 |
| 1107 | Fast conflict | 13 | 120 | 7 | 0 / 0 |
| 1108 | Emotional story, no padding | 12 | 100 | 4 | 0 / 0 |
| 1111 | Series 1110, episode 1 | 14 | 120 | 6 | 0 / 0 |
| 1112 | Series 1110, episode 2 | 16 | 120 | 7 | 0 / 0 |

Project 1108 confirms the default lower bound is advisory: all twelve planned
cards were retained unchanged and no padding or lower-bound repair call occurred.
Project 1106 retains durations 2 and 8 seconds after persistence. Browser checks
confirmed progressive script body/SSE updates and tenant timing configuration.
Tenant production build passes (existing Sass/chunk-size warnings remain).
The second episode receives the first episode's state and digest. It initially
failed on an audit that invented `before` values for newly registered fields.
Recovery reused all five completed generation units and the original audit;
only one corrective audit call was added. Existing semantic warnings remain
visible for editorial review; tests do not imply perfect creative quality.

Regression coverage includes timing, legacy duration, story continuity, explicit
timeline persistence, provider prompt capture, transactional unit persistence,
dialogue, multi-episode contracts and director prompt formatting. Historical
AutoDuration's minimum expectation and two PlanNormalizationContract fallback
cases also fail on pre-change commit 4023ff761; they are not silently rebaselined.
142 focused regression tests / 5279 assertions pass, including the transactional
persistence suites. Video visual acceptance must be reported separately from
these contract tests. Disable the tenant switch to stop adoption
by new tasks; running tasks continue using their own policy snapshots.
