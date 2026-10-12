# Neuron dialogue replacement implementation plan

Goal: Replace the short-drama Deep Agents bridge with neuron-core/neuron-ai without Python while preserving all business and metered transport contracts.

Architecture: A finite Neuron workflow delegates once to the existing ConversationProviderInterface; the existing decision parser, PHP transaction executor, frozen snapshots, SSE callbacks and billing transport remain authoritative. Old deepagents_v1 snapshots are read through a compatibility mapping to the PHP implementation without rewriting database receipts.

Global constraints: No direct upstream SDK provider; no extra model calls, retry or summary; no business tool execution in the framework; no shared database mutations; keep unrelated release, copyright, commercial access and picture-avatar code at develop.

Review focus: Changed requests after preflight; unknown paid outcome; old queued engine snapshots; cross-tenant/canvas/thread use; multimodal and SSE receipt parity.

- [x] Record full-suite baseline on the integrated isolated checkout. Existing failures remain; compare by case identity, not counts alone.
- [x] Add failing provider parity tests covering preflight, one-shot dispatch, scope and frozen request, complete receipt and SSE forwarding, malformed output and uncertain outcomes.
- [x] Use the real pinned Neuron SDK for preflight preparation plus a single terminal workflow node with no generic agent loop.
- [x] Route new accepted runs to neuron_v1 and old deepagents_v1 runs to the same bounded PHP bridge; preserve legacy and decision_v1 modes.
- [x] Remove the private Python runtime/configuration and convert SDK integration/routing tests to PHP.
- [x] Run database-backed acceptance against a private database clone, plus the full application suite and affected frontend contracts. Core 25/379 and extended 81/741 passed; full suite has unchanged baseline failures, documented separately.
- [x] Obtain independent code review, fix findings and rerun affected checks. Frozen SSE callback order fixed; final review has no new P1/P2.
- [x] Commit only task files; merge checkpoints into isolated local develop for final runtime verification. Deliver code and exact verification limitations, without activating it on the PHP 8.0 live workspace. Implementation commit 18dfc6535, isolated backend merge 0b3d534da. Final full comparison has no new failures, and 81/741 acceptance passed after the merge. Branches are available in the original repositories; original develop checkouts remain untouched.
